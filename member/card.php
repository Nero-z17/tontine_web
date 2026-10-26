<?php
// member/card.php — Carte de membre avec QR Code contenant uniquement le code public
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$user_id = (int)$_SESSION['user_id'];
$stmtMembre = $pdo->prepare("
    SELECT u.code_public, u.nom_complet, u.statut_membre, t.nom AS nom_tontine
    FROM users u
    LEFT JOIN tontines t ON t.id = u.tontine_id
    WHERE u.id = ?
    LIMIT 1
");
$stmtMembre->execute([$user_id]);
$membre = $stmtMembre->fetch();

$titre_page = 'Ma carte de membre';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<style>
    .member-card {
        max-width: 620px;
        margin-inline: auto;
        overflow: hidden;
    }

    .member-card__top {
        background: linear-gradient(135deg, #102a43, #1769aa);
        color: #fff;
    }

    .member-card__qr {
        display: inline-flex;
        min-width: 190px;
        min-height: 190px;
        align-items: center;
        justify-content: center;
        padding: 12px;
        background: #fff;
        border-radius: 12px;
    }

    .member-card__qr img,
    .member-card__qr canvas {
        display: block;
        max-width: 100%;
        height: auto;
    }

    @media print {
        body {
            background: #fff !important;
        }

        body * {
            visibility: hidden !important;
        }

        .member-card,
        .member-card * {
            visibility: visible !important;
        }

        .member-card {
            position: absolute;
            inset: 20mm auto auto 50%;
            width: 100%;
            max-width: 160mm;
            transform: translateX(-50%);
            box-shadow: none !important;
            border: 1px solid #ced4da !important;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }

        .member-card__actions {
            display: none !important;
        }
    }
</style>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-info bg-opacity-25 text-info px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-qr-code me-1"></i>IDENTIFICATION DU MEMBRE
        </span>
        <h1 class="fw-bold h2 mb-1">Ma carte de membre</h1>
        <p class="text-muted small mb-0">Présentez votre code public pour vous identifier au sein de votre tontine.</p>
    </div>
</div>

<main class="container py-5">
    <?php if (!$membre): ?>
        <div class="card card-djangui p-5 text-center">
            <i class="bi bi-person-exclamation fs-1 text-warning mb-2"></i>
            <h2 class="h5 fw-bold">Carte indisponible</h2>
            <p class="text-muted small mb-0">Les informations de votre compte n’ont pas pu être retrouvées.</p>
        </div>
    <?php else: ?>
        <article class="card card-djangui member-card">
            <div class="member-card__top p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="small text-uppercase fw-semibold opacity-75">Carte de membre</div>
                        <h2 class="h4 fw-bold mt-2 mb-1"><?= e($membre['nom_tontine'] ?? 'Tontine') ?></h2>
                        <div class="small opacity-75">DJANGUI</div>
                    </div>
                    <i class="bi bi-people-fill fs-2" aria-hidden="true"></i>
                </div>
            </div>

            <div class="p-4 p-md-5">
                <div class="row align-items-center g-4">
                    <div class="col-md">
                        <div class="small text-muted text-uppercase fw-semibold mb-1">Membre</div>
                        <div class="h5 fw-bold mb-3"><?= e($membre['nom_complet']) ?></div>

                        <div class="small text-muted text-uppercase fw-semibold mb-1">Identifiant public</div>
                        <div class="h5 fw-bold text-primary mb-3"><?= e($membre['code_public']) ?></div>

                        <span class="badge <?= $membre['statut_membre'] === 'actif' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= e(ucfirst($membre['statut_membre'])) ?>
                        </span>
                    </div>
                    <div class="col-md-auto text-center">
                        <div id="memberQrCode" class="member-card__qr" role="img" aria-label="Code QR contenant votre identifiant public"></div>
                        <div class="small text-muted mt-2">Scannez pour lire le code public</div>
                    </div>
                </div>
                <p class="small text-muted border-top pt-3 mt-4 mb-0">
                    Cette carte et son QR code affichent uniquement votre identifiant public. Ils ne contiennent ni mot de passe, ni numéro de téléphone, ni numéro de CNI.
                </p>
            </div>
        </article>

        <div class="member-card__actions text-center mt-4">
            <button type="button" class="btn btn-glow-blue" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimer ma carte
            </button>
        </div>
    <?php endif; ?>
</main>

<?php if ($membre && !empty($membre['code_public'])): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>
    <script>
        (function () {
            const target = document.getElementById('memberQrCode');
            const codePublic = <?= json_encode(
                $membre['code_public'],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?>;

            if (target && typeof QRCode !== 'undefined') {
                new QRCode(target, {
                    text: codePublic,
                    width: 166,
                    height: 166,
                    colorDark: '#102a43',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } else if (target) {
                target.textContent = 'QR code indisponible. Utilisez le code public affiché sur la carte.';
                target.setAttribute('role', 'status');
                target.setAttribute('aria-label', 'QR code indisponible');
            }
        })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>