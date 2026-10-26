<?php
// member/notifications.php — Alertes et rappels calculés à partir des données de la tontine
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$user_id = (int)$_SESSION['user_id'];
$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$alertes = [];

$stmtCotisations = $pdo->prepare("
    SELECT co.numero_seance, co.date_echeance, co.montant_attendu,
           co.montant_paye, c.titre AS titre_cycle
    FROM contributions co
    JOIN cycle_members cm ON cm.id = co.cycle_member_id
    JOIN cycles c ON c.id = cm.cycle_id
    WHERE cm.user_id = ?
      AND c.tontine_id = ?
      AND c.statut = 'actif'
      AND co.statut IN ('en_attente', 'partiel', 'en_retard')
      AND co.date_echeance <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY co.date_echeance ASC
");
$stmtCotisations->execute([$user_id, $tontine_id]);

foreach ($stmtCotisations->fetchAll() as $cotisation) {
    $en_retard = $cotisation['date_echeance'] < date('Y-m-d');
    $reste_a_payer = max(0, (float)$cotisation['montant_attendu'] - (float)$cotisation['montant_paye']);
    $alertes[] = [
        'type' => $en_retard ? 'danger' : 'warning',
        'icone' => $en_retard ? 'bi-exclamation-triangle-fill' : 'bi-calendar-event',
        'date_tri' => strtotime($cotisation['date_echeance'] . ' 23:59:59'),
        'titre' => $en_retard ? 'Cotisation en retard' : 'Échéance de cotisation à venir',
        'message' => sprintf(
            '%s — séance %d : %s à régler avant le %s.',
            $cotisation['titre_cycle'],
            (int)$cotisation['numero_seance'],
            format_fcfa($reste_a_payer),
            date('d/m/Y', strtotime($cotisation['date_echeance']))
        ),
        'lien' => BASE_URL . '/member/calendar.php',
        'libelle_lien' => 'Voir mon calendrier',
    ];
}

$stmtPenalites = $pdo->prepare("
    SELECT p.montant, p.motif, p.date_operation
    FROM penalties p
    JOIN cycle_members cm ON cm.id = p.cycle_member_id
    JOIN cycles c ON c.id = cm.cycle_id
    WHERE cm.user_id = ?
      AND c.tontine_id = ?
      AND p.statut = 'en_attente'
    ORDER BY p.date_operation DESC
");
$stmtPenalites->execute([$user_id, $tontine_id]);

foreach ($stmtPenalites->fetchAll() as $penalite) {
    $alertes[] = [
        'type' => 'warning',
        'icone' => 'bi-cash-coin',
        'date_tri' => strtotime($penalite['date_operation']),
        'titre' => 'Opération en attente',
        'message' => sprintf(
            '%s — opération enregistrée le %s. Motif : %s.',
            format_fcfa($penalite['montant']),
            date('d/m/Y', strtotime($penalite['date_operation'])),
            $penalite['motif']
        ),
        'lien' => BASE_URL . '/member/penalties.php',
        'libelle_lien' => 'Voir mes pénalités',
    ];
}

$stmtReunions = $pdo->prepare("
    SELECT titre, date_heure
    FROM meetings
    WHERE tontine_id = ?
      AND statut = 'publiee'
      AND date_heure >= NOW()
    ORDER BY date_heure ASC
");
$stmtReunions->execute([$tontine_id]);

foreach ($stmtReunions->fetchAll() as $reunion) {
    $alertes[] = [
        'type' => 'info',
        'icone' => 'bi-camera-video',
        'date_tri' => strtotime($reunion['date_heure']),
        'titre' => 'Réunion à venir',
        'message' => sprintf(
            '%s — le %s.',
            $reunion['titre'],
            date('d/m/Y à H:i', strtotime($reunion['date_heure']))
        ),
        'lien' => BASE_URL . '/member/meetings.php',
        'libelle_lien' => 'Voir les réunions',
    ];
}

usort($alertes, static function ($a, $b) {
    return $a['date_tri'] <=> $b['date_tri'];
});

$titre_page = 'Mes notifications';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-warning bg-opacity-25 text-warning px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-bell me-1"></i>MES ALERTES ET RAPPELS
        </span>
        <h1 class="fw-bold h2 mb-1">Notifications</h1>
        <p class="text-muted small mb-0">
            Retrouvez les rappels de cotisation, les opérations en attente et les réunions annoncées.
        </p>
    </div>
</div>

<main class="container py-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 fw-bold mb-0">Mes alertes</h2>
        <span class="badge rounded-pill text-bg-light">
            <?= count($alertes) ?> alerte<?= count($alertes) === 1 ? '' : 's' ?>
        </span>
    </div>

    <?php if (empty($alertes)): ?>
        <div class="card card-djangui p-5 text-center">
            <i class="bi bi-check-circle fs-1 text-success mb-2"></i>
            <h3 class="h5 fw-bold">Tout est à jour</h3>
            <p class="text-muted small mb-0">Aucune échéance imminente, opération en attente ou réunion à venir à signaler.</p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($alertes as $alerte): ?>
                <article class="card card-djangui p-3 p-md-4 border-start border-4 border-<?= e($alerte['type']) ?>">
                    <div class="d-flex align-items-start gap-3">
                        <i class="bi <?= e($alerte['icone']) ?> fs-4 text-<?= e($alerte['type']) ?>" aria-hidden="true"></i>
                        <div class="flex-grow-1">
                            <h3 class="h6 fw-bold mb-1"><?= e($alerte['titre']) ?></h3>
                            <p class="text-muted small mb-2"><?= e($alerte['message']) ?></p>
                            <a class="small fw-semibold text-decoration-none" href="<?= e($alerte['lien']) ?>">
                                <?= e($alerte['libelle_lien']) ?><i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>