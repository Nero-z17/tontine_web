<?php
/**
 * =============================================================================
 * MEMBRE 3 : member/contributions.php
 * =============================================================================
 * Liste des cotisations du membre connecté et paiement avec :
 *  - Point 1 : Verrouillage séquentiel (impossible de cotiser la séance N tant que N-1 n'est pas clôturée)
 *  - Point 2 : Taux de pénalité centralisé (pas de formulaire en doublon ici)
 *  - Point 4 : Choix libre du montant exact à payer (plafonné à cotisation restante + dettes et pénalités)
 *  - Point 5 : La propre part du bouffeur est automatiquement compensée sur son pot
 *  - Point 10 : Affichage des plages de dates exactes par séance
 * =============================================================================
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];
$est_admin  = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// 1. CYCLE ACTIF & PARTICIPATION DU MEMBRE
$stmtCycle = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? AND statut = 'actif' LIMIT 1");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

$mon_cycle_member   = null;
$seance_max_ouverte = 1;

if ($cycle) {
    $cid = (int)$cycle['id'];
    $seance_max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

    $stmtCM = $pdo->prepare("SELECT * FROM cycle_members WHERE cycle_id = ? AND user_id = ? LIMIT 1");
    $stmtCM->execute([$cid, $user_id]);
    $mon_cycle_member = $stmtCM->fetch();
}

$montant_cotis_cycle = $cycle ? (float)$cycle['montant_cotisation'] : 0.0;
$penalite_montant    = $cycle ? (float)($cycle['montant_penalite_retard'] ?? 0) : 0.0;
$pourcentage_penalite = ($cycle && $montant_cotis_cycle > 0)
    ? round(($penalite_montant / $montant_cotis_cycle) * 100, 1)
    : 0.0;

// 2. ACTION MEMBRE : TRAITEMENT D'UN PAIEMENT AU MONTANT CHOISI (POINTS 1, 4, 5)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payer_cotisation' && $cycle && $mon_cycle_member) {
    verifier_csrf();

    $contribution_id = (int)($_POST['contribution_id'] ?? 0);
    $mode_paiement   = trim($_POST['mode_paiement'] ?? '');
    $montant_saisi   = filter_var($_POST['montant_saisi'] ?? null, FILTER_VALIDATE_FLOAT);

    $modes_autorises = ['momo', 'om', 'especes'];
    if (!in_array($mode_paiement, $modes_autorises, true)) {
        message_flash('danger', 'Veuillez sélectionner un mode de paiement valide (MoMo, OM ou Espèces).');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    // Récupérer la cotisation ciblée du membre
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

    $num_seance = (int)$cotisation['numero_seance'];

    // POINT 1 : Interdire le paiement d'une séance si la séance précédente n'est pas encore clôturée
    if ($num_seance > $seance_max_ouverte) {
        message_flash('danger', "Action refusée : vous ne pouvez pas cotiser pour la Séance #{$num_seance} tant que la Séance #" . ($num_seance - 1) . " n'est pas clôturée.");
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    // Vérifier aussi que le membre n'a pas laissé une séance antérieure (< $num_seance) totalement impayée sans la régler d'abord
    $stmtDettesAnt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_attendu - montant_paye), 0)
        FROM contributions
        WHERE cycle_member_id = ?
          AND beneficiaire_cycle_member_id != ?
          AND numero_seance < ?
          AND montant_paye < montant_attendu
    ");
    $stmtDettesAnt->execute([(int)$mon_cycle_member['id'], (int)$mon_cycle_member['id'], $num_seance]);
    $dettes_anterieures = (float)$stmtDettesAnt->fetchColumn();

    if ($cotisation['statut'] === 'paye' || (float)$cotisation['montant_paye'] >= (float)$cotisation['montant_attendu']) {
        message_flash('warning', 'Vous avez déjà entièrement réglé cette cotisation.');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    // Calcul du reste à cotiser sur cette séance et de la pénalité en attente éventuelle (Point 4)
    $reste_cotisation = max(0.0, (float)$cotisation['montant_attendu'] - (float)$cotisation['montant_paye']);
    $date_echeance    = $cotisation['date_echeance'];
    $aujourdhui       = date_actuelle('Y-m-d');
    $est_en_retard    = ((int)$cotisation['cycle_member_id'] !== (int)$cotisation['beneficiaire_cycle_member_id'])
                        && ((strtotime($date_echeance) < strtotime($aujourdhui)) || ($cotisation['statut'] === 'en_retard'));

    // Vérifier le montant exact de la pénalité encore en attente sur cette séance (ou toutes pénalités en attente du membre)
    $stmtPenDue = $pdo->prepare("
        SELECT id, montant
        FROM penalties
        WHERE cycle_member_id = ?
          AND (contribution_id = ? OR contribution_id IS NULL)
          AND type_operation = 'penalite'
          AND statut = 'en_attente'
        ORDER BY (contribution_id = ?) DESC, id ASC
    ");
    $stmtPenDue->execute([(int)$mon_cycle_member['id'], $contribution_id, $contribution_id]);
    $penalites_attente = $stmtPenDue->fetchAll();

    $total_penalites_dues = 0.0;
    foreach ($penalites_attente as $pa) {
        $total_penalites_dues += (float)$pa['montant'];
    }
    if ($est_en_retard && $total_penalites_dues == 0.0 && $penalite_montant > 0) {
        // Vérifier qu'elle n'a pas déjà été réglée ou annulée
        $stmtDejaReglee = $pdo->prepare("SELECT id FROM penalties WHERE contribution_id = ? AND statut IN ('regle', 'annule') LIMIT 1");
        $stmtDejaReglee->execute([$contribution_id]);
        if (!$stmtDejaReglee->fetchColumn()) {
            $total_penalites_dues = $penalite_montant;
        }
    }

    // Plafond maximal autorisé (Point 4 : cotisation de la séance + dettes antérieures + pénalités)
    $plafond_max = round($reste_cotisation + $dettes_anterieures + $total_penalites_dues, 2);

    if ($montant_saisi === false || $montant_saisi <= 0) {
        message_flash('danger', 'Veuillez saisir un montant à payer supérieur à 0 FCFA.');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    if ($montant_saisi > $plafond_max + 0.01) {
        message_flash('danger', 'Montant refusé : vous ne pouvez pas payer plus que le total dû (' . format_fcfa($plafond_max) . ').');
        header('Location: ' . BASE_URL . '/member/contributions.php');
        exit;
    }

    $montant_disponible = round((float)$montant_saisi, 2);

    try {
        $pdo->beginTransaction();

        $prefixe = strtoupper($mode_paiement);
        $total_vers_bouffeurs = 0.0;
        $total_vers_caisse    = 0.0;

        // ÉTAPE 1 : Si le membre avait des dettes de cotisations antérieures et paie un montant qui les couvre,
        //           ou s'il règle d'abord la séance sélectionnée :
        //           On affecte d'abord à la cotisation de la séance sélectionnée (jusqu'à $reste_cotisation)
        $part_seance_courante = min($montant_disponible, $reste_cotisation);
        $montant_disponible   = round($montant_disponible - $part_seance_courante, 2);

        // Si le membre a saisi plus que la séance courante et qu'il avait des dettes de séances précédentes, on les solde
        if ($montant_disponible > 0 && $dettes_anterieures > 0) {
            $stmtOld = $pdo->prepare("
                SELECT id, beneficiaire_cycle_member_id, numero_seance, montant_attendu, montant_paye
                FROM contributions
                WHERE cycle_member_id = ?
                  AND beneficiaire_cycle_member_id != ?
                  AND numero_seance < ?
                  AND montant_paye < montant_attendu
                ORDER BY numero_seance ASC
            ");
            $stmtOld->execute([(int)$mon_cycle_member['id'], (int)$mon_cycle_member['id'], $num_seance]);

            foreach ($stmtOld->fetchAll() as $oldCo) {
                if ($montant_disponible <= 0) {
                    break;
                }
                $reste_old = max(0.0, (float)$oldCo['montant_attendu'] - (float)$oldCo['montant_paye']);
                $paye_old  = min($montant_disponible, $reste_old);

                if ($paye_old > 0) {
                    $nv_paye_old   = (float)$oldCo['montant_paye'] + $paye_old;
                    $nv_statut_old = ($nv_paye_old >= (float)$oldCo['montant_attendu']) ? 'paye' : 'en_retard';

                    $pdo->prepare("UPDATE contributions SET montant_paye = ?, statut = ? WHERE id = ?")
                        ->execute([$nv_paye_old, $nv_statut_old, (int)$oldCo['id']]);

                    $pdo->prepare("
                        UPDATE cycle_members
                        SET montant_pot_recu = montant_pot_recu + ?,
                            pot_totalement_recu = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu THEN 1 ELSE 0 END,
                            date_reception_pot = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu AND date_reception_pot IS NULL THEN NOW() ELSE date_reception_pot END
                        WHERE id = ?
                    ")->execute([$paye_old, $paye_old, $paye_old, (int)$oldCo['beneficiaire_cycle_member_id']]);

                    $ref_old = $prefixe . date('ymd') . '.' . str_pad((string)$oldCo['id'], 3, '0', STR_PAD_LEFT) . '.' . random_int(10, 99);
                    $pdo->prepare("
                        INSERT INTO payments (
                            contribution_id, paye_par_user_id, montant_total,
                            part_beneficiaire, part_caisse_reserve, mode_paiement,
                            reference_paiement, date_paiement, est_en_retard,
                            statut_paiement, commentaire
                        ) VALUES (?, ?, ?, ?, 0.00, ?, ?, NOW(), 1, 'valide', ?)
                    ")->execute([
                        (int)$oldCo['id'],
                        $user_id,
                        $paye_old,
                        $paye_old,
                        $mode_paiement,
                        $ref_old,
                        "Règlement dette antérieure (Séance #{$oldCo['numero_seance']})"
                    ]);

                    $total_vers_bouffeurs += $paye_old;
                    $montant_disponible    = round($montant_disponible - $paye_old, 2);
                }
            }
        }

        // ÉTAPE 2 : Le surplus éventuel (jusqu'à $total_penalites_dues) règle les pénalités vers la Caisse de réserve
        $part_caisse_reserve = min($montant_disponible, $total_penalites_dues);
        $total_seance_et_pen = round($part_seance_courante + $part_caisse_reserve, 2);

        if ($total_seance_et_pen > 0) {
            $ref_paiement = $prefixe . date('ymd') . '.' . str_pad((string)$contribution_id, 3, '0', STR_PAD_LEFT) . '.' . random_int(10, 99);
            $nouveau_montant_paye = round((float)$cotisation['montant_paye'] + $part_seance_courante, 2);
            $est_soldee = ($nouveau_montant_paye >= (float)$cotisation['montant_attendu'] - 0.01);
            $nouveau_statut_co = $est_soldee ? 'paye' : ($est_en_retard ? 'en_retard' : 'partiel');

            $commentaire = $est_soldee
                ? ($est_en_retard ? "Solde cotisation en retard" : "Paiement cotisation Séance #{$num_seance}")
                : "Versement partiel (" . format_fcfa($part_seance_courante) . ") sur Séance #{$num_seance}";

            // 1. Insertion du reçu dans payments
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
                $total_seance_et_pen,
                $part_seance_courante,
                $part_caisse_reserve,
                $mode_paiement,
                $ref_paiement,
                $est_en_retard ? 1 : 0,
                $commentaire
            ]);

            // 2. Mise à jour de contributions
            $stmtUpdateContrib = $pdo->prepare("
                UPDATE contributions 
                SET montant_paye = ?, statut = ?
                WHERE id = ?
            ");
            $stmtUpdateContrib->execute([$nouveau_montant_paye, $nouveau_statut_co, $contribution_id]);

            // 3. Créditer le pot du bouffeur de la séance
            if ($part_seance_courante > 0) {
                $stmtUpdatePot = $pdo->prepare("
                    UPDATE cycle_members 
                    SET montant_pot_recu = montant_pot_recu + ?,
                        pot_totalement_recu = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu THEN 1 ELSE 0 END,
                        date_reception_pot = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu AND date_reception_pot IS NULL THEN NOW() ELSE date_reception_pot END
                    WHERE id = ?
                ");
                $stmtUpdatePot->execute([
                    $part_seance_courante,
                    $part_seance_courante,
                    $part_seance_courante,
                    (int)$cotisation['beneficiaire_cycle_member_id']
                ]);
                $total_vers_bouffeurs += $part_seance_courante;
            }

            // 4. Si une part va à la caisse de réserve (pénalités réglées)
            if ($part_caisse_reserve > 0) {
                $pdo->prepare("UPDATE tontines SET solde_caisse_reserve = solde_caisse_reserve + ? WHERE id = ?")
                    ->execute([$part_caisse_reserve, $tontine_id]);

                $reste_pen_a_imputer = $part_caisse_reserve;
                foreach ($penalites_attente as $pa) {
                    if ($reste_pen_a_imputer <= 0) {
                        break;
                    }
                    $m_pa = (float)$pa['montant'];
                    if ($reste_pen_a_imputer >= $m_pa - 0.01) {
                        $pdo->prepare("UPDATE penalties SET statut = 'regle', date_reglement = NOW() WHERE id = ?")
                            ->execute([(int)$pa['id']]);
                        $reste_pen_a_imputer -= $m_pa;
                    } else {
                        $pdo->prepare("UPDATE penalties SET montant = ? WHERE id = ?")
                            ->execute([$m_pa - $reste_pen_a_imputer, (int)$pa['id']]);
                        $reste_pen_a_imputer = 0;
                    }
                }
                $total_vers_caisse += $part_caisse_reserve;
            }
        }

        $pdo->commit();

        $msg = 'Versement de ' . format_fcfa($montant_saisi) . ' enregistré avec succès (Part Bouffeur : ' . format_fcfa($total_vers_bouffeurs);
        if ($total_vers_caisse > 0) {
            $msg .= ' | Part Caisse de réserve : ' . format_fcfa($total_vers_caisse);
        }
        $msg .= ').';
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

// 3. RÉCUPÉRATION DES SÉANCES DU MEMBRE CONNECTÉ
$seances = [];
$mes_penalites_attente = 0.0;
$mes_dettes_totales    = 0.0;

if ($cycle && $mon_cycle_member) {
    $stmtPenTot = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0)
        FROM penalties
        WHERE cycle_member_id = ? AND type_operation = 'penalite' AND statut = 'en_attente'
    ");
    $stmtPenTot->execute([(int)$mon_cycle_member['id']]);
    $mes_penalites_attente = (float)$stmtPenTot->fetchColumn();

    $stmtSeances = $pdo->prepare("
        SELECT 
            c.id AS contribution_id,
            c.cycle_member_id,
            c.beneficiaire_cycle_member_id,
            c.numero_seance,
            c.date_echeance,
            c.montant_attendu,
            c.montant_paye,
            c.statut AS statut_contrib,
            u_ben.nom_complet AS ben_nom,
            (SELECT p.mode_paiement FROM payments p WHERE p.contribution_id = c.id AND p.statut_paiement = 'valide' ORDER BY p.id DESC LIMIT 1) AS mode_paiement,
            (SELECT COALESCE(SUM(pen.montant), 0) FROM penalties pen WHERE pen.contribution_id = c.id AND pen.type_operation = 'penalite' AND pen.statut = 'en_attente') AS penalite_seance_attente
        FROM contributions c
        JOIN cycle_members cm_bouffeur ON cm_bouffeur.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_bouffeur.user_id
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
            Suivi et paiement de vos séances — Cycle <?= $cycle ? '#' . (int)$cycle['numero_cycle'] . ' (' . e($cycle['titre']) . ')' : '—' ?>
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

        <!-- Rappel des règles de cotisation, de pénalité et d'auto-compensation -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3 p-3 rounded-3 box-adaptive">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-warning text-dark"><i class="bi bi-shield-exclamation me-1"></i>Règles du cycle</span>
                <span class="small">
                    Pénalité de retard : <strong><?= $pourcentage_penalite ?>%</strong> (<strong><?= format_fcfa($penalite_montant) ?></strong>) ·
                    Séance ouverte : <strong>Séance #<?= $seance_max_ouverte ?></strong>
                </span>
            </div>
            <div class="small text-muted mt-2 mt-md-0">
                <i class="bi bi-info-circle me-1"></i>Lors de votre tour de bouffe, votre part et vos dettes éventuelles sont déduites directement de votre pot.
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
                            <th>Période de la séance</th>
                            <th>Versé / Attendu</th>
                            <th>Reste à régler</th>
                            <th>Statut</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $aujourdhui = date_actuelle('Y-m-d');
                        $cumul_dettes_precedentes = 0.0;

                        foreach ($seances as $s): 
                            $num_s          = (int)$s['numero_seance'];
                            $est_mon_tour   = ((int)$s['cycle_member_id'] === (int)$s['beneficiaire_cycle_member_id']);
                            $deja_paye      = ($s['statut_contrib'] === 'paye' || (float)$s['montant_paye'] >= (float)$s['montant_attendu']);
                            $reste_seance   = max(0.0, (float)$s['montant_attendu'] - (float)$s['montant_paye']);
                            $date_ech       = strtotime($s['date_echeance']);
                            $en_retard      = (!$deja_paye && !$est_mon_tour && $date_ech < strtotime($aujourdhui));
                            $est_verrouillee = ($num_s > $seance_max_ouverte);
                            $penalite_seance = (float)$s['penalite_seance_attente'];
                            $periode_label   = formater_periode_seance($cycle['date_debut'], $num_s, $cycle['frequence'], $s['date_echeance']);

                            // Plafond payable depuis cette ligne = reste de cette séance + dettes antérieures + pénalités en attente
                            $max_payable_ligne = round($reste_seance + $cumul_dettes_precedentes + $mes_penalites_attente, 2);
                        ?>
                        <tr class="<?= $est_mon_tour ? 'table-primary bg-opacity-10' : '' ?>">
                            <td class="fw-bold">
                                #<?= $num_s ?>
                                <?php if ($est_mon_tour): ?>
                                    <span class="badge bg-success ms-1">Mon Tour</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($s['ben_nom']) ?></td>
                            <td class="small">
                                <i class="bi bi-calendar3 me-1 text-primary"></i>
                                <?= e($periode_label) ?>
                            </td>
                            <td>
                                <strong><?= format_fcfa($s['montant_paye']) ?></strong>
                                <span class="text-muted small">/ <?= format_fcfa($s['montant_attendu']) ?></span>
                            </td>
                            <td>
                                <?php if ($deja_paye): ?>
                                    <span class="text-success small fw-semibold">0 FCFA</span>
                                <?php else: ?>
                                    <strong class="text-danger"><?= format_fcfa($reste_seance) ?></strong>
                                    <?php if ($penalite_seance > 0): ?>
                                        <br><small class="text-danger fw-semibold">+ <?= format_fcfa($penalite_seance) ?> (Pénalité)</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($est_mon_tour && $deja_paye): ?>
                                    <span class="badge bg-info text-dark"><i class="bi bi-arrow-left-right me-1"></i>Déduit sur mon pot</span>
                                <?php elseif ($deja_paye): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Payé</span>
                                <?php elseif ($est_verrouillee): ?>
                                    <span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Séance #<?= $num_s - 1 ?> non clôturée</span>
                                <?php elseif ($en_retard): ?>
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>En retard</span>
                                <?php elseif ((float)$s['montant_paye'] > 0): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-pie-chart me-1"></i>Partiel</span>
                                <?php else: ?>
                                    <span class="badge bg-primary"><i class="bi bi-clock me-1"></i>Ouverte</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($est_mon_tour): ?>
                                    <span class="text-primary small fw-semibold">
                                        <i class="bi bi-shield-check me-1"></i>Compensé sur votre pot
                                    </span>
                                <?php elseif ($deja_paye): ?>
                                    <span class="text-success small fw-semibold">
                                        <i class="bi bi-check2-all me-1"></i><?= e(strtoupper($s['mode_paiement'] ?: 'RÉGLÉ')) ?>
                                    </span>
                                <?php elseif ($est_verrouillee): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Attendez la clôture de la séance précédente">
                                        <i class="bi bi-lock-fill me-1"></i>Verrouillé
                                    </button>
                                <?php else: ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-glow-emerald"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalPaiement"
                                            data-contrib-id="<?= (int)$s['contribution_id'] ?>"
                                            data-seance-num="<?= $num_s ?>"
                                            data-bouffeur="<?= e($s['ben_nom']) ?>"
                                            data-reste-seance="<?= $reste_seance ?>"
                                            data-dettes-ant="<?= $cumul_dettes_precedentes ?>"
                                            data-penalites="<?= $mes_penalites_attente ?>"
                                            data-max-payable="<?= $max_payable_ligne ?>">
                                        <i class="bi bi-credit-card me-1"></i>Payer
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                            if (!$deja_paye && !$est_mon_tour) {
                                $cumul_dettes_precedentes += $reste_seance;
                            }
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal de paiement avec saisie du montant exact (Point 4) -->
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
                            <div class="p-3 rounded-3 box-adaptive mb-3 small">
                                <p class="mb-1"><strong>Séance :</strong> <span id="resumeSeance"></span></p>
                                <p class="mb-1"><strong>Bénéficiaire (Bouffeur) :</strong> <span id="resumeBouffeur"></span></p>
                                <p class="mb-1"><strong>Reste sur cette séance :</strong> <span id="resumeResteSeance" class="fw-bold"></span></p>

                                <div id="blocDettesAnt" class="d-none text-warning-emphasis">
                                    <p class="mb-1"><strong>+ Dettes séances précédentes :</strong> <span id="resumeDettesAnt"></span></p>
                                </div>

                                <div id="blocPenalite" class="d-none text-danger">
                                    <p class="mb-1"><strong>+ Pénalités en attente :</strong> <span id="resumePenalite"></span></p>
                                </div>

                                <hr class="my-2">
                                <p class="mb-0 fw-bold fs-6 text-primary">
                                    Plafond total autorisé : <span id="resumeTotalMax"></span>
                                </p>
                            </div>

                            <!-- Point 4 : Choisir le montant exact à payer -->
                            <div class="mb-3">
                                <label for="inputMontantSaisi" class="form-label fw-bold">
                                    Montant exact à verser maintenant (FCFA) *
                                </label>
                                <div class="input-group">
                                    <input type="number"
                                           name="montant_saisi"
                                           id="inputMontantSaisi"
                                           class="form-control form-control-lg fw-bold text-success"
                                           min="100"
                                           step="100"
                                           required>
                                    <span class="input-group-text fw-bold">FCFA</span>
                                </div>
                                <div class="form-text small">
                                    Vous pouvez verser une avance partielle ou solder la totalité (sans dépasser le plafond).
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
        const btn         = event.relatedTarget;
        const contribId   = btn.getAttribute('data-contrib-id');
        const seanceNum   = btn.getAttribute('data-seance-num');
        const bouffeur    = btn.getAttribute('data-bouffeur');
        const resteSeance = parseFloat(btn.getAttribute('data-reste-seance')) || 0;
        const dettesAnt   = parseFloat(btn.getAttribute('data-dettes-ant')) || 0;
        const penalites   = parseFloat(btn.getAttribute('data-penalites')) || 0;
        const maxPayable  = parseFloat(btn.getAttribute('data-max-payable')) || resteSeance;

        document.getElementById('inputContribId').value          = contribId;
        document.getElementById('resumeSeance').textContent      = 'Séance n°' + seanceNum;
        document.getElementById('resumeBouffeur').textContent    = bouffeur;
        document.getElementById('resumeResteSeance').textContent = resteSeance.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('resumeTotalMax').textContent    = maxPayable.toLocaleString('fr-FR') + ' FCFA';

        const inputMontant = document.getElementById('inputMontantSaisi');
        inputMontant.max   = maxPayable;
        inputMontant.value = resteSeance + (penalites > 0 ? penalites : 0);

        const blocDettes = document.getElementById('blocDettesAnt');
        if (dettesAnt > 0) {
            document.getElementById('resumeDettesAnt').textContent = dettesAnt.toLocaleString('fr-FR') + ' FCFA';
            blocDettes.classList.remove('d-none');
        } else {
            blocDettes.classList.add('d-none');
        }

        const blocPen = document.getElementById('blocPenalite');
        if (penalites > 0) {
            document.getElementById('resumePenalite').textContent = penalites.toLocaleString('fr-FR') + ' FCFA';
            blocPen.classList.remove('d-none');
        } else {
            blocPen.classList.add('d-none');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
