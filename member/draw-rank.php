<?php
// member/draw-rank.php — Consultation par le membre de son rang au tirage et du suivi de son pot (Membre 2)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];

// 1. Récupérer le cycle actif (ou le dernier cycle de la tontine)
$stmtCycle = $pdo->prepare("
    SELECT * FROM cycles
    WHERE tontine_id = ?
    ORDER BY (statut = 'actif') DESC, numero_cycle DESC
    LIMIT 1
");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

$ma_fiche_cycle = null;
$ordre_complet  = [];
$details_mon_pot = [];

if ($cycle) {
    // 2. Récupérer la fiche cycle_members du membre connecté
    $stmtMe = $pdo->prepare("
        SELECT cm.*, u.nom_complet, u.canal_paiement, u.telephone_paiement
        FROM cycle_members cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.cycle_id = ? AND cm.user_id = ?
        LIMIT 1
    ");
    $stmtMe->execute([$cycle['id'], $user_id]);
    $ma_fiche_cycle = $stmtMe->fetch();

    // 3. Récupérer l'ordre complet des bénéficiaires du cycle
    $stmtOrdre = $pdo->prepare("
        SELECT cm.*, u.nom_complet, u.code_public
        FROM cycle_members cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.cycle_id = ?
        ORDER BY cm.rang_beneficiaire IS NULL ASC, cm.rang_beneficiaire ASC
    ");
    $stmtOrdre->execute([$cycle['id']]);
    $ordre_complet = $stmtOrdre->fetchAll();

    // 4. Si j'ai un rang, récupérer l'état des cotisations des autres membres pour MA séance de bouffe
    if ($ma_fiche_cycle && !empty($ma_fiche_cycle['rang_beneficiaire'])) {
        $stmtPot = $pdo->prepare("
            SELECT co.*, u.nom_complet AS nom_cotisant, u.code_public
            FROM contributions co
            JOIN cycle_members cm_cot ON cm_cot.id = co.cycle_member_id
            JOIN users u ON u.id = cm_cot.user_id
            WHERE co.cycle_id = ? AND co.beneficiaire_cycle_member_id = ?
            ORDER BY co.statut = 'paye' DESC, u.nom_complet ASC
        ");
        $stmtPot->execute([$cycle['id'], $ma_fiche_cycle['id']]);
        $details_mon_pot = $stmtPot->fetchAll();
    }
}

$titre_page = "Mon Rang de Bouffeur";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-info text-dark px-3 py-2 rounded-pill fw-bold mb-2">
            <i class="bi bi-trophy-fill me-1"></i>SUIVI DE MON TOUR DE BOUFFE
        </span>
        <h1 class="fw-bold h2 mb-1">Mon Rang au Tirage & Mon Pot</h1>
        <p class="text-muted small mb-0">
            Suivez votre position officielle dans le cycle ainsi que les versements reçus sur votre pot.
        </p>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle || !$ma_fiche_cycle): ?>
        <div class="card card-djangui p-5 text-center">
            <i class="bi bi-info-circle fs-1 text-primary mb-2"></i>
            <h5 class="fw-bold">Vous n'êtes inscrit dans aucun cycle actif pour le moment</h5>
            <p class="text-muted small mb-0">Dès que la secrétaire lancera le cycle et le tirage au sort, votre rang apparaîtra ici.</p>
        </div>
    <?php else: ?>

        <!-- 1. CARTE VEDETTE : MON RANG PERSONNEL ET LA PROGRESSION DE MON POT -->
        <?php
        $pot_attendu = (float)$ma_fiche_cycle['montant_pot_attendu'];
        $pot_recu    = (float)$ma_fiche_cycle['montant_pot_recu'];
        $pot_restant = max(0, $pot_attendu - $pot_recu);
        $pct_pot     = ($pot_attendu > 0) ? round(($pot_recu / $pot_attendu) * 100, 1) : 0;
        ?>
        <div class="card-accent-djangui p-4 p-lg-5 mb-4 hover-lift">
            <div class="row align-items-center g-4">
                <div class="col-lg-4 text-center">
                    <div class="p-4 rounded-4 accent-subbox">
                        <span class="text-primary small fw-bold text-uppercase">Mon Rang Officiel</span>
                        <?php if (!empty($ma_fiche_cycle['rang_beneficiaire'])): ?>
                            <div class="display-2 fw-bold text-primary my-1">
                                n°<?= (int)$ma_fiche_cycle['rang_beneficiaire'] ?>
                            </div>
                            <div class="small fw-semibold">
                                Date prévue : <strong><?= e($ma_fiche_cycle['date_bouffe_prevue'] ?? '—') ?></strong>
                            </div>
                        <?php else: ?>
                            <div class="h3 fw-bold my-3">Tirage en attente</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-8">
                    <span class="badge bg-success px-3 py-2 rounded-pill mb-2">
                        Cycle #<?= (int)$cycle['numero_cycle'] ?> — <?= e($cycle['titre'] ?? '') ?>
                    </span>
                    <h3 class="fw-bold mb-2">Suivi financier de mon pot de bénéficiaire</h3>
                    <p class="text-muted small mb-3">
                        Compte de réception configuré : <strong class="text-uppercase"><?= e($ma_fiche_cycle['canal_paiement']) ?> (<?= e($ma_fiche_cycle['telephone_paiement']) ?>)</strong>.
                        Même si un membre paie en retard lors d'une séance suivante, sa part principale de cotisation vous est automatiquement reversée.
                    </p>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3 accent-subbox">
                                <span class="small text-muted d-block">Pot total attendu</span>
                                <strong class="fs-5"><?= format_fcfa($pot_attendu) ?></strong>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3 accent-subbox">
                                <span class="small text-muted d-block">Déjà reçu</span>
                                <strong class="fs-5 text-success"><?= format_fcfa($pot_recu) ?></strong>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3 accent-subbox">
                                <span class="small text-muted d-block">Reste à percevoir</span>
                                <strong class="fs-5 text-warning-emphasis"><?= format_fcfa($pot_restant) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-semibold">Progression de réception de mon pot :</span>
                        <span class="fw-bold text-success"><?= $pct_pot ?> %</span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-success" style="width: <?= min(100, $pct_pot) ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- 2. DÉTAIL DES COTISATIONS REÇUES POUR MON TOUR DE BOUFFE -->
            <div class="col-lg-6">
                <div class="card card-djangui h-100 p-4 border-left-emerald">
                    <h5 class="fw-bold mb-3">
                        <i class="bi bi-cash-coin text-success me-2"></i>Cotisations des membres pour mon tour
                    </h5>

                    <?php if (empty($details_mon_pot)): ?>
                        <p class="text-muted small mb-0">Le tirage au sort n'a pas encore généré les séances de ce cycle.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-djangui align-middle small mb-0">
                                <thead>
                                    <tr>
                                        <th>Membre cotisant</th>
                                        <th>Attendu</th>
                                        <th>Versé</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($details_mon_pot as $dp): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= e($dp['nom_cotisant']) ?></td>
                                            <td><?= format_fcfa($dp['montant_attendu']) ?></td>
                                            <td class="fw-bold text-success"><?= format_fcfa($dp['montant_paye']) ?></td>
                                            <td>
                                                <?php if ($dp['statut'] === 'paye'): ?>
                                                    <span class="badge bg-success-subtle text-success">Reçu</span>
                                                <?php elseif ($dp['statut'] === 'partiel'): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis">Partiel</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary">En attente</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. ORDRE COMPLET DES BÉNÉFICIAIRES DU CYCLE -->
            <div class="col-lg-6">
                <div class="card card-djangui h-100 p-4 border-left-navy">
                    <h5 class="fw-bold mb-3">
                        <i class="bi bi-list-ol text-primary me-2"></i>Ordre général de passage du cycle
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle small mb-0">
                            <thead>
                                <tr>
                                    <th>Tour</th>
                                    <th>Bénéficiaire</th>
                                    <th>Date prévue</th>
                                    <th>État du pot</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ordre_complet as $oc): ?>
                                    <?php $est_moi = ((int)$oc['user_id'] === $user_id); ?>
                                    <tr class="<?= $est_moi ? 'table-primary fw-bold' : '' ?>">
                                        <td>
                                            <?= !empty($oc['rang_beneficiaire']) ? '<span class="badge bg-primary">Tour n°' . (int)$oc['rang_beneficiaire'] . '</span>' : '—' ?>
                                        </td>
                                        <td>
                                            <?= e($oc['nom_complet']) ?>
                                            <?php if ($est_moi): ?>
                                                <span class="badge bg-success ms-1">Moi</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($oc['date_bouffe_prevue'] ?? '—') ?></td>
                                        <td>
                                            <?php if ((int)$oc['pot_totalement_recu'] === 1 || ((float)$oc['montant_pot_attendu'] > 0 && (float)$oc['montant_pot_recu'] >= (float)$oc['montant_pot_attendu'])): ?>
                                                <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Servi</span>
                                            <?php else: ?>
                                                <span class="text-muted"><?= format_fcfa($oc['montant_pot_recu']) ?> reçus</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>