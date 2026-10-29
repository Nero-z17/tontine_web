<?php
// admin/transfer.php — Passation du rôle d'administrateur de la tontine
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

$stmtTontine = $pdo->prepare("
    SELECT t.id, t.nom, t.admin_id, u.nom_complet AS nom_admin
    FROM tontines t
    JOIN users u ON u.id = t.admin_id
    WHERE t.id = ?
    LIMIT 1
");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

if (!$tontine || (int)$tontine['admin_id'] !== $user_id) {
    message_flash('warning', 'Votre compte n’est plus l’administrateur actuel de cette tontine.');
    header('Location: ' . BASE_URL . '/member/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    if (($_POST['action'] ?? '') !== 'transferer_administration') {
        message_flash('danger', 'Action non reconnue.');
        header('Location: ' . BASE_URL . '/admin/transfer.php');
        exit;
    }

    $nouvel_admin_id = filter_var($_POST['nouvel_admin_id'] ?? null, FILTER_VALIDATE_INT);
    $cycle_id_saisi = trim((string)($_POST['cycle_id'] ?? ''));
    $cycle_id = $cycle_id_saisi === '' ? null : filter_var($cycle_id_saisi, FILTER_VALIDATE_INT);
    $ancien_reste_membre_saisi = $_POST['ancien_reste_membre'] ?? '';
    $motif = trim((string)($_POST['motif'] ?? ''));
    $motif_trop_long = function_exists('mb_strlen')
        ? mb_strlen($motif, 'UTF-8') > 255
        : strlen($motif) > 255;

    if (!$nouvel_admin_id || $nouvel_admin_id === $user_id) {
        message_flash('danger', 'Veuillez sélectionner un autre membre actif de votre tontine.');
    } elseif ($cycle_id_saisi !== '' && (!$cycle_id || $cycle_id < 1)) {
        message_flash('danger', 'Le cycle sélectionné est invalide.');
    } elseif (!in_array($ancien_reste_membre_saisi, ['0', '1'], true)) {
        message_flash('danger', 'Veuillez préciser si l’administrateur sortant reste membre.');
    } elseif ($motif_trop_long) {
        message_flash('danger', 'Le motif ne doit pas dépasser 255 caractères.');
    } else {
        try {
            $pdo->beginTransaction();

            $stmtVerrouTontine = $pdo->prepare("
                SELECT admin_id
                FROM tontines
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmtVerrouTontine->execute([$tontine_id]);
            $admin_actuel_id = $stmtVerrouTontine->fetchColumn();

            if (!$admin_actuel_id || (int)$admin_actuel_id !== $user_id) {
                throw new RuntimeException('La responsabilité de cette tontine a déjà changé.');
            }

            $stmtNouveauAdmin = $pdo->prepare("
                SELECT id, role, statut_membre
                FROM users
                WHERE id = ? AND tontine_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmtNouveauAdmin->execute([$nouvel_admin_id, $tontine_id]);
            $nouveau_admin = $stmtNouveauAdmin->fetch();

            if (
                !$nouveau_admin
                || $nouveau_admin['role'] !== 'member'
                || $nouveau_admin['statut_membre'] !== 'actif'
            ) {
                throw new RuntimeException('Le membre choisi n’est plus éligible à cette passation.');
            }

            if ($cycle_id !== null) {
                $stmtCycle = $pdo->prepare("
                    SELECT id
                    FROM cycles
                    WHERE id = ? AND tontine_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmtCycle->execute([$cycle_id, $tontine_id]);
                if (!$stmtCycle->fetchColumn()) {
                    throw new RuntimeException('Le cycle sélectionné n’appartient pas à cette tontine.');
                }
            }

            $stmtAncienAdmin = $pdo->prepare("
                UPDATE users
                SET role = 'member', statut_membre = ?
                WHERE id = ? AND tontine_id = ? AND role = 'admin'
            ");
            $stmtAncienAdmin->execute([
                $ancien_reste_membre_saisi === '1' ? 'actif' : 'sorti',
                $user_id,
                $tontine_id,
            ]);
            if ($stmtAncienAdmin->rowCount() !== 1) {
                throw new RuntimeException('Le compte de l’administrateur sortant a changé.');
            }

            $stmtPromouvoir = $pdo->prepare("
                UPDATE users
                SET role = 'admin', statut_membre = 'actif'
                WHERE id = ? AND tontine_id = ? AND role = 'member' AND statut_membre = 'actif'
            ");
            $stmtPromouvoir->execute([$nouvel_admin_id, $tontine_id]);
            if ($stmtPromouvoir->rowCount() !== 1) {
                throw new RuntimeException('Le membre choisi a changé avant la passation.');
            }

            $stmtChangerAdmin = $pdo->prepare("
                UPDATE tontines
                SET admin_id = ?
                WHERE id = ? AND admin_id = ?
            ");
            $stmtChangerAdmin->execute([$nouvel_admin_id, $tontine_id, $user_id]);
            if ($stmtChangerAdmin->rowCount() !== 1) {
                throw new RuntimeException('La responsabilité de la tontine a déjà changé.');
            }

            $stmtHistorique = $pdo->prepare("
                INSERT INTO admin_transfers (
                    tontine_id, cycle_id, ancien_admin_id, nouvel_admin_id,
                    ancien_reste_membre, motif
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtHistorique->execute([
                $tontine_id,
                $cycle_id,
                $user_id,
                $nouvel_admin_id,
                (int)$ancien_reste_membre_saisi,
                $motif !== '' ? $motif : null,
            ]);

            $pdo->commit();
            message_flash('success', 'La passation d’administration a été enregistrée.');
            $_SESSION['role'] = 'member';

            if ($ancien_reste_membre_saisi === '0') {
                session_unset();
                session_destroy();
                header('Location: ' . BASE_URL . '/login.php');
            } else {
                header('Location: ' . BASE_URL . '/member/dashboard.php');
            }
            exit;
        } catch (PDOException | RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            message_flash(
                'danger',
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Impossible d’enregistrer la passation. Veuillez réessayer.'
            );
            header('Location: ' . BASE_URL . '/admin/transfer.php');
            exit;
        }
    }

    header('Location: ' . BASE_URL . '/admin/transfer.php');
    exit;
}

$stmtMembres = $pdo->prepare("
    SELECT id, nom_complet, code_public
    FROM users
    WHERE tontine_id = ? AND role = 'member' AND statut_membre = 'actif'
    ORDER BY nom_complet
");
$stmtMembres->execute([$tontine_id]);
$membres = $stmtMembres->fetchAll();

$stmtCycles = $pdo->prepare("
    SELECT id, numero_cycle, titre, statut
    FROM cycles
    WHERE tontine_id = ?
    ORDER BY numero_cycle DESC
");
$stmtCycles->execute([$tontine_id]);
$cycles = $stmtCycles->fetchAll();

$stmtHistorique = $pdo->prepare("
    SELECT tr.date_transfert, tr.ancien_reste_membre, tr.motif,
           ua.nom_complet AS ancien_admin,
           un.nom_complet AS nouvel_admin,
           c.numero_cycle, c.titre AS titre_cycle
    FROM admin_transfers tr
    JOIN users ua ON ua.id = tr.ancien_admin_id
    JOIN users un ON un.id = tr.nouvel_admin_id
    LEFT JOIN cycles c ON c.id = tr.cycle_id
    WHERE tr.tontine_id = ?
    ORDER BY tr.date_transfert DESC, tr.id DESC
");
$stmtHistorique->execute([$tontine_id]);
$transferts = $stmtHistorique->fetchAll();

$titre_page = 'Passation d’administration';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-arrow-left-right me-1"></i>GESTION DE LA TONTINE
        </span>
        <h1 class="fw-bold h2 mb-1">Passation d’administration</h1>
        <p class="text-muted small mb-0">
            Transférez la gestion de <?= e($tontine['nom']) ?> à un membre actif et consultez les passations précédentes.
        </p>
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
        <section class="col-lg-5" aria-labelledby="transfert-title">
            <div class="card card-djangui p-4">
                <h2 id="transfert-title" class="h5 fw-bold mb-3">
                    <i class="bi bi-person-gear text-primary me-2"></i>Transférer la responsabilité
                </h2>
                <p class="small text-muted">
                    Administrateur actuel : <strong><?= e($tontine['nom_admin']) ?></strong>.
                    La passation est immédiate et sera conservée dans l’historique.
                </p>

                <?php if (empty($membres)): ?>
                    <div class="alert alert-warning mb-0" role="status">
                        Aucun autre membre actif ne peut recevoir l’administration pour le moment.
                    </div>
                <?php else: ?>
                    <form method="POST" action="transfer.php">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="transferer_administration">

                        <div class="mb-3">
                            <label for="nouvel_admin_id" class="form-label fw-semibold">Nouvel administrateur</label>
                            <select class="form-select" id="nouvel_admin_id" name="nouvel_admin_id" required>
                                <option value="">Sélectionner un membre actif</option>
                                <?php foreach ($membres as $membre): ?>
                                    <option value="<?= (int)$membre['id'] ?>">
                                        <?= e($membre['nom_complet']) ?> (<?= e($membre['code_public']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="cycle_id" class="form-label fw-semibold">Cycle concerné <span class="text-muted fw-normal">(facultatif)</span></label>
                            <select class="form-select" id="cycle_id" name="cycle_id">
                                <option value="">Aucun cycle précisé</option>
                                <?php foreach ($cycles as $cycle): ?>
                                    <option value="<?= (int)$cycle['id'] ?>">
                                        Cycle #<?= (int)$cycle['numero_cycle'] ?> — <?= e($cycle['titre']) ?> (<?= e($cycle['statut']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <fieldset class="mb-3">
                            <legend class="form-label fw-semibold">Après la passation, l’administrateur sortant reste membre ?</legend>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="ancien_reste_membre" id="ancien_reste_oui" value="1" checked required>
                                <label class="form-check-label" for="ancien_reste_oui">Oui, il reste membre actif</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="ancien_reste_membre" id="ancien_reste_non" value="0" required>
                                <label class="form-check-label" for="ancien_reste_non">Non, il quitte la tontine</label>
                            </div>
                        </fieldset>

                        <div class="mb-3">
                            <label for="motif" class="form-label fw-semibold">Motif <span class="text-muted fw-normal">(facultatif)</span></label>
                            <textarea class="form-control" id="motif" name="motif" rows="3" maxlength="255"></textarea>
                        </div>

                        <p class="form-text">
                            Cette action modifie les rôles d’administration et enregistre la passation. Elle ne modifie pas les paiements ni les données financières des cycles.
                        </p>
                        <button type="submit" class="btn btn-glow-blue w-100">
                            <i class="bi bi-arrow-left-right me-1"></i>Confirmer la passation
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="col-lg-7" aria-labelledby="historique-title">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="historique-title" class="h5 fw-bold mb-0">Historique des passations</h2>
                <span class="badge rounded-pill text-bg-light"><?= count($transferts) ?> passation<?= count($transferts) > 1 ? 's' : '' ?></span>
            </div>

            <?php if (empty($transferts)): ?>
                <div class="card card-djangui p-4 text-center text-muted">
                    <i class="bi bi-clock-history fs-2 mb-2"></i>
                    Aucune passation n’a encore été enregistrée.
                </div>
            <?php else: ?>
                <div class="card card-djangui p-3 p-md-4">
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Administrateurs</th>
                                    <th>Cycle</th>
                                    <th>Date</th>
                                    <th>Après passation</th>
                                    <th>Motif</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transferts as $transfert): ?>
                                    <tr>
                                        <td>
                                            <div class="small text-muted">De</div>
                                            <div class="fw-semibold"><?= e($transfert['ancien_admin']) ?></div>
                                            <div class="small text-muted mt-1">À</div>
                                            <div class="fw-semibold"><?= e($transfert['nouvel_admin']) ?></div>
                                        </td>
                                        <td class="small">
                                            <?php if ($transfert['numero_cycle'] !== null): ?>
                                                Cycle #<?= (int)$transfert['numero_cycle'] ?><br>
                                                <?= e($transfert['titre_cycle']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">Non précisé</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-nowrap"><?= e(date('d/m/Y H:i', strtotime($transfert['date_transfert']))) ?></td>
                                        <td class="small">
                                            <?= (int)$transfert['ancien_reste_membre'] === 1 ? 'Reste membre' : 'Quitte la tontine' ?>
                                        </td>
                                        <td class="small"><?= e($transfert['motif'] ?: '—') ?></td>
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
