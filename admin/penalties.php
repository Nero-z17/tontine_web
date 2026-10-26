<?php
// admin/penalties.php — Gestion de la caisse de réserve, des pénalités et des bonus
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'creer_operation') {
        $cycle_member_id = (int)($_POST['cycle_member_id'] ?? 0);
        $type_operation = $_POST['type_operation'] ?? '';
        $montant_saisi = trim($_POST['montant'] ?? '');
        $motif = trim($_POST['motif'] ?? '');
        $motif_trop_long = function_exists('mb_strlen')
            ? mb_strlen($motif, 'UTF-8') > 255
            : strlen($motif) > 255;
        $montant_valide = preg_match('/^\d+(?:[.,]\d{1,2})?$/D', $montant_saisi) === 1;
        $montant = $montant_valide ? (float)str_replace(',', '.', $montant_saisi) : 0;

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
            message_flash('danger', 'Veuillez sélectionner un membre inscrit à un cycle de votre tontine.');
        } elseif (!in_array($type_operation, ['penalite', 'bonus'], true)) {
            message_flash('danger', 'Le type d’opération choisi est invalide.');
        } elseif (!$montant_valide || $montant <= 0 || $montant > 9999999999.99) {
            message_flash('danger', 'Le montant doit être supérieur à zéro et comporter au maximum deux décimales.');
        } elseif ($motif === '' || $motif_trop_long) {
            message_flash('danger', 'Le motif est obligatoire et ne doit pas dépasser 255 caractères.');
        } else {
            try {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO penalties (cycle_member_id, type_operation, montant, motif, statut, auteur_id)
                    VALUES (?, ?, ?, ?, 'en_attente', ?)
                ");
                $stmtInsert->execute([$cycle_member_id, $type_operation, $montant, $motif, $user_id]);
                message_flash('success', 'L’opération a été enregistrée en attente de règlement.');
            } catch (PDOException $e) {
                message_flash('danger', 'Impossible d’enregistrer cette opération. Veuillez réessayer.');
            }
        }

        header('Location: ' . BASE_URL . '/admin/penalties.php');
        exit;
    }

    if (in_array($action, ['regler_operation', 'annuler_operation'], true)) {
        $penalty_id = (int)($_POST['penalty_id'] ?? 0);

        try {
            $pdo->beginTransaction();
            $stmtOperation = $pdo->prepare("
                SELECT p.id, p.type_operation, p.montant, p.statut
                FROM penalties p
                JOIN cycle_members cm ON cm.id = p.cycle_member_id
                JOIN cycles c ON c.id = cm.cycle_id
                WHERE p.id = ? AND c.tontine_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmtOperation->execute([$penalty_id, $tontine_id]);
            $operation = $stmtOperation->fetch();

            if (!$operation || $operation['statut'] !== 'en_attente') {
                $pdo->rollBack();
                message_flash('warning', 'Cette opération est introuvable ou a déjà été traitée.');
            } elseif ($action === 'regler_operation') {
                $stmtUpdate = $pdo->prepare("
                    UPDATE penalties
                    SET statut = 'regle', date_reglement = NOW()
                    WHERE id = ? AND statut = 'en_attente'
                ");
                $stmtUpdate->execute([$penalty_id]);

                if ($stmtUpdate->rowCount() !== 1) {
                    throw new RuntimeException('L’opération a été modifiée avant son règlement.');
                }

                $stmtCaisse = $pdo->prepare("
                    UPDATE tontines
                    SET solde_caisse_reserve = solde_caisse_reserve + ?
                    WHERE id = ?
                ");
                $stmtCaisse->execute([$operation['montant'], $tontine_id]);
                if ($stmtCaisse->rowCount() !== 1) {
                    throw new RuntimeException('La caisse de réserve de la tontine est introuvable.');
                }

                $pdo->commit();
                message_flash('success', 'L’opération a été réglée et ajoutée à la caisse de réserve.');
            } else {
                $stmtUpdate = $pdo->prepare("
                    UPDATE penalties
                    SET statut = 'annule'
                    WHERE id = ? AND statut = 'en_attente'
                ");
                $stmtUpdate->execute([$penalty_id]);
                $pdo->commit();
                message_flash('success', 'L’opération en attente a été annulée.');
            }
        } catch (PDOException | RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            message_flash('danger', 'Impossible de traiter cette opération. Veuillez réessayer.');
        }

        header('Location: ' . BASE_URL . '/admin/penalties.php');
        exit;
    }

    message_flash('danger', 'Action non reconnue.');
    header('Location: ' . BASE_URL . '/admin/penalties.php');
    exit;
}

$stmtTontine = $pdo->prepare("
    SELECT solde_caisse_reserve
    FROM tontines
    WHERE id = ?
    LIMIT 1
");
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
        COALESCE(SUM(CASE WHEN p.type_operation = 'penalite' AND p.statut = 'regle' THEN p.montant ELSE 0 END), 0) AS penalites_reglees,
        COALESCE(SUM(CASE WHEN p.type_operation = 'bonus' AND p.statut = 'regle' THEN p.montant ELSE 0 END), 0) AS bonus_regles,
        COALESCE(SUM(CASE WHEN p.statut = 'en_attente' THEN 1 ELSE 0 END), 0) AS operations_en_attente
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
    WHERE c.tontine_id = ?
    ORDER BY (p.statut = 'en_attente') DESC, p.date_operation DESC, p.id DESC
");
$stmtOperations->execute([$tontine_id]);
$operations = $stmtOperations->fetchAll();

$libelles_type = [
    'penalite' => 'Pénalité',
    'bonus' => 'Bonus',
];
$classes_statut = [
    'en_attente' => 'text-bg-warning',
    'regle' => 'text-bg-success',
    'annule' => 'text-bg-secondary',
];
$libelles_statut = [
    'en_attente' => 'En attente',
    'regle' => 'Réglée',
    'annule' => 'Annulée',
];

$titre_page = 'Caisse & Pénalités';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-piggy-bank me-1"></i>CAISSE DE RÉSERVE
        </span>
        <h1 class="fw-bold h2 mb-1">Caisse & Pénalités</h1>
        <p class="text-muted small mb-0">Suivez les pénalités et bonus, puis enregistrez leur règlement dans la caisse de réserve.</p>
    </div>
</div>

<main class="container pb-5">
    <?php if (!empty($_SESSION['flash_texte'])): ?>
        <div class="alert alert-<?= e($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show mt-4" role="alert">
            <?= e($_SESSION['flash_texte']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
        <?php unset($_SESSION['flash_type'], $_SESSION['flash_texte']); ?>
    <?php endif; ?>

    <div class="row g-3 mt-1 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-primary">
                <span class="text-muted small fw-semibold">Solde de la caisse</span>
                <h2 class="h4 fw-bold text-primary mt-2 mb-0"><?= format_fcfa($tontine['solde_caisse_reserve'] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-danger">
                <span class="text-muted small fw-semibold">Pénalités réglées</span>
                <h2 class="h4 fw-bold text-danger mt-2 mb-0"><?= format_fcfa($stats['penalites_reglees'] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-success">
                <span class="text-muted small fw-semibold">Bonus réglés</span>
                <h2 class="h4 fw-bold text-success mt-2 mb-0"><?= format_fcfa($stats['bonus_regles'] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-djangui h-100 p-4 border-left-warning">
                <span class="text-muted small fw-semibold">Opérations en attente</span>
                <h2 class="h4 fw-bold text-warning-emphasis mt-2 mb-0"><?= (int)($stats['operations_en_attente'] ?? 0) ?></h2>
            </div>
        </div>
    </div>

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
                                    <option value="<?= (int)$membre['id'] ?>">
                                        <?= e($membre['titre_cycle']) ?> — <?= e($membre['nom_complet']) ?> (<?= e($membre['code_public']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="type_operation" class="form-label fw-semibold">Type d’opération</label>
                            <select class="form-select" id="type_operation" name="type_operation" required>
                                <option value="penalite">Pénalité</option>
                                <option value="bonus">Bonus / versement volontaire</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="montant" class="form-label fw-semibold">Montant (FCFA)</label>
                            <input type="number" class="form-control" id="montant" name="montant" min="0.01" step="0.01" required>
                        </div>

                        <div class="mb-3">
                            <label for="motif" class="form-label fw-semibold">Motif</label>
                            <textarea class="form-control" id="motif" name="motif" rows="3" maxlength="255" required></textarea>
                        </div>

                        <p class="form-text">L’opération sera créée en attente. La caisse ne sera créditée qu’au moment du règlement.</p>
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
                <span class="badge rounded-pill text-bg-light"><?= count($operations) ?> opération<?= count($operations) > 1 ? 's' : '' ?></span>
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
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($operations as $operation): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= e($libelles_type[$operation['type_operation']] ?? $operation['type_operation']) ?></div>
                                            <div class="small text-muted"><?= e($operation['motif']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= e($operation['nom_complet']) ?></div>
                                            <div class="small text-muted">
                                                <?= e($operation['code_public']) ?> · <?= e($operation['titre_cycle']) ?>
                                            </div>
                                        </td>
                                        <td class="fw-bold"><?= format_fcfa($operation['montant']) ?></td>
                                        <td class="small"><?= date('d/m/Y', strtotime($operation['date_operation'])) ?></td>
                                        <td>
                                            <span class="badge <?= e($classes_statut[$operation['statut']] ?? 'text-bg-secondary') ?>">
                                                <?= e($libelles_statut[$operation['statut']] ?? $operation['statut']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($operation['statut'] === 'en_attente'): ?>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <form method="POST" action="penalties.php">
                                                        <?= champ_csrf() ?>
                                                        <input type="hidden" name="action" value="regler_operation">
                                                        <input type="hidden" name="penalty_id" value="<?= (int)$operation['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                                            <i class="bi bi-check-circle me-1"></i>Régler
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="penalties.php" onsubmit="return confirm('Annuler cette opération en attente ?');">
                                                        <?= champ_csrf() ?>
                                                        <input type="hidden" name="action" value="annuler_operation">
                                                        <input type="hidden" name="penalty_id" value="<?= (int)$operation['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-x-circle me-1"></i>Annuler
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php elseif ($operation['statut'] === 'regle' && !empty($operation['date_reglement'])): ?>
                                                <span class="small text-muted">Réglée le <?= date('d/m/Y', strtotime($operation['date_reglement'])) ?></span>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
