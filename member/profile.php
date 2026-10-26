<?php
// member/profile.php — Gestion des informations personnelles et du mot de passe
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$user_id = (int)$_SESSION['user_id'];
$stmtMembre = $pdo->prepare("
    SELECT nom_complet, username, telephone, email, canal_paiement, telephone_paiement
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmtMembre->execute([$user_id]);
$membre = $stmtMembre->fetch();

if (!$membre) {
    http_response_code(404);
    exit('Compte membre introuvable.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'modifier_profil') {
        $nom_complet = trim($_POST['nom_complet'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $canal_paiement = $_POST['canal_paiement'] ?? '';
        $telephone_paiement = trim($_POST['telephone_paiement'] ?? '');

        $longueur_nom = function_exists('mb_strlen')
            ? mb_strlen($nom_complet, 'UTF-8')
            : strlen($nom_complet);
        $email_valide = $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL);

        if ($nom_complet === '' || $longueur_nom > 120) {
            message_flash('danger', 'Le nom complet est obligatoire et ne doit pas dépasser 120 caractères.');
        } elseif ($telephone === '' || strlen($telephone) > 20) {
            message_flash('danger', 'Veuillez saisir un numéro de téléphone valide de 20 caractères maximum.');
        } elseif (!$email_valide || strlen($email) > 150) {
            message_flash('danger', 'Veuillez saisir une adresse e-mail valide de 150 caractères maximum.');
        } elseif (!in_array($canal_paiement, ['momo', 'om', 'especes'], true)) {
            message_flash('danger', 'Le canal de paiement sélectionné est invalide.');
        } elseif ($telephone_paiement === '' || strlen($telephone_paiement) > 20) {
            message_flash('danger', 'Veuillez saisir le numéro associé au paiement, sur 20 caractères maximum.');
        } else {
            try {
                $stmtUpdate = $pdo->prepare("
                    UPDATE users
                    SET nom_complet = ?, telephone = ?, email = ?, canal_paiement = ?, telephone_paiement = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $nom_complet,
                    $telephone,
                    $email !== '' ? $email : null,
                    $canal_paiement,
                    $telephone_paiement,
                    $user_id,
                ]);

                $_SESSION['nom_complet'] = $nom_complet;
                message_flash('success', 'Vos informations personnelles ont été mises à jour.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    message_flash('danger', 'Ce numéro de téléphone ou cette adresse e-mail est déjà associé à un autre compte.');
                } else {
                    throw $e;
                }
            }
        }

        header('Location: ' . BASE_URL . '/member/profile.php');
        exit;
    }

    if ($action === 'changer_mot_de_passe') {
        $mot_de_passe_actuel = $_POST['mot_de_passe_actuel'] ?? '';
        $nouveau_mot_de_passe = $_POST['nouveau_mot_de_passe'] ?? '';
        $confirmation_mot_de_passe = $_POST['confirmation_mot_de_passe'] ?? '';

        $stmtHash = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmtHash->execute([$user_id]);
        $password_hash = $stmtHash->fetchColumn();

        if (!is_string($password_hash) || !password_verify($mot_de_passe_actuel, $password_hash)) {
            message_flash('danger', 'Le mot de passe actuel est incorrect.');
        } elseif (strlen($nouveau_mot_de_passe) < 8) {
            message_flash('danger', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
        } elseif ($nouveau_mot_de_passe !== $confirmation_mot_de_passe) {
            message_flash('danger', 'La confirmation du nouveau mot de passe ne correspond pas.');
        } elseif (password_verify($nouveau_mot_de_passe, $password_hash)) {
            message_flash('warning', 'Choisissez un nouveau mot de passe différent de l’actuel.');
        } else {
            $nouveau_hash = password_hash($nouveau_mot_de_passe, PASSWORD_DEFAULT);
            $stmtUpdatePassword = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmtUpdatePassword->execute([$nouveau_hash, $user_id]);
            message_flash('success', 'Votre mot de passe a été modifié.');
        }

        header('Location: ' . BASE_URL . '/member/profile.php');
        exit;
    }

    message_flash('danger', 'Action non reconnue.');
    header('Location: ' . BASE_URL . '/member/profile.php');
    exit;
}

$titre_page = 'Mon profil';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-person-gear me-1"></i>PARAMÈTRES DU COMPTE
        </span>
        <h1 class="fw-bold h2 mb-1">Mon profil</h1>
        <p class="text-muted small mb-0">Mettez à jour vos coordonnées, votre moyen de paiement et votre mot de passe.</p>
    </div>
</div>

<main class="container py-4 pb-5">
    <div class="row justify-content-center g-4">
        <section class="col-lg-7" aria-labelledby="coordonnees-title">
            <div class="card card-djangui p-4 p-md-5">
                <h2 id="coordonnees-title" class="h5 fw-bold mb-1">Mes coordonnées</h2>
                <p class="text-muted small mb-4">Identifiant de connexion : <strong><?= e($membre['username']) ?></strong></p>

                <form method="POST" action="profile.php">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="modifier_profil">

                    <div class="mb-3">
                        <label for="nom_complet" class="form-label fw-semibold">Nom complet</label>
                        <input type="text" class="form-control" id="nom_complet" name="nom_complet" maxlength="120" value="<?= e($membre['nom_complet']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="telephone" class="form-label fw-semibold">Téléphone de contact</label>
                        <input type="tel" class="form-control" id="telephone" name="telephone" maxlength="20" value="<?= e($membre['telephone']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Adresse e-mail <span class="text-muted fw-normal">(facultative)</span></label>
                        <input type="email" class="form-control" id="email" name="email" maxlength="150" value="<?= e($membre['email'] ?? '') ?>">
                    </div>

                    <hr class="my-4">
                    <h3 class="h6 fw-bold mb-3">Informations de paiement</h3>

                    <div class="mb-3">
                        <label for="canal_paiement" class="form-label fw-semibold">Canal de paiement</label>
                        <select class="form-select" id="canal_paiement" name="canal_paiement" required>
                            <option value="momo" <?= $membre['canal_paiement'] === 'momo' ? 'selected' : '' ?>>MTN Mobile Money</option>
                            <option value="om" <?= $membre['canal_paiement'] === 'om' ? 'selected' : '' ?>>Orange Money</option>
                            <option value="especes" <?= $membre['canal_paiement'] === 'especes' ? 'selected' : '' ?>>Espèces</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="telephone_paiement" class="form-label fw-semibold">Numéro associé au paiement</label>
                        <input type="tel" class="form-control" id="telephone_paiement" name="telephone_paiement" maxlength="20" value="<?= e($membre['telephone_paiement']) ?>" required>
                        <div class="form-text">Indiquez le numéro qui sert à envoyer les cotisations et recevoir votre pot.</div>
                    </div>

                    <button type="submit" class="btn btn-glow-blue">
                        <i class="bi bi-check2-circle me-1"></i>Enregistrer mes coordonnées
                    </button>
                </form>
            </div>
        </section>

        <section class="col-lg-5" aria-labelledby="mot-de-passe-title">
            <div class="card card-djangui p-4 p-md-5">
                <h2 id="mot-de-passe-title" class="h5 fw-bold mb-1">
                    <i class="bi bi-shield-lock text-primary me-2"></i>Sécurité du compte
                </h2>
                <p class="text-muted small mb-4">Choisissez un mot de passe d’au moins 8 caractères.</p>

                <form method="POST" action="profile.php">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="changer_mot_de_passe">

                    <div class="mb-3">
                        <label for="mot_de_passe_actuel" class="form-label fw-semibold">Mot de passe actuel</label>
                        <input type="password" class="form-control" id="mot_de_passe_actuel" name="mot_de_passe_actuel" autocomplete="current-password" required>
                    </div>

                    <div class="mb-3">
                        <label for="nouveau_mot_de_passe" class="form-label fw-semibold">Nouveau mot de passe</label>
                        <input type="password" class="form-control" id="nouveau_mot_de_passe" name="nouveau_mot_de_passe" minlength="8" autocomplete="new-password" required>
                    </div>

                    <div class="mb-4">
                        <label for="confirmation_mot_de_passe" class="form-label fw-semibold">Confirmer le nouveau mot de passe</label>
                        <input type="password" class="form-control" id="confirmation_mot_de_passe" name="confirmation_mot_de_passe" minlength="8" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-key me-1"></i>Modifier mon mot de passe
                    </button>
                </form>
            </div>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>