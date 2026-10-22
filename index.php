<?php
// index.php — Page d'accueil Djangui (100% Adaptative Mode Clair Lumineux & Mode Sombre Bleu Nuit)
$titre_page = "Accueil";
require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<!-- ==========================================================================
     1. SECTION HERO : VIDÉO BACKGROUND + FONDU LUMINEUX (CLAIR) OU BLEU NUIT (SOMBRE)
     ========================================================================== -->
<main class="hero-video-section">
    <video id="heroVideo" class="hero-video-bg" autoplay muted loop playsinline preload="auto"
           referrerpolicy="no-referrer"
           poster="https://images.unsplash.com/photo-1531123897727-8f129e1688ce?auto=format&fit=crop&w=1600&q=80">
        <source src="<?= BASE_URL ?>/assets/video/djangui.mp4" type="video/mp4">
        <source src="https://assets.mixkit.co/videos/preview/mixkit-african-american-woman-using-her-smartphone-41862-large.mp4" type="video/mp4">
    </video>

    <!-- Voile dégradé qui devient Blanc/Azur/Émeraude en Mode Clair et Bleu Nuit en Mode Sombre -->
    <div class="hero-overlay"></div>

    <div class="container hero-content py-3 py-lg-5">
        <div class="row align-items-center justify-content-center g-5">
            
            <!-- COLONNE GAUCHE : Titre, explications et parcours d'accès -->
            <div class="col-lg-8 text-center col-xl-7">
                <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill mb-4 hero-badge">
                    <span class="badge bg-success rounded-pill px-2 py-1">OFFRE CAMEROUN</span>
                    <span class="small fw-semibold">2 mois d'essai gratuits pour toute votre tontine</span>
                </div>

                <h1 class="hero-title mb-4">
                    Votre tontine gérée avec <span class="text-gradient-blue">élégance, rigueur et transparence.</span>
                </h1>

                <p class="hero-subtitle mb-4 mx-auto" style="max-width: 620px;">
                    Fini les litiges de cahier. Chaque membre cotise depuis son téléphone : la part du tour va directement au <strong>bénéficiaire (« bouffeur »)</strong>, les dettes remboursées reviennent à l'ancien bénéficiaire concerné, et les pénalités alimentent la <strong>caisse de réserve</strong>.
                </p>

                <!-- LES 2 PARCOURS PRINCIPAUX -->
                <div class="d-flex flex-column flex-sm-row justify-content-center gap-3 mb-4">
                    <a href="<?= BASE_URL ?>/register-tontine.php" class="btn btn-glow-emerald btn-lg px-4 py-3">
                        <i class="bi bi-plus-circle-fill me-2"></i>Créer une tontine
                    </a>
                    <a href="<?= BASE_URL ?>/login.php" class="btn btn-glow-blue btn-lg px-4 py-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Accéder à ma tontine
                    </a>
                </div>

                <!-- Encadré d'invitation avec code -->
                <div class="p-3 rounded-4 d-inline-flex flex-wrap align-items-center justify-content-center gap-2 hero-invite-box">
                    <i class="bi bi-key-fill text-primary fs-5"></i>
                    <span class="small fw-medium">Votre secrétaire vous a remis un code ?</span>
                    <a href="<?= BASE_URL ?>/join-tontine.php" class="btn btn-sm btn-glow-blue rounded-pill px-3">
                        Rejoindre avec mon code <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ==========================================================================
     2. BARRE DES POINTS FORTS
     ========================================================================== -->
