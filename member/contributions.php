<?php
/**
 * =============================================================================
 * MEMBRE 3 : member/contributions.php
 * =============================================================================
 * Liste des cotisations du membre connecté et paiement avec :
 *  - Prise en compte dynamique du POURCENTAGE de la pénalité de retard
 *  - Possibilité pour l'administrateur / secrétaire de MODIFIER ce pourcentage
 *    directement depuis son espace
 *  - Ventilation financière automatique :
 *      * Cas 1 (Normal)    : 100% au bouffeur de la séance
 *      * Cas 2 (En retard) : Cotisation au bouffeur + Pénalité (%) à la caisse de réserve
 *  - Transaction atomique SQL ($pdo->beginTransaction) :
 *      1. payments (paye_par_user_id, part_beneficiaire, part_caisse_reserve, est_en_retard)
 *      2. contributions (montant_paye, statut)
 *      3. cycle_members (montant_pot_recu du bouffeur)
 *      4. tontines (solde_caisse_reserve)
 *
 * Tables SQL : cycles, cycle_members, contributions, payments, tontines, users
 * =============================================================================
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Sécurité : connexion obligatoire
verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];
$est_admin  = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// ══════════════════════════════════════════════════════════════════════════════
// 1. CYCLE ACTIF & PARTICIPATION DU MEMBRE
// ══════════════════════════════════════════════════════════════════════════════
$stmtCycle = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? AND statut = 'actif' LIMIT 1");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

$mon_cycle_member = null;
if ($cycle) {
    $stmtCM = $pdo->prepare("SELECT * FROM cycle_members WHERE cycle_id = ? AND user_id = ? LIMIT 1");
    $stmtCM->execute([(int)$cycle['id'], $user_id]);
    $mon_cycle_member = $stmtCM->fetch();
}

// Calcul du pourcentage et du montant de pénalité en vigueur sur le cycle
$montant_cotis_cycle = $cycle ? (float)$cycle['montant_cotisation'] : 0.0;
$penalite_montant    = $cycle ? (float)($cycle['montant_penalite_retard'] ?? 0) : 0.0;

// Si un montant existe en BDD, on déduit le pourcentage correspondant ; sinon 10% par défaut
if ($cycle && $montant_cotis_cycle > 0) {
    if ($penalite_montant > 0) {
        $pourcentage_penalite = round(($penalite_montant / $montant_cotis_cycle) * 100, 1);
    } else {
        $pourcentage_penalite = 10.0;
        $penalite_montant     = round($montant_cotis_cycle * 0.10);
    }
} else {
    $pourcentage_penalite = 10.0;
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. ACTION ADMIN : MODIFIER LE POURCENTAGE DE LA PÉNALITÉ
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_pourcentage_penalite') {
    verifier_csrf();

    if (!$est_admin) {
        message_flash('danger', 'Action refusée : réservée à l\'administrateur.');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    if (!$cycle) {
        message_flash('danger', 'Impossible de modifier la pénalité : aucun cycle actif.');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    $nouveau_pourcentage = filter_var($_POST['pourcentage_penalite'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($nouveau_pourcentage === false || $nouveau_pourcentage < 0 || $nouveau_pourcentage > 100) {
        message_flash('danger', 'Veuillez saisir un pourcentage valide (entre 0% et 100%).');
    } else {
        try {
            // Conversion du pourcentage en montant FCFA pour la table cycles
            $nouveau_montant_penalite = round($montant_cotis_cycle * ($nouveau_pourcentage / 100));

            $stmtUpdate = $pdo->prepare("
                UPDATE cycles 
                SET montant_penalite_retard = ? 
                WHERE id = ? AND tontine_id = ?
            ");
            $stmtUpdate->execute([$nouveau_montant_penalite, (int)$cycle['id'], $tontine_id]);

            message_flash('success', "Le taux de pénalité de retard a été fixé à {$nouveau_pourcentage}% (soit " . format_fcfa($nouveau_montant_penalite) . " par cotisation en retard).");
        } catch (PDOException $e) {
            message_flash('danger', 'Erreur lors de la mise à jour : ' . $e->getMessage());
        }
    }

    header('Location: ' . BASE_URL . '/member/contributions.php');
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. ACTION MEMBRE : TRAITEMENT D'UN PAIEMENT (POST)
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payer_cotisation' && $cycle && $mon_cycle_member) {
    verifier_csrf();

    $contribution_id = (int)($_POST['contribution_id'] ?? 0);
    $mode_paiement   = trim($_POST['mode_paiement'] ?? '');

    $modes_autorises = ['momo', 'om', 'especes'];
    if (!in_array($mode_paiement, $modes_autorises, true)) {
        message_flash('danger', 'Veuillez sélectionner un mode de paiement valide (MoMo, OM ou Espèces).');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    // Récupérer la cotisation du membre
    $stmtVerif = $pdo->prepare("
        SELECT c.*, cm_bouffeur.user_id AS bouffeur_user_id
        FROM contributions c
        JOIN cycle_members cm_payeur ON cm_payeur.id = c.cycle_member_id
        JOIN cycle_members cm_bouffeur ON cm_bouffeur.id = c.beneficiaire_cycle_member_id
        WHERE c.id = ? 
          AND c.cycle_member_id = ?
          AND cm_payeur.cycle_id = ?
        LIMIT 1
    ");
    $stmtVerif->execute([$contribution_id, (int)$mon_cycle_member['id'], (int)$cycle['id']]);
    $cotisation = $stmtVerif->fetch();

    if (!$cotisation) {
        message_flash('danger', 'Séance de cotisation introuvable ou non assignée à votre compte.');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    if ($cotisation['statut'] === 'paye' || (float)$cotisation['montant_paye'] >= (float)$cotisation['montant_attendu']) {
        message_flash('warning', 'Vous avez déjà entièrement réglé cette cotisation.');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    // ── Ventilation financière selon le pourcentage configuré ──
    $montant_cotisation = (float)$cotisation['montant_attendu'];
    $date_echeance      = $cotisation['date_echeance'];
    $aujourdhui         = date('Y-m-d');
    $est_en_retard      = (strtotime($date_echeance) < strtotime($aujourdhui));

    if ($est_en_retard) {
        // En retard : Cotisation normale au bouffeur + pénalité (%) à la caisse de réserve
        $penalite_appliquee  = round($montant_cotisation * ($pourcentage_penalite / 100));
        $part_beneficiaire   = $montant_cotisation;
        $part_caisse_reserve = $penalite_appliquee;
        $montant_total       = $part_beneficiaire + $part_caisse_reserve;
    } else {
        // À l'heure : 100% au bouffeur du tour
        $part_beneficiaire   = $montant_cotisation;
        $part_caisse_reserve = 0.00;
        $montant_total       = $montant_cotisation;
    }

    $prefixe = strtoupper($mode_paiement);
    $ref_paiement = $prefixe . date('ymd') . '.' . str_pad((string)$contribution_id, 3, '0', STR_PAD_LEFT);

    // ── Transaction atomique SQL ──
    try {
        $pdo->beginTransaction();

        // 1. Insertion du reçu de paiement
        $stmtPay = $pdo->prepare("
            INSERT INTO payments (
                contribution_id, paye_par_user_id, montant_total,
                part_beneficiaire, part_caisse_reserve, mode_paiement,
                reference_paiement, date_paiement, est_en_retard,
                statut_paiement, commentaire
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'valide', ?)
        ");
        $stmtPay->execute([
            $contribution_id,
            $user_id,
            $montant_total,
            $part_beneficiaire,
            $part_caisse_reserve,
            $mode_paiement,
            $ref_paiement,
            $est_en_retard ? 1 : 0,
            $est_en_retard ? "Paiement en retard (pénalité {$pourcentage_penalite}%)" : 'Paiement à échéance'
        ]);

        // 2. Mise à jour de la table contributions
        $stmtUpdateContrib = $pdo->prepare("
            UPDATE contributions 
            SET montant_paye = montant_attendu, statut = 'paye'
            WHERE id = ?
        ");
        $stmtUpdateContrib->execute([$contribution_id]);

        // 3. Créditer le pot du bouffeur
        $stmtUpdatePot = $pdo->prepare("
            UPDATE cycle_members 
            SET montant_pot_recu = montant_pot_recu + ?
            WHERE id = ?
        ");
        $stmtUpdatePot->execute([$part_beneficiaire, (int)$cotisation['beneficiaire_cycle_member_id']]);

        // 4. Si pénalité, alimenter la caisse de réserve
        if ($part_caisse_reserve > 0) {
            $stmtUpdateCaisse = $pdo->prepare("
                UPDATE tontines 
                SET solde_caisse_reserve = solde_caisse_reserve + ?
                WHERE id = ?
            ");
            $stmtUpdateCaisse->execute([$part_caisse_reserve, $tontine_id]);
        }

        $pdo->commit();

        $msg = 'Paiement de ' . format_fcfa($montant_total) . ' enregistré avec succès !';
        if ($est_en_retard) {
            $msg .= " (dont " . format_fcfa($part_caisse_reserve) . " de pénalité de retard [{$pourcentage_penalite}%] versés à la Caisse de réserve).";
        }
        message_flash('success', $msg);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        message_flash('danger', 'Erreur lors du traitement du paiement : ' . $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/member/contributions.php');
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. RÉCUPÉRATION DES SÉANCES DU MEMBRE CONNECTÉ
// ══════════════════════════════════════════════════════════════════════════════
$seances = [];

if ($cycle && $mon_cycle_member) {
    $stmtSeances = $pdo->prepare("
        SELECT 
            c.id AS contribution_id, c.numero_seance, c.date_echeance,
            c.montant_attendu, c.montant_paye, c.statut AS statut_contrib,
            u_ben.nom_complet AS ben_nom,
            p.id AS payment_id, p.montant_total, p.part_beneficiaire,
            p.part_caisse_reserve, p.mode_paiement, p.date_paiement
        FROM contributions c
        JOIN cycle_members cm_bouffeur ON cm_bouffeur.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_bouffeur.user_id
        LEFT JOIN payments p ON p.contribution_id = c.id AND p.statut_paiement = 'valide'
        WHERE c.cycle_member_id = ?
        ORDER BY c.numero_seance ASC
    ");
    $stmtSeances->execute([(int)$mon_cycle_member['id']]);
    $seances = $stmtSeances->fetchAll();
}

$titre_page = "Mes Cotisations";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <h2 class="fw-bold mb-1"><i class="bi bi-cash-stack me-2"></i>Mes Cotisations</h2>
        <p class="text-muted mb-0 small">
            Suivi et paiement de vos cotisations — Cycle <?= $cycle ? '#' . (int)$cycle['numero_cycle'] : '—' ?>
        </p>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle): ?>
        <div class="card card-djangui hover-lift p-4 text-center mt-4">
            <i class="bi bi-info-circle display-4 text-muted"></i>
            <h5 class="mt-3">Aucun cycle actif</h5>
            <p class="text-muted">Les cotisations ne sont pas encore ouvertes pour le moment.</p>
        </div>
    <?php elseif (!$mon_cycle_member): ?>
        <div class="card card-djangui hover-lift p-4 text-center mt-4">
            <i class="bi bi-person-x display-4 text-warning"></i>
            <h5 class="mt-3">Non inscrit à ce cycle</h5>
            <p class="text-muted">Vous ne participez pas au cycle actuellement actif.</p>
        </div>
    <?php elseif (empty($seances)): ?>
        <div class="card card-djangui hover-lift p-4 text-center mt-4">
            <i class="bi bi-hourglass-split display-4 text-warning"></i>
            <h5 class="mt-3">Séances en attente</h5>
            <p class="text-muted">Les séances de cotisation seront générées dès que le tirage au sort aura été effectué.</p>
        </div>
    <?php else: ?>

        <!-- =================================================================== -->
        <!-- SECTION RÉSERVÉE À L'ADMINISTRATEUR : MODIFICATION DU POURCENTAGE   -->
        <!-- =================================================================== -->
        <?php if ($est_admin): ?>
            <div class="card card-djangui p-4 mt-4 border-left-warning">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">
                            <i class="bi bi-percent text-warning me-2"></i>Paramétrage de la Pénalité (Espace Secrétaire)
                        </h5>
                        <p class="text-muted small mb-0">
                            Modifiez le pourcentage appliqué en cas de retard. La pénalité s'appliquera automatiquement 
                            à tous les membres et sera versée dans la <strong>Caisse de réserve</strong>.
                        </p>
                    </div>
                    <div class="mt-2 mt-md-0">
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                            Taux actuel : <?= $pourcentage_penalite ?>% (<?= format_fcfa($penalite_montant) ?>)
                        </span>
                    </div>
                </div>

                <form method="POST" action="<?= BASE_URL ?>/member/contributions.php" class="row g-3 align-items-center">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="modifier_pourcentage_penalite">

                    <div class="col-auto">
                        <label for="pourcentage_penalite" class="col-form-label fw-semibold small">
                            Nouveau pourcentage de pénalité :
                        </label>
                    </div>
                    <div class="col-auto">
                        <div class="input-group">
                            <input type="number" 
                                   step="0.5" 
                                   min="0" 
                                   max="100" 
                                   name="pourcentage_penalite" 
                                   id="pourcentage_penalite"
                                   class="form-control" 
                                   value="<?= $pourcentage_penalite ?>" 
                                   required 
                                   style="max-width: 120px;">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-glow-blue">
                            <i class="bi bi-check-lg me-1"></i>Enregistrer le pourcentage
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Rappel du taux pour les membres -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3 p-3 rounded-3 box-adaptive">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-warning text-dark"><i class="bi bi-shield-exclamation me-1"></i>Règle de retard</span>
                <span class="small">
                    Pénalité de retard fixée par l'administrateur : 
                    <strong><?= $pourcentage_penalite ?>%</strong> 
                    (soit <strong><?= format_fcfa($penalite_montant) ?></strong> par séance impayée à l'échéance)
                </span>
            </div>
            <div class="small text-muted mt-2 mt-md-0">
                <i class="bi bi-info-circle me-1"></i>100% de la pénalité est reversée à la Caisse de réserve
            </div>
        </div>

        <!-- Tableau des séances -->
        <div class="card card-djangui p-4">
            <div class="table-responsive">
                <table class="table table-djangui align-middle">
                    <thead>
                        <tr>
                            <th>Séance</th>
                            <th>Bénéficiaire (Bouffeur)</th>
                            <th>Montant cotisation</th>
                            <th>Date d'échéance</th>
                            <th>Statut</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $aujourdhui = date('Y-m-d');
                        foreach ($seances as $s): 
                            $deja_paye   = ($s['statut_contrib'] === 'paye' || (float)$s['montant_paye'] >= (float)$s['montant_attendu']);
                            $date_ech    = strtotime($s['date_echeance']);
                            $en_retard   = (!$deja_paye && $date_ech < strtotime($aujourdhui));
                            $penalite_du = round((float)$s['montant_attendu'] * ($pourcentage_penalite / 100));
                        ?>
                        <tr>
                            <td class="fw-bold">#<?= (int)$s['numero_seance'] ?></td>
                            <td><?= e($s['ben_nom']) ?></td>
                            <td>
                                <strong><?= format_fcfa($s['montant_attendu']) ?></strong>
                                <?php if ($en_retard): ?>
                                    <br>
                                    <small class="text-danger fw-semibold">
                                        + <?= format_fcfa($penalite_du) ?> (Pénalité <?= $pourcentage_penalite ?>%)
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <i class="bi bi-calendar3 me-1 text-muted"></i>
                                <?= date('d/m/Y', $date_ech) ?>
                            </td>
                            <td>
                                <?php if ($deja_paye): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Payé</span>
                                <?php elseif ($en_retard): ?>
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>En retard</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>À payer</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($deja_paye): ?>
                                    <span class="text-success small fw-semibold">
                                        <i class="bi bi-check2-all me-1"></i><?= e(strtoupper($s['mode_paiement'] ?: 'RÉGLÉ')) ?>
                                    </span>
                                <?php else: ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-glow-emerald"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalPaiement"
                                            data-contrib-id="<?= (int)$s['contribution_id'] ?>"
                                            data-seance-num="<?= (int)$s['numero_seance'] ?>"
                                            data-bouffeur="<?= e($s['ben_nom']) ?>"
                                            data-montant="<?= (float)$s['montant_attendu'] ?>"
                                            data-penalite="<?= (float)$penalite_du ?>"
                                            data-pourcentage="<?= $pourcentage_penalite ?>"
                                            data-en-retard="<?= $en_retard ? '1' : '0' ?>">
                                        <i class="bi bi-credit-card me-1"></i>Payer
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal de confirmation de paiement -->
        <div class="modal fade" id="modalPaiement" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content card-djangui">
                    <form method="POST" action="<?= BASE_URL ?>/member/contributions.php">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="payer_cotisation">
                        <input type="hidden" name="contribution_id" id="inputContribId">

                        <div class="modal-header">
                            <h5 class="modal-title fw-bold">
                                <i class="bi bi-cash-coin me-2 text-success"></i>Régler ma cotisation
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <!-- Résumé de la séance -->
                            <div class="p-3 rounded-3 box-adaptive mb-3">
                                <p class="mb-1"><strong>Séance :</strong> <span id="resumeSeance"></span></p>
                                <p class="mb-1"><strong>Bénéficiaire :</strong> <span id="resumeBouffeur"></span></p>
                                <p class="mb-1"><strong>Cotisation :</strong> <span id="resumeMontant"></span></p>

                                <div id="blocPenalite" class="d-none">
                                    <hr class="my-2">
                                    <p class="mb-1 text-danger">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <strong>Pénalité de retard (<span id="resumePourcentage"></span>%) :</strong> 
                                        <span id="resumePenalite"></span>
                                    </p>
                                    <p class="mb-0 fw-bold fs-6 text-warning">
                                        Total à payer : <span id="resumeTotal"></span>
                                    </p>
                                </div>
                            </div>

                            <!-- Choix du mode de paiement -->
                            <label class="form-label fw-semibold">Sélectionnez le mode de paiement :</label>
                            <div class="d-flex flex-column gap-2">
                                <div class="form-check p-2 rounded-3 box-adaptive">
                                    <input class="form-check-input" type="radio" name="mode_paiement" id="m1" value="momo" checked>
                                    <label class="form-check-label ms-1" for="m1">
                                        <i class="bi bi-phone text-warning me-1"></i>MTN Mobile Money (MoMo)
                                    </label>
                                </div>
                                <div class="form-check p-2 rounded-3 box-adaptive">
                                    <input class="form-check-input" type="radio" name="mode_paiement" id="m2" value="om">
                                    <label class="form-check-label ms-1" for="m2">
                                        <i class="bi bi-phone text-primary me-1"></i>Orange Money (OM)
                                    </label>
                                </div>
                                <div class="form-check p-2 rounded-3 box-adaptive">
                                    <input class="form-check-input" type="radio" name="mode_paiement" id="m3" value="especes">
                                    <label class="form-check-label ms-1" for="m3">
                                        <i class="bi bi-cash text-success me-1"></i>Espèces (Remise physique)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-glow-emerald">
                                <i class="bi bi-check-circle me-1"></i>Confirmer le versement
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modalPaiement');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const contribId   = btn.getAttribute('data-contrib-id');
        const seanceNum   = btn.getAttribute('data-seance-num');
        const bouffeur    = btn.getAttribute('data-bouffeur');
        const montant     = parseFloat(btn.getAttribute('data-montant')) || 0;
        const penalite    = parseFloat(btn.getAttribute('data-penalite')) || 0;
        const pourcentage = btn.getAttribute('data-pourcentage') || '10';
        const enRetard    = btn.getAttribute('data-en-retard') === '1';

        document.getElementById('inputContribId').value       = contribId;
        document.getElementById('resumeSeance').textContent    = 'Séance n°' + seanceNum;
        document.getElementById('resumeBouffeur').textContent  = bouffeur;
        document.getElementById('resumeMontant').textContent   = montant.toLocaleString('fr-FR') + ' FCFA';

        const bloc = document.getElementById('blocPenalite');
        if (enRetard && penalite > 0) {
            const total = montant + penalite;
            document.getElementById('resumePourcentage').textContent = pourcentage;
            document.getElementById('resumePenalite').textContent    = penalite.toLocaleString('fr-FR') + ' FCFA';
            document.getElementById('resumeTotal').textContent       = total.toLocaleString('fr-FR') + ' FCFA';
            bloc.classList.remove('d-none');
        } else {
            bloc.classList.add('d-none');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
