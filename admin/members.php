<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
if ($tontine_id < 1) {
    message_flash('danger', 'La tontine associée à votre compte est introuvable.');
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    $action = $_POST['action'] ?? '';
    $membre_id = (int)($_POST['membre_id'] ?? 0);

    if (!in_array($action, ['accepter', 'refuser'], true) || $membre_id < 1) {
        message_flash('danger', 'La demande sélectionnée est invalide.');
        header('Location: ' . BASE_URL . '/admin/members.php');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmtMembre = $pdo->prepare("
            SELECT id, role, statut_validation
            FROM users
            WHERE id = ? AND tontine_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmtMembre->execute([$membre_id, $tontine_id]);
        $membre = $stmtMembre->fetch();

        if (!$membre || $membre['role'] !== 'member' || $membre['statut_validation'] !== 'en_attente') {
            $pdo->rollBack();
            message_flash('warning', 'Cette demande est introuvable ou a déjà été traitée.');
        } elseif ($action === 'accepter') {
            $stmtUpdate = $pdo->prepare("
                UPDATE users
                SET statut_validation = 'accepte', statut_membre = 'actif'
                WHERE id = ? AND tontine_id = ? AND role = 'member' AND statut_validation = 'en_attente'
            ");
            $stmtUpdate->execute([$membre_id, $tontine_id]);

            if ($stmtUpdate->rowCount() !== 1) {
                throw new RuntimeException('La demande a été modifiée avant son approbation.');
            }

            $pdo->commit();
            message_flash('success', 'Le membre a été accepté. Il peut maintenant se connecter à son espace.');
        } else {
            $stmtDelete = $pdo->prepare("
                DELETE FROM users
                WHERE id = ? AND tontine_id = ? AND role = 'member' AND statut_validation = 'en_attente'
            ");
            $stmtDelete->execute([$membre_id, $tontine_id]);

            if ($stmtDelete->rowCount() !== 1) {
                throw new RuntimeException('La demande a été modifiée avant son refus.');
            }

            $pdo->commit();
            message_flash('success', 'La demande a été refusée et les informations du compte ont été supprimées.');
        }
    } catch (PDOException | RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        message_flash('danger', 'Impossible de traiter cette demande. Veuillez réessayer.');
    }

    header('Location: ' . BASE_URL . '/admin/members.php');
    exit;
}

$stmtTontine = $pdo->prepare('SELECT nom FROM tontines WHERE id = ? LIMIT 1');
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

if (!$tontine) {
    message_flash('danger', 'La tontine associée à votre compte est introuvable.');
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$stmtMembres = $pdo->prepare("
    SELECT id, code_public, nom_complet, username, telephone, email, statut_membre,
           statut_validation, created_at
    FROM users
    WHERE tontine_id = ? AND role = 'member'
    ORDER BY CASE WHEN statut_validation = 'en_attente' THEN 0 ELSE 1 END,
             created_at DESC, id DESC
");
$stmtMembres->execute([$tontine_id]);
$membres = $stmtMembres->fetchAll();

$demandes = array_filter($membres, static function ($membre) {
    return $membre['statut_validation'] === 'en_attente';
});
$membres_acceptes = array_filter($membres, static function ($membre) {
    return $membre['statut_validation'] === 'accepte';
});

$titre_page = 'Gestion des membres';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Membres de <?= e($tontine['nom']) ?></h1>
            <p class="text-muted mb-0">Examinez les demandes d’adhésion avant de donner accès à la tontine.</p>
        </div>
        <span class="badge text-bg-warning fs-6">
            <?= count($demandes) ?> demande<?= count($demandes) === 1 ? '' : 's' ?> en attente
        </span>
    </div>

    <?php if (!empty($_SESSION['flash_texte'])): ?>
        <div class="alert alert-<?= e($_SESSION['flash_type'] ?? 'info') ?> small" role="status">
            <?= e($_SESSION['flash_texte']) ?>
        </div>
        <?php unset($_SESSION['flash_texte'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <section class="card card-djangui p-3 p-md-4 mb-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-person-check me-2 text-primary"></i>Demandes d’adhésion</h2>
        <?php if (empty($demandes)): ?>
            <p class="text-muted mb-0">Aucune demande en attente pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Demandeur</th>
                            <th>Contact</th>
                            <th>Date de demande</th>
                            <th class="text-end">Décision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($demandes as $membre): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($membre['nom_complet']) ?></div>
                                    <div class="small text-muted"><?= e($membre['code_public']) ?> · <?= e($membre['username']) ?></div>
                                </td>
                                <td>
                                    <div><?= e($membre['telephone']) ?></div>
                                    <?php if (!empty($membre['email'])): ?>
                                        <div class="small text-muted"><?= e($membre['email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(date('d/m/Y H:i', strtotime($membre['created_at']))) ?></td>
                                <td>
                                    <div class="d-flex justify-content-end gap-2">
                                        <form method="POST" action="<?= BASE_URL ?>/admin/members.php">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">
                                            <input type="hidden" name="action" value="accepter">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="bi bi-check-lg me-1"></i>Accepter
                                            </button>
                                        </form>
                                        <form method="POST" action="<?= BASE_URL ?>/admin/members.php"
                                              onsubmit="return confirm('Refuser cette demande et supprimer définitivement les informations du compte ?');">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">
                                            <input type="hidden" name="action" value="refuser">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="bi bi-x-lg me-1"></i>Refuser
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card card-djangui p-3 p-md-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-people me-2 text-primary"></i>Membres acceptés</h2>
        <?php if (empty($membres_acceptes)): ?>
            <p class="text-muted mb-0">Aucun membre accepté pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Membre</th>
                            <th>Téléphone</th>
                            <th>Email</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($membres_acceptes as $membre): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($membre['nom_complet']) ?></div>
                                    <div class="small text-muted"><?= e($membre['code_public']) ?> · <?= e($membre['username']) ?></div>
                                </td>
                                <td><?= e($membre['telephone']) ?></td>
                                <td><?= e($membre['email'] ?? '—') ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $membre['statut_membre'] === 'actif' ? 'success' : 'secondary' ?>">
                                        <?= e(ucfirst($membre['statut_membre'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
