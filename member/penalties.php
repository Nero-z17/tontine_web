<?php
// member/penalties.php — Génération automatique et suivi des pénalités du membre
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$user_id = (int)$_SESSION['user_id'];
$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$nombre_penalites_generees = 0;

try {
    $pdo->beginTransaction();

    $stmtRetards = $pdo->prepare("
        SELECT co.id AS contribution_id,
               co.cycle_member_id,
               co.numero_seance,
               co.date_echeance,
               co.montant_attendu,
               c.montant_penalite_retard,
               t.admin_id
        FROM contributions co
        JOIN cycle_members cm ON cm.id = co.cycle_member_id
        JOIN cycles c ON c.id = cm.cycle_id
        JOIN tontines t ON t.id = c.tontine_id
        WHERE cm.user_id = ?
          AND c.tontine_id = ?
          AND c.statut IN ('actif', 'termine')
          AND co.date_echeance < CURDATE()
          AND c.montant_penalite_retard > 0
        ORDER BY co.date_echeance ASC, co.id ASC
        FOR UPDATE
    ");
    $stmtRetards->execute([$user_id, $tontine_id]);
    $cotisations_echues = $stmtRetards->fetchAll();

    $stmtPaiementsAvantEcheance = $pdo->prepare("
        SELECT COALESCE(SUM(montant_total), 0)
        FROM payments
        WHERE contribution_id = ?
          AND statut_paiement = 'valide'
          AND date_paiement < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $stmtPenaliteExistante = $pdo->prepare("
        SELECT id
        FROM penalties
        WHERE contribution_id = ?
          AND type_operation = 'penalite'
        LIMIT 1
        FOR UPDATE
    ");
    $stmtCreerPenalite = $pdo->prepare("
        INSERT INTO penalties (
            cycle_member_id, contribution_id, type_operation, montant,
            motif, statut, auteur_id
        ) VALUES (?, ?, 'penalite', ?, ?, 'en_attente', ?)
    ");

    foreach ($cotisations_echues as $cotisation) {
        $stmtPaiementsAvantEcheance->execute([
            (int)$cotisation['contribution_id'],
            $cotisation['date_echeance'],
        ]);
        $montant_paye_a_temps = (float)$stmtPaiementsAvantEcheance->fetchColumn();

        if ($montant_paye_a_temps >= (float)$cotisation['montant_attendu']) {
            continue;
        }

        $stmtPenaliteExistante->execute([(int)$cotisation['contribution_id']]);
        if ($stmtPenaliteExistante->fetchColumn()) {
            continue;
        }

        $motif = sprintf(
            'Retard sur la cotisation de la séance %d (échéance du %s)',
            (int)$cotisation['numero_seance'],
            date('d/m/Y', strtotime($cotisation['date_echeance']))
        );
        $stmtCreerPenalite->execute([
            (int)$cotisation['cycle_member_id'],
            (int)$cotisation['contribution_id'],
            (float)$cotisation['montant_penalite_retard'],
            $motif,
            (int)$cotisation['admin_id'],
        ]);
        $nombre_penalites_generees++;
    }

    $pdo->commit();

    if ($nombre_penalites_generees > 0) {
        message_flash(
            'warning',
            $nombre_penalites_generees === 1
                ? 'Une pénalité de retard a été générée et transmise à l’administration pour règlement.'
                : $nombre_penalites_generees . ' pénalités de retard ont été générées et transmises à l’administration pour règlement.'
        );
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    message_flash('danger', 'Impossible de vérifier ou de générer vos pénalités. Veuillez réessayer plus tard.');
}

$stmtStats = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN p.type_operation = 'penalite' AND p.statut = 'en_attente' THEN p.montant ELSE 0 END), 0) AS penalites_en_attente,
        COALESCE(SUM(CASE WHEN p.type_operation = 'penalite' AND p.statut = 'regle' THEN p.montant ELSE 0 END), 0) AS penalites_reglees,
        COALESCE(SUM(CASE WHEN p.type_operation = 'bonus' AND p.statut = 'regle' THEN p.montant ELSE 0 END), 0) AS bonus_regles
    FROM penalties p
    JOIN cycle_members cm ON cm.id = p.cycle_member_id
    JOIN cycles c ON c.id = cm.cycle_id
    WHERE cm.user_id = ?
      AND c.tontine_id = ?
      AND p.statut != 'annule'
");
$stmtStats->execute([$user_id, $tontine_id]);
$stats = $stmtStats->fetch();

$stmtPenalites = $pdo->prepare("
    SELECT p.type_operation, p.montant, p.motif, p.statut,
           p.date_operation, p.date_reglement,
           c.titre AS titre_cycle, c.numero_cycle,
           co.numero_seance
    FROM penalties p
    JOIN cycle_members cm ON cm.id = p.cycle_member_id
    JOIN cycles c ON c.id = cm.cycle_id
    LEFT JOIN contributions co ON co.id = p.contribution_id
    WHERE cm.user_id = ?
      AND c.tontine_id = ?
      AND p.statut != 'annule'
    ORDER BY (p.statut = 'en_attente') DESC, p.date_operation DESC, p.id DESC
");
$stmtPenalites->execute([$user_id, $tontine_id]);
$penalites = $stmtPenalites->fetchAll();

$libelles_type = [
    'penalite' => 'Pénalité de retard',
    'bonus' => 'Bonus',
];
$libelles_statut = [
    'en_attente' => 'En attente de règlement',
    'regle' => 'Réglée',
];
$classes_statut = [
    'en_attente' => 'text-bg-warning',
    'regle' => 'text-bg-success',
];

$titre_page = 'Mes pénalités & bonus';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-exclamation-circle me-1"></i>SUIVI DE MES OPÉRATIONS
        </span>
        <h1 class="fw-bold h2 mb-1">Mes pénalités & bonus</h1>
        <p class="text-muted small mb-0">Les retards sont calculés selon les règles du cycle et transmis à l’administration pour règlement.</p>
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
        <div class="col-sm-6 col-lg-4">
            <div class="card card-djangui h-100 p-4 border-left-warning">
                <span class="text-muted small fw-semibold">Pénalités en attente</span>
                <h2 class="h4 fw-bold text-warning-emphasis mt-2 mb-0"><?= format_fcfa($stats['penalites_en_attente'] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card card-djangui h-100 p-4 border-left-danger">
                <span class="text-muted small fw-semibold">Pénalités réglées</span>
                <h2 class="h4 fw-bold text-danger mt-2 mb-0"><?= format_fcfa($stats['penalites_reglees'] ?? 0) ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card card-djangui h-100 p-4 border-left-success">
                <span class="text-muted small fw-semibold">Bonus réglés</span>
                <h2 class="h4 fw-bold text-success mt-2 mb-0"><?= format_fcfa($stats['bonus_regles'] ?? 0) ?></h2>
            </div>
        </div>
    </div>

    <section aria-labelledby="mes-penalites-title">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 id="mes-penalites-title" class="h5 fw-bold mb-0">Historique de mes opérations</h2>
            <span class="badge rounded-pill text-bg-light"><?= count($penalites) ?> opération<?= count($penalites) > 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($penalites)): ?>
            <div class="card card-djangui p-4 text-center text-muted">
                <i class="bi bi-check-circle fs-2 mb-2"></i>
                Aucune pénalité ni aucun bonus à afficher.
            </div>
        <?php else: ?>
            <div class="card card-djangui p-3 p-md-4">
                <div class="table-responsive">
                    <table class="table table-djangui align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Opération</th>
                                <th>Cycle / séance</th>
                                <th>Montant</th>
                                <th>Date</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($penalites as $penalite): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e($libelles_type[$penalite['type_operation']] ?? $penalite['type_operation']) ?></div>
                                        <div class="small text-muted"><?= e($penalite['motif']) ?></div>
                                    </td>
                                    <td>
                                        <div><?= e($penalite['titre_cycle']) ?></div>
                                        <?php if ($penalite['numero_seance'] !== null): ?>
                                            <div class="small text-muted">Séance n°<?= (int)$penalite['numero_seance'] ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold"><?= format_fcfa($penalite['montant']) ?></td>
                                    <td class="small"><?= date('d/m/Y', strtotime($penalite['date_operation'])) ?></td>
                                    <td>
                                        <span class="badge <?= e($classes_statut[$penalite['statut']] ?? 'text-bg-secondary') ?>">
                                            <?= e($libelles_statut[$penalite['statut']] ?? $penalite['statut']) ?>
                                        </span>
                                        <?php if ($penalite['statut'] === 'regle' && !empty($penalite['date_reglement'])): ?>
                                            <div class="small text-muted mt-1">Le <?= date('d/m/Y', strtotime($penalite['date_reglement'])) ?></div>
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
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
