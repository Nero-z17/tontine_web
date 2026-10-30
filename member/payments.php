<?php
/**
 * =============================================================================
 * MEMBRE 3 : member/payments.php
 * =============================================================================
 * Reçus et historique des paiements du membre avec ventilation financière :
 *  - Montant total déboursé
 *  - Part versée au bouffeur (actuel ou ancien)
 *  - Part versée à la Caisse de réserve (pénalité de retard)
 *
 * Tables SQL : payments, contributions, cycle_members, users, cycles
 * =============================================================================
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];

// Récupération des paiements du membre
$stmt = $pdo->prepare("
    SELECT 
        p.id, 
        p.montant_total, 
        p.part_beneficiaire, 
        p.part_caisse_reserve,
        p.mode_paiement, 
        p.reference_paiement,
        p.statut_paiement, 
        p.est_en_retard,
        p.date_paiement,
        c.numero_seance, 
        cy.numero_cycle,
        u_ben.nom_complet AS ben_nom
    FROM payments p
    JOIN contributions c ON c.id = p.contribution_id
    JOIN cycle_members cm_payeur ON cm_payeur.id = c.cycle_member_id
    JOIN cycles cy ON cy.id = cm_payeur.cycle_id
    JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
    JOIN users u_ben ON u_ben.id = cm_ben.user_id
    WHERE p.paye_par_user_id = ?
    ORDER BY p.date_paiement DESC
");
$stmt->execute([$user_id]);
$paiements = $stmt->fetchAll();

// Totaux agrégés
$total_paye      = 0.0;
$total_bouffeurs = 0.0;
$total_caisse    = 0.0;

foreach ($paiements as $p) {
    if ($p['statut_paiement'] === 'valide' || $p['statut_paiement'] === 'paye') {
        $total_paye      += (float)$p['montant_total'];
        $total_bouffeurs += (float)$p['part_beneficiaire'];
        $total_caisse    += (float)$p['part_caisse_reserve'];
    }
}

$titre_page = "Mes Paiements";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <h2 class="fw-bold mb-1"><i class="bi bi-receipt me-2"></i>Mes Paiements</h2>
        <p class="text-muted mb-0 small">Historique et traçabilité détaillée de vos versements</p>
    </div>
</div>

<div class="container pb-5">
    <!-- Cartes de synthèse financière -->
    <div class="row g-4 mt-2">
        <div class="col-md-4">
            <div class="card-accent-djangui hover-lift p-4 text-center">
                <i class="bi bi-wallet2 display-6 text-primary"></i>
                <h3 class="fw-bold mt-2 mb-0"><?= format_fcfa($total_paye) ?></h3>
                <small class="text-muted">Total déboursé (<?= count($paiements) ?> versement<?= count($paiements) > 1 ? 's' : '' ?>)</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-djangui hover-lift p-4 text-center border-left-emerald">
                <i class="bi bi-person-check display-6 text-success"></i>
                <h3 class="fw-bold mt-2 mb-0"><?= format_fcfa($total_bouffeurs) ?></h3>
                <small class="text-muted">Part versée aux bouffeurs</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-djangui hover-lift p-4 text-center border-left-warning">
                <i class="bi bi-safe display-6 text-warning"></i>
                <h3 class="fw-bold mt-2 mb-0"><?= format_fcfa($total_caisse) ?></h3>
                <small class="text-muted">Part Caisse de réserve (Pénalités)</small>
            </div>
        </div>
    </div>

    <!-- Tableau détaillé -->
    <div class="card card-djangui p-4 mt-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-list-check me-2"></i>Reçus détaillés</h5>
        <?php if (empty($paiements)): ?>
            <p class="text-muted text-center py-4">Aucun versement enregistré sur votre compte.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-djangui align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Cycle</th>
                            <th>Séance</th>
                            <th>Bénéficiaire (Bouffeur)</th>
                            <th class="text-end">Total payé</th>
                            <th class="text-end text-success">Part Bouffeur</th>
                            <th class="text-end text-warning">Part Caisse</th>
                            <th>Mode</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paiements as $p): ?>
                        <tr>
                            <td>
                                <small><?= date('d/m/Y H:i', strtotime($p['date_paiement'])) ?></small>
                                <?php if (!empty($p['reference_paiement'])): ?>
                                    <br><span class="text-muted font-monospace" style="font-size: 0.75rem;"><?= e($p['reference_paiement']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold">#<?= (int)$p['numero_cycle'] ?></td>
                            <td>Séance #<?= (int)$p['numero_seance'] ?></td>
                            <td><?= e($p['ben_nom']) ?></td>
                            <td class="text-end fw-bold"><?= format_fcfa($p['montant_total']) ?></td>
                            <td class="text-end text-success"><?= format_fcfa($p['part_beneficiaire']) ?></td>
                            <td class="text-end <?= (float)$p['part_caisse_reserve'] > 0 ? 'text-warning fw-bold' : 'text-muted' ?>">
                                <?= (float)$p['part_caisse_reserve'] > 0 ? format_fcfa($p['part_caisse_reserve']) : '0 FCFA' ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= e(strtoupper($p['mode_paiement'])) ?></span>
                            </td>
                            <td>
                                <?php if ((int)$p['est_en_retard'] === 1): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Payé en retard</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Validé</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-3 rounded-3 box-adaptive mt-3 small">
                <i class="bi bi-info-circle me-1 text-primary"></i>
                <strong>Comprendre la ventilation :</strong> 
                La <em>Part Bouffeur</em> va directement alimenter le pot du bénéficiaire du tour. 
                La <em>Part Caisse</em> correspond à la pénalité de retard configurée par la secrétaire qui est versée dans la Caisse de réserve.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
