<?php
// admin/draws.php — Tirage au sort aléatoire verrouillé et suivi des pots par bénéficiaire (Membre 2)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)$_SESSION['tontine_id'];

// 1. Récupérer le cycle actif (ou le dernier cycle si aucun n'est actif)
$stmtCycle = $pdo->prepare("
    SELECT * FROM cycles
    WHERE tontine_id = ?
    ORDER BY (statut = 'actif') DESC, numero_cycle DESC
    LIMIT 1
");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

// 2. TRAITEMENT POST : LANCEMENT DU TIRAGE AU SORT OFFICIEL ET VERROUILLAGE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lancer_tirage'])) {
    verifier_csrf();

    if (!$cycle || $cycle['statut'] !== 'actif') {
        message_flash('danger', 'Aucun cycle actif trouvé. Activez d\'abord un cycle avant de lancer le tirage.');
    } elseif ((int)$cycle['tirage_verrouille'] === 1) {
        message_flash('warning', 'Le tirage au sort de ce cycle est déjà verrouillé et ne peut plus être modifié.');
    } else {
        try {
            $pdo->beginTransaction();

            // Récupérer les membres participants à ce cycle
            $stmtParts = $pdo->prepare("SELECT id, user_id FROM cycle_members WHERE cycle_id = ? FOR UPDATE");
            $stmtParts->execute([$cycle['id']]);
            $participants = $stmtParts->fetchAll();

            $nb = count($participants);
            if ($nb < 2) {
                throw new Exception("Il faut au moins 2 participants dans le cycle pour effectuer le tirage.");
            }

            // Mélange aléatoire équitable des participants
            shuffle($participants);

            $montant_cotisation = (float)$cycle['montant_cotisation'];
            $pot_attendu        = $montant_cotisation * $nb;
            $date_base          = $cycle['date_debut'] ?: date('Y-m-d');

            // Supprimer d'éventuelles cotisations brouillon si le tirage n'était pas encore verrouillé
            $stmtClean = $pdo->prepare("DELETE FROM contributions WHERE cycle_id = ?");
            $stmtClean->execute([$cycle['id']]);

            // Attribuer les rangs 1..N et calculer la date prévue de chaque séance
            $stmtUpdCM = $pdo->prepare("
                UPDATE cycle_members
                SET rang_beneficiaire = ?, montant_pot_attendu = ?, date_bouffe_prevue = ?
                WHERE id = ?
            ");

            $seances_info = [];
            foreach ($participants as $index => $part) {
                $rang = $index + 1;
                $decalage = match ($cycle['frequence']) {
                    'hebdo'     => '+' . $index . ' weeks',
                    'bimensuel' => '+' . ($index * 15) . ' days',
                    default     => '+' . $index . ' months',
                };
                $date_seance = date('Y-m-d', strtotime($date_base . ' ' . $decalage));

                $stmtUpdCM->execute([$rang, $pot_attendu, $date_seance, $part['id']]);

                $seances_info[] = [
                    'numero_seance'               => $rang,
                    'date_echeance'               => $date_seance,
                    'beneficiaire_cycle_member_id'=> (int)$part['id'],
                ];
            }

            // Générer automatiquement le carnet des cotisations (contributions) pour chaque séance
            $stmtInsContrib = $pdo->prepare("
                INSERT INTO contributions (
                    cycle_id, numero_seance, date_echeance, cycle_member_id,
                    beneficiaire_cycle_member_id, montant_attendu, montant_paye, statut
                ) VALUES (?, ?, ?, ?, ?, ?, 0.00, 'en_attente')
            ");

            foreach ($seances_info as $seance) {
                foreach ($participants as $cotisant) {
                    $stmtInsContrib->execute([
                        (int)$cycle['id'],
                        $seance['numero_seance'],
                        $seance['date_echeance'],
                        (int)$cotisant['id'],
                        $seance['beneficiaire_cycle_member_id'],
                        $montant_cotisation
                    ]);
                }
            }

            // Verrouiller définitivement le tirage au sort du cycle
            $stmtLock = $pdo->prepare("UPDATE cycles SET tirage_verrouille = 1 WHERE id = ?");
            $stmtLock->execute([$cycle['id']]);

            $pdo->commit();
            message_flash('success', "Tirage au sort effectué et scellé avec succès ! Les {$nb} séances de cotisation ont été générées.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            message_flash('danger', 'Erreur lors du tirage au sort : ' . $e->getMessage());
        }
    }

    header('Location: ' . BASE_URL . '/admin/draws.php');
    exit;
}

// 3. Récupérer l'ordre des bénéficiaires du cycle sélectionné
$beneficiaires = [];
if ($cycle) {
    $stmtBen = $pdo->prepare("
        SELECT cm.*, u.nom_complet, u.code_public, u.role, u.canal_paiement, u.telephone_paiement,
               (SELECT COUNT(*) FROM contributions co WHERE co.cycle_id = cm.cycle_id AND co.beneficiaire_cycle_member_id = cm.id AND co.statut = 'paye') AS nb_cotisations_payees,
               (SELECT COUNT(*) FROM contributions co WHERE co.cycle_id = cm.cycle_id AND co.beneficiaire_cycle_member_id = cm.id) AS nb_cotisations_total
        FROM cycle_members cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.cycle_id = ?
        ORDER BY cm.rang_beneficiaire IS NULL ASC, cm.rang_beneficiaire ASC, u.nom_complet ASC
    ");
    $stmtBen->execute([$cycle['id']]);
    $beneficiaires = $stmtBen->fetchAll();
}

$titre_page = "Tirage au sort & Suivi des Pots";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge bg-info text-dark px-3 py-2 rounded-pill fw-bold mb-2">
                    <i class="bi bi-shuffle me-1"></i>ORDRE DES BÉNÉFICIAIRES (« BOUFFEURS »)
                </span>
                <h1 class="fw-bold h2 mb-1">Tirage au Sort & Réception des Pots</h1>
                <p class="text-muted small mb-0">
                    Le tirage est réalisé une seule fois par cycle puis verrouillé pour garantir une équité totale entre tous les membres.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="<?= BASE_URL ?>/admin/cycles.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i>Gestion des cycles
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$cycle): ?>
        <div class="card card-djangui p-5 text-center">
            <i class="bi bi-exclamation-circle fs-1 text-warning mb-3"></i>
            <h4 class="fw-bold">Aucun cycle disponible</h4>
            <p class="text-muted">Créez et activez d'abord un cycle de cotisation pour organiser le tirage au sort.</p>
            <div>
                <a href="<?= BASE_URL ?>/admin/cycles.php" class="btn btn-glow-emerald px-4">Créer un cycle</a>
            </div>
        </div>
    <?php else: ?>

        <!-- BANNIÈRE D'ÉTAT DU TIRAGE DU CYCLE -->
        <div class="card-accent-djangui p-4 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-primary">CYCLE #<?= (int)$cycle['numero_cycle'] ?></span>
                        <?php if ((int)$cycle['tirage_verrouille'] === 1): ?>
                            <span class="badge bg-success"><i class="bi bi-shield-lock-fill me-1"></i>Tirage Officiel Verrouillé</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>En attente de tirage</span>
                        <?php endif; ?>
                    </div>
                    <h4 class="fw-bold mb-1"><?= e($cycle['titre'] ?? ('Cycle #' . $cycle['numero_cycle'])) ?></h4>
                    <p class="text-muted small mb-0">
                        Cotisation : <strong><?= format_fcfa($cycle['montant_cotisation']) ?></strong> |
                        Participants : <strong><?= count($beneficiaires) ?> membres</strong> |
                        Pot complet par bénéficiaire : <strong><?= format_fcfa((float)$cycle['montant_cotisation'] * count($beneficiaires)) ?></strong>
                    </p>
                </div>

                <div class="col-lg-4 text-lg-end">
                    <?php if ((int)$cycle['tirage_verrouille'] === 0 && $cycle['statut'] === 'actif'): ?>
                        <form method="POST" action="draws.php" id="formTirage">
                            <?= champ_csrf() ?>
                            <input type="hidden" name="lancer_tirage" value="1">
                            <button type="submit" class="btn btn-glow-emerald btn-lg px-4" id="btnLancerTirage">
                                <i class="bi bi-dice-5-fill me-2"></i>Lancer le tirage au sort
                            </button>
                        </form>
                    <?php elseif ((int)$cycle['tirage_verrouille'] === 1): ?>
                        <div class="p-3 rounded-3 accent-subbox d-inline-block text-start small">
                            <div class="fw-bold text-success"><i class="bi bi-check-circle-fill me-1"></i>Ordre scellé pour tout le cycle</div>
                            <div class="text-muted">Aucune modification manuelle n'est autorisée.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Animation visuelle lors du clic sur "Lancer le tirage" -->
        <div id="animationTirageBox" class="card card-djangui p-5 text-center mb-4 d-none">
            <div class="spinner-border text-primary mx-auto mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
            <h4 class="fw-bold text-primary" id="nomRoulette">Mélange aléatoire des membres en cours...</h4>
            <p class="text-muted small mb-0">Attribution des rangs et création automatique du calendrier des séances...</p>
        </div>

        <!-- TABLEAU DE L'ORDRE DES BOUFFEURS ET SUIVI DES POTS -->
        <div class="card card-djangui p-4">
            <h5 class="fw-bold mb-3">
                <i class="bi bi-trophy-fill text-warning me-2"></i>Ordre officiel de passage et suivi de réception des pots
            </h5>

            <div class="table-responsive">
                <table class="table table-djangui align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Rang / Séance</th>
                            <th>Membre Bénéficiaire (« Bouffeur »)</th>
                            <th>Compte de réception du pot</th>
                            <th>Date prévue</th>
                            <th>Progression du Pot reçu</th>
                            <th>Statut du Pot</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($beneficiaires as $b): ?>
                            <?php
                            $attendu = (float)$b['montant_pot_attendu'];
                            $recu    = (float)$b['montant_pot_recu'];
                            $reste   = max(0, $attendu - $recu);
                            $pct     = ($attendu > 0) ? round(($recu / $attendu) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td>
                                    <?php if (!empty($b['rang_beneficiaire'])): ?>
                                        <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill">
                                            Tour n°<?= (int)$b['rang_beneficiaire'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Non tiré</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold">
                                        <?= e($b['nom_complet']) ?>
                                        <?php if ($b['role'] === 'admin'): ?>
                                            <span class="badge bg-primary-subtle text-primary ms-1">Secrétaire</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted"><?= e($b['code_public']) ?></div>
                                </td>
                                <td class="small">
                                    <span class="badge bg-info-subtle text-info-emphasis text-uppercase me-1">
                                        <?= e($b['canal_paiement']) ?>
                                    </span>
                                    <span class="fw-semibold"><?= e($b['telephone_paiement']) ?></span>
                                </td>
                                <td class="small fw-semibold">
                                    <?= !empty($b['date_bouffe_prevue']) ? e($b['date_bouffe_prevue']) : '—' ?>
                                </td>
                                <td style="min-width: 230px;">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="fw-bold text-success"><?= format_fcfa($recu) ?></span>
                                        <span class="text-muted">sur <?= format_fcfa($attendu) ?></span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?= min(100, $pct) ?>%"></div>
                                    </div>
                                    <?php if ($reste > 0 && !empty($b['rang_beneficiaire'])): ?>
                                        <div class="small text-warning-emphasis mt-1" style="font-size: 0.75rem;">
                                            Reste à percevoir : <strong><?= format_fcfa($reste) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$b['pot_totalement_recu'] === 1 || ($attendu > 0 && $recu >= $attendu)): ?>
                                        <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill">
                                            <i class="bi bi-check-circle-fill me-1"></i>Pot 100 % reçu
                                        </span>
                                    <?php elseif ($recu > 0): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis px-3 py-2 rounded-pill">
                                            <i class="bi bi-clock-history me-1"></i>Partiel (Reliquat attendu)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary px-3 py-2 rounded-pill">
                                            En attente de son tour
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const formTirage = document.getElementById('formTirage');
    const animBox = document.getElementById('animationTirageBox');
    const btnTirage = document.getElementById('btnLancerTirage');

    if (formTirage && animBox && btnTirage) {
        formTirage.addEventListener('submit', function (e) {
            e.preventDefault();
            btnTirage.disabled = true;
            animBox.classList.remove('d-none');
            setTimeout(function () {
                formTirage.submit();
            }, 1400);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>