<?php
/**
 * =============================================================================
 * MEMBRE 3 : member/dashboard.php
 * =============================================================================
 * Tableau de bord personnel du membre cotisant (et de la secrétaire) :
 *  - Prochaine cotisation à payer (montant, échéance, bouffeur, pénalité si retard)
 *  - Mon pot de tontine & rang de passage issu du tirage
 *  - Mes statistiques du cycle (séances payées / total, total versé)
 *  - Prochaine réunion Zoom publiée
 *
 * Tables SQL : users, tontines, cycles, cycle_members, contributions, payments, meetings
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
$prochaine_reunion    = null;
$nb_payees            = 0;
$nb_total_seances     = 0;
$total_verse          = 0.0;

if ($cycle) {
    // Mon inscription dans le cycle
    $stmtCM = $pdo->prepare("SELECT * FROM cycle_members WHERE cycle_id = ? AND user_id = ? LIMIT 1");
    $stmtCM->execute([(int)$cycle['id'], $user_id]);
    $mon_cycle_member = $stmtCM->fetch();

    if ($mon_cycle_member) {
        // Prochaine cotisation non payée du membre
        $stmtNext = $pdo->prepare("
            SELECT 
                c.id, 
                c.numero_seance, 
                c.date_echeance, 
                c.montant_attendu,
                u_ben.nom_complet AS ben_nom
            FROM contributions c
            JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
            JOIN users u_ben ON u_ben.id = cm_ben.user_id
            WHERE c.cycle_member_id = ? 
              AND (c.statut != 'paye' AND c.montant_paye < c.montant_attendu)
            ORDER BY c.numero_seance ASC
            LIMIT 1
        ");
        $stmtNext->execute([(int)$mon_cycle_member['id']]);
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
$pot_recu        = $mon_cycle_member ? (float)$mon_cycle_member['montant_pot_recu'] : 0.0;
$pot_attendu     = $mon_cycle_member ? (float)$mon_cycle_member['montant_pot_attendu'] : 0.0;
$pct_pot         = ($pot_attendu > 0) ? round(($pot_recu / $pot_attendu) * 100) : 0;
$pct_cotisations = ($nb_total_seances > 0) ? round(($nb_payees / $nb_total_seances) * 100) : 0;
$est_en_retard   = ($prochaine_cotisation && strtotime($prochaine_cotisation['date_echeance']) < strtotime(date('Y-m-d')));

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
            <!-- Prochaine cotisation -->
            <div class="col-lg-6">
                <div class="card card-djangui hover-lift p-4 border-left-<?= $est_en_retard ? 'danger' : 'emerald' ?>">
                    <h6 class="text-uppercase text-muted small mb-3"><i class="bi bi-cash-coin me-1"></i>Prochaine Cotisation</h6>
                    <?php if ($prochaine_cotisation): ?>
                        <div class="p-3 rounded-3 box-adaptive mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold">Séance n°<?= (int)$prochaine_cotisation['numero_seance'] ?></span>
                                <span class="badge bg-<?= $est_en_retard ? 'danger' : 'primary' ?>">
                                    <?= $est_en_retard ? 'EN RETARD' : 'À payer' ?>
                                </span>
                            </div>
                            <p class="mb-1"><strong>Montant :</strong> <?= format_fcfa($prochaine_cotisation['montant_attendu']) ?></p>
                            <p class="mb-1"><strong>Date limite :</strong> <?= date('d/m/Y', strtotime($prochaine_cotisation['date_echeance'])) ?></p>
                            <p class="mb-0"><strong>Bouffeur :</strong> <?= e($prochaine_cotisation['ben_nom']) ?></p>
                        </div>
                        <a href="<?= BASE_URL ?>/member/contributions.php" class="btn btn-glow-emerald w-100">
                            <i class="bi bi-credit-card me-1"></i>Payer maintenant
                        </a>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="bi bi-check-circle display-6 text-success"></i>
                            <p class="mt-2 mb-0 fw-semibold">Toutes vos cotisations sont à jour !</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mon Pot & Rang -->
            <div class="col-lg-6">
                <div class="card-accent-djangui hover-lift p-4">
                    <h6 class="text-uppercase text-muted small mb-3"><i class="bi bi-trophy me-1"></i>Mon Pot de Tontine</h6>
                    <?php if ($mon_cycle_member && $mon_cycle_member['rang_beneficiaire']): ?>
                        <div class="text-center mb-3">
                            <span class="display-5 fw-bold">#<?= (int)$mon_cycle_member['rang_beneficiaire'] ?></span>
                            <p class="text-muted small mb-0">Mon rang de passage (bouffe)</p>
                        </div>
                        <div class="p-3 rounded-3 box-adaptive">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Reçu : <?= format_fcfa($pot_recu) ?></span>
                                <span>Attendu : <?= format_fcfa($pot_attendu) ?></span>
                            </div>
                            <div class="progress" style="height: 22px;">
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
                                <small class="text-muted">Séances payées</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded-3 box-adaptive">
                                <span class="d-block fs-4 fw-bold"><?= $pct_cotisations ?>%</span>
                                <small class="text-muted">Taux à jour</small>
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
