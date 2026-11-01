<?php
// admin/contributions.php — Suivi global des cotisations et des reliquats par la secrétaire (Membre 3)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)$_SESSION['tontine_id'];

// 1. RÉCUPÉRATION DU CYCLE ACTIF
$stmtCycle = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? AND statut = 'actif' LIMIT 1");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

// 2. FILTRES GET & RÉCUPÉRATION DES COTISATIONS
$filtre_seance = isset($_GET['seance']) && $_GET['seance'] !== '' ? (int)$_GET['seance'] : null;
$filtre_statut = isset($_GET['statut']) && $_GET['statut'] !== '' ? $_GET['statut'] : null;

$seances_liste   = [];
$cotisations     = [];
$bilan_bouffeurs = [];
$stats           = ['collecte' => 0, 'nb_payes' => 0, 'nb_retard' => 0, 'nb_attente' => 0, 'total_attendu' => 0];
$seance_max_ouverte = 1;

if ($cycle) {
    $cid = (int)$cycle['id'];
    $seance_max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

    // Liste des séances distinctes pour le menu déroulant
    $stmtSeances = $pdo->prepare("
        SELECT DISTINCT c.numero_seance, c.date_echeance, u_ben.nom_complet AS ben_nom
        FROM contributions c
        JOIN cycle_members cm_payeur ON cm_payeur.id = c.cycle_member_id
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_ben.user_id
        WHERE cm_payeur.cycle_id = ?
        ORDER BY c.numero_seance ASC
    ");
    $stmtSeances->execute([$cid]);
    $seances_liste = $stmtSeances->fetchAll();

    // Récupération des cotisations avec agrégation des paiements (évite les doublons en cas de paiement partiel)
    $sql = "
        SELECT 
            c.id AS contribution_id,
            c.cycle_member_id,
            c.beneficiaire_cycle_member_id,
            c.numero_seance,
            c.date_echeance,
            c.montant_attendu,
            c.montant_paye,
            c.statut AS statut_contrib,
            u_mem.nom_complet AS mem_nom,
            u_ben.nom_complet AS ben_nom,
            p_agg.montant_total,
            p_agg.part_beneficiaire,
            p_agg.part_caisse_reserve,
            p_agg.mode_paiement,
            p_agg.date_paiement
        FROM contributions c
        JOIN cycle_members cm_payeur ON cm_payeur.id = c.cycle_member_id
        JOIN users u_mem ON u_mem.id = cm_payeur.user_id
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_ben.user_id
        LEFT JOIN (
            SELECT contribution_id,
                   SUM(montant_total) AS montant_total,
                   SUM(part_beneficiaire) AS part_beneficiaire,
                   SUM(part_caisse_reserve) AS part_caisse_reserve,
                   MAX(mode_paiement) AS mode_paiement,
                   MAX(date_paiement) AS date_paiement
            FROM payments
            WHERE statut_paiement = 'valide'
            GROUP BY contribution_id
        ) p_agg ON p_agg.contribution_id = c.id
        WHERE cm_payeur.cycle_id = ?
    ";
    $params = [$cid];

    if ($filtre_seance !== null) {
        $sql .= " AND c.numero_seance = ?";
        $params[] = $filtre_seance;
    }
    $sql .= " ORDER BY c.numero_seance ASC, u_mem.nom_complet ASC";

    $stmtCotis = $pdo->prepare($sql);
    $stmtCotis->execute($params);
    $lignes = $stmtCotis->fetchAll();

    $aujourdhui = date_actuelle('Y-m-d');
    foreach ($lignes as $l) {
        $st = 'en_attente';
        if ($l['statut_contrib'] === 'paye' || (float)$l['montant_paye'] >= (float)$l['montant_attendu']) {
            $st = 'paye';
        } elseif ((int)$l['cycle_member_id'] !== (int)$l['beneficiaire_cycle_member_id'] && strtotime($l['date_echeance']) < strtotime($aujourdhui)) {
            $st = 'en_retard';
        } elseif ((float)$l['montant_paye'] > 0) {
            $st = 'partiel';
        }

        if ($filtre_statut && $st !== $filtre_statut) {
            continue;
        }

        $l['statut_effectif'] = $st;
        $cotisations[] = $l;

        $stats['total_attendu'] += (float)$l['montant_attendu'];
        $stats['collecte']      += (float)$l['montant_paye'];
        if ($st === 'paye') {
            $stats['nb_payes']++;
        } elseif ($st === 'en_retard') {
            $stats['nb_retard']++;
        } else {
            $stats['nb_attente']++;
        }
    }

    // Suivi des pots et reliquats pour chaque bouffeur
    $stmtBouffeurs = $pdo->prepare("
        SELECT cm.rang_beneficiaire, cm.montant_pot_attendu, cm.montant_pot_recu, u.nom_complet
        FROM cycle_members cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.cycle_id = ?
        ORDER BY cm.rang_beneficiaire ASC
    ");
    $stmtBouffeurs->execute([$cid]);
    $bilan_bouffeurs = $stmtBouffeurs->fetchAll();
}

$taux_collecte = ($stats['total_attendu'] > 0)
    ? round(($stats['collecte'] / $stats['total_attendu']) * 100)
    : 0;

$pct_penalite_cycle = ($cycle && (float)$cycle['montant_cotisation'] > 0)
    ? round(((float)$cycle['montant_penalite_retard'] / (float)$cycle['montant_cotisation']) * 100, 1)
    : 0;

$titre_page = "Suivi des Cotisations";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <h2 class="fw-bold mb-1"><i class="bi bi-clipboard-data me-2"></i>Suivi des Cotisations</h2>
        <p class="text-muted mb-0 small">
            Supervision de la collecte — Cycle <?= $cycle ? '#' . (int)$cycle['numero_cycle'] . ' (' . e($cycle['titre']) . ')' : '—' ?>
        </p>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle): ?>
        <div class="card card-djangui hover-lift p-4 text-center mt-4">
            <i class="bi bi-info-circle display-4 text-muted"></i>
            <h5 class="mt-3">Aucun cycle actif</h5>
            <p class="text-muted">Veuillez activer un cycle dans la section « Cycles » pour suivre les cotisations.</p>
        </div>
    <?php else: ?>

        <!-- KPIs de la collecte -->
        <div class="row g-4 mt-2">
            <div class="col-md-3">
                <div class="card card-djangui hover-lift p-3 text-center border-left-emerald">
                    <span class="display-6 fw-bold text-success"><?= $taux_collecte ?>%</span>
                    <small class="text-muted d-block">Taux de recouvrement</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-djangui hover-lift p-3 text-center border-left-navy">
                    <span class="fs-4 fw-bold text-primary"><?= format_fcfa($stats['collecte']) ?></span>
                    <small class="text-muted d-block">Collecté sur <?= format_fcfa($stats['total_attendu']) ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-djangui hover-lift p-3 text-center border-left-warning">
                    <span class="display-6 fw-bold text-warning"><?= $stats['nb_retard'] ?></span>
                    <small class="text-muted d-block">Cotisations en retard</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-djangui hover-lift p-3 text-center border-left-danger">
                    <span class="display-6 fw-bold text-secondary"><?= $stats['nb_attente'] ?></span>
                    <small class="text-muted d-block">En attente / Partiel</small>
                </div>
            </div>
        </div>

        <!-- Rappel du taux de pénalité (modifiable dans Caisse & Pénalités — Point 2) -->
        <div class="card card-djangui p-3 mt-4 border-left-warning">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="small">
                    <i class="bi bi-shield-exclamation text-warning me-1"></i>
                    <strong>Pénalité de retard du cycle :</strong> <?= $pct_penalite_cycle ?> % (<?= format_fcfa($cycle['montant_penalite_retard']) ?> par séance en retard) ·
                    <strong>Séance actuellement ouverte :</strong> Séance #<?= $seance_max_ouverte ?>
                </div>
                <a href="<?= BASE_URL ?>/admin/penalties.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-sliders me-1"></i>Modifier le % dans Caisse & Pénalités
                </a>
            </div>
        </div>

        <!-- Filtres des cotisations -->
        <div class="card card-djangui p-4 mt-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-funnel me-1"></i>Filtrer les cotisations</h6>
            <form method="GET" action="<?= BASE_URL ?>/admin/contributions.php" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Par séance :</label>
                    <select name="seance" class="form-select">
                        <option value="">— Toutes les séances —</option>
                        <?php foreach ($seances_liste as $sl): ?>
                            <option value="<?= (int)$sl['numero_seance'] ?>" <?= $filtre_seance === (int)$sl['numero_seance'] ? 'selected' : '' ?>>
                                Séance #<?= (int)$sl['numero_seance'] ?> (<?= e($sl['ben_nom']) ?> — <?= e(formater_periode_seance($cycle['date_debut'], (int)$sl['numero_seance'], $cycle['frequence'], $sl['date_echeance'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Par statut :</label>
                    <select name="statut" class="form-select">
                        <option value="">— Tous les statuts —</option>
                        <option value="paye" <?= $filtre_statut === 'paye' ? 'selected' : '' ?>>Payé / Compensé</option>
                        <option value="partiel" <?= $filtre_statut === 'partiel' ? 'selected' : '' ?>>Partiel</option>
                        <option value="en_retard" <?= $filtre_statut === 'en_retard' ? 'selected' : '' ?>>En retard</option>
                        <option value="en_attente" <?= $filtre_statut === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-glow-blue me-2"><i class="bi bi-search me-1"></i>Filtrer</button>
                    <a href="<?= BASE_URL ?>/admin/contributions.php" class="btn btn-outline-secondary">Réinitialiser</a>
                </div>
            </form>
        </div>

        <!-- Tableau détaillé des cotisations -->
        <div class="card card-djangui p-4 mt-4">
            <h5 class="fw-bold mb-3">
                <i class="bi bi-table me-2"></i>Détail des cotisations 
                <span class="badge bg-secondary ms-2"><?= count($cotisations) ?> ligne(s)</span>
            </h5>
            <div class="table-responsive">
                <table class="table table-djangui align-middle">
                    <thead>
                        <tr>
                            <th>Séance</th>
                            <th>Membre cotisant</th>
                            <th>Bouffeur du tour</th>
                            <th>Période de la séance</th>
                            <th class="text-end">Versé / Attendu</th>
                            <th class="text-end">Part Bouffeur</th>
                            <th class="text-end">Part Caisse</th>
                            <th>Mode</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cotisations as $c): ?>
                        <?php
                            $est_propre_bouffeur = ((int)$c['cycle_member_id'] === (int)$c['beneficiaire_cycle_member_id']);
                            $periode_txt = formater_periode_seance($cycle['date_debut'], (int)$c['numero_seance'], $cycle['frequence'], $c['date_echeance']);
                        ?>
                        <tr>
                            <td class="fw-bold">#<?= (int)$c['numero_seance'] ?></td>
                            <td>
                                <?= e($c['mem_nom']) ?>
                                <?php if ($est_propre_bouffeur): ?>
                                    <span class="badge bg-info-subtle text-info border ms-1">Bouffeur</span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= e($c['ben_nom']) ?></small></td>
                            <td class="small"><?= e($periode_txt) ?></td>
                            <td class="text-end fw-bold">
                                <?= format_fcfa($c['montant_paye']) ?> / <?= format_fcfa($c['montant_attendu']) ?>
                            </td>
                            <td class="text-end text-success">
                                <?= (float)$c['part_beneficiaire'] > 0 ? format_fcfa($c['part_beneficiaire']) : format_fcfa($c['montant_paye']) ?>
                            </td>
                            <td class="text-end <?= (float)$c['part_caisse_reserve'] > 0 ? 'text-warning fw-bold' : 'text-muted' ?>">
                                <?= (float)$c['part_caisse_reserve'] > 0 ? format_fcfa($c['part_caisse_reserve']) : '0 FCFA' ?>
                            </td>
                            <td>
                                <?php if ($est_propre_bouffeur && $c['statut_effectif'] === 'paye'): ?>
                                    <span class="badge bg-info text-dark">SUR POT</span>
                                <?php elseif ($c['mode_paiement']): ?>
                                    <span class="badge bg-light text-dark border"><?= e(strtoupper($c['mode_paiement'])) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badgeClass = match($c['statut_effectif']) {
                                    'paye'       => 'bg-success',
                                    'partiel'    => 'bg-warning text-dark',
                                    'en_retard'  => 'bg-danger',
                                    'en_attente' => 'bg-secondary',
                                    default      => 'bg-secondary'
                                };
                                $badgeLabel = match($c['statut_effectif']) {
                                    'paye'       => $est_propre_bouffeur ? 'Déduit du pot' : 'Payé',
                                    'partiel'    => 'Partiel',
                                    'en_retard'  => 'En retard',
                                    'en_attente' => ((int)$c['numero_seance'] > $seance_max_ouverte) ? 'Verrouillée' : 'À venir',
                                    default      => $c['statut_effectif']
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Reliquats et suivi des pots par bouffeur -->
        <div class="card card-djangui p-4 mt-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2"></i>Suivi des pots par bouffeur (Reliquats)</h5>
            <div class="row g-3">
                <?php foreach ($bilan_bouffeurs as $b): 
                    $reliquat = max(0, (float)$b['montant_pot_attendu'] - (float)$b['montant_pot_recu']);
                    $pct = ((float)$b['montant_pot_attendu'] > 0) ? round(((float)$b['montant_pot_recu'] / (float)$b['montant_pot_attendu']) * 100) : 0;
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="p-3 rounded-3 box-adaptive">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong>Tour #<?= (int)$b['rang_beneficiaire'] ?> — <?= e($b['nom_complet']) ?></strong>
                            <span class="badge <?= $pct >= 100 ? 'bg-success' : 'bg-primary' ?>"><?= $pct ?>%</span>
                        </div>
                        <div class="progress mb-2" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: <?= min($pct, 100) ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span>Reçu : <?= format_fcfa($b['montant_pot_recu']) ?></span>
                            <span class="<?= $reliquat > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                <?= $reliquat > 0 ? 'Reste : ' . format_fcfa($reliquat) : 'Pot complet' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
