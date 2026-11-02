<?php
// admin/penalties.php — Gestion de la caisse de réserve, du taux de pénalité (%) et des opérations (Pénalités & Bonus)
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";

verifier_admin();

$tontine_id = (int)($_SESSION["tontine_id"] ?? 0);
$user_id    = (int)$_SESSION["user_id"];

// Cycle actif de la tontine (pour le réglage du % de pénalité)
$stmtCycleActif = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? AND statut = \x27actif\x27 LIMIT 1");
$stmtCycleActif->execute([$tontine_id]);
$cycle_actif = $stmtCycleActif->fetch();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verifier_csrf();
    $action = $_POST["action"] ?? "";

    // ACTION A : Modifier le pourcentage (%) de pénalité de retard du cycle actif
    if ($action === "modifier_taux_penalite") {
        if (!$cycle_actif) {
            message_flash("warning", "Aucun cycle actif : activez d\x27abord un cycle pour modifier son taux de pénalité.");
            header("Location: " . BASE_URL . "/admin/penalties.php");
            exit;
        }

        $nouveau_pct = filter_var($_POST["pourcentage_penalite"] ?? null, FILTER_VALIDATE_FLOAT);
        if ($nouveau_pct === false || $nouveau_pct < 0 || $nouveau_pct > 100) {
            message_flash("danger", "Veuillez saisir un pourcentage valide compris entre 0 % et 100 %.");
        } else {
            $montant_cotis = (float)$cycle_actif["montant_cotisation"];
            $nouveau_montant_pen = round($montant_cotis * ($nouveau_pct / 100));

            $pdo->beginTransaction();
            try {
                $stmtUpdCycle = $pdo->prepare("UPDATE cycles SET montant_penalite_retard = ? WHERE id = ? AND tontine_id = ?");
                $stmtUpdCycle->execute([$nouveau_montant_pen, (int)$cycle_actif["id"], $tontine_id]);

                // Mettre également à jour les pénalités auto-générées encore en_attente sur ce cycle
                $stmtUpdPen = $pdo->prepare("
                    UPDATE penalties p
                    INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
                    SET p.montant = ?
                    WHERE cm.cycle_id = ?
                      AND p.type_operation = \x27penalite\x27
                      AND p.statut = \x27en_attente\x27
                      AND p.contribution_id IS NOT NULL
                ");
                $stmtUpdPen->execute([$nouveau_montant_pen, (int)$cycle_actif["id"]]);

                $pdo->commit();
                message_flash("success", "Le taux de pénalité du cycle actif a été fixé à {$nouveau_pct} % (soit " . format_fcfa($nouveau_montant_pen) . " par cotisation en retard).");
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                message_flash("danger", "Erreur lors de la mise à jour du taux de pénalité.");
            }
        }
        header("Location: " . BASE_URL . "/admin/penalties.php");
        exit;
    }

    // ACTION B : Créer une opération manuelle (Pénalité en attente pour le membre OU Bonus encaissé dans la caisse)
    if ($action === "creer_operation") {
        $cycle_member_id = (int)($_POST["cycle_member_id"] ?? 0);
        $type_operation  = $_POST["type_operation"] ?? "";
        $montant_saisi   = trim($_POST["montant"] ?? "");
        $motif           = trim($_POST["motif"] ?? "");
        $motif_trop_long = function_exists("mb_strlen")
            ? mb_strlen($motif, "UTF-8") > 255
            : strlen($motif) > 255;
        $montant_valide  = preg_match("/^\\d+(?:[.,]\\d{1,2})?$/D", $montant_saisi) === 1;
        $montant         = $montant_valide ? (float)str_replace(",", ".", $montant_saisi) : 0;

        $stmtMembre = $pdo->prepare("
            SELECT cm.id
            FROM cycle_members cm
            JOIN cycles c ON c.id = cm.cycle_id
            WHERE cm.id = ? AND c.tontine_id = ?
            LIMIT 1
        ");
        $stmtMembre->execute([$cycle_member_id, $tontine_id]);
        $membre_cycle_valide = (bool)$stmtMembre->fetchColumn();

        if (!$membre_cycle_valide) {
            message_flash("danger", "Veuillez sélectionner un membre inscrit à un cycle de votre tontine.");
        } elseif (!in_array($type_operation, ["penalite", "bonus"], true)) {
            message_flash("danger", "Le type d’opération choisi est invalide.");
        } elseif (!$montant_valide || $montant <= 0 || $montant > 9999999999.99) {
            message_flash("danger", "Le montant doit être supérieur à zéro et comporter au maximum deux décimales.");
        } elseif ($motif === "" || $motif_trop_long) {
            message_flash("danger", "Le motif est obligatoire et ne doit pas dépasser 255 caractères.");
        } else {
            try {
                $pdo->beginTransaction();
                if ($type_operation === "bonus") {
                    // Un bonus (versement volontaire) alimente directement la caisse de réserve
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO penalties (cycle_member_id, type_operation, montant, motif, statut, date_operation, date_reglement, auteur_id)
                        VALUES (?, \x27bonus\x27, ?, ?, \x27regle\x27, NOW(), NOW(), ?)
                    ");
                    $stmtInsert->execute([$cycle_member_id, $montant, $motif, $user_id]);

                    $stmtCaisse = $pdo->prepare("UPDATE tontines SET solde_caisse_reserve = solde_caisse_reserve + ? WHERE id = ?");
                    $stmtCaisse->execute([$montant, $tontine_id]);

                    $pdo->commit();
                    message_flash("success", "Le bonus de " . format_fcfa($montant) . " a été enregistré et ajouté à la caisse de réserve.");
                } else {
                    // Une pénalité est enregistrée en_attente : seul le membre peut la régler (ou elle sera déduite de son pot)
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO penalties (cycle_member_id, type_operation, montant, motif, statut, date_operation, auteur_id)
                        VALUES (?, \x27penalite\x27, ?, ?, \x27en_attente\x27, NOW(), ?)
                    ");
                    $stmtInsert->execute([$cycle_member_id, $montant, $motif, $user_id]);

                    $pdo->commit();
                    message_flash("success", "La pénalité a été imputée au membre (il pourra la régler depuis son espace ou elle sera déduite de son pot).");
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                message_flash("danger", "Impossible d’enregistrer cette opération. Veuillez réessayer.");
            }
        }

        header("Location: " . BASE_URL . "/admin/penalties.php");
        exit;
    }

    // ACTION C : Supprimer uniquement une pénalité en attente (L\x27admin ne règle jamais à la place du membre)
    if ($action === "supprimer_operation") {
        $penalty_id = (int)($_POST["penalty_id"] ?? 0);

        try {
            $stmtDel = $pdo->prepare("
                DELETE p FROM penalties p
                INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
                INNER JOIN cycles c ON c.id = cm.cycle_id
                WHERE p.id = ? AND c.tontine_id = ? AND p.statut = \x27en_attente\x27
            ");
            $stmtDel->execute([$penalty_id, $tontine_id]);

            if ($stmtDel->rowCount() === 1) {
                message_flash("success", "La pénalité en attente a été supprimée.");
            } else {
                message_flash("warning", "Cette opération est introuvable ou a déjà été réglée par le membre.");
            }
        } catch (PDOException $e) {
            message_flash("danger", "Impossible de supprimer cette pénalité.");
        }

        header("Location: " . BASE_URL . "/admin/penalties.php");
        exit;
    }

    message_flash("danger", "Action non reconnue.");
    header("Location: " . BASE_URL . "/admin/penalties.php");
    exit;
}

$stmtTontine = $pdo->prepare("SELECT solde_caisse_reserve FROM tontines WHERE id = ? LIMIT 1");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

$stmtMembres = $pdo->prepare("
    SELECT cm.id, cm.rang_beneficiaire, u.nom_complet, u.code_public,
           c.titre AS titre_cycle, c.numero_cycle
    FROM cycle_members cm
    JOIN cycles c ON c.id = cm.cycle_id
    JOIN users u ON u.id = cm.user_id
    WHERE c.tontine_id = ?
    ORDER BY c.numero_cycle DESC, cm.rang_beneficiaire IS NULL, cm.rang_beneficiaire, u.nom_complet
");
$stmtMembres->execute([$tontine_id]);
$membres_cycle = $stmtMembres->fetchAll();

$stmtStats = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN p.type_operation = \x27penalite\x27 AND p.statut = \x27regle\x27 THEN p.montant ELSE 0 END), 0) AS penalites_reglees,
        COALESCE(SUM(CASE WHEN p.type_operation = \x27bonus\x27 AND p.statut = \x27regle\x27 THEN p.montant ELSE 0 END), 0) AS bonus_regles,
        COALESCE(SUM(CASE WHEN p.statut = \x27en_attente\x27 THEN 1 ELSE 0 END), 0) AS operations_en_attente
    FROM penalties p
    JOIN cycle_members cm ON cm.id = p.cycle_member_id
    JOIN cycles c ON c.id = cm.cycle_id
    WHERE c.tontine_id = ?
");
$stmtStats->execute([$tontine_id]);
$stats = $stmtStats->fetch();

$stmtOperations = $pdo->prepare("
    SELECT p.id, p.type_operation, p.montant, p.motif, p.statut,
           p.date_operation, p.date_reglement,
           u.nom_complet, u.code_public, c.titre AS titre_cycle,
           c.numero_cycle, cm.rang_beneficiaire
    FROM penalties p
    JOIN cycle_members cm ON cm.id = p.cycle_member_id
    JOIN users u ON u.id = cm.user_id
    JOIN cycles c ON c.id = cm.cycle_id
    WHERE c.tontine_id = ? AND p.statut != \x27annule\x27
    ORDER BY (p.statut = \x27en_attente\x27) DESC, p.date_operation DESC, p.id DESC
");
$stmtOperations->execute([$tontine_id]);
$operations = $stmtOperations->fetchAll();

$pct_actuel = ($cycle_actif && (float)$cycle_actif["montant_cotisation"] > 0)
    ? round(((float)$cycle_actif["montant_penalite_retard"] / (float)$cycle_actif["montant_cotisation"]) * 100, 1)
    : 0.0;

$libelles_type = [
    "penalite" => "Pénalité",
    "bonus"    => "Bonus",
];
$classes_statut = [
    "en_attente" => "text-bg-warning",
    "regle"      => "text-bg-success",
];
$libelles_statut = [
    "en_attente" => "En attente (à régler par le membre)",
    "regle"      => "Réglée",
];

$titre_page = "Caisse & Pénalités";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/navbar.php";
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-piggy-bank me-1"></i>CAISSE DE RÉSERVE & TAUX DE PÉNALITÉ
        </span>
        <h1 class="fw-bold h2 mb-1">Caisse & Pénalités</h1>
        <p class="text-muted small mb-0">Ajustez le pourcentage de pénalité du cycle actif et suivez les opérations de la caisse de réserve.</p>
    </div>
</div>

<main class="container pb-5">
    <div class="row g-3 mt-1 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-primary">
                <span class="text-muted small fw-semibold">Solde de la caisse</span>
                <h2 class="h4 fw-bold text-primary mt-2 mb-0"><?= format_fcfa($tontine["solde_caisse_reserve"] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-danger">
                <span class="text-muted small fw-semibold">Pénalités réglées</span>
                <h2 class="h4 fw-bold text-danger mt-2 mb-0"><?= format_fcfa($stats["penalites_reglees"] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-success">
                <span class="text-muted small fw-semibold">Bonus réglés</span>
                <h2 class="h4 fw-bold text-success mt-2 mb-0"><?= format_fcfa($stats["bonus_regles"] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-warning">
                <span class="text-muted small fw-semibold">Pénalités en attente</span>
                <h2 class="h4 fw-bold text-warning-emphasis mt-2 mb-0"><?= (int)($stats["operations_en_attente"] ?? 0) ?></h2>
            </div>
        </div>
    </div>

    <?php if ($cycle_actif): ?>
        <div class="card card-djangui p-4 mb-4 border-left-warning">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h2 class="h5 fw-bold mb-1">
                        <i class="bi bi-percent text-warning me-2"></i>Taux de pénalité de retard — <?= e($cycle_actif["titre"]) ?>
                    </h2>
                    <p class="text-muted small mb-0">
                        Défini en pourcentage de la cotisation par séance (<strong><?= format_fcfa($cycle_actif["montant_cotisation"]) ?></strong>).
                    </p>
                </div>
                <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                    Taux actif : <?= $pct_actuel ?> % (<?= format_fcfa($cycle_actif["montant_penalite_retard"]) ?>)
                </span>
            </div>

            <form method="POST" action="penalties.php" class="row g-3 align-items-center">
                <?= champ_csrf() ?>
                <input type="hidden" name="action" value="modifier_taux_penalite">
                <div class="col-auto">
                    <label for="pourcentage_penalite" class="col-form-label fw-semibold small">Modifier le pourcentage :</label>
                </div>
                <div class="col-auto">
                    <div class="input-group">
                        <input type="number" name="pourcentage_penalite" id="pourcentage_penalite" class="form-control fw-bold"
                               min="0" max="100" step="0.5" value="<?= $pct_actuel ?>" style="max-width: 110px;" required>
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-glow-blue">
                        <i class="bi bi-check2-circle me-1"></i>Appliquer le nouveau taux
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <section class="col-lg-4" aria-labelledby="nouvelle-operation-title">
            <div class="card card-djangui p-4">
                <h2 id="nouvelle-operation-title" class="h5 fw-bold mb-3">
                    <i class="bi bi-plus-circle text-primary me-2"></i>Nouvelle opération
                </h2>
                <?php if (empty($membres_cycle)): ?>
                    <p class="text-muted mb-0">Aucun membre n’est encore inscrit à un cycle. Créez un cycle avant d’ajouter une opération.</p>
                <?php else: ?>
                    <form method="POST" action="penalties.php">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="creer_operation">

                        <div class="mb-3">
                            <label for="cycle_member_id" class="form-label fw-semibold">Membre et cycle</label>
                            <select class="form-select" id="cycle_member_id" name="cycle_member_id" required>
                                <option value="">Sélectionner un membre</option>
                                <?php foreach ($membres_cycle as $membre): ?>
                                    <option value="<?= (int)$membre["id"] ?>">
                                        <?= e($membre["titre_cycle"]) ?> — <?= e($membre["nom_complet"]) ?> (<?= e($membre["code_public"]) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="type_operation" class="form-label fw-semibold">Type d’opération</label>
                            <select class="form-select" id="type_operation" name="type_operation" required>
                                <option value="bonus">Bonus / versement volontaire (encaissé)</option>
                                <option value="penalite">Pénalité manuelle (à payer par le membre)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="montant" class="form-label fw-semibold">Montant (FCFA)</label>
                            <input type="number" class="form-control" id="montant" name="montant" min="100" step="100" required>
                        </div>

                        <div class="mb-3">
                            <label for="motif" class="form-label fw-semibold">Motif</label>
                            <textarea class="form-control" id="motif" name="motif" rows="3" maxlength="255" required></textarea>
                        </div>

                        <p class="form-text">Les pénalités sont réglées par le membre depuis son espace (ou prélevées automatiquement sur son pot).</p>
                        <button type="submit" class="btn btn-glow-blue w-100">
                            <i class="bi bi-check2-circle me-1"></i>Enregistrer l’opération
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="col-lg-8" aria-labelledby="operations-title">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="operations-title" class="h5 fw-bold mb-0">Historique des opérations</h2>
                <span class="badge rounded-pill text-bg-light"><?= count($operations) ?> opération<?= count($operations) > 1 ? "s" : "" ?></span>
            </div>

            <?php if (empty($operations)): ?>
                <div class="card card-djangui p-4 text-center text-muted">
                    <i class="bi bi-receipt fs-2 mb-2"></i>
                    Aucune pénalité ni aucun bonus enregistré pour cette tontine.
                </div>
            <?php else: ?>
                <div class="card card-djangui p-3 p-md-4">
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Opération</th>
                                    <th>Membre / cycle</th>
                                    <th>Montant</th>
                                    <th>Date</th>
                                    <th>Statut</th>
                                    <th class="text-end">Action Admin</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($operations as $operation): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= e($libelles_type[$operation["type_operation"]] ?? $operation["type_operation"]) ?></div>
                                            <div class="small text-muted"><?= e($operation["motif"]) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= e($operation["nom_complet"]) ?></div>
                                            <div class="small text-muted">
                                                <?= e($operation["code_public"]) ?> · <?= e($operation["titre_cycle"]) ?>
                                            </div>
                                        </td>
                                        <td class="fw-bold"><?= format_fcfa($operation["montant"]) ?></td>
                                        <td class="small"><?= date("d/m/Y", strtotime($operation["date_operation"])) ?></td>
                                        <td>
                                            <span class="badge <?= e($classes_statut[$operation["statut"]] ?? "text-bg-secondary") ?>">
                                                <?= e($libelles_statut[$operation["statut"]] ?? $operation["statut"]) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($operation["statut"] === "en_attente"): ?>
                                                <form method="POST" action="penalties.php" class="d-inline" onsubmit="return confirm(\x27Supprimer définitivement cette pénalité en attente ?\x27);">
                                                    <?= champ_csrf() ?>
                                                    <input type="hidden" name="action" value="supprimer_operation">
                                                    <input type="hidden" name="penalty_id" value="<?= (int)$operation["id"] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash3 me-1"></i>Supprimer
                                                    </button>
                                                </form>
                                            <?php elseif ($operation["statut"] === "regle" && !empty($operation["date_reglement"])): ?>
                                                <span class="small text-muted">Réglée le <?= date("d/m/Y", strtotime($operation["date_reglement"])) ?></span>
                                            <?php else: ?>
                                                <span class="small text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
