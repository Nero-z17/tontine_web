<?php
// admin/tontine-info.php — Informations, invitation et fermeture de la tontine
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($tontine_id < 1 || $user_id < 1) {
    message_flash('danger', 'La tontine associée à votre compte est introuvable.');
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$stmtTontine = $pdo->prepare("
    SELECT id, nom, code_tontine, code_invitation, description, ville, devise, statut,
           date_debut_essai, date_fin_essai, statut_abonnement, abonnement_valide_jusqua,
           created_at
    FROM tontines
    WHERE id = ? AND admin_id = ?
    LIMIT 1
");
$stmtTontine->execute([$tontine_id, $user_id]);
$tontine = $stmtTontine->fetch();

if (!$tontine) {
    message_flash('danger', 'Vous n’êtes pas l’administrateur actuel de cette tontine.');
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'modifier_informations') {
        $nom = trim($_POST['nom'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $longueur_nom = function_exists('mb_strlen')
            ? mb_strlen($nom, 'UTF-8')
            : strlen($nom);
        $longueur_ville = function_exists('mb_strlen')
            ? mb_strlen($ville, 'UTF-8')
            : strlen($ville);

        if ($nom === '' || $longueur_nom > 120) {
            message_flash('danger', 'Le nom de la tontine est obligatoire et ne doit pas dépasser 120 caractères.');
        } elseif ($ville === '' || $longueur_ville > 80) {
            message_flash('danger', 'La ville est obligatoire et ne doit pas dépasser 80 caractères.');
        } else {
            $stmtUpdate = $pdo->prepare("
                UPDATE tontines
                SET nom = ?, ville = ?, description = ?
                WHERE id = ? AND admin_id = ?
            ");
            $stmtUpdate->execute([
                $nom,
                $ville,
                $description !== '' ? $description : null,
                $tontine_id,
                $user_id,
            ]);
            message_flash('success', 'Les informations de la tontine ont été mises à jour.');
        }

        header('Location: ' . BASE_URL . '/admin/tontine-info.php');
        exit;
    }

    if ($action === 'regenerer_code') {
        if ($tontine['statut'] === 'fermee') {
            message_flash('warning', 'Le code d’invitation ne peut pas être renouvelé : cette tontine est fermée.');
        } else {
            $nouveau_code = 'INV-' . strtoupper(bin2hex(random_bytes(3))) . '-' . date('y');
            $stmtCode = $pdo->prepare("
                UPDATE tontines
                SET code_invitation = ?
                WHERE id = ? AND admin_id = ? AND statut <> 'fermee'
            ");
            $stmtCode->execute([$nouveau_code, $tontine_id, $user_id]);

            if ($stmtCode->rowCount() === 1) {
                message_flash('success', 'Nouveau code d’invitation généré : ' . $nouveau_code);
            } else {
                message_flash('warning', 'Le code n’a pas été modifié. Vérifiez le statut de la tontine et réessayez.');
            }
        }

        header('Location: ' . BASE_URL . '/admin/tontine-info.php');
        exit;
    }

    if ($action === 'fermer_tontine') {
        if ($tontine['statut'] === 'fermee') {
            message_flash('info', 'Cette tontine est déjà fermée.');
        } else {
            $confirmation = trim($_POST['confirmation_fermeture'] ?? '');
            if ($confirmation !== 'FERMER') {
                message_flash('danger', 'Saisissez FERMER pour confirmer l’arrêt définitif de la tontine.');
            } else {
                $stmtFermer = $pdo->prepare("
                    UPDATE tontines
                    SET statut = 'fermee'
                    WHERE id = ? AND admin_id = ? AND statut <> 'fermee'
                ");
                $stmtFermer->execute([$tontine_id, $user_id]);

                if ($stmtFermer->rowCount() === 1) {
                    message_flash('success', 'La tontine est fermée. Les nouvelles adhésions ne sont plus acceptées.');
                } else {
                    message_flash('warning', 'La tontine n’a pas été fermée. Vérifiez son statut et réessayez.');
                }
            }
        }

        header('Location: ' . BASE_URL . '/admin/tontine-info.php');
        exit;
    }

    message_flash('danger', 'Action non reconnue.');
    header('Location: ' . BASE_URL . '/admin/tontine-info.php');
    exit;
}

$lien_invitation = BASE_URL . '/join-tontine.php?code=' . urlencode($tontine['code_invitation']);
$statuts = [
    'active' => ['label' => 'Active', 'badge' => 'success'],
    'suspendue' => ['label' => 'Suspendue', 'badge' => 'warning text-dark'],
    'fermee' => ['label' => 'Fermée', 'badge' => 'secondary'],
];
$statut_tontine = $statuts[$tontine['statut']] ?? ['label' => ucfirst($tontine['statut']), 'badge' => 'secondary'];

$titre_page = 'Informations de la tontine';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-info-circle me-1"></i>GESTION DE LA TONTINE
        </span>
        <h1 class="fw-bold h2 mb-1"><?= e($tontine['nom']) ?></h1>
        <p class="text-muted small mb-0">Consultez et mettez à jour les informations de votre groupe.</p>
    </div>
</div>

<main class="container pb-5">
    <div class="row g-4 mt-1">
        <section class="col-lg-7" aria-labelledby="infos-tontine-title">
            <div class="card card-djangui p-4 p-md-5 h-100">
                <h2 id="infos-tontine-title" class="h5 fw-bold mb-4">Informations générales</h2>
                <form method="POST" action="<?= BASE_URL ?>/admin/tontine-info.php">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="modifier_informations">

                    <div class="mb-3">
                        <label for="nom" class="form-label fw-semibold">Nom de la tontine</label>
                        <input type="text" class="form-control" id="nom" name="nom" maxlength="120" value="<?= e($tontine['nom']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="ville" class="form-label fw-semibold">Ville</label>
                        <input type="text" class="form-control" id="ville" name="ville" maxlength="80" value="<?= e($tontine['ville']) ?>" required>
                    </div>

                    <div class="mb-4">
                        <label for="description" class="form-label fw-semibold">Description <span class="text-muted fw-normal">(facultative)</span></label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?= e($tontine['description'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-glow-blue">
                        <i class="bi bi-check2-circle me-1"></i>Enregistrer les informations
                    </button>
                </form>

                <hr class="my-4">
                <h3 class="h6 fw-bold mb-3">Références de la tontine</h3>
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted fw-normal">Code de la tontine</dt>
                    <dd class="col-sm-7 font-monospace"><?= e($tontine['code_tontine']) ?></dd>
                    <dt class="col-sm-5 text-muted fw-normal">Statut</dt>
                    <dd class="col-sm-7"><span class="badge bg-<?= e($statut_tontine['badge']) ?>"><?= e($statut_tontine['label']) ?></span></dd>
                    <dt class="col-sm-5 text-muted fw-normal">Créée le</dt>
                    <dd class="col-sm-7"><?= date('d/m/Y', strtotime($tontine['created_at'])) ?></dd>
                </dl>
            </div>
        </section>

        <div class="col-lg-5">
            <section class="card-accent-djangui p-4 mb-4" aria-labelledby="invitation-title">
                <h2 id="invitation-title" class="h5 fw-bold mb-1">
                    <i class="bi bi-person-plus text-primary me-2"></i>Code d’invitation
                </h2>
                <p class="text-muted small">Partagez ce code ou le lien pour recevoir des demandes d’adhésion.</p>
                <p class="mb-3"><code class="fs-4 text-primary" id="codeInvitationText"><?= e($tontine['code_invitation']) ?></code></p>
                <?php if ($tontine['statut'] === 'active'): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-glow-blue btn-sm" id="btnCopyCode" data-copy="<?= e($tontine['code_invitation']) ?>">
                            <i class="bi bi-clipboard me-1"></i>Copier le code
                        </button>
                        <button type="button" class="btn btn-glow-emerald btn-sm" id="btnCopyLink" data-copy="<?= e($lien_invitation) ?>">
                            <i class="bi bi-link-45deg me-1"></i>Copier le lien
                        </button>
                        <form method="POST" action="<?= BASE_URL ?>/admin/tontine-info.php" onsubmit="return confirm('L’ancien code ne permettra plus de demander une adhésion. Générer un nouveau code ?');">
                            <?= champ_csrf() ?>
                            <input type="hidden" name="action" value="regenerer_code">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-clockwise me-1"></i>Nouveau code
                            </button>
                        </form>
                    </div>
                <?php elseif ($tontine['statut'] === 'suspendue'): ?>
                    <p class="text-warning-emphasis small mb-0">Les nouvelles adhésions sont suspendues tant que la tontine n’est pas réactivée.</p>
                <?php else: ?>
                    <p class="text-muted small mb-0">La tontine est fermée ; les nouvelles adhésions ne sont plus acceptées.</p>
                <?php endif; ?>
            </section>

            <section class="card card-djangui p-4 border-left-danger" aria-labelledby="fermeture-title">
                <h2 id="fermeture-title" class="h5 fw-bold text-danger mb-2">
                    <i class="bi bi-exclamation-triangle me-2"></i>Arrêt définitif
                </h2>
                <?php if ($tontine['statut'] === 'fermee'): ?>
                    <p class="text-muted small mb-0">Cette tontine est fermée. Les nouvelles adhésions ne sont plus acceptées ; les membres existants conservent l’accès à leur espace.</p>
                <?php else: ?>
                    <p class="text-muted small">La fermeture empêchera les nouvelles personnes de rejoindre la tontine. Les membres actuels pourront toujours se connecter et utiliser leur espace.</p>
                    <form method="POST" action="<?= BASE_URL ?>/admin/tontine-info.php" onsubmit="return confirm('Confirmer la fermeture définitive de cette tontine ? Les nouvelles adhésions seront bloquées.');">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="fermer_tontine">
                        <div class="mb-3">
                            <label for="confirmation_fermeture" class="form-label small">Saisissez <strong>FERMER</strong> pour confirmer</label>
                            <input type="text" class="form-control" id="confirmation_fermeture" name="confirmation_fermeture" autocomplete="off" required>
                        </div>
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="bi bi-lock-fill me-1"></i>Fermer définitivement la tontine
                        </button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function configurerCopie(idBouton, messageSucces) {
        const bouton = document.getElementById(idBouton);
        if (!bouton) return;
        bouton.addEventListener('click', function () {
            const texte = this.getAttribute('data-copy');
            navigator.clipboard.writeText(texte).then(() => {
                const libelleOriginal = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + messageSucces;
                setTimeout(() => { this.innerHTML = libelleOriginal; }, 2000);
            }).catch(() => {
                window.prompt('Copiez cette valeur :', texte);
            });
        });
    }

    configurerCopie('btnCopyCode', 'Code copié');
    configurerCopie('btnCopyLink', 'Lien copié');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