<section class="py-4 section-surface border-bottom">
    <div class="container">
        <div class="row g-3 text-center">
            <div class="col-6 col-lg-3">
                <div class="p-2">
                    <i class="bi bi-person-check-fill text-primary fs-3"></i>
                    <h6 class="fw-bold mt-2 mb-1">1 Compte = 1 Tontine</h6>
                    <p class="text-muted small mb-0">Isolation stricte des données et sécurité garantie</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="p-2">
                    <i class="bi bi-shuffle text-success fs-3"></i>
                    <h6 class="fw-bold mt-2 mb-1">Tirage Verrouillé</h6>
                    <p class="text-muted small mb-0">Ordre des bénéficiaires scellé pour tout le cycle</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="p-2">
                    <i class="bi bi-phone-vibrate-fill text-info fs-3"></i>
                    <h6 class="fw-bold mt-2 mb-1">MTN MoMo & Orange Money</h6>
                    <p class="text-muted small mb-0">Ventilation automatique entre bouffeur et caisse</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="p-2">
                    <i class="bi bi-sun-fill text-warning fs-3"></i>
                    <h6 class="fw-bold mt-2 mb-1">Mode Clair & Sombre</h6>
                    <p class="text-muted small mb-0">Confort visuel pensé pour les jeunes et les aînés</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     3. CIRCUIT FINANCIER AUTOMATIQUE (LES 3 RÈGLES MÉTIER)
     ========================================================================== -->
<section class="py-5">
    <div class="container py-2">
        <div class="text-center mb-5">
            <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                LOGIQUE MÉTIER CAMEROUNAISE
            </span>
            <h2 class="fw-bold display-6">Où va l'argent lors de chaque paiement ?</h2>
            <p class="text-muted mx-auto" style="max-width: 680px;">
                Djangui sépare automatiquement chaque franc payé par un membre selon sa nature exacte, sans erreur de calcul.
            </p>
        </div>

        <div class="row g-4">
            <!-- Cas 1 -->
            <div class="col-md-6 col-lg-4">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-emerald">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill fw-bold">Cas n°1 : Tour actif</span>
                        <i class="bi bi-person-hearts fs-2 text-success"></i>
                    </div>
                    <h5 class="fw-bold">Cotisation normale du tour</h5>
                    <p class="text-muted small">
                        Lorsque le membre paie sa cotisation dans les délais pour la séance en cours, <strong>100 % de la cotisation</strong> est destinée au bénéficiaire (« bouffeur ») désigné pour ce tour.
                    </p>
                    <div class="mt-auto p-3 rounded-3 box-adaptive">
                        <div class="small fw-bold text-success"><i class="bi bi-check2-circle me-1"></i>Destination directe</div>
                        <div class="small text-muted mt-1">Compte Mobile Money du bouffeur du tour actif.</div>
                    </div>
                </div>
            </div>

            <!-- Cas 2 -->
            <div class="col-md-6 col-lg-4">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-warning">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge bg-warning-subtle text-warning-emphasis px-3 py-2 rounded-pill fw-bold">Cas n°2 : Retard & Dette</span>
                        <i class="bi bi-arrow-left-right fs-2 text-warning"></i>
                    </div>
                    <h5 class="fw-bold">Remboursement d'un tour passé</h5>
                    <p class="text-muted small">
                        Si un membre règle en retard la cotisation d'une séance passée avec une pénalité, Djangui divise le paiement en deux parts distinctes :
                    </p>
                    <div class="mt-auto p-3 rounded-3 box-adaptive">
                        <div class="small fw-bold text-primary"><i class="bi bi-1-circle-fill me-1"></i>Cotisation principale :</div>
                        <div class="small text-muted mb-2">Versée à l'ancien bouffeur qui avait manqué cette part lors de son tour.</div>
                        <div class="small fw-bold text-success"><i class="bi bi-2-circle-fill me-1"></i>Pénalité de retard :</div>
                        <div class="small text-muted">Versée dans la caisse de réserve gérée par la secrétaire.</div>
                    </div>
                </div>
            </div>

            <!-- Cas 3 -->
            <div class="col-md-6 col-lg-4">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-navy">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge bg-primary-subtle text-primary px-3 py-2 rounded-pill fw-bold">Cas n°3 : Double rôle</span>
                        <i class="bi bi-piggy-bank-fill fs-2 text-primary"></i>
                    </div>
                    <h5 class="fw-bold">Caisse de réserve & Rotation</h5>
                    <p class="text-muted small">
                        La secrétaire est aussi membre cotisante : elle cotise et bouffe à son tour, tout en gardant la caisse des pénalités et bonus.
                    </p>
                    <div class="mt-auto p-3 rounded-3 box-adaptive">
                        <div class="small fw-bold text-primary"><i class="bi bi-shield-check me-1"></i>Passation en 1 clic</div>
                        <div class="small text-muted mt-1">À la clôture d'un cycle, l'historique est conservé et le rôle d'admin peut être transmis.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     4. FONCTIONNALITÉS COMPLÈTES (CARTES AVEC EFFET HOVER)
     ========================================================================== -->
