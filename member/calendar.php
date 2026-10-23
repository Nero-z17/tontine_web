<?php
// member/calendar.php — Calendrier des échéances, tours de bouffe et réunions (Membre 2)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. SÉCURITÉ : Accessible à tout membre connecté (ainsi qu'à la secrétaire en mode cotisant)
verifier_connexion();

$user_id    = (int)$_SESSION['user_id'];
$tontine_id = (int)$_SESSION['tontine_id'];

// 2. RÉCUPÉRATION DE TOUS LES CYCLES DE LA TONTINE (Actif et passés)
$stmtCycles = $pdo->prepare("
    SELECT * FROM cycles 
    WHERE tontine_id = ? 
    ORDER BY (statut = 'actif') DESC, numero_cycle DESC, id DESC
");
$stmtCycles->execute([$tontine_id]);
$cycles = $stmtCycles->fetchAll();

// Sélection du cycle à afficher (par défaut : le cycle actif ou le plus récent)
$cycle_id_selectionne = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$cycle_courant = null;

foreach ($cycles as $c) {
    if ($cycle_id_selectionne > 0 && (int)$c['id'] === $cycle_id_selectionne) {
        $cycle_courant = $c;
        break;
    }
    if ($cycle_id_selectionne === 0 && $c['statut'] === 'actif') {
        $cycle_courant = $c;
        break;
    }
}
if (!$cycle_courant && !empty($cycles)) {
    $cycle_courant = $cycles[0];
}

// 3. FICHE DE PARTICIPATION DU MEMBRE CONNECTÉ DANS CE CYCLE
$ma_participation = null;
$seances = [];
$mes_cotisations_par_seance = [];
$prochaine_echeance = null;
$ma_date_bouffe = null;

if ($cycle_courant) {
    $cycle_id = (int)$cycle_courant['id'];

    $stmtMe = $pdo->prepare("
        SELECT * FROM cycle_members 
        WHERE cycle_id = ? AND user_id = ? 
        LIMIT 1
    ");
    $stmtMe->execute([$cycle_id, $user_id]);
    $ma_participation = $stmtMe->fetch();

    // 4. RÉCUPÉRATION DES SÉANCES DU CYCLE AVEC LE BOUFFEUR ET LE TOTAL COLLECTÉ PAR SÉANCE
    $stmtSeances = $pdo->prepare("
        SELECT 
            c.numero_seance,
            c.date_echeance,
            c.beneficiaire_cycle_member_id,
            cm_ben.rang_beneficiaire,
            cm_ben.montant_pot_attendu,
            cm_ben.montant_pot_recu,
            cm_ben.pot_totalement_recu,
            u_ben.id AS beneficiaire_user_id,
            u_ben.nom_complet AS beneficiaire_nom,
            u_ben.canal_paiement AS beneficiaire_canal,
            COUNT(c.id) AS nb_cotisants,
            SUM(c.montant_attendu) AS total_attendu_seance,
            SUM(c.montant_paye) AS total_paye_seance,
            SUM(CASE WHEN c.statut = 'paye' THEN 1 ELSE 0 END) AS nb_a_jour
        FROM contributions c
        LEFT JOIN cycle_members cm_ben ON c.beneficiaire_cycle_member_id = cm_ben.id
        LEFT JOIN users u_ben ON cm_ben.user_id = u_ben.id
        WHERE c.cycle_id = ?
        GROUP BY 
            c.numero_seance,
            c.date_echeance,
            c.beneficiaire_cycle_member_id,
            cm_ben.rang_beneficiaire,
            cm_ben.montant_pot_attendu,
            cm_ben.montant_pot_recu,
            cm_ben.pot_totalement_recu,
            u_ben.id,
            u_ben.nom_complet,
            u_ben.canal_paiement
        ORDER BY c.numero_seance ASC
    ");
    $stmtSeances->execute([$cycle_id]);
    $seances = $stmtSeances->fetchAll();

    // 5. RÉCUPÉRATION DE L'ÉTAT PERSONNEL DU MEMBRE SUR CHAQUE SÉANCE
    if ($ma_participation) {
        $stmtMesCotis = $pdo->prepare("
            SELECT id, numero_seance, date_echeance, montant_attendu, montant_paye, statut
            FROM contributions
            WHERE cycle_id = ? AND cycle_member_id = ?
            ORDER BY numero_seance ASC
        ");
        $stmtMesCotis->execute([$cycle_id, (int)$ma_participation['id']]);
        foreach ($stmtMesCotis->fetchAll() as $ligne) {
            $num = (int)$ligne['numero_seance'];
            $mes_cotisations_par_seance[$num] = $ligne;

            // Repérer la prochaine échéance personnelle non soldée
            if ($prochaine_echeance === null && $ligne['statut'] !== 'paye') {
                $prochaine_echeance = $ligne;
            }
        }
    }

    // Repérer la séance où le membre connecté est le bénéficiaire ("bouffeur")
    foreach ($seances as $s) {
        if ((int)($s['beneficiaire_user_id'] ?? 0) === $user_id) {
            $ma_date_bouffe = $s;
            break;
        }
    }
}

// 6. RÉCUPÉRATION DES PROCHAINES RÉUNIONS PUBLIÉES DE LA TONTINE
$stmtMeetings = $pdo->prepare("
    SELECT * FROM meetings 
    WHERE tontine_id = ? AND statut = 'publiee' AND date_heure >= NOW()
    ORDER BY date_heure ASC
    LIMIT 3
");
$stmtMeetings->execute([$tontine_id]);
$prochaines_reunions = $stmtMeetings->fetchAll();

$titre_page = "Calendrier des échéances";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- BANDEAU D'EN-TÊTE ADAPTATIF (CLAIR / SOMBRE) -->
<div class="page-header-dark">
    <div class="container">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                    <i class="bi bi-calendar3-week me-1"></i>PLANNING OFFICIEL DE LA TONTINE
                </span>
                <h1 class="fw-bold h2 mb-1">Calendrier des échéances & Tours de bouffe</h1>
                <p class="text-muted small mb-0">
                    Consultez les dates limites de cotisation, le bénéficiaire de chaque séance et l'avancement des pots.
                </p>
            </div>

            <!-- Sélecteur de cycle (permet aussi de consulter les cycles archivés) -->
            <div class="col-lg-5">
                <?php if (!empty($cycles)): ?>
                    <form method="GET" action="calendar.php" class="d-flex gap-2 justify-content-lg-end">
                        <select name="cycle_id" class="form-select form-select-sm" style="max-width: 280px;" onchange="this.form.submit()">
                            <?php foreach ($cycles as $c): ?>
                                <?php
                                    $id_c = (int)$c['id'];
                                    $label_statut = match ($c['statut']) {
                                        'actif'     => '🟢 Actif',
                                        'termine'   => '⚪ Terminé',
                                        default     => '🟡 Brouillon',
                                    };
                                ?>
                                <option value="<?= $id_c ?>" <?= ($cycle_courant && (int)$cycle_courant['id'] === $id_c) ? 'selected' : '' ?>>
                                    Cycle #<?= e($c['numero_cycle']) ?> — <?= format_fcfa($c['montant_cotisation']) ?> (<?= $label_statut ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-sm btn-glow-blue">Voir</button></noscript>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle_courant): ?>
        <div class="card card-djangui p-5 text-center">
            <i class="bi bi-calendar-x fs-1 text-muted mb-3"></i>
            <h4 class="fw-bold">Aucun cycle configuré pour le moment</h4>
            <p class="text-muted small mb-0">
                Dès que la secrétaire aura activé le cycle et lancé le tirage au sort, tout le calendrier des séances s'affichera ici.
            </p>
        </div>
    <?php else: ?>

        <!-- 1. CARTES RÉCAPITULATIVES PERSONNELLES EN HAUT DE PAGE -->
        <div class="row g-4 mb-4">
            <!-- Carte A : Paramètres du cycle -->
            <div class="col-md-4">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-navy">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="text-muted small fw-semibold text-uppercase">Cycle #<?= e($cycle_courant['numero_cycle']) ?></span>
                        <span class="badge bg-primary-subtle text-primary rounded-pill px-3">
                            <?= e(ucfirst($cycle_courant['frequence'] ?? 'mensuel')) ?>
                        </span>
                    </div>
                    <h4 class="fw-bold mb-1"><?= format_fcfa($cycle_courant['montant_cotisation']) ?> <span class="fs-6 fw-normal text-muted">/ séance</span></h4>
                    <div class="small text-muted mt-2">
                        <i class="bi bi-calendar-range me-1 text-primary"></i>
                        Du <strong><?= date('d/m/Y', strtotime($cycle_courant['date_debut'])) ?></strong>
                        <?php if (!empty($cycle_courant['date_fin'])): ?>
                            au <strong><?= date('d/m/Y', strtotime($cycle_courant['date_fin'])) ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Carte B : Ma prochaine échéance à payer -->
            <div class="col-md-4">
                <div class="card card-djangui hover-lift h-100 p-4 <?= $prochaine_echeance && $prochaine_echeance['statut'] === 'en_retard' ? 'border-left-danger' : 'border-left-warning' ?>">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="text-muted small fw-semibold text-uppercase">Ma prochaine cotisation</span>
                        <?php if ($prochaine_echeance): ?>
                            <?php if ($prochaine_echeance['statut'] === 'en_retard'): ?>
                                <span class="badge bg-danger rounded-pill">En retard</span>
                            <?php elseif ($prochaine_echeance['statut'] === 'partiel'): ?>
                                <span class="badge bg-warning text-dark rounded-pill">Partiel</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark rounded-pill">À venir</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-success rounded-pill">100 % à jour</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($prochaine_echeance): ?>
                        <?php $reste_a_payer = max(0, (float)$prochaine_echeance['montant_attendu'] - (float)$prochaine_echeance['montant_paye']); ?>
                        <h4 class="fw-bold mb-1"><?= format_fcfa($reste_a_payer) ?></h4>
                        <div class="small text-muted mb-3">
                            Séance n°<?= (int)$prochaine_echeance['numero_seance'] ?> — Limite :
                            <strong class="text-adaptive"><?= date('d/m/Y', strtotime($prochaine_echeance['date_echeance'])) ?></strong>
                        </div>
                        <a href="<?= BASE_URL ?>/member/contributions.php" class="btn btn-sm btn-glow-blue w-100 mt-auto">
                            <i class="bi bi-credit-card-2-front me-1"></i>Régler ma cotisation
                        </a>
                    <?php else: ?>
                        <h5 class="fw-bold text-success mb-1">Toutes vos séances sont réglées</h5>
                        <p class="small text-muted mb-0">Aucune cotisation en attente sur ce cycle.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Carte C : Ma date de bouffe (Mon tour de réception du pot) -->
            <div class="col-md-4">
                <div class="card-accent-djangui hover-lift h-100 p-4">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="small fw-bold text-primary text-uppercase">
                            <i class="bi bi-trophy-fill me-1"></i>Mon tour de bouffe
                        </span>
                        <?php if ($ma_participation && !empty($ma_participation['rang_beneficiaire'])): ?>
                            <span class="badge bg-success rounded-pill px-3">Rang n°<?= (int)$ma_participation['rang_beneficiaire'] ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($ma_date_bouffe): ?>
                        <h4 class="fw-bold mb-1"><?= date('d/m/Y', strtotime($ma_date_bouffe['date_echeance'])) ?></h4>
                        <div class="small text-muted mb-2">
                            Séance n°<?= (int)$ma_date_bouffe['numero_seance'] ?> — Pot :
                            <strong><?= format_fcfa($ma_date_bouffe['montant_pot_attendu']) ?></strong>
                        </div>
                        <a href="<?= BASE_URL ?>/member/draw-rank.php" class="btn btn-sm btn-glow-emerald w-100 mt-auto">
                            <i class="bi bi-graph-up-arrow me-1"></i>Suivre la réception de mon pot
                        </a>
                    <?php else: ?>
                        <h5 class="fw-bold mb-1">Tirage en attente</h5>
                        <p class="small text-muted mb-0">
                            Votre date de réception du pot apparaîtra dès que le tirage au sort du cycle sera effectué.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 2. CHRONOLOGIE DES SÉANCES DU CYCLE (TABLEAU SUR PC & CARTES SUR MOBILE) -->
        <div class="card card-djangui p-4 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
                <div>
                    <h5 class="fw-bold mb-1"><i class="bi bi-list-ol text-primary me-2"></i>Calendrier détaillé séance par séance</h5>
                    <p class="text-muted small mb-0">
                        Tout remboursement en retard sur une séance passée est automatiquement reversé à l'ancien bouffeur de cette séance.
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Payé</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">Partiel</span>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">En retard</span>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">À venir</span>
                </div>
            </div>

            <?php if (empty($seances)): ?>
                <div class="p-4 rounded-3 box-adaptive text-center">
                    <i class="bi bi-shuffle fs-2 text-primary mb-2 d-block"></i>
                    <h6 class="fw-bold">Les séances de ce cycle n'ont pas encore été générées</h6>
                    <p class="text-muted small mb-0">
                        La secrétaire doit lancer le tirage au sort pour générer automatiquement toutes les dates d'échéance et l'ordre des bénéficiaires.
                    </p>
                </div>
            <?php else: ?>

                <!-- VUE DESKTOP (Tableau large sur PC >= 992px) -->
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-djangui align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Séance</th>
                                <th>Date d'échéance</th>
                                <th>Bénéficiaire du tour (« Bouffeur »)</th>
                                <th>Avancement du Pot</th>
                                <th>Ma Cotisation</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seances as $seance): ?>
                                <?php
                                    $num = (int)$seance['numero_seance'];
                                    $est_mon_tour = ((int)($seance['beneficiaire_user_id'] ?? 0) === $user_id);
                                    $ma_cotis = $mes_cotisations_par_seance[$num] ?? null;

                                    $attendu_seance = (float)($seance['total_attendu_seance'] ?? 0);
                                    $paye_seance    = (float)($seance['total_paye_seance'] ?? 0);
                                    $pourcentage    = $attendu_seance > 0 ? min(100, round(($paye_seance / $attendu_seance) * 100)) : 0;

                                    $date_ts = strtotime($seance['date_echeance']);
                                    $est_passee = ($date_ts < strtotime('today'));
                                ?>
                                <tr class="<?= $est_mon_tour ? 'table-primary bg-opacity-10' : '' ?>">
                                    <td>
                                        <span class="fw-bold">Séance #<?= $num ?></span>
                                        <?php if ($est_mon_tour): ?>
                                            <span class="badge bg-success ms-1">Mon Tour</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">
                                            <i class="bi bi-calendar-event text-primary me-1"></i><?= date('d/m/Y', $date_ts) ?>
                                        </div>
                                        <span class="small text-muted">
                                            <?= $est_passee ? 'Échéance passée' : 'Échéance à venir' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold d-flex align-items-center gap-2">
                                            <i class="bi bi-person-check-fill text-primary"></i>
                                            <span><?= e($seance['beneficiaire_nom'] ?? 'Non attribué') ?></span>
                                        </div>
                                        <span class="small text-muted">
                                            Rang n°<?= (int)($seance['rang_beneficiaire'] ?? $num) ?>
                                            • Réception via <?= strtoupper(e($seance['beneficiaire_canal'] ?? 'MOMO')) ?>
                                        </span>
                                    </td>
                                    <td style="min-width: 210px;">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="fw-semibold"><?= format_fcfa($paye_seance) ?></span>
                                            <span class="text-muted">/ <?= format_fcfa($attendu_seance) ?></span>
                                        </div>
                                        <div class="progress" style="height: 7px;">
                                            <div class="progress-bar <?= $pourcentage >= 100 ? 'bg-success' : 'bg-primary' ?>"
                                                 style="width: <?= $pourcentage ?>%"></div>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            <?= (int)$seance['nb_a_jour'] ?> / <?= (int)$seance['nb_cotisants'] ?> membres à jour (<?= $pourcentage ?> %)
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($ma_cotis): ?>
                                            <?php
                                                $badge_cotis = match ($ma_cotis['statut']) {
                                                    'paye'      => '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Payé</span>',
                                                    'partiel'   => '<span class="badge bg-warning text-dark"><i class="bi bi-pie-chart me-1"></i>Partiel</span>',
                                                    'en_retard' => '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>En retard</span>',
                                                    default     => '<span class="badge bg-secondary"><i class="bi bi-hourglass-split me-1"></i>En attente</span>',
                                                };
                                            ?>
                                            <?= $badge_cotis ?>
                                            <div class="small text-muted mt-1">
                                                <?= format_fcfa($ma_cotis['montant_paye']) ?> / <?= format_fcfa($ma_cotis['montant_attendu']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">Non inscrit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($ma_cotis && $ma_cotis['statut'] !== 'paye'): ?>
                                            <a href="<?= BASE_URL ?>/member/contributions.php?seance=<?= $num ?>" class="btn btn-sm btn-glow-emerald">
                                                <i class="bi bi-wallet2 me-1"></i>Cotiser
                                            </a>
                                        <?php elseif ($ma_cotis && $ma_cotis['statut'] === 'paye'): ?>
                                            <a href="<?= BASE_URL ?>/member/payments.php" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-receipt me-1"></i>Reçu
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- VUE MOBILE (Cartes empilées ergonomiques sur Smartphone < 992px) -->
                <div class="d-lg-none d-flex flex-column gap-3">
                    <?php foreach ($seances as $seance): ?>
                        <?php
                            $num = (int)$seance['numero_seance'];
                            $est_mon_tour = ((int)($seance['beneficiaire_user_id'] ?? 0) === $user_id);
                            $ma_cotis = $mes_cotisations_par_seance[$num] ?? null;

                            $attendu_seance = (float)($seance['total_attendu_seance'] ?? 0);
                            $paye_seance    = (float)($seance['total_paye_seance'] ?? 0);
                            $pourcentage    = $attendu_seance > 0 ? min(100, round(($paye_seance / $attendu_seance) * 100)) : 0;

                            $bordure_mobile = 'border-left-navy';
                            if ($ma_cotis) {
                                $bordure_mobile = match ($ma_cotis['statut']) {
                                    'paye'      => 'border-left-emerald',
                                    'en_retard' => 'border-left-danger',
                                    'partiel'   => 'border-left-warning',
                                    default     => 'border-left-navy',
                                };
                            }
                        ?>
                        <div class="p-3 rounded-3 box-adaptive <?= $bordure_mobile ?>">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="fw-bold">Séance #<?= $num ?></span>
                                    <?php if ($est_mon_tour): ?>
                                        <span class="badge bg-success ms-1">Mon Tour</span>
                                    <?php endif; ?>
                                </div>
                                <span class="small fw-semibold text-primary">
                                    <i class="bi bi-calendar-event me-1"></i><?= date('d/m/Y', strtotime($seance['date_echeance'])) ?>
                                </span>
                            </div>

                            <div class="small mb-2">
                                <span class="text-muted">Bouffeur du tour :</span>
                                <strong class="ms-1"><?= e($seance['beneficiaire_nom'] ?? 'Non attribué') ?></strong>
                                <span class="badge bg-primary-subtle text-primary ms-1">Rang #<?= (int)($seance['rang_beneficiaire'] ?? $num) ?></span>
                            </div>

                            <div class="mb-2">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-muted">Pot collecté :</span>
                                    <span class="fw-semibold"><?= format_fcfa($paye_seance) ?> / <?= format_fcfa($attendu_seance) ?></span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar <?= $pourcentage >= 100 ? 'bg-success' : 'bg-primary' ?>"
                                         style="width: <?= $pourcentage ?>%"></div>
                                </div>
                            </div>

                            <?php if ($ma_cotis): ?>
                                <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
                                    <div class="small">
                                        <span class="text-muted">Ma part :</span>
                                        <strong><?= format_fcfa($ma_cotis['montant_paye']) ?></strong> / <?= format_fcfa($ma_cotis['montant_attendu']) ?>
                                    </div>
                                    <?php if ($ma_cotis['statut'] !== 'paye'): ?>
                                        <a href="<?= BASE_URL ?>/member/contributions.php?seance=<?= $num ?>" class="btn btn-sm btn-glow-emerald py-1 px-3">
                                            Cotiser
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>À jour</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>

        <!-- 3. PROCHAINES RÉUNIONS ZOOM PROGRAMMÉES -->
        <div class="card card-djangui p-4 border-left-navy">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-camera-video-fill text-info me-2"></i>Prochaines réunions Zoom à l'agenda
                </h5>
                <a href="<?= BASE_URL ?>/member/meetings.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                    Toutes les réunions
                </a>
            </div>

            <?php if (empty($prochaines_reunions)): ?>
                <p class="text-muted small mb-0">Aucune réunion en ligne n'est programmée à venir pour le moment.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($prochaines_reunions as $reunion): ?>
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 box-adaptive h-100 d-flex flex-column">
                                <div class="small fw-bold text-primary mb-1">
                                    <i class="bi bi-clock me-1"></i><?= date('d/m/Y à H:i', strtotime($reunion['date_heure'])) ?>
                                </div>
                                <h6 class="fw-bold mb-1"><?= e($reunion['titre']) ?></h6>
                                <p class="text-muted small mb-3"><?= e($reunion['description'] ?? '') ?></p>
                                <?php if (!empty($reunion['lien_zoom'])): ?>
                                    <a href="<?= e($reunion['lien_zoom']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-glow-blue mt-auto">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Rejoindre sur Zoom
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>