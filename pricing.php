<?php
// pricing.php — Présentation transparente du modèle d'abonnement SaaS Djangui (Membre 1)
$titre_page = "Tarifs & Abonnement SaaS";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container text-center">
        <span class="badge bg-success px-3 py-2 rounded-pill mb-2">TARIFICATION SOLIDAIRE CAMEROUN</span>
        <h1 class="fw-bold display-6 mb-2">Un seul abonnement pour toute votre tontine</h1>
        <p class="text-muted mx-auto mb-0" style="max-width: 640px;">
            L'abonnement s'applique à la tontine entière : lorsque la secrétaire active l'abonnement du groupe, tous les membres accèdent gratuitement à leur espace.
        </p>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4 align-items-stretch justify-content-center mb-5">
        <!-- CARTE 1 : LES 2 PREMIERS MOIS -->
        <div class="col-md-6 col-lg-5">
            <div class="card card-djangui hover-lift h-100 p-4 p-lg-5 border-left-emerald">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill fw-bold">Mois 1 & Mois 2</span>
                    <i class="bi bi-gift-fill fs-2 text-success"></i>
                </div>
                <h3 class="fw-bold">Période d'essai gratuite</h3>
                <div class="display-5 fw-bold text-success my-3">
                    0 FCFA <span class="fs-6 text-muted fw-normal">/ pendant 2 mois</span>
                </div>
                <p class="text-muted small mb-4">
                    Activée automatiquement dès la création de la tontine. Permet de configurer votre premier cycle, d'inscrire vos membres et de réaliser votre tirage au sort sans rien payer.
                </p>
                <ul class="list-unstyled small mb-4">
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Création illimitée de membres dans la tontine</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Tirage au sort aléatoire verrouillé</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Suivi des cotisations & Caisse de réserve</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Cartes de membre avec QR Code sécurisé</li>
                </ul>
                <a href="<?= BASE_URL ?>/register-tontine.php" class="btn btn-glow-emerald w-100 mt-auto py-2.5">
                    Démarrer les 2 mois gratuits
                </a>
            </div>
        </div>

        <!-- CARTE 2 : À PARTIR DU 3e MOIS -->
        <div class="col-md-6 col-lg-5">
            <div class="card-accent-djangui hover-lift h-100 p-4 p-lg-5">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge bg-primary px-3 py-2 rounded-pill fw-bold">À partir du 3e mois</span>
                    <i class="bi bi-gem fs-2 text-primary"></i>
                </div>
                <h3 class="fw-bold">Forfait Mensuel Tontine</h3>
                <div class="display-5 fw-bold text-primary my-3">
                    2 000 FCFA <span class="fs-6 text-muted fw-normal">/ mois par tontine</span>
                </div>
                <p class="text-muted small mb-4">
                    Payé par l'administrateur (souvent prélevé sur la caisse de réserve). Aucun membre n'a besoin de payer un abonnement individuel.
                </p>
                <ul class="list-unstyled small mb-4">
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i><strong>0 FCFA</strong> pour les membres invités</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Cycles successifs illimités & Historique complet</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Passation d'administration en fin de cycle</li>
                    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Programmation des réunions Zoom</li>
                </ul>
                <a href="<?= BASE_URL ?>/login.php" class="btn btn-glow-blue w-100 mt-auto py-2.5">
                    Gérer l'abonnement de ma tontine
                </a>
            </div>
        </div>
    </div>

    <!-- RÈGLES DE GESTION DE L'ABONNEMENT -->
    <div class="card card-djangui p-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-info-circle-fill text-primary me-2"></i>Règles de transparence sur l'abonnement</h5>
        <div class="row g-4 small">
            <div class="col-md-4">
                <div class="p-3 rounded-3 box-adaptive h-100">
                    <div class="fw-bold mb-1">1. Essai rattaché à la tontine</div>
                    <p class="text-muted mb-0">
                        Les 2 mois d'essai sont enregistrés sur la tontine dès sa création (`date_debut_essai` et `date_fin_essai`). Un transfert d'administrateur ne réinitialise pas artificiellement la période d'essai.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3 box-adaptive h-100">
                    <div class="fw-bold mb-1">2. Que se passe-t-il en cas d'expiration ?</div>
                    <p class="text-muted mb-0">
                        Vos données ne sont jamais supprimées : les membres et la secrétaire conservent la consultation de l'historique et des bilans, mais le lancement d'un nouveau cycle est bloqué jusqu'au renouvellement.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3 box-adaptive h-100">
                    <div class="fw-bold mb-1">3. Prototype Scolaire (Mode Démo)</div>
                    <p class="text-muted mb-0">
                        Dans cette version académique, le renouvellement à 2 000 FCFA est simulé depuis l'espace administrateur avec la mention explicite <code>simule</code> dans le journal d'abonnement.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>