<?php
// admin/draws.php — Tirage au sort aléatoire verrouillé et suivi des pots par séance (Membre 2)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)$_SESSION['tontine_id'];

// 1. Récupérer tous les cycles de la tontine
$stmtCycles = $pdo->prepare("
    SELECT * FROM cycles
    WHERE tontine_id = ?
    ORDER BY (statut = 'actif') DESC, numero_cycle DESC
");
$stmtCycles->execute([$tontine_id]);
$cycles = $stmtCycles->fetchAll();

// Sélection du cycle à afficher (cycle demandé en GET ou cycle actif par défaut)
$cycle_id_select = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$cycle_courant = null;

foreach ($cycles as $c) {
    if ($cycle_id_select > 0 && (int)$c['id'] === $cycle_id_select) {
        $cycle_courant = $c;
        break;
    }
    if ($cycle_id_select === 0 && $c['statut'] === 'actif') {
        $cycle_courant = $c;
        break;
    }
}
if (!$cycle_courant && !empty($cycles)) {
    $cycle_courant = $cycles[0];
}

// 2. TRAITEMENT POST : LANCER LE TIRAGE AU SORT ET VERROUILLER L'ORDRE DES BOUFFEURS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lancer_tirage') {
    verifier_csrf();
    $cycle_id_post = (int)($_POST['cycle_id'] ?? 0);

    $stmtVerif = $pdo->prepare("SELECT * FROM cycles WHERE id = ? AND tontine_id = ? LIMIT 1");
    $stmtVerif->execute([$cycle_id_post, $tontine_id]);
    $cycle_tirage = $stmtVerif->fetch();

    if (!$cycle_tirage) {
        message_flash('danger', 'Cycle introuvable.');
    } elseif ($cycle_tirage['statut'] !== 'actif') {
        message_flash('warning', 'Le cycle doit être activé avant de pouvoir lancer le tirage au sort.');
    } elseif ((int)$cycle_tirage['tirage_verrouille'] === 1) {
        message_flash('danger', 'Action refusée : le tirage au sort de ce cycle est déjà effectué et verrouillé.');
    } else {
        // Récupérer tous les participants inscrits à ce cycle
        $stmtPart = $pdo->prepare("SELECT id, user_id FROM cycle_members WHERE cycle_id = ?");
        $stmtPart->execute([$cycle_id_post]);
        $participants = $stmtPart->fetchAll();

        $nb = count($participants);
        if ($nb < 2) {
            message_flash('danger', 'Il faut au moins 2 participants dans le cycle pour lancer le tirage.');
        } else {
            try {
                $pdo->beginTransaction();

                // Mélange aléatoire équitable des participants
                shuffle($participants);

                $montant_cotis = (float)$cycle_tirage['montant_cotisation'];
                $pot_attendu   = $montant_cotis * $nb;

                $stmtUpdateRang = $pdo->prepare("
                    UPDATE cycle_members
                    SET rang_beneficiaire = ?, montant_pot_attendu = ?
                    WHERE id = ?
                ");

                foreach ($participants as $index => $p) {
                    $rang = $index + 1;
                    $stmtUpdateRang->execute([$rang, $pot_attendu, (int)$p['id']]);
                    $participants[$index]['rang'] = $rang;
                }

                // Nettoyage de sécurité des cotisations non payées si un brouillon existait
                $stmtClean = $pdo->prepare("
                    DELETE co FROM contributions co
                    INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
                    WHERE cm.cycle_id = ? AND co.montant_paye = 0
                ");
                $stmtClean->execute([$cycle_id_post]);

                // Génération automatique des séances de cotisation (Sans colonne cycle_id dans contributions)
                $stmtInsertCotis = $pdo->prepare("
                    INSERT INTO contributions (
                        cycle_member_id, beneficiaire_cycle_member_id,
                        numero_seance, date_echeance, montant_attendu, montant_paye, statut
                    ) VALUES (?, ?, ?, ?, ?, 0.00, 'en_attente')
                ");

                $date_base = $cycle_tirage['date_debut'];
                $frequence = $cycle_tirage['frequence'];

                foreach ($participants as $seance_index => $beneficiaire) {
                    $numero_seance = $seance_index + 1;

                    // Calcul de la date d'échéance de chaque séance
                    if ($seance_index === 0) {
                        $date_echeance = $date_base;
                    } else {
                        $decalage = match ($frequence) {
                            'hebdo'     => '+' . $seance_index . ' weeks',
                            'bimensuel' => '+' . ($seance_index * 15) . ' days',
                            default     => '+' . $seance_index . ' months',
                        };
                        $date_echeance = date('Y-m-d', strtotime($date_base . ' ' . $decalage));
                    }

                    // Chaque membre du cycle doit cotiser pour le bénéficiaire ("bouffeur") de cette séance
                    foreach ($participants as $cotisant) {
                        $stmtInsertCotis->execute([
                            (int)$cotisant['id'],
                            (int)$beneficiaire['id'],
                            $numero_seance,
                            $date_echeance,
                            $montant_cotis
                        ]);
                    }
                }

                // Verrouillage définitif du tirage au sort sur le cycle
                $stmtLock = $pdo->prepare("UPDATE cycles SET tirage_verrouille = 1 WHERE id = ?");
                $stmtLock->execute([$cycle_id_post]);

                $pdo->commit();
                message_flash('success', 'Tirage au sort effectué et verrouillé avec succès ! Toutes les séances de cotisation ont été générées.');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                message_flash('danger', 'Erreur lors du tirage au sort : ' . $e->getMessage());
            }
        }
    }

    header('Location: ' . BASE_URL . '/admin/draws.php?cycle_id=' . $cycle_id_post);
    exit;
}

// 3. RÉCUPÉRATION DES RÉSULTATS DU TIRAGE ET DE L'AVANCEMENT DES POTS
$membres_cycle = [];
$seances_stats = [];

if ($cycle_courant) {
    $cid = (int)$cycle_courant['id'];

    // Liste des participants et de leur rang de bouffeur
    $stmtMC = $pdo->prepare("
        SELECT cm.*, u.code_public, u.nom_complet, u.telephone, u.canal_paiement, u.telephone_paiement, u.role
        FROM cycle_members cm
        INNER JOIN users u ON u.id = cm.user_id
        WHERE cm.cycle_id = ?
        ORDER BY (cm.rang_beneficiaire IS NULL) ASC, cm.rang_beneficiaire ASC, u.nom_complet ASC
    ");
    $stmtMC->execute([$cid]);
    $membres_cycle = $stmtMC->fetchAll();

    // CORRECTION LIGNE 130 : Jointure avec cycle_members cm pour filtrer par cm.cycle_id
    $stmtSeances = $pdo->prepare("
        SELECT co.numero_seance,
               co.date_echeance,
               co.beneficiaire_cycle_member_id,
               COUNT(co.id) AS nb_cotisations,
               SUM(CASE WHEN co.statut = 'paye' THEN 1 ELSE 0 END) AS nb_payees,
               COALESCE(SUM(co.montant_attendu), 0) AS total_attendu_seance,
               COALESCE(SUM(co.montant_paye), 0) AS total_paye_seance
        FROM contributions co
        INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
        WHERE cm.cycle_id = ?
        GROUP BY co.numero_seance, co.date_echeance, co.beneficiaire_cycle_member_id
        ORDER BY co.numero_seance ASC
    ");
    $stmtSeances->execute([$cid]);
    foreach ($stmtSeances->fetchAll() as $row) {
        $seances_stats[(int)$row['numero_seance']] = $row;
    }
}

$titre_page = "Tirage au Sort & Ordre des Bouffeurs";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                    <i class="bi bi-shuffle me-1"></i>TIRAGE AU SORT ÉQUITABLE & SCELLÉ
                </span>
                <h1 class="fw-bold h2 mb-1">Ordre des Bénéficiaires (« Bouffeurs »)</h1>
                <p class="text-muted small mb-0">
                    Le tirage attribue un rang unique à chaque participant et se verrouille automatiquement pour tout le cycle.
                </p>
            </div>
            <div class="col-lg-5">
                <?php if (!empty($cycles)): ?>
                    <form method="GET" action="draws.php" class="d-flex gap-2 justify-content-lg-end">
                        <select name="cycle_id" class="form-select form-select-sm" style="max-width: 280px;" onchange="this.form.submit()">
                            <?php foreach ($cycles as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= ($cycle_courant && (int)$cycle_courant['id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                    Cycle #<?= (int)$c['numero_cycle'] ?> — <?= e($c['titre'] ?? '') ?> (<?= e(ucfirst($c['statut'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle_courant): ?>
        <div class="card card-djangui p-5 text-center">
            <i class="bi bi-arrow-repeat fs-1 text-muted mb-3"></i>
            <h4 class="fw-bold">Aucun cycle trouvé</h4>
            <p class="text-muted small mb-3">Veuillez d'abord créer et activer un cycle dans la gestion des cycles.</p>
            <div>
                <a href="<?= BASE_URL ?>/admin/cycles.php" class="btn btn-glow-blue">Créer un cycle</a>
            </div>
        </div>
    <?php else: ?>

        <!-- BANNIÈRE D'ACTION DU TIRAGE AU SORT -->
        <div class="card-accent-djangui p-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-primary rounded-pill">Cycle #<?= (int)$cycle_courant['numero_cycle'] ?></span>
                        <?php if ((int)$cycle_courant['tirage_verrouille'] === 1): ?>
                            <span class="badge bg-success rounded-pill"><i class="bi bi-lock-fill me-1"></i>Tirage Verrouillé</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark rounded-pill"><i class="bi bi-unlock-fill me-1"></i>En attente de tirage</span>
                        <?php endif; ?>
                    </div>
                    <h4 class="fw-bold mb-1">
                        <?= e($cycle_courant['titre'] ?? ('Cycle #' . $cycle_courant['numero_cycle'])) ?> —
                        Pot de <?= format_fcfa((float)$cycle_courant['montant_cotisation'] * count($membres_cycle)) ?> par tour
                    </h4>
                    <p class="text-muted small mb-0">
                        <?= count($membres_cycle) ?> participants inscrits • Cotisation individuelle :
                        <strong><?= format_fcfa($cycle_courant['montant_cotisation']) ?></strong>
                    </p>
                </div>

                <div class="col-lg-4 text-lg-end">
                    <?php if ((int)$cycle_courant['tirage_verrouille'] === 0 && $cycle_courant['statut'] === 'actif'): ?>
                        <form method="POST" action="draws.php" id="formTirage" onsubmit="return confirm('Attention : le tirage au sort est unique et sera verrouillé définitivement pour ce cycle. Confirmer ?');">
                            <?= champ_csrf() ?>
                            <input type="hidden" name="action" value="lancer_tirage">
                            <input type="hidden" name="cycle_id" value="<?= (int)$cycle_courant['id'] ?>">
                            <button type="submit" class="btn btn-glow-emerald btn-lg px-4">
                                <i class="bi bi-shuffle me-2"></i>Lancer le tirage au sort
                            </button>
                        </form>
                    <?php elseif ($cycle_courant['statut'] === 'brouillon'): ?>
                        <a href="<?= BASE_URL ?>/admin/cycles.php" class="btn btn-warning fw-bold text-dark">
                            Activer d'abord ce cycle
                        </a>
                    <?php else: ?>
                        <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-3 accent-subbox small fw-semibold">
                            <i class="bi bi-shield-check text-success fs-5"></i>
                            <span>Ordre officiel scellé pour tout le cycle</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TABLEAU OFFICIEL DE L'ORDRE DES BOUFFEURS ET DU SUIVI DES POTS -->
        <div class="card card-djangui p-4 border-left-navy">
            <h5 class="fw-bold mb-3">
                <i class="bi bi-trophy-fill text-warning me-2"></i>Ordre de passage et réception des pots
            </h5>

            <div class="table-responsive">
                <table class="table table-djangui align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>Membre Bénéficiaire (« Bouffeur »)</th>
                            <th>Compte de Réception</th>
                            <th>Date de Séance</th>
                            <th>Pot Reçu / Attendu</th>
                            <th>État du Pot</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($membres_cycle as $m): ?>
                            <?php
                                $rang = $m['rang_beneficiaire'] ? (int)$m['rang_beneficiaire'] : null;
                                $info_seance = ($rang && isset($seances_stats[$rang])) ? $seances_stats[$rang] : null;
                                $pot_attendu = (float)$m['montant_pot_attendu'];
                                $pot_recu    = $info_seance ? max((float)$m['montant_pot_recu'], (float)$info_seance['total_paye_seance']) : (float)$m['montant_pot_recu'];
                                $pct_pot     = $pot_attendu > 0 ? min(100, round(($pot_recu / $pot_attendu) * 100)) : 0;
                                $manque_pot  = max(0, $pot_attendu - $pot_recu);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($rang): ?>
                                        <span class="badge bg-primary rounded-pill px-3 py-2 fs-6">Tour n°<?= $rang ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill">Non tiré</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= e($m['nom_complet']) ?></div>
                                    <div class="small text-muted">
                                        <?= e($m['code_public']) ?>
                                        <?php if ($m['role'] === 'admin'): ?>
                                            • <span class="text-primary fw-semibold">Secrétaire</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-uppercase">
                                        <?= e($m['canal_paiement']) ?>
                                    </span>
                                    <div class="small fw-semibold mt-1"><?= e($m['telephone_paiement'] ?: $m['telephone']) ?></div>
                                </td>
                                <td>
                                    <?php if ($info_seance): ?>
                                        <div class="fw-semibold">
                                            <i class="bi bi-calendar-event text-primary me-1"></i>
                                            <?= date('d/m/Y', strtotime($info_seance['date_echeance'])) ?>
                                        </div>
                                        <span class="small text-muted">Séance #<?= $rang ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 220px;">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <strong class="text-success"><?= format_fcfa($pot_recu) ?></strong>
                                        <span class="text-muted">/ <?= format_fcfa($pot_attendu) ?></span>
                                    </div>
                                    <div class="progress" style="height: 7px;">
                                        <div class="progress-bar bg-success" style="width: <?= $pct_pot ?>%"></div>
                                    </div>
                                    <?php if ($rang && $manque_pot > 0): ?>
                                        <div class="small text-danger mt-1">
                                            Reliquat dû à ce bouffeur : <strong><?= format_fcfa($manque_pot) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$rang): ?>
                                        <span class="badge bg-secondary">En attente du tirage</span>
                                    <?php elseif ($pct_pot >= 100 || (int)$m['pot_totalement_recu'] === 1): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Pot complet reçu</span>
                                    <?php elseif ($pot_recu > 0): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Partiel (<?= $pct_pot ?> %)</span>
                                    <?php else: ?>
                                        <span class="badge bg-info text-dark">Tour à venir</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>