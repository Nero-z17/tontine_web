<?php
// admin/members.php — Approbation des adhésions, gestion des membres, CNI et code d'invitation (Membre 1)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$admin_id   = (int)($_SESSION['user_id'] ?? 0);

if ($tontine_id < 1) {
    message_flash('danger', 'La tontine associée à votre compte est introuvable.');
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

// =========================================================================
// 1. TRAITEMENT DES ACTIONS POST (Accepter, Refuser, Changer statut, Régénérer code)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    $action    = $_POST['action'] ?? '';
    $membre_id = (int)($_POST['membre_id'] ?? 0);

    // ACTION A : Régénérer le code d'invitation de la tontine
    if ($action === 'regenerer_code') {
        $nouveau_code = 'INV-' . strtoupper(bin2hex(random_bytes(3))) . '-' . date('y');
        $stmtCode = $pdo->prepare("UPDATE tontines SET code_invitation = ? WHERE id = ?");
        $stmtCode->execute([$nouveau_code, $tontine_id]);

        message_flash('success', "Nouveau code d'invitation généré avec succès : {$nouveau_code}");
        header('Location: ' . BASE_URL . '/admin/members.php');
        exit;
    }

    // ACTION B : Modifier le statut d'un membre déjà accepté (actif, inactif, sorti)
    if ($action === 'changer_statut') {
        $nouveau_statut = $_POST['statut_membre'] ?? '';

        if ($membre_id < 1 || !in_array($nouveau_statut, ['actif', 'inactif', 'sorti'], true)) {
            message_flash('danger', 'Paramètres de statut invalides.');
        } elseif ($membre_id === $admin_id && $nouveau_statut !== 'actif') {
            message_flash('warning', 'En tant qu\'administrateur en exercice, votre statut doit rester « Actif ». Transférez d\'abord le rôle d\'admin si vous souhaitez quitter la tontine.');
        } else {
            $stmtUpdStatut = $pdo->prepare("
                UPDATE users
                SET statut_membre = ?
                WHERE id = ? AND tontine_id = ? AND statut_validation = 'accepte'
            ");
            $stmtUpdStatut->execute([$nouveau_statut, $membre_id, $tontine_id]);

            message_flash('success', 'Le statut du membre a été mis à jour en « ' . ucfirst($nouveau_statut) . ' ».');
        }
        header('Location: ' . BASE_URL . '/admin/members.php');
        exit;
    }

    // ACTION C : Accepter ou Refuser une demande d'adhésion en attente
    if (!in_array($action, ['accepter', 'refuser'], true) || $membre_id < 1) {
        message_flash('danger', 'La demande sélectionnée est invalide.');
        header('Location: ' . BASE_URL . '/admin/members.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtMembre = $pdo->prepare("
            SELECT id, nom_complet, role, statut_validation
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
            message_flash('success', 'Adhésion de « ' . $membre['nom_complet'] . ' » approuvée ! Ce membre peut maintenant se connecter à son espace.');
        } else {
            // Suppression de la demande refusée pour libérer les contraintes UNIQUE (téléphone, username, email)
            $stmtDelete = $pdo->prepare("
                DELETE FROM users
                WHERE id = ? AND tontine_id = ? AND role = 'member' AND statut_validation = 'en_attente'
            ");
            $stmtDelete->execute([$membre_id, $tontine_id]);

            if ($stmtDelete->rowCount() !== 1) {
                throw new RuntimeException('La demande a été modifiée avant son refus.');
            }

            $pdo->commit();
            message_flash('info', 'La demande de « ' . $membre['nom_complet'] . ' » a été refusée et supprimée.');
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

// =========================================================================
// 2. RÉCUPÉRATION DES INFOS DE LA TONTINE ET DE TOUS LES MEMBRES (AVEC CNI)
// =========================================================================
$stmtTontine = $pdo->prepare("SELECT id, nom, code_tontine, code_invitation, ville FROM tontines WHERE id = ? LIMIT 1");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

if (!$tontine) {
    message_flash('danger', 'La tontine associée à votre compte est introuvable.');
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

// On récupère toutes les colonnes nécessaires, y compris numero_cni (visible uniquement ici par l'admin)
$stmtMembres = $pdo->prepare("
    SELECT id, code_public, nom_complet, username, telephone, email, numero_cni,
           role, canal_paiement, telephone_paiement, statut_membre, statut_validation, created_at
    FROM users
    WHERE tontine_id = ?
    ORDER BY CASE WHEN statut_validation = 'en_attente' THEN 0 ELSE 1 END,
             (role = 'admin') DESC,
             created_at DESC, id DESC
");
$stmtMembres->execute([$tontine_id]);
$tous_les_utilisateurs = $stmtMembres->fetchAll();

$demandes = array_filter($tous_les_utilisateurs, static function ($u) {
    return ($u['statut_validation'] ?? 'accepte') === 'en_attente';
});

$membres_acceptes = array_filter($tous_les_utilisateurs, static function ($u) {
    return ($u['statut_validation'] ?? 'accepte') === 'accepte';
});

$nb_actifs = count(array_filter($membres_acceptes, static function ($u) {
    return $u['statut_membre'] === 'actif';
}));

$lien_invitation = BASE_URL . '/join-tontine.php?code=' . urlencode($tontine['code_invitation']);

$titre_page = 'Gestion des membres';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- BANDEAU D'EN-TÊTE ADAPTATIF (MODE CLAIR / MODE SOMBRE) -->
<div class="page-header-dark">
    <div class="container">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                    <i class="bi bi-people-fill me-1"></i>CONTRÔLE DES ADHÉSIONS & REGISTRE CNI
                </span>
                <h1 class="fw-bold h2 mb-1">Membres de <?= e($tontine['nom']) ?></h1>
                <p class="text-muted small mb-0">
                    Partagez votre code d'invitation, vérifiez le numéro de CNI des candidats et gérez le statut des participants.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fs-6">
                    <i class="bi bi-hourglass-split me-1"></i><?= count($demandes) ?> en attente
                </span>
                <span class="badge bg-success px-3 py-2 rounded-pill fs-6">
                    <i class="bi bi-check-circle-fill me-1"></i><?= $nb_actifs ?> actif<?= $nb_actifs > 1 ? 's' : '' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<main class="container pb-5">

    <!-- 1. CARTE DU CODE D'INVITATION (PARTAGE WHATSAPP & RÉGÉNÉRATION) -->
    <section class="card-accent-djangui p-4 mb-4">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-key-fill text-primary fs-5"></i>
                    <span class="fw-bold text-primary small text-uppercase">Invitation de nouveaux membres</span>
                </div>
                <h5 class="fw-bold mb-1">Code d'invitation officiel : <code class="fs-4 text-primary ms-1" id="codeInvitationText"><?= e($tontine['code_invitation']) ?></code></h5>
                <p class="text-muted small mb-0">
                    Envoyez ce code ou le lien direct à vos membres. Une fois leur formulaire rempli, leur demande apparaîtra ci-dessous pour validation.
                </p>
            </div>
            <div class="col-lg-5">
                <div class="d-flex flex-wrap justify-content-lg-end gap-2">
                    <button type="button" class="btn btn-glow-blue btn-sm px-3 py-2" id="btnCopyCode" data-copy="<?= e($tontine['code_invitation']) ?>">
                        <i class="bi bi-clipboard-check me-1"></i>Copier le code
                    </button>
                    <button type="button" class="btn btn-glow-emerald btn-sm px-3 py-2" id="btnCopyLink" data-copy="<?= e($lien_invitation) ?>">
                        <i class="bi bi-whatsapp me-1"></i>Copier le lien d'adhésion
                    </button>
                    <form method="POST" action="<?= BASE_URL ?>/admin/members.php" class="d-inline"
                          onsubmit="return confirm('Régénérer un nouveau code d\'invitation ? L\'ancien code ne fonctionnera plus pour les prochains inscrits.');">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="regenerer_code">
                        <button type="submit" class="btn btn-outline-secondary btn-sm px-3 py-2" title="Générer un nouveau code">
                            <i class="bi bi-arrow-clockwise me-1"></i>Nouveau code
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- 2. SECTION DES DEMANDES D'ADHÉSION EN ATTENTE DE VALIDATION -->
    <section class="card card-djangui p-4 mb-4 border-left-warning">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">
                    <i class="bi bi-person-exclamation me-2 text-warning"></i>Demandes d’adhésion en attente (<?= count($demandes) ?>)
                </h2>
                <p class="text-muted small mb-0">Vérifiez le numéro de CNI et le téléphone avant d'autoriser l'accès à la tontine.</p>
            </div>
        </div>

        <?php if (empty($demandes)): ?>
            <div class="p-3 rounded-3 box-adaptive text-center text-muted small">
                <i class="bi bi-check2-circle text-success fs-5 me-1 align-middle"></i>
                Aucune demande d'adhésion en attente pour le moment.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-djangui align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Demandeur</th>
                            <th>Téléphone & Email</th>
                            <th>Canal MoMo / OM</th>
                            <th>N° CNI (Confidentiel)</th>
                            <th>Date de demande</th>
                            <th class="text-end">Décision Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($demandes as $membre): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= e($membre['nom_complet']) ?></div>
                                    <div class="small text-muted">
                                        <code><?= e($membre['code_public']) ?></code> · @<?= e($membre['username']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e($membre['telephone']) ?></div>
                                    <div class="small text-muted"><?= e($membre['email'] ?: 'Pas d\'email') ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-uppercase">
                                        <?= e($membre['canal_paiement']) ?>
                                    </span>
                                    <div class="small text-muted mt-1"><?= e($membre['telephone_paiement']) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-adaptive border px-2 py-1 font-monospace">
                                        <i class="bi bi-person-vcard me-1 text-primary"></i><?= e($membre['numero_cni'] ?: 'Non renseigné') ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?= e(date('d/m/Y à H:i', strtotime($membre['created_at']))) ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2">
                                        <form method="POST" action="<?= BASE_URL ?>/admin/members.php">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">
                                            <input type="hidden" name="action" value="accepter">
                                            <button type="submit" class="btn btn-glow-emerald btn-sm px-3">
                                                <i class="bi bi-check-lg me-1"></i>Accepter
                                            </button>
                                        </form>
                                        <form method="POST" action="<?= BASE_URL ?>/admin/members.php"
                                              onsubmit="return confirm('Refuser cette demande et supprimer définitivement les informations de ce compte ?');">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">
                                            <input type="hidden" name="action" value="refuser">
                                            <button type="submit" class="btn btn-outline-danger btn-sm px-3">
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

    <!-- 3. SECTION DES MEMBRES OFFICIELS DE LA TONTINE (AVEC GESTION DU STATUT & CNI PROTÉGÉE) -->
    <section class="card card-djangui p-4 border-left-navy">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">
                    <i class="bi bi-shield-check me-2 text-primary"></i>Membres validés de la tontine (<?= count($membres_acceptes) ?>)
                </h2>
                <p class="text-muted small mb-0">
                    Conformément aux règles de confidentialité, les numéros de CNI ne sont visibles que sur cet écran Administrateur.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <input type="search" id="searchMembre" class="form-control form-control-sm" style="max-width: 220px;" placeholder="Rechercher un membre...">
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnToggleCNI">
                    <i class="bi bi-eye me-1"></i>Afficher les CNI
                </button>
            </div>
        </div>

        <?php if (empty($membres_acceptes)): ?>
            <p class="text-muted mb-0">Aucun membre validé pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-djangui align-middle mb-0" id="tableMembresAcceptes">
                    <thead>
                        <tr>
                            <th>Membre & Code</th>
                            <th>Contact</th>
                            <th>Compte MoMo / OM</th>
                            <th>N° CNI (Admin seul)</th>
                            <th>Statut actuel</th>
                            <th class="text-end">Modifier le statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($membres_acceptes as $membre): ?>
                            <?php
                                $badge_statut = match ($membre['statut_membre']) {
                                    'actif'   => 'success',
                                    'inactif' => 'warning text-dark',
                                    default   => 'secondary',
                                };
                            ?>
                            <tr class="js-membre-row">
                                <td>
                                    <div class="fw-bold d-flex align-items-center gap-2">
                                        <span><?= e($membre['nom_complet']) ?></span>
                                        <?php if ($membre['role'] === 'admin'): ?>
                                            <span class="badge bg-primary-subtle text-primary rounded-pill">Secrétaire</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">
                                        <code><?= e($membre['code_public']) ?></code> · @<?= e($membre['username']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e($membre['telephone']) ?></div>
                                    <div class="small text-muted"><?= e($membre['email'] ?: '—') ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-uppercase">
                                        <?= e($membre['canal_paiement']) ?>
                                    </span>
                                    <div class="small text-muted mt-1"><?= e($membre['telephone_paiement']) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($membre['numero_cni'])): ?>
                                        <span class="font-monospace small js-cni-mask" data-cni="<?= e($membre['numero_cni']) ?>">
                                            ••••••••••••
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">Non renseigné</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $badge_statut ?> rounded-pill px-3 py-1">
                                        <?= e(ucfirst($membre['statut_membre'])) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <?php if ($membre['role'] === 'admin'): ?>
                                        <span class="small text-muted fst-italic">Admin en exercice</span>
                                    <?php else: ?>
                                        <form method="POST" action="<?= BASE_URL ?>/admin/members.php" class="d-inline-flex align-items-center gap-1">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="action" value="changer_statut">
                                            <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">
                                            <select name="statut_membre" class="form-select form-select-sm" style="width: 115px;">
                                                <option value="actif" <?= $membre['statut_membre'] === 'actif' ? 'selected' : '' ?>>Actif</option>
                                                <option value="inactif" <?= $membre['statut_membre'] === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                                                <option value="sorti" <?= $membre['statut_membre'] === 'sorti' ? 'selected' : '' ?>>Sorti</option>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Enregistrer le statut">
                                                <i class="bi bi-check2"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Copie rapide du code ou du lien d'invitation
    function configurerBoutonCopie(idBouton, labelSucces) {
        const btn = document.getElementById(idBouton);
        if (!btn) return;
        btn.addEventListener('click', function () {
            const texte = this.getAttribute('data-copy');
            const htmlOriginal = this.innerHTML;
            navigator.clipboard.writeText(texte).then(() => {
                this.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + labelSucces;
                setTimeout(() => { this.innerHTML = htmlOriginal; }, 2000);
            });
        });
    }
    configurerBoutonCopie('btnCopyCode', 'Code copié !');
    configurerBoutonCopie('btnCopyLink', 'Lien copié !');

    // 2. Afficher / Masquer les numéros de CNI des membres acceptés
    const btnToggleCNI = document.getElementById('btnToggleCNI');
    let cniVisible = false;
    if (btnToggleCNI) {
        btnToggleCNI.addEventListener('click', function () {
            cniVisible = !cniVisible;
            document.querySelectorAll('.js-cni-mask').forEach(function (span) {
                span.textContent = cniVisible ? span.getAttribute('data-cni') : '••••••••••••';
            });
            this.innerHTML = cniVisible
                ? '<i class="bi bi-eye-slash me-1"></i>Masquer les CNI'
                : '<i class="bi bi-eye me-1"></i>Afficher les CNI';
        });
    }

    // 3. Recherche instantanée dans le tableau des membres
    const inputSearch = document.getElementById('searchMembre');
    if (inputSearch) {
        inputSearch.addEventListener('input', function () {
            const terme = this.value.toLowerCase().trim();
            document.querySelectorAll('.js-membre-row').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(terme) ? '' : 'none';
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>