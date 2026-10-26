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
            COALESCE(SUM(co.montant_attendu), 0) AS total_attendu,
            COALESCE(SUM(co.montant_paye), 0) AS total_collecte
        FROM contributions co
        JOIN cycle_members cm ON cm.id = co.cycle_member_id
        WHERE cm.cycle_id = ?
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
        FROM contributions co
        JOIN cycle_members cm ON cm.id = co.cycle_member_id
        WHERE cm.cycle_id = ?
    ");
    $stmtDerniereSeance->execute([$cycle_id]);
    $derniereSeance = (int)($stmtDerniereSeance->fetchColumn() ?? 0);

    if ($derniereSeance > 0) {
        $stmtRetards = $pdo->prepare("
            SELECT COUNT(*)
            FROM contributions c
            JOIN cycle_members cm ON cm.id = c.cycle_member_id
            WHERE cm.cycle_id = ?
              AND c.numero_seance = ?
              AND c.montant_paye < c.montant_attendu
              AND c.date_echeance < CURDATE()
        ");
        $stmtRetards->execute([$cycle_id, $derniereSeance]);
        $membres_en_retard = (int)$stmtRetards->fetchColumn();

        $stmtRegles = $pdo->prepare("
            SELECT COUNT(*)
            FROM contributions c
            JOIN cycle_members cm ON cm.id = c.cycle_member_id
            WHERE cm.cycle_id = ?
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
        JOIN cycle_members cotisant ON cotisant.id = c.cycle_member_id
        JOIN cycle_members beneficiaire ON beneficiaire.id = c.beneficiaire_cycle_member_id
        JOIN users u ON u.id = beneficiaire.user_id
        WHERE cotisant.cycle_id = ?
        AND beneficiaire.cycle_id = ?
        AND c.numero_seance = ?
        GROUP BY beneficiaire.id, u.id, u.nom_complet
        LIMIT 1
    ");
    $stmtBouffeur->execute([$cycle_id, $cycle_id, $derniereSeance]);
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
        JOIN cycle_members cm ON cm.id = c.cycle_member_id
        WHERE cm.cycle_id = ?
        ORDER BY p.date_paiement DESC
        LIMIT 5
    ");
    $stmtPaiements->execute([$cycle_id]);
    $dernier_paiements = $stmtPaiements->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                    <i class="bi bi-speedometer2 me-1"></i>PILOTAGE DE LA TONTINE
                </span>
                <h1 class="fw-bold h2 mb-1">Administration de la tontine</h1>
                <p class="text-muted small mb-0">
                    <?php if ($tontine): ?>
                        <?= e($tontine['nom']) ?>
                    <?php else: ?>
                        Suivez l’activité et les finances de votre tontine.
                    <?php endif; ?>
                    <?php if ($cycle): ?>
                        <span class="mx-1">·</span>
                        Cycle n°<?= (int)$cycle['numero_cycle'] ?>
                        <?php if ($derniereSeance > 0): ?>
                            <span class="mx-1">·</span>Séance n°<?= $derniereSeance ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <?php if ($cycle): ?>
                    <a href="<?= BASE_URL ?>/admin/contributions.php" class="btn btn-glow-blue btn-sm px-3 py-2">
                        <i class="bi bi-receipt me-1"></i>Suivi des cotisations
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/admin/cycles.php" class="btn btn-glow-emerald btn-sm px-3 py-2">
                        <i class="bi bi-plus-circle me-1"></i>Créer un cycle
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<main class="container pb-5">
    <?php if ($cycle): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="card card-djangui hover-lift border-left-navy h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="text-muted small">Montant attendu</div>
                                <div class="fs-4 fw-bold mt-2"><?= format_fcfa($montant_attendu) ?></div>
                            </div>
                            <span class="rounded-circle box-adaptive p-2 text-primary"><i class="bi bi-bullseye fs-5"></i></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3">
                <div class="card card-djangui hover-lift border-left-emerald h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="text-muted small">Montant collecté</div>
                                <div class="fs-4 fw-bold mt-2"><?= format_fcfa($montant_collecte) ?></div>
                            </div>
                            <span class="rounded-circle box-adaptive p-2 text-success"><i class="bi bi-cash-stack fs-5"></i></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3">
                <div class="card card-djangui hover-lift border-left-warning h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="text-muted small">Taux de cotisation</div>
                                <div class="fs-4 fw-bold mt-2"><?= number_format($taux_cotisation, 2, ',', ' ') ?> %</div>
                            </div>
                            <span class="rounded-circle box-adaptive p-2 text-warning"><i class="bi bi-graph-up-arrow fs-5"></i></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-3">
                <div class="card-accent-djangui hover-lift h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="text-muted small">Caisse de réserve</div>
                                <div class="fs-4 fw-bold mt-2"><?= format_fcfa((float)($tontine['solde_caisse_reserve'] ?? 0)) ?></div>
                            </div>
                            <span class="rounded-circle box-adaptive p-2 text-success"><i class="bi bi-safe2 fs-5"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-4">
                <div class="card card-djangui hover-lift border-left-danger h-100">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Membres en retard</div>
                            <div class="fs-3 fw-bold mt-2"><?= (int)$membres_en_retard ?></div>
                        </div>
                        <span class="rounded-circle box-adaptive p-3 text-danger"><i class="bi bi-exclamation-triangle fs-5"></i></span>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-djangui hover-lift border-left-emerald h-100">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Membres en règle</div>
                            <div class="fs-3 fw-bold mt-2"><?= (int)$membres_en_regle ?></div>
                        </div>
                        <span class="rounded-circle box-adaptive p-3 text-success"><i class="bi bi-check2-circle fs-5"></i></span>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card-accent-djangui h-100">
                    <div class="card-body p-4 d-flex align-items-center gap-3">
                        <span class="rounded-circle box-adaptive p-3 text-primary"><i class="bi bi-trophy fs-4"></i></span>
                        <div>
                            <div class="text-muted small">Bouffeur de la séance n°<?= $derniereSeance ?></div>
                            <div class="fw-bold fs-5 mt-1"><?= e($bouffeur_nom) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-djangui overflow-hidden">
            <div class="p-4 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <span class="rounded-circle box-adaptive p-2 text-primary"><i class="bi bi-clock-history"></i></span>
                    <div>
                        <h2 class="h5 fw-bold mb-0">5 derniers paiements</h2>
                        <p class="text-muted small mb-0">Activité récente du cycle actif</p>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-djangui align-middle mb-0">
                        <thead>
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
                                                $statut_labels = [
                                                    'valide' => 'Validé',
                                                    'en_attente' => 'En attente',
                                                    'rejete' => 'Rejeté',
                                                ];
                                                if ($p['statut_paiement'] === 'valide') $badge = 'success';
                                                elseif ($p['statut_paiement'] === 'en_attente') $badge = 'warning';
                                                elseif ($p['statut_paiement'] === 'rejete') $badge = 'danger';
                                                $statut_label = $statut_labels[$p['statut_paiement']] ?? $p['statut_paiement'];
                                            ?>
                                            <span class="badge text-bg-<?= $badge ?> rounded-pill px-2 py-1"><?= e($statut_label) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                        Aucun paiement enregistré pour ce cycle.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-djangui p-5 text-center">
            <span class="rounded-circle box-adaptive p-3 text-warning mx-auto mb-3"><i class="bi bi-calendar-x fs-3"></i></span>
            <h2 class="h4 fw-bold">Aucun cycle actif</h2>
            <p class="text-muted mb-4">Créez et activez un cycle pour afficher les indicateurs et les paiements de votre tontine.</p>
            <div>
                <a href="<?= BASE_URL ?>/admin/cycles.php" class="btn btn-glow-emerald px-4">
                    <i class="bi bi-plus-circle me-1"></i>Gérer les cycles
                </a>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
