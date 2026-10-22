<?php
// about-terms.php — Fonctionnement complet, Règles métier et Conditions d'utilisation (Membre 1)
$titre_page = "Fonctionnement & CGU";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container text-center">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            CHARTE & RÈGLES DE GESTION
        </span>
        <h1 class="fw-bold display-6 mb-2">Fonctionnement & Conditions d'Utilisation</h1>
        <p class="text-muted mx-auto mb-0" style="max-width: 680px;">
            Découvrez comment Djangui sécurise les cotisations, protège les anciens bénéficiaires lors des remboursements en retard et garantit la confidentialité des données personnelles.
        </p>
    </div>
</div>

<div class="container pb-5">
    <!-- 1. LES 4 PILIERS DE FONCTIONNEMENT -->
    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card card-djangui hover-lift h-100 p-4 border-left-navy">
                <h5 class="fw-bold text-primary"><i class="bi bi-1-circle-fill me-2"></i>Unicité des comptes et des tontines</h5>
                <ul class="text-muted small mb-0 ps-3">
                    <li class="mb-2">Chaque personne possède un <strong>compte unique</strong> identifié par son nom d'utilisateur, son numéro de téléphone et son <code>code_public</code>.</li>
                    <li class="mb-2">Un administrateur (secrétaire) ne peut créer et diriger qu'<strong>une seule tontine</strong>.</li>
                    <li class="mb-2">Un membre ne peut appartenir qu'à <strong>une seule tontine</strong> dans toute l'application et ne consulte que les données de sa propre tontine.</li>
                    <li>La secrétaire possède une double casquette : elle administre la tontine et la caisse de réserve, tout en cotisant et en recevant le pot (« bouffe ») à son tour comme membre.</li>
                </ul>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-djangui hover-lift h-100 p-4 border-left-emerald">
                <h5 class="fw-bold text-success"><i class="bi bi-2-circle-fill me-2"></i>Circuit financier & Ventilation automatique</h5>
                <ul class="text-muted small mb-0 ps-3">
                    <li class="mb-2"><strong>Paiement depuis l'espace membre :</strong> chaque participant enregistre son règlement (MTN MoMo, Orange Money ou Espèces) depuis son espace.</li>
                    <li class="mb-2"><strong>Cotisation à jour :</strong> le montant va directement au bénéficiaire (« bouffeur ») de la séance en cours.</li>
                    <li class="mb-2"><strong>Remboursement d'un impayé passé :</strong> la part de cotisation manquante est automatiquement attribuée à <strong>l'ancien bouffeur de la séance concernée</strong>, tandis que la pénalité de retard va dans la <strong>caisse de réserve</strong> gérée par la secrétaire.</li>
                </ul>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-djangui hover-lift h-100 p-4 border-left-warning">
                <h5 class="fw-bold text-warning-emphasis"><i class="bi bi-3-circle-fill me-2"></i>Cycles successifs, Tirage au sort & Passation</h5>
                <ul class="text-muted small mb-0 ps-3">
                    <li class="mb-2">Chaque cycle possède son montant, sa fréquence, son jour limite de paiement et ses règles de pénalité.</li>
                    <li class="mb-2">Le tirage au sort aléatoire attribue un rang unique à chaque participant du cycle et <strong>se verrouille définitivement</strong> pour empêcher toute modification silencieuse.</li>
                    <li class="mb-2">À la fin d'un cycle, la secrétaire peut clôturer le cycle, consulter le bilan, ouvrir un nouveau cycle sans effacer l'historique, ou transférer atomiquement le rôle d'administrateur à un autre membre éligible.</li>
                </ul>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-djangui hover-lift h-100 p-4 border-left-danger">
                <h5 class="fw-bold text-danger"><i class="bi bi-4-circle-fill me-2"></i>Sécurité, Carte QR Code & Protection CNI</h5>
                <ul class="text-muted small mb-0 ps-3">
                    <li class="mb-2"><strong>Mots de passe :</strong> hachés avec <code>password_hash()</code> et protégés contre le forçage par blocage automatique après 5 échecs.</li>
                    <li class="mb-2"><strong>Confidentialité de la CNI :</strong> le numéro de CNI est une donnée sensible strictement réservée à la vérification par l'administrateur. Il n'est jamais affiché aux autres membres.</li>
                    <li><strong>QR Code sécurisé :</strong> la carte de membre QR Code contient uniquement l'identifiant public (<code>code_public</code>) et ne stocke jamais de mot de passe ni de numéro de CNI.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- 2. MENTION EXPLICITE SUR LES MOYENS DE PAIEMENT (TRANSPARENCE JURY) -->
    <div class="card-accent-djangui p-4">
        <div class="row align-items-center g-3">
            <div class="col-lg-9">
                <h5 class="fw-bold mb-1"><i class="bi bi-shield-check me-2 text-primary"></i>Cadre d'utilisation académique</h5>
                <p class="text-muted small mb-0">
                    Dans cette version scolaire, les numéros MTN Mobile Money et Orange Money permettent d'identifier les comptes de réception du bénéficiaire du tour et de la caisse de réserve, avec génération immédiate des écritures comptables et de la ventilation dans la base de données MySQL.
                </p>
            </div>
            <div class="col-lg-3 text-lg-end">
                <a href="<?= BASE_URL ?>/register-tontine.php" class="btn btn-glow-emerald">
                    Créer une tontine
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>