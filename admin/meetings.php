<?php
// admin/meetings.php — Planification et gestion des réunions de la tontine
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'creer_reunion') {
        $titre = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $date_heure_saisie = trim($_POST['date_heure'] ?? '');
        $lien_zoom = trim($_POST['lien_zoom'] ?? '');
        $statut = $_POST['statut'] ?? 'brouillon';

        $date_heure = DateTime::createFromFormat('!Y-m-d\TH:i', $date_heure_saisie);
        $date_valide = $date_heure && $date_heure->format('Y-m-d\TH:i') === $date_heure_saisie;
        $titre_trop_long = function_exists('mb_strlen')
            ? mb_strlen($titre, 'UTF-8') > 150
            : strlen($titre) > 150;
        $lien_valide = $lien_zoom === ''
            || (filter_var($lien_zoom, FILTER_VALIDATE_URL)
                && in_array(strtolower((string)parse_url($lien_zoom, PHP_URL_SCHEME)), ['http', 'https'], true));

        if ($titre === '' || $titre_trop_long) {
            message_flash('danger', 'Le titre est obligatoire et ne doit pas dépasser 150 caractères.');
        } elseif (!$date_valide) {
            message_flash('danger', 'Veuillez saisir une date et une heure valides.');
        } elseif (!$lien_valide || strlen($lien_zoom) > 500) {
            message_flash('danger', 'Le lien de visioconférence doit être une URL HTTP ou HTTPS valide de 500 caractères maximum.');
        } elseif (!in_array($statut, ['brouillon', 'publiee'], true)) {
            message_flash('danger', 'Le statut choisi est invalide.');
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO meetings (tontine_id, titre, date_heure, lien_zoom, description, statut, cree_par_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $tontine_id,
                $titre,
                $date_heure->format('Y-m-d H:i:s'),
                $lien_zoom !== '' ? $lien_zoom : null,
                $description !== '' ? $description : null,
                $statut,
                $user_id,
            ]);
            message_flash('success', $statut === 'publiee'
                ? 'La réunion a été créée et publiée.'
                : 'La réunion a été enregistrée en brouillon.');
        }

        header('Location: ' . BASE_URL . '/admin/meetings.php');
        exit;
    }

    if ($action === 'changer_statut') {
        $meeting_id = (int)($_POST['meeting_id'] ?? 0);
        $nouveau_statut = $_POST['nouveau_statut'] ?? '';

        $transitions = [
            'brouillon' => ['publiee', 'annulee'],
            'publiee' => ['terminee', 'annulee'],
        ];

        $stmtMeeting = $pdo->prepare("
            SELECT statut FROM meetings
            WHERE id = ? AND tontine_id = ?
            LIMIT 1
        ");
        $stmtMeeting->execute([$meeting_id, $tontine_id]);
        $reunion = $stmtMeeting->fetch();

        if (!$reunion || !in_array($nouveau_statut, $transitions[$reunion['statut']] ?? [], true)) {
            message_flash('warning', 'Cette réunion est introuvable ou cette action n’est plus autorisée.');
        } else {
            $stmtUpdate = $pdo->prepare("
                UPDATE meetings
                SET statut = ?
                WHERE id = ? AND tontine_id = ? AND statut = ?
            ");
            $stmtUpdate->execute([$nouveau_statut, $meeting_id, $tontine_id, $reunion['statut']]);

            if ($stmtUpdate->rowCount() === 1) {
                $messages = [
                    'publiee' => 'La réunion est maintenant publiée et visible par les membres.',
                    'terminee' => 'La réunion a été marquée comme terminée.',
                    'annulee' => 'La réunion a été annulée.',
                ];
                message_flash('success', $messages[$nouveau_statut]);
            } else {
                message_flash('warning', 'La réunion a été modifiée entre-temps. Veuillez vérifier son statut.');
            }
        }

        header('Location: ' . BASE_URL . '/admin/meetings.php');
        exit;
    }

    message_flash('danger', 'Action non reconnue.');
    header('Location: ' . BASE_URL . '/admin/meetings.php');
    exit;
}

$stmtMeetings = $pdo->prepare("
    SELECT id, titre, date_heure, lien_zoom, description, statut
    FROM meetings
    WHERE tontine_id = ?
    ORDER BY (date_heure < NOW()) ASC,
             CASE WHEN date_heure >= NOW() THEN date_heure END ASC,
             date_heure DESC
");
$stmtMeetings->execute([$tontine_id]);
$reunions = $stmtMeetings->fetchAll();

$libelles_statut = [
    'brouillon' => 'Brouillon',
    'publiee' => 'Publiée',
    'terminee' => 'Terminée',
    'annulee' => 'Annulée',
];
$classes_statut = [
    'brouillon' => 'text-bg-secondary',
    'publiee' => 'text-bg-success',
    'terminee' => 'text-bg-primary',
    'annulee' => 'text-bg-danger',
];

$titre_page = 'Gestion des réunions';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-camera-video me-1"></i>AGENDA DE LA TONTINE
        </span>
        <h1 class="fw-bold h2 mb-1">Gestion des réunions</h1>
        <p class="text-muted small mb-0">Planifiez les réunions en ligne et gérez leur publication auprès des membres.</p>
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

    <div class="row g-4 mt-1">
        <section class="col-lg-5" aria-labelledby="form-reunion-title">
            <div class="card card-djangui p-4">
                <h2 id="form-reunion-title" class="h5 fw-bold mb-3">
                    <i class="bi bi-calendar-plus text-primary me-2"></i>Programmer une réunion
                </h2>
                <form method="POST" action="meetings.php">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="creer_reunion">

                    <div class="mb-3">
                        <label for="titre" class="form-label fw-semibold">Titre</label>
                        <input type="text" class="form-control" id="titre" name="titre" maxlength="150" required>
                    </div>

                    <div class="mb-3">
                        <label for="date_heure" class="form-label fw-semibold">Date et heure</label>
                        <input type="datetime-local" class="form-control" id="date_heure" name="date_heure" required>
                    </div>

                    <div class="mb-3">
                        <label for="lien_zoom" class="form-label fw-semibold">Lien de visioconférence <span class="text-muted fw-normal">(facultatif)</span></label>
                        <input type="url" class="form-control" id="lien_zoom" name="lien_zoom" maxlength="500" placeholder="https://...">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label fw-semibold">Description <span class="text-muted fw-normal">(facultatif)</span></label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="statut" class="form-label fw-semibold">Publication</label>
                        <select class="form-select" id="statut" name="statut">
                            <option value="brouillon">Enregistrer comme brouillon</option>
                            <option value="publiee">Publier immédiatement</option>
                        </select>
                        <div class="form-text">Seules les réunions publiées sont visibles dans l’espace membre.</div>
                    </div>

                    <button type="submit" class="btn btn-glow-blue w-100">
                        <i class="bi bi-check2-circle me-1"></i>Enregistrer la réunion
                    </button>
                </form>
            </div>
        </section>

        <section class="col-lg-7" aria-labelledby="reunions-title">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="reunions-title" class="h5 fw-bold mb-0">Toutes les réunions</h2>
                <span class="badge rounded-pill text-bg-light"><?= count($reunions) ?> réunion<?= count($reunions) > 1 ? 's' : '' ?></span>
            </div>

            <?php if (empty($reunions)): ?>
                <div class="card card-djangui p-4 text-center text-muted">
                    <i class="bi bi-calendar2-x fs-2 mb-2"></i>
                    Aucune réunion n’est programmée. Utilisez le formulaire pour en créer une.
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($reunions as $reunion): ?>
                        <?php
                        $statut = $reunion['statut'];
                        $transitions_possibles = [
                            'brouillon' => ['publiee' => 'Publier'],
                            'publiee' => ['terminee' => 'Marquer terminée'],
                        ];
                        ?>
                        <article class="card card-djangui p-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                <h3 class="h6 fw-bold mb-0"><?= e($reunion['titre']) ?></h3>
                                <span class="badge <?= e($classes_statut[$statut] ?? 'text-bg-secondary') ?>">
                                    <?= e($libelles_statut[$statut] ?? $statut) ?>
                                </span>
                            </div>

                            <p class="small text-muted mb-2">
                                <i class="bi bi-clock me-1"></i><?= date('d/m/Y à H:i', strtotime($reunion['date_heure'])) ?>
                            </p>

                            <?php if (!empty($reunion['description'])): ?>
                                <p class="mb-3"><?= nl2br(e($reunion['description'])) ?></p>
                            <?php endif; ?>

                            <?php if (!empty($reunion['lien_zoom'])): ?>
                                <p class="small mb-3">
                                    <i class="bi bi-link-45deg me-1"></i>
                                    <a href="<?= e($reunion['lien_zoom']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir le lien de la réunion</a>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($transitions_possibles[$statut]) || in_array($statut, ['brouillon', 'publiee'], true)): ?>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <?php foreach ($transitions_possibles[$statut] ?? [] as $destination => $libelle): ?>
                                        <form method="POST" action="meetings.php">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="action" value="changer_statut">
                                            <input type="hidden" name="meeting_id" value="<?= (int)$reunion['id'] ?>">
                                            <input type="hidden" name="nouveau_statut" value="<?= e($destination) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-arrow-up-right-circle me-1"></i><?= e($libelle) ?>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                    <?php if (in_array($statut, ['brouillon', 'publiee'], true)): ?>
                                        <form method="POST" action="meetings.php" onsubmit="return confirm('Confirmer l’annulation de cette réunion ?');">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="action" value="changer_statut">
                                            <input type="hidden" name="meeting_id" value="<?= (int)$reunion['id'] ?>">
                                            <input type="hidden" name="nouveau_statut" value="annulee">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-x-circle me-1"></i>Annuler
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>