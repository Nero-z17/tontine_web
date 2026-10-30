<?php
// member/tontine.php — Informations de la tontine et liste des membres
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
if ($tontine_id < 1) {
    http_response_code(404);
    exit('Tontine associée à votre compte introuvable.');
}

$stmtTontine = $pdo->prepare("
    SELECT nom, code_tontine, description, ville, devise, statut, created_at
    FROM tontines
    WHERE id = ?
    LIMIT 1
");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

if (!$tontine) {
    http_response_code(404);
    exit('Tontine introuvable.');
}

$stmtMembres = $pdo->prepare("
    SELECT nom_complet, telephone, role, statut_membre, code_public, created_at
    FROM users
    WHERE tontine_id = ? AND statut_validation = 'accepte'
    ORDER BY (role = 'admin') DESC,
             CASE statut_membre WHEN 'actif' THEN 0 WHEN 'inactif' THEN 1 ELSE 2 END,
             nom_complet ASC
");
$stmtMembres->execute([$tontine_id]);
$membres = $stmtMembres->fetchAll();

$nb_actifs = count(array_filter($membres, static function ($membre) {
    return $membre['statut_membre'] === 'actif';
}));

$statuts_tontine = [
    'active' => ['label' => 'Active', 'badge' => 'success'],
    'suspendue' => ['label' => 'Suspendue', 'badge' => 'warning text-dark'],
    'fermee' => ['label' => 'Fermée', 'badge' => 'secondary'],
];
$statut_tontine = $statuts_tontine[$tontine['statut']] ?? ['label' => ucfirst($tontine['statut']), 'badge' => 'secondary'];

$titre_page = 'Ma tontine';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-people me-1"></i>MA TONTINE
        </span>
        <h1 class="fw-bold h2 mb-1"><?= e($tontine['nom']) ?></h1>
        <p class="text-muted small mb-0">Les informations du groupe et les membres ayant rejoint la tontine.</p>
    </div>
</div>

<main class="container pb-5">
    <section class="card-accent-djangui p-4 p-md-5 mt-4" aria-labelledby="tontine-title">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <h2 id="tontine-title" class="h4 fw-bold mb-1"><?= e($tontine['nom']) ?></h2>
                <p class="text-muted mb-0"><i class="bi bi-geo-alt me-1"></i><?= e($tontine['ville']) ?></p>
            </div>
            <span class="badge bg-<?= e($statut_tontine['badge']) ?> rounded-pill px-3 py-2"><?= e($statut_tontine['label']) ?></span>
        </div>

        <?php if (!empty($tontine['description'])): ?>
            <p class="mb-4"><?= nl2br(e($tontine['description'])) ?></p>
        <?php else: ?>
            <p class="text-muted small mb-4">Aucune description n’a été renseignée pour cette tontine.</p>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-sm-6 col-lg-4">
                <div class="card card-djangui p-3 h-100">
                    <div class="text-muted small">Code de la tontine</div>
                    <div class="fw-bold font-monospace mt-1"><?= e($tontine['code_tontine']) ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card card-djangui p-3 h-100">
                    <div class="text-muted small">Membres actifs</div>
                    <div class="fw-bold mt-1"><?= $nb_actifs ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card card-djangui p-3 h-100">
                    <div class="text-muted small">Créée le</div>
                    <div class="fw-bold mt-1"><?= date('d/m/Y', strtotime($tontine['created_at'])) ?></div>
                </div>
            </div>
        </div>
    </section>

    <section class="card card-djangui p-4 mt-4" aria-labelledby="membres-title">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 id="membres-title" class="h5 fw-bold mb-1">Membres de la tontine</h2>
                <p class="text-muted small mb-0"><?= count($membres) ?> membre<?= count($membres) > 1 ? 's' : '' ?> accepté<?= count($membres) > 1 ? 's' : '' ?></p>
            </div>
        </div>

        <?php if (empty($membres)): ?>
            <div class="p-4 rounded-3 box-adaptive text-center text-muted">
                <i class="bi bi-people fs-2 d-block mb-2"></i>
                Aucun membre accepté pour le moment.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-djangui align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Membre</th>
                            <th>Téléphone</th>
                            <th>Rôle</th>
                            <th>Statut</th>
                            <th>Inscription</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($membres as $membre): ?>
                            <?php
                                $badge_statut = match ($membre['statut_membre']) {
                                    'actif' => 'success',
                                    'inactif' => 'warning text-dark',
                                    default => 'secondary',
                                };
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($membre['nom_complet']) ?></div>
                                    <div class="small text-muted"><code><?= e($membre['code_public']) ?></code></div>
                                </td>
                                <td><?= e($membre['telephone']) ?></td>
                                <td>
                                    <?php if ($membre['role'] === 'admin'): ?>
                                        <span class="badge bg-primary-subtle text-primary rounded-pill">Secrétaire</span>
                                    <?php else: ?>
                                        <span class="text-muted">Membre</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?= e($badge_statut) ?> rounded-pill"><?= e(ucfirst($membre['statut_membre'])) ?></span></td>
                                <td><?= date('d/m/Y', strtotime($membre['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
