<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$titre_page = 'Tableau de bord administrateur';

// 1. Info de la tontine (solde caisse de reserve)
$stmtTontine = $pdo->prepare("SELECT * FROM tontines WHERE id = ? LIMIT 1");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

// 2. Cycle actif
$stmtCycle = $pdo->prepare("
    SELECT * FROM cycles
    WHERE tontine_id = ? AND statut = 'actif'
    ORDER BY numero_cycle DESC, id DESC
    LIMIT 1
");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

$cycle_id = $cycle ? (int)$cycle['id'] : 0;
$montant_attendu = 0.0;
$montant_collecte = 0.0;
$taux_cotisation = 0.0;
$membres_en_retard = 0;
$membres_en_regle = 0;
$bouffeur_nom = '—';
$dernier_paiements = [];

if ($cycle) {
    // 3. Somme attendue et collectee sur les contributions du cycle actif
    $stmtStats = $pdo->prepare("
        SELECT
            COALESCE(SUM(montant_attendu), 0) AS total_attendu,
            COALESCE(SUM(montant_paye), 0) AS total_collecte
        FROM contributions
        WHERE cycle_id = ?
    ");
    $stmtStats->execute([$cycle_id]);
    $stats = $stmtStats->fetch();

    $montant_attendu = (float)($stats['total_attendu'] ?? 0);
    $montant_collecte = (float)($stats['total_collecte'] ?? 0);
    if ($montant_attendu > 0) {
        $taux_cotisation = round(($montant_collecte / $montant_attendu) * 100, 2);
    }

    // 4. Membres en retard / en regle pour la séance en cours
    // On prend la séance la plus récente (la plus haute numero_seance) de ce cycle
    $stmtDerniereSeance = $pdo->prepare("
        SELECT MAX(numero_seance) AS max_numero
        FROM contributions
        WHERE cycle_id = ?
    ");
    $stmtDerniereSeance->execute([$cycle_id]);
    $derniereSeance = (int)($stmtDerniereSeance->fetchColumn() ?? 0);

    if ($derniereSeance > 0) {
        $stmtRetards = $pdo->prepare("
            SELECT COUNT(*)
            FROM contributions c
            WHERE c.cycle_id = ?
              AND c.numero_seance = ?
              AND c.montant_paye < c.montant_attendu
              AND c.date_echeance < CURDATE()
        ");
        $stmtRetards->execute([$cycle_id, $derniereSeance]);
        $membres_en_retard = (int)$stmtRetards->fetchColumn();

        $stmtRegles = $pdo->prepare("
            SELECT COUNT(*)
            FROM contributions c
            WHERE c.cycle_id = ?
              AND c.numero_seance = ?
              AND c.montant_paye >= c.montant_attendu
        ");
        $stmtRegles->execute([$cycle_id, $derniereSeance]);
        $membres_en_regle = (int)$stmtRegles->fetchColumn();
    }

    // 5. Bouffeur de la derniere seance / seance courante
    $stmtBouffeur = $pdo->prepare("
        SELECT u.nom_complet
        FROM contributions c
        JOIN cycle_members cm ON cm.id = c.beneficiaire_cycle_member_id
        JOIN users u ON u.id = cm.user_id
        WHERE c.cycle_id = ?
        AND c.numero_seance = ?
        GROUP BY u.id, u.nom_complet
        LIMIT 1
    ");
    $stmtBouffeur->execute([$cycle_id, $derniereSeance]);
    $bouffeur = $stmtBouffeur->fetch();
    if ($bouffeur) {
        $bouffeur_nom = $bouffeur['nom_complet'];
    }

    // 6. 5 derniers paiements du cycle actif
    $stmtPaiements = $pdo->prepare("
        SELECT p.id, p.date_paiement, p.montant_total, p.mode_paiement, p.statut_paiement,
               u.nom_complet AS payeur_nom
        FROM payments p
        JOIN users u ON u.id = p.paye_par_user_id
        JOIN contributions c ON c.id = p.contribution_id
        WHERE c.cycle_id = ?
        ORDER BY p.date_paiement DESC
        LIMIT 5
    ");
    $stmtPaiements->execute([$cycle_id]);
    $dernier_paiements = $stmtPaiements->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2 mb-2">
                <i class="bi bi-speedometer2 me-1"></i> Tableau de bord
            </span>
            <h1 class="h3 mb-0 fw-bold">Administration de la tontine</h1>
        </div>
        <?php if ($tontine): ?>
            <div class="text-end">
                <div class="small text-muted">Tontine</div>
                <strong><?= e($tontine['nom']) ?></strong>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($cycle): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">Montant attendu</div>
                        <div class="fs-4 fw-bold mt-2"><?= format_fcfa($montant_attendu) ?></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">Montant collecté</div>
                        <div class="fs-4 fw-bold mt-2"><?= format_fcfa($montant_collecte) ?></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">Taux de cotisation</div>
                        <div class="fs-4 fw-bold mt-2"><?= number_format($taux_cotisation, 2, ',', ' ') ?> %</div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">Caisse de réserve</div>
                        <div class="fs-4 fw-bold mt-2"><?= format_fcfa((float)($tontine['solde_caisse_reserve'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Membres en retard</div>
                                <div class="fs-4 fw-bold mt-2"><?= (int)$membres_en_retard ?></div>
                            </div>
                            <span class="badge bg-danger-subtle text-danger rounded-pill px-3 py-2"><i class="bi bi-exclamation-triangle"></i></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Membres en règle</div>
                                <div class="fs-4 fw-bold mt-2"><?= (int)$membres_en_regle ?></div>
                            </div>
                            <span class="badge bg-success-subtle text-success rounded-pill px-3 py-2"><i class="bi bi-check2-circle"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Bouffeur de la séance</div>
                        <div class="fw-bold fs-5"><?= e($bouffeur_nom) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0">
                <h2 class="h5 mb-0">5 derniers paiements</h2>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Membre</th>
                                <th>Montant</th>
                                <th>Mode</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($dernier_paiements)): ?>
                                <?php foreach ($dernier_paiements as $p): ?>
                                    <tr>
                                        <td><?= e(date('d/m/Y', strtotime($p['date_paiement']))) ?></td>
                                        <td><?= e($p['payeur_nom']) ?></td>
                                        <td><?= format_fcfa((float)$p['montant_total']) ?></td>
                                        <td><?= e(strtoupper($p['mode_paiement'])) ?></td>
                                        <td>
                                            <?php
                                                $badge = 'secondary';
                                                if ($p['statut_paiement'] === 'valide') $badge = 'success';
                                                elseif ($p['statut_paiement'] === 'en_attente') $badge = 'warning';
                                                elseif ($p['statut_paiement'] === 'rejete') $badge = 'danger';
                                            ?>
                                            <span class="badge bg-<?= $badge ?>-subtle text-<?= $badge ?> rounded-pill px-2 py-1"><?= e($p['statut_paiement']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Aucun paiement enregistré pour ce cycle.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">Aucun cycle actif n’a été trouvé pour cette tontine.</div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
