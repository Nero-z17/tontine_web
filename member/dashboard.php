<?php
/**
 * =============================================================================
 * MEMBRE 3 : member/dashboard.php
 * =============================================================================
 * Tableau de bord personnel du membre cotisant (et de la secrétaire) :
 *  - Prochaine cotisation ouverte à payer (montant restant, période, bouffeur)
 *  - Point 7 : Bloc « Pot de la Tontine Actuelle » (bouffeur de la séance en cours & progression)
 *  - Mes statistiques du cycle (séances payées / total, total versé)
 *  - Prochaine réunion Zoom publiée
 * =============================================================================
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];

// 1. Récupération des données utilisateur & tontine
$stmtUser = $pdo->prepare("SELECT nom_complet, role, canal_paiement FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$user = $stmtUser->fetch();

$stmtTontine = $pdo->prepare("SELECT nom, solde_caisse_reserve FROM tontines WHERE id = ?");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

// 2. Cycle actif
$stmtCycle = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? AND statut = 'actif' LIMIT 1");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

$mon_cycle_member     = null;
$prochaine_cotisation = null;
$pot_seance_actuelle  = null;
$prochaine_reunion    = null;
$nb_payees            = 0;
$nb_total_seances     = 0;
$total_verse          = 0.0;
$seance_max_ouverte   = 1;

if ($cycle) {
    $cid = (int)$cycle['id'];
    $seance_max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

    // Mon inscription dans le cycle
    $stmtCM = $pdo->prepare("SELECT * FROM cycle_members WHERE cycle_id = ? AND user_id = ? LIMIT 1");
    $stmtCM->execute([$cid, $user_id]);
    $mon_cycle_member = $stmtCM->fetch();

    if ($mon_cycle_member) {
        // Prochaine cotisation non payée du membre (parmi les séances ouvertes <= $seance_max_ouverte)
        $stmtNext = $pdo->prepare("
            SELECT 
                c.id, 
                c.numero_seance, 
                c.date_echeance, 
                c.montant_attendu,
                c.montant_paye,
                u_ben.nom_complet AS ben_nom
            FROM contributions c
            JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
            JOIN users u_ben ON u_ben.id = cm_ben.user_id
            WHERE c.cycle_member_id = ? 
              AND c.numero_seance <= ?
              AND (c.statut != 'paye' AND c.montant_paye < c.montant_attendu)
            ORDER BY c.numero_seance ASC
            LIMIT 1
        ");
        $stmtNext->execute([(int)$mon_cycle_member['id'], $seance_max_ouverte]);
        $prochaine_cotisation = $stmtNext->fetch();

        // Total des séances du membre dans ce cycle
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE cycle_member_id = ?");
        $stmtTotal->execute([(int)$mon_cycle_member['id']]);
        $nb_total_seances = (int)$stmtTotal->fetchColumn();

        // Nombre de séances payées par le membre
        $stmtPayees = $pdo->prepare("
            SELECT COUNT(*) FROM contributions 
            WHERE cycle_member_id = ? AND (statut = 'paye' OR montant_paye >= montant_attendu)
        ");
        $stmtPayees->execute([(int)$mon_cycle_member['id']]);
        $nb_payees = (int)$stmtPayees->fetchColumn();

        // Montant total versé par le membre
        $stmtVerse = $pdo->prepare("
            SELECT COALESCE(SUM(montant_total), 0) 
            FROM payments 
            WHERE paye_par_user_id = ? AND statut_paiement = 'valide'
        ");
        $stmtVerse->execute([$user_id]);
        $total_verse = (float)$stmtVerse->fetchColumn();
    }

    // POINT 7 : Récupérer le « Pot de la Tontine Actuelle » (bouffeur de la séance active $seance_max_ouverte)
    $stmtPotActuel = $pdo->prepare("
        SELECT 
            c.numero_seance,
            MIN(c.date_echeance) AS date_echeance,
            u_ben.id AS bouffeur_user_id,
            u_ben.nom_complet AS bouffeur_nom,
            u_ben.canal_paiement AS bouffeur_canal,
            cm_ben.rang_beneficiaire,
            cm_ben.montant_pot_attendu,
            cm_ben.montant_pot_recu,
            COUNT(c.id) AS nb_cotisants,
            SUM(CASE WHEN c.statut = 'paye' OR c.montant_paye >= c.montant_attendu THEN 1 ELSE 0 END) AS nb_a_jour
        FROM contributions c
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_ben.user_id
        WHERE cm_ben.cycle_id = ? AND c.numero_seance = ?
        GROUP BY c.numero_seance, u_ben.id, u_ben.nom_complet, u_ben.canal_paiement, cm_ben.rang_beneficiaire, cm_ben.montant_pot_attendu, cm_ben.montant_pot_recu
        LIMIT 1
    ");
    $stmtPotActuel->execute([$cid, $seance_max_ouverte]);
    $pot_seance_actuelle = $stmtPotActuel->fetch();
}

// 3. Prochaine réunion Zoom publiée
$stmtReunion = $pdo->prepare("
    SELECT titre, date_heure, lien_zoom 
    FROM meetings
    WHERE tontine_id = ? AND statut = 'publiee' AND date_heure >= NOW()
    ORDER BY date_heure ASC 
    LIMIT 1
");
$stmtReunion->execute([$tontine_id]);
$prochaine_reunion = $stmtReunion->fetch();

// 4. Calculs d'affichage
$pot_recu        = $pot_seance_actuelle ? (float)$pot_seance_actuelle['montant_pot_recu'] : 0.0;
$pot_attendu     = $pot_seance_actuelle ? (float)$pot_seance_actuelle['montant_pot_attendu'] : 0.0;
$pct_pot         = ($pot_attendu > 0) ? min(100, round(($pot_recu / $pot_attendu) * 100)) : 0;
$pct_cotisations = ($nb_total_seances > 0) ? round(($nb_payees / $nb_total_seances) * 100) : 0;
$est_en_retard   = ($prochaine_cotisation && strtotime($prochaine_cotisation['date_echeance']) < strtotime(date_actuelle('Y-m-d')));

$titre_page = "Mon Tableau de Bord";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <h2 class="fw-bold mb-1"><i class="bi bi-speedometer2 me-2"></i>Mon Tableau de Bord</h2>
        <p class="text-muted mb-0 small">
            Bienvenue, <?= e($user['nom_complet']) ?> — Tontine « <?= e($tontine['nom']) ?> »
        </p>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle): ?>
        <div class="card card-djangui hover-lift p-4 text-center mt-4">
            <i class="bi bi-info-circle display-4 text-muted"></i>
            <h5 class="mt-3">Aucun cycle actif</h5>
            <p class="text-muted">La secrétaire n'a pas encore lancé de cycle de cotisation.</p>
        </div>
    <?php else: ?>
        <div class="row g-4 mt-2">
            <!-- Prochaine cotisation ouverte -->
            <div class="col-lg-6">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-<?= $est_en_retard ? 'danger' : 'emerald' ?>">
                    <h6 class="text-uppercase text-muted small mb-3"><i class="bi bi-cash-coin me-1"></i>Ma Cotisation (Séance ouverte)</h6>
                    <?php if ($prochaine_cotisation): ?>
                        <?php
                            $reste_du = max(0.0, (float)$prochaine_cotisation['montant_attendu'] - (float)$prochaine_cotisation['montant_paye']);
                            $periode_next = formater_periode_seance($cycle['date_debut'], (int)$prochaine_cotisation['numero_seance'], $cycle['frequence'], $prochaine_cotisation['date_echeance']);
                        ?>
                        <div class="p-3 rounded-3 box-adaptive mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold">Séance n°<?= (int)$prochaine_cotisation['numero_seance'] ?></span>
                                <span class="badge bg-<?= $est_en_retard ? 'danger' : 'primary' ?>">
                                    <?= $est_en_retard ? 'EN RETARD' : 'À payer' ?>
                                </span>
                            </div>
                            <p class="mb-1"><strong>Reste à verser :</strong> <?= format_fcfa($reste_du) ?></p>
                            <p class="mb-1"><strong>Période :</strong> <?= e($periode_next) ?></p>
                            <p class="mb-0"><strong>Bouffeur :</strong> <?= e($prochaine_cotisation['ben_nom']) ?></p>
                        </div>
                        <a href="<?= BASE_URL ?>/member/contributions.php" class="btn btn-glow-emerald w-100 mt-auto">
                            <i class="bi bi-credit-card me-1"></i>Payer maintenant
                        </a>
                    <?php else: ?>
                        <div class="text-center py-3 my-auto">
                            <i class="bi bi-check-circle display-6 text-success"></i>
                            <p class="mt-2 mb-1 fw-semibold">Vous êtes à jour sur la séance actuelle (#<?= $seance_max_ouverte ?>) !</p>
                            <small class="text-muted">La séance suivante s'ouvrira dès la clôture de la séance en cours.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- POINT 7 : POT DE LA TONTINE ACTUELLE (PERSONNE QUI BOUFFE À LA SÉANCE ACTUELLE) -->
            <div class="col-lg-6">
                <div class="card-accent-djangui hover-lift h-100 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-uppercase text-muted small mb-0">
                            <i class="bi bi-trophy-fill text-warning me-1"></i>Pot de la Tontine Actuelle (Séance #<?= $seance_max_ouverte ?>)
                        </h6>
                        <?php if ($pot_seance_actuelle && (int)$pot_seance_actuelle['bouffeur_user_id'] === $user_id): ?>
                            <span class="badge bg-success rounded-pill">C'est votre tour !</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($pot_seance_actuelle): ?>
                        <?php
                            $periode_act = formater_periode_seance($cycle['date_debut'], (int)$pot_seance_actuelle['numero_seance'], $cycle['frequence'], $pot_seance_actuelle['date_echeance']);
                        ?>
                        <div class="text-center mb-3">
                            <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill mb-1">
                                Bénéficiaire (« Bouffeur ») — Tour #<?= (int)$pot_seance_actuelle['rang_beneficiaire'] ?>
                            </span>
                            <h3 class="fw-bold mb-1"><?= e($pot_seance_actuelle['bouffeur_nom']) ?></h3>
                            <p class="text-muted small mb-0">
                                <i class="bi bi-calendar3 me-1"></i><?= e($periode_act) ?> ·
                                <?= (int)$pot_seance_actuelle['nb_a_jour'] ?>/<?= (int)$pot_seance_actuelle['nb_cotisants'] ?> membres à jour
                            </p>
                        </div>
                        <div class="p-3 rounded-3 box-adaptive">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Collecté : <strong><?= format_fcfa($pot_recu) ?></strong></span>
                                <span>Pot attendu : <strong><?= format_fcfa($pot_attendu) ?></strong></span>
                            </div>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-success progress-bar-striped" role="progressbar" style="width: <?= $pct_pot ?>%">
                                    <?= $pct_pot ?>%
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="bi bi-hourglass-split display-6 text-warning"></i>
                            <p class="mt-2 mb-0">Le tirage au sort n'a pas encore eu lieu.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistiques du membre -->
            <div class="col-lg-6">
                <div class="card card-djangui hover-lift p-4 border-left-navy">
                    <h6 class="text-uppercase text-muted small mb-3"><i class="bi bi-bar-chart me-1"></i>Mes Statistiques — Cycle <?= (int)$cycle['numero_cycle'] ?></h6>
                    <div class="row text-center g-3">
                        <div class="col-4">
                            <div class="p-2 rounded-3 box-adaptive">
                                <span class="d-block fs-4 fw-bold"><?= $nb_payees ?>/<?= $nb_total_seances ?></span>
                                <small class="text-muted">Séances réglées</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded-3 box-adaptive">
                                <span class="d-block fs-4 fw-bold"><?= $pct_cotisations ?>%</span>
                                <small class="text-muted">Progression</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded-3 box-adaptive">
                                <span class="d-block fs-4 fw-bold"><?= format_fcfa($total_verse) ?></span>
                                <small class="text-muted">Total versé</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prochaine Réunion Zoom -->
            <div class="col-lg-6">
                <div class="card card-djangui hover-lift p-4 border-left-warning">
                    <h6 class="text-uppercase text-muted small mb-3"><i class="bi bi-camera-video me-1"></i>Prochaine Réunion Zoom</h6>
                    <?php if ($prochaine_reunion): ?>
                        <div class="p-3 rounded-3 box-adaptive">
                            <p class="fw-bold mb-1"><?= e($prochaine_reunion['titre']) ?></p>
                            <p class="mb-1"><i class="bi bi-calendar-event me-1"></i><?= date('d/m/Y à H:i', strtotime($prochaine_reunion['date_heure'])) ?></p>
                        </div>
                        <?php if (!empty($prochaine_reunion['lien_zoom'])): ?>
                            <a href="<?= e($prochaine_reunion['lien_zoom']) ?>" target="_blank" class="btn btn-glow-blue w-100 mt-3">
                                <i class="bi bi-camera-video-fill me-1"></i>Rejoindre la réunion
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="bi bi-calendar-x display-6 text-muted"></i>
                            <p class="mt-2 mb-0 text-muted">Aucune réunion programmée pour le moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>