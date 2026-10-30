<?php
/**
 * =============================================================================
 * MEMBRE 3 : admin/contributions.php
 * =============================================================================
 * Suivi global des cotisations par la secrétaire / administratrice :
 *  - Filtrage des cotisations par séance et/ou statut (payé, en attente, en retard)
 *  - Modification dynamique du montant de la pénalité de retard du cycle actif
 *  - Suivi des reliquats et pots reçus pour chaque bénéficiaire ("bouffeur")
 *  - Indicateurs clés (KPI) de collecte du cycle
 *
 * Tables SQL : cycles, cycle_members, contributions, payments, users, tontines
 * =============================================================================
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Sécurité : réservé à la secrétaire / administrateur
verifier_admin();

$tontine_id = (int)$_SESSION['tontine_id'];

// ══════════════════════════════════════════════════════════════════════════════
// 1. RÉCUPÉRATION DU CYCLE ACTIF
// ══════════════════════════════════════════════════════════════════════════════
$stmtCycle = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? AND statut = 'actif' LIMIT 1");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

// ══════════════════════════════════════════════════════════════════════════════
// 2. TRAITEMENT POST : MODIFICATION DU MONTANT DE LA PÉNALITÉ DE RETARD
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_penalite') {
    verifier_csrf();

    if (!$cycle) {
        message_flash('danger', 'Impossible de modifier la pénalité : aucun cycle actif.');
        header('Location: ' . BASE_URL . '/admin/contributions.php');
        exit;
    }

    $nouveau_montant = filter_var($_POST['montant_penalite'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($nouveau_montant === false || $nouveau_montant < 0) {
        message_flash('danger', 'Veuillez saisir un montant de pénalité valide (supérieur ou égal à 0 FCFA).');
    } else {
        try {
            $stmtUpdatePenalite = $pdo->prepare("
                UPDATE cycles 
                SET montant_penalite_retard = ? 
                WHERE id = ? AND tontine_id = ?
            ");
            $stmtUpdatePenalite->execute([$nouveau_montant, (int)$cycle['id'], $tontine_id]);

            message_flash('success', 'Le montant de la pénalité de retard a été mis à jour avec succès : ' . format_fcfa($nouveau_montant));
        } catch (PDOException $e) {
            message_flash('danger', 'Erreur lors de la mise à jour de la pénalité : ' . $e->getMessage());
        }
    }

    header('Location: ' . BASE_URL . '/admin/contributions.php');
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. FILTRES GET & RÉCUPÉRATION DES COTISATIONS
// ══════════════════════════════════════════════════════════════════════════════
$filtre_seance = isset($_GET['seance']) && $_GET['seance'] !== '' ? (int)$_GET['seance'] : null;
$filtre_statut = isset($_GET['statut']) && $_GET['statut'] !== '' ? $_GET['statut'] : null;

$seances_liste   = [];
$cotisations     = [];
$bilan_bouffeurs = [];
$stats           = ['collecte' => 0, 'nb_payes' => 0, 'nb_retard' => 0, 'nb_attente' => 0, 'total_attendu' => 0];

if ($cycle) {
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
    $stmtSeances->execute([(int)$cycle['id']]);
    $seances_liste = $stmtSeances->fetchAll();

    // Récupération des cotisations (Membres x Séances) avec leurs paiements
    $sql = "
        SELECT 
            c.id AS contribution_id,
            c.numero_seance,
            c.date_echeance,
            c.montant_attendu,
            c.montant_paye,
            c.statut AS statut_contrib,
            u_mem.nom_complet AS mem_nom,
            u_ben.nom_complet AS ben_nom,
            p.id AS payment_id,
            p.montant_total,
            p.part_beneficiaire,
            p.part_caisse_reserve,
            p.mode_paiement,
            p.date_paiement
        FROM contributions c
        JOIN cycle_members cm_payeur ON cm_payeur.id = c.cycle_member_id
        JOIN users u_mem ON u_mem.id = cm_payeur.user_id
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_ben.user_id
        LEFT JOIN payments p ON p.contribution_id = c.id AND p.statut_paiement = 'valide'
        WHERE cm_payeur.cycle_id = ?
    ";
    $params = [(int)$cycle['id']];

    if ($filtre_seance !== null) {
        $sql .= " AND c.numero_seance = ?";
        $params[] = $filtre_seance;
    }
    $sql .= " ORDER BY c.numero_seance ASC, u_mem.nom_complet ASC";

    $stmtCotis = $pdo->prepare($sql);
    $stmtCotis->execute($params);
    $lignes = $stmtCotis->fetchAll();

    $aujourdhui = date('Y-m-d');
    foreach ($lignes as $l) {
        // Détermination du statut dynamique
        $st = 'en_attente';
        if ($l['statut_contrib'] === 'paye' || $l['montant_paye'] >= $l['montant_attendu']) {
            $st = 'paye';
        } elseif (strtotime($l['date_echeance']) < strtotime($aujourdhui)) {
            $st = 'en_retard';
        }

        if ($filtre_statut && $st !== $filtre_statut) {
            continue;
        }

        $l['statut_effectif'] = $st;
        $cotisations[] = $l;

        // Statistiques
        $stats['total_attendu'] += (float)$l['montant_attendu'];
        if ($st === 'paye') {
            $stats['nb_payes']++;
            $stats['collecte'] += (float)($l['montant_total'] ?: $l['montant_paye']);
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
    $stmtBouffeurs->execute([(int)$cycle['id']]);
    $bilan_bouffeurs = $stmtBouffeurs->fetchAll();
}

$taux_collecte = ($stats['total_attendu'] > 0)
    ? round(($stats['collecte'] / $stats['total_attendu']) * 100)
    : 0;

$titre_page = "Suivi des Cotisations";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- En-tête de page officiel Djangui -->
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
                    <small class="text-muted d-block">En attente d'échéance</small>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- CARTE : PARAMÉTRAGE DU MONTANT DE LA PÉNALITÉ DE RETARD (ADMIN)     -->
        <!-- =================================================================== -->
        <div class="card card-djangui p-4 mt-4 border-left-warning">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-1">
                        <i class="bi bi-shield-exclamation text-warning me-2"></i>Pénalité de retard appliquée au cycle
                    </h5>
                    <p class="text-muted small mb-0">
                        Cette somme est automatiquement ajoutée à la cotisation si le membre paie après l'échéance. 
                        <strong>100% de la pénalité est reversée à la Caisse de réserve</strong>.
                    </p>
                </div>
                <div class="text-end mt-2 mt-md-0">
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                        Pénalité actuelle : <?= format_fcfa($cycle['montant_penalite_retard']) ?>
                    </span>
                </div>
            </div>

            <!-- Formulaire de modification directe -->
            <form method="POST" action="<?= BASE_URL ?>/admin/contributions.php" class="row g-3 align-items-center">
                <?= champ_csrf() ?>
                <input type="hidden" name="action" value="modifier_penalite">

                <div class="col-auto">
                    <label for="montant_penalite" class="col-form-label fw-semibold small">
                        Modifier le montant de la pénalité :
                    </label>
                </div>
                <div class="col-auto">
                    <div class="input-group">
                        <input type="number" 
                               step="100" 
                               min="0" 
                               name="montant_penalite" 
                               id="montant_penalite"
                               class="form-control" 
                               value="<?= (float)$cycle['montant_penalite_retard'] ?>" 
                               required>
                        <span class="input-group-text">FCFA</span>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-glow-blue">
                        <i class="bi bi-check-circle me-1"></i>Enregistrer le nouveau montant
                    </button>
                </div>
            </form>
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
                                Séance #<?= (int)$sl['numero_seance'] ?> (Bouffeur : <?= e($sl['ben_nom']) ?> — <?= date('d/m/Y', strtotime($sl['date_echeance'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Par statut :</label>
                    <select name="statut" class="form-select">
                        <option value="">— Tous les statuts —</option>
                        <option value="paye" <?= $filtre_statut === 'paye' ? 'selected' : '' ?>>✅ Payé</option>
                        <option value="en_retard" <?= $filtre_statut === 'en_retard' ? 'selected' : '' ?>>🔴 En retard</option>
                        <option value="en_attente" <?= $filtre_statut === 'en_attente' ? 'selected' : '' ?>>⏳ En attente</option>
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
                            <th>Date d'échéance</th>
                            <th class="text-end">Total payé</th>
                            <th class="text-end">Part Bouffeur</th>
                            <th class="text-end">Part Caisse</th>
                            <th>Mode</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cotisations as $c): ?>
                        <tr>
                            <td class="fw-bold">#<?= (int)$c['numero_seance'] ?></td>
                            <td><?= e($c['mem_nom']) ?></td>
                            <td><small class="text-muted"><?= e($c['ben_nom']) ?></small></td>
                            <td><?= date('d/m/Y', strtotime($c['date_echeance'])) ?></td>
                            <td class="text-end fw-bold">
                                <?= $c['montant_total'] ? format_fcfa($c['montant_total']) : format_fcfa($c['montant_attendu']) ?>
                            </td>
                            <td class="text-end text-success">
                                <?= $c['part_beneficiaire'] ? format_fcfa($c['part_beneficiaire']) : '—' ?>
                            </td>
                            <td class="text-end <?= (float)$c['part_caisse_reserve'] > 0 ? 'text-warning fw-bold' : 'text-muted' ?>">
                                <?= (float)$c['part_caisse_reserve'] > 0 ? format_fcfa($c['part_caisse_reserve']) : '0 FCFA' ?>
                            </td>
                            <td>
                                <?php if ($c['mode_paiement']): ?>
                                    <span class="badge bg-light text-dark border"><?= e(strtoupper($c['mode_paiement'])) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badgeClass = match($c['statut_effectif']) {
                                    'paye'       => 'bg-success',
                                    'en_retard'  => 'bg-danger',
                                    'en_attente' => 'bg-warning text-dark',
                                    default      => 'bg-secondary'
                                };
                                $badgeLabel = match($c['statut_effectif']) {
                                    'paye'       => 'Payé',
                                    'en_retard'  => 'En retard',
                                    'en_attente' => 'À venir',
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