<section class="py-5 section-surface border-top border-bottom">
    <div class="container py-2">
        <div class="row align-items-end mb-5">
            <div class="col-lg-8">
                <span class="text-primary fw-bold small text-uppercase">Outils complets sur Mobile & PC</span>
                <h2 class="fw-bold mt-1 mb-0">Conçu pour simplifier la vie du groupe</h2>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <a href="<?= BASE_URL ?>/about-terms.php" class="btn btn-outline-primary rounded-pill px-4 fw-semibold">
                    Voir toutes les règles <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card card-djangui hover-lift h-100 p-4">
                    <i class="bi bi-arrow-repeat fs-2 text-primary mb-3"></i>
                    <h5 class="fw-bold">Cycles successifs sans perte</h5>
                    <p class="text-muted small mb-0">
                        Enchaînez les cycles (Cycle 1, Cycle 2…), ajoutez ou retirez des participants entre deux cycles tout en gardant les bilans passés.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card card-djangui hover-lift h-100 p-4">
                    <i class="bi bi-camera-video-fill fs-2 text-info mb-3"></i>
                    <h5 class="fw-bold">Réunions & Liens Zoom</h5>
                    <p class="text-muted small mb-0">
                        La secrétaire programme les assemblées générales avec date, ordre du jour et lien Zoom consultables directement par les membres.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card card-djangui hover-lift h-100 p-4">
                    <i class="bi bi-qr-code-scan fs-2 text-success mb-3"></i>
                    <h5 class="fw-bold">Carte de Membre & QR Code</h5>
                    <p class="text-muted small mb-0">
                        Chaque membre dispose d'une carte numérique avec QR Code vérifiable contenant uniquement son statut officiel dans la tontine.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==========================================================================
     5. SECTION TARIF SAAS (FONDU AZUR-ÉMERAUDE EN MODE CLAIR / BLEU NUIT EN SOMBRE)
     ========================================================================== -->
<section class="py-5">
    <div class="container py-3">
        <div class="card-accent-djangui p-4 p-lg-5 hover-lift">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge bg-success px-3 py-2 rounded-pill mb-3">MODÈLE SAAS COMMUNAUTAIRE</span>
                    <h2 class="fw-bold mb-3">Un seul abonnement couvre toute votre tontine</h2>
                    <p class="text-muted mb-4">
                        Lorsque la secrétaire crée la tontine, le groupe bénéficie immédiatement de <strong>2 mois d'essai gratuits</strong>. Ensuite, l'abonnement de 2 000 FCFA/mois payé par l'administrateur donne un accès 100 % gratuit à tous les membres.
                    </p>
                    <div class="d-flex flex-wrap gap-3 text-primary small fw-bold">
                        <span><i class="bi bi-check-circle-fill text-success me-1"></i>Membres illimités dans la tontine</span>
                        <span><i class="bi bi-check-circle-fill text-success me-1"></i>Historique conservé</span>
                        <span><i class="bi bi-check-circle-fill text-success me-1"></i>Support Mobile & Desktop</span>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="p-4 rounded-4 text-center accent-subbox">
                        <div class="text-primary small fw-bold text-uppercase">À partir du 3e mois</div>
                        <div class="display-4 fw-bold my-2">2 000 <span class="fs-4">FCFA</span></div>
                        <div class="text-muted small mb-4">par mois et par tontine (0 FCFA les 2 premiers mois)</div>
                        <a href="<?= BASE_URL ?>/register-tontine.php" class="btn btn-glow-emerald btn-lg w-100 mb-2">
                            Créer notre tontine maintenant
                        </a>
                        <a href="<?= BASE_URL ?>/pricing.php" class="btn btn-link text-primary text-decoration-none small fw-semibold">
                            Voir les détails de l'abonnement <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Script de secours pour lancer la vidéo muette automatiquement -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const video = document.getElementById('heroVideo');
    if (video) {
        video.muted = true;
        video.play().catch(function () {});
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>