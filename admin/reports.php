<?php
// admin/reports.php — Bilans financiers et historique multi-cycles (Membre 2)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)$_SESSION['tontine_id'];

// 1. Récupérer les infos de la tontine (dont le solde de la caisse de réserve)
$stmtTontine = $pdo->prepare("SELECT * FROM tontines WHERE id = ? LIMIT 1");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

// 2. Récupérer tous les cycles de la tontine pour le sélecteur d'historique
$stmtCycles = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? ORDER BY numero_cycle DESC");
$stmtCycles->execute([$tontine_id]);
$cycles = $stmtCycles->fetchAll();

// Déterminer le cycle sélectionné (?cycle_id=...)
$cycle_id_choisi = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$cycle_selectionne = null;

foreach ($cycles as $c) {
    if ($cycle_id_choisi === (int)$c['id']) {
        $cycle_selectionne = $c;
        break;
    }
}
if (!$cycle_selectionne && !empty($cycles)) {
    $cycle_selectionne = $cycles[0];
}

$bilan_seances = [];
$bilan_membres = [];
$stats_cycle = [
    'attendu'          => 0.0,
    'collecte'         => 0.0,
    'reliquat'         => 0.0,
    'penalites_reglees'=> 0.0,
];

if ($cycle_selectionne) {
    $cid = (int)$cycle_selectionne['id'];

    // Statistiques financières globales du cycle sélectionné
    $stmtStats = $pdo->prepare("
        SELECT COALESCE(SUM(co.montant_attendu), 0) AS total_attendu,
               COALESCE(SUM(co.montant_paye), 0) AS total_collecte
        FROM contributions co
        INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
        WHERE cm.cycle_id = ?
    ");
    $stmtStats->execute([$cid]);
    $rowStats = $stmtStats->fetch();

    $stats_cycle['attendu']  = (float)($rowStats['total_attendu'] ?? 0);
    $stats_cycle['collecte'] = (float)($rowStats['total_collecte'] ?? 0);
    $stats_cycle['reliquat'] = max(0, $stats_cycle['attendu'] - $stats_cycle['collecte']);

    $stmtPen = $pdo->prepare("
        SELECT COALESCE(SUM(p.montant), 0)
        FROM penalties p
        JOIN cycle_members cm ON cm.id = p.cycle_member_id
        WHERE cm.cycle_id = ? AND p.statut = 'regle'
    ");
    $stmtPen->execute([$cid]);
    $stats_cycle['penalites_reglees'] = (float)$stmtPen->fetchColumn();

    // Bilan détaillé par séance (qui a bouffé, combien a été collecté, combien reste dû à l'ancien bouffeur)
    $stmtSeances = $pdo->prepare("
        SELECT co.numero_seance,
               co.date_echeance,
               u.nom_complet AS nom_bouffeur,
               u.code_public AS code_bouffeur,
               SUM(co.montant_attendu) AS pot_attendu,
               SUM(co.montant_paye) AS pot_collecte,
               SUM(CASE WHEN co.statut = 'paye' THEN 1 ELSE 0 END) AS nb_payes,
               COUNT(*) AS nb_total
        FROM contributions co
        JOIN cycle_members cm_ben ON cm_ben.id = co.beneficiaire_cycle_member_id
        JOIN users u ON u.id = cm_ben.user_id
        WHERE cm_ben.cycle_id = ?
        GROUP BY co.numero_seance, co.date_echeance, u.nom_complet, u.code_public
        ORDER BY co.numero_seance ASC
    ");
    $stmtSeances->execute([$cid]);
    $bilan_seances = $stmtSeances->fetchAll();

    // Bilan détaillé par membre participant au cycle
    $stmtMembresBilan = $pdo->prepare("
        SELECT cm.rang_beneficiaire,
               cm.montant_pot_attendu,
               cm.montant_pot_recu,
               u.nom_complet,
               u.code_public,
               u.role,
               COALESCE(SUM(co.montant_attendu), 0) AS total_du_membre,
               COALESCE(SUM(co.montant_paye), 0) AS total_paye_membre,
               (SELECT COALESCE(SUM(p.montant), 0) FROM penalties p WHERE p.cycle_member_id = cm.id AND p.statut != 'annule') AS total_penalites
        FROM cycle_members cm
        JOIN users u ON u.id = cm.user_id
        LEFT JOIN contributions co ON co.cycle_member_id = cm.id
        WHERE cm.cycle_id = ?
        GROUP BY cm.id, cm.rang_beneficiaire, cm.montant_pot_attendu, cm.montant_pot_recu, u.nom_complet, u.code_public, u.role
        ORDER BY cm.rang_beneficiaire IS NULL ASC, cm.rang_beneficiaire ASC
    ");
    $stmtMembresBilan->execute([$cid]);
    $bilan_membres = $stmtMembresBilan->fetchAll();
}

$titre_page = "Bilans & Historique Multi-Cycles";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                    <i class="bi bi-journal-check me-1"></i>ARCHIVES & RAPPORTS FINANCIERS
                </span>
                <h1 class="fw-bold h2 mb-1">Bilans & Historique des Cycles</h1>
                <p class="text-muted small mb-0">
                    Consultez les états financiers de chaque cycle actif ou clôturé sans aucune perte d'historique.
                </p>
            </div>

            <div class="col-lg-5">
                <form method="GET" action="reports.php" class="d-flex gap-2 justify-content-lg-end">
                    <select name="cycle_id" class="form-select form-select-sm" style="max-width: 260px;" onchange="this.form.submit()">
                        <?php foreach ($cycles as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ($cycle_selectionne && (int)$cycle_selectionne['id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                Cycle #<?= (int)$c['numero_cycle'] ?> — <?= e($c['titre'] ?? '') ?> (<?= strtoupper($c['statut']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="window.print()" class="btn btn-glow-blue btn-sm px-3">
                        <i class="bi bi-printer-fill me-1"></i>Imprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle_selectionne): ?>
        <div class="card card-djangui p-5 text-center text-muted">
            Aucun cycle trouvé pour générer un rapport financier.
        </div>
    <?php else: ?>

        <!-- 1. CARTES DE SYNTHÈSE DU CYCLE SÉLECTIONNÉ -->
        <div class="row g-4 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-navy">
                    <span class="text-muted small fw-semibold">Total attendu sur le cycle</span>
                    <h3 class="fw-bold mt-2 mb-1"><?= format_fcfa($stats_cycle['attendu']) ?></h3>
                    <span class="small text-primary">Cycle #<?= (int)$cycle_selectionne['numero_cycle'] ?> (<?= e(ucfirst($cycle_selectionne['statut'])) ?>)</span>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-emerald">
                    <span class="text-muted small fw-semibold">Total cotisé et versé</span>
                    <h3 class="fw-bold text-success mt-2 mb-1"><?= format_fcfa($stats_cycle['collecte']) ?></h3>
                    <?php $pct_global = ($stats_cycle['attendu'] > 0) ? round(($stats_cycle['collecte'] / $stats_cycle['attendu']) * 100, 1) : 0; ?>
                    <span class="small text-success fw-semibold">Taux de recouvrement : <?= $pct_global ?> %</span>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-warning">
                    <span class="text-muted small fw-semibold">Reliquats & Impayés à recouvrer</span>
                    <h3 class="fw-bold text-warning-emphasis mt-2 mb-1"><?= format_fcfa($stats_cycle['reliquat']) ?></h3>
                    <span class="small text-muted">Restant dû aux bénéficiaires</span>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-danger">
                    <span class="text-muted small fw-semibold">Solde Caisse de Réserve</span>
                    <h3 class="fw-bold text-primary mt-2 mb-1"><?= format_fcfa($tontine['solde_caisse_reserve'] ?? 0) ?></h3>
                    <span class="small text-muted">Dont <?= format_fcfa($stats_cycle['penalites_reglees']) ?> sur ce cycle</span>
                </div>
            </div>
        </div>

        <!-- 2. BILAN PAR SÉANCE DE COTISATION -->
        <div class="card card-djangui p-4 mb-4">
            <h5 class="fw-bold mb-3">
                <i class="bi bi-calendar3-range text-primary me-2"></i>1. Bilan détaillé par séance et par bénéficiaire
            </h5>
            <div class="table-responsive">
                <table class="table table-djangui align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Séance</th>
                            <th>Date d'échéance</th>
                            <th>Bénéficiaire (« Bouffeur »)</th>
                            <th>Pot Attendu</th>
                            <th>Pot Collecté</th>
                            <th>Manquant (Reliquat)</th>
                            <th>Cotisations réglées</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bilan_seances)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Aucune séance générée pour ce cycle.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bilan_seances as $s): ?>
                                <?php $manquant = max(0, (float)$s['pot_attendu'] - (float)$s['pot_collecte']); ?>
                                <tr>
                                    <td><span class="badge bg-primary rounded-pill">Séance n°<?= (int)$s['numero_seance'] ?></span></td>
                                    <td class="small fw-semibold"><?= e($s['date_echeance']) ?></td>
                                    <td>
                                        <div class="fw-bold"><?= e($s['nom_bouffeur']) ?></div>
                                        <div class="small text-muted"><?= e($s['code_bouffeur']) ?></div>
                                    </td>
                                    <td class="fw-semibold"><?= format_fcfa($s['pot_attendu']) ?></td>
                                    <td class="fw-bold text-success"><?= format_fcfa($s['pot_collecte']) ?></td>
                                    <td>
                                        <?php if ($manquant > 0): ?>
                                            <span class="text-danger fw-bold"><?= format_fcfa($manquant) ?></span>
                                        <?php else: ?>
                                            <span class="text-success small fw-semibold"><i class="bi bi-check-all"></i> Complet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= ((int)$s['nb_payes'] === (int)$s['nb_total']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                                            <?= (int)$s['nb_payes'] ?> / <?= (int)$s['nb_total'] ?> membres
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. BILAN INDIVIDUEL PAR MEMBRE DU CYCLE -->
        <div class="card card-djangui p-4">
            <h5 class="fw-bold mb-3">
                <i class="bi bi-people-fill text-success me-2"></i>2. Bilan financier individuel par membre participant
            </h5>
            <div class="table-responsive">
                <table class="table table-djangui align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>Membre</th>
                            <th>Total Cotisé</th>
                            <th>Dette de cotisation</th>
                            <th>Pot Reçu (Bouffe)</th>
                            <th>Pénalités & Bonus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bilan_membres as $bm): ?>
                            <?php $dette_membre = max(0, (float)$bm['total_du_membre'] - (float)$bm['total_paye_membre']); ?>
                            <tr>
                                <td>
                                    <?= !empty($bm['rang_beneficiaire']) ? '<span class="fw-bold">#' . (int)$bm['rang_beneficiaire'] . '</span>' : '—' ?>
                                </td>
                                <td>
                                    <div class="fw-bold">
                                        <?= e($bm['nom_complet']) ?>
                                        <?php if ($bm['role'] === 'admin'): ?>
                                            <span class="badge bg-primary-subtle text-primary ms-1">Secrétaire</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted"><?= e($bm['code_public']) ?></div>
                                </td>
                                <td class="fw-bold text-success"><?= format_fcfa($bm['total_paye_membre']) ?></td>
                                <td>
                                    <?php if ($dette_membre > 0): ?>
                                        <span class="badge bg-danger-subtle text-danger px-2 py-1"><?= format_fcfa($dette_membre) ?> en retard</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success px-2 py-1">À jour (0 FCFA)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold"><?= format_fcfa($bm['montant_pot_recu']) ?></span>
                                    <span class="small text-muted">/ <?= format_fcfa($bm['montant_pot_attendu']) ?></span>
                                </td>
                                <td class="small fw-semibold">
                                    <?= format_fcfa($bm['total_penalites']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>