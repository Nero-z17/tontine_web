<?php
// member/meetings.php — Réunions publiées de la tontine
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);

$stmtMeetings = $pdo->prepare("
    SELECT id, titre, date_heure, lien_zoom, description, statut,
           (date_heure >= NOW()) AS est_a_venir
    FROM meetings
    WHERE tontine_id = ?
      AND statut IN ('publiee', 'terminee')
    ORDER BY (date_heure >= NOW()) DESC,
             CASE WHEN date_heure >= NOW() THEN date_heure END ASC,
             date_heure DESC
");
$stmtMeetings->execute([$tontine_id]);
$reunions = $stmtMeetings->fetchAll();

$titre_page = 'Réunions de la tontine';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-info bg-opacity-25 text-info px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-camera-video me-1"></i>AGENDA DE LA TONTINE
        </span>
        <h1 class="fw-bold h2 mb-1">Réunions</h1>
        <p class="text-muted small mb-0">
            Retrouvez les prochaines réunions et les informations pour y participer.
        </p>
    </div>
</div>

<main class="container pb-5">
    <?php if (empty($reunions)): ?>
        <div class="card card-djangui p-5 text-center mt-4">
            <i class="bi bi-calendar2-x fs-1 text-primary mb-2"></i>
            <h2 class="h5 fw-bold">Aucune réunion à afficher</h2>
            <p class="text-muted small mb-0">
                Les réunions seront affichées ici dès que la secrétaire les publiera.
            </p>
        </div>
    <?php else: ?>
        <?php
        $reunions_a_venir = array_filter($reunions, static fn($reunion) => (int)$reunion['est_a_venir'] === 1);
        $reunions_passees = array_filter($reunions, static fn($reunion) => (int)$reunion['est_a_venir'] !== 1);
        ?>

        <?php if (!empty($reunions_a_venir)): ?>
            <section class="mt-4" aria-labelledby="reunions-a-venir">
                <h2 id="reunions-a-venir" class="h5 fw-bold mb-3">
                    <i class="bi bi-calendar-event text-primary me-2"></i>Prochaines réunions
                </h2>
                <div class="row g-3">
                    <?php foreach ($reunions_a_venir as $reunion): ?>
                        <div class="col-md-6 col-xl-4">
                            <article class="card card-djangui p-4 h-100">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <h3 class="h6 fw-bold mb-0"><?= e($reunion['titre']) ?></h3>
                                    <span class="badge text-bg-success flex-shrink-0">À venir</span>
                                </div>
                                <p class="small text-primary fw-semibold mb-2">
                                    <i class="bi bi-clock me-1"></i><?= date('d/m/Y à H:i', strtotime($reunion['date_heure'])) ?>
                                </p>
                                <?php if (!empty($reunion['description'])): ?>
                                    <p class="text-muted small mb-3"><?= nl2br(e($reunion['description'])) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($reunion['lien_zoom'])): ?>
                                    <a href="<?= e($reunion['lien_zoom']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-glow-blue mt-auto">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Rejoindre la réunion
                                    </a>
                                <?php else: ?>
                                    <p class="text-muted small mt-auto mb-0">
                                        <i class="bi bi-info-circle me-1"></i>Le lien de participation n’a pas encore été communiqué.
                                    </p>
                                <?php endif; ?>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($reunions_passees)): ?>
            <section class="mt-5" aria-labelledby="reunions-passees">
                <h2 id="reunions-passees" class="h5 fw-bold mb-3">
                    <i class="bi bi-clock-history text-muted me-2"></i>Réunions passées
                </h2>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($reunions_passees as $reunion): ?>
                        <article class="card card-djangui p-3 p-md-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <h3 class="h6 fw-bold mb-1"><?= e($reunion['titre']) ?></h3>
                                    <p class="small text-muted mb-0">
                                        <i class="bi bi-clock me-1"></i><?= date('d/m/Y à H:i', strtotime($reunion['date_heure'])) ?>
                                    </p>
                                </div>
                                <span class="badge <?= $reunion['statut'] === 'terminee' ? 'text-bg-secondary' : 'text-bg-light' ?>">
                                    <?= $reunion['statut'] === 'terminee' ? 'Terminée' : 'Passée' ?>
                                </span>
                            </div>
                            <?php if (!empty($reunion['description'])): ?>
                                <p class="text-muted small mt-3 mb-0"><?= nl2br(e($reunion['description'])) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>