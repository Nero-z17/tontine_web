<?php
// admin/cycles.php — Gestion des cycles successifs de la tontine (Création %, Activation, Suppression brouillon, Clôture)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];

// 1. Récupérer les informations de la tontine et vérifier l'abonnement SaaS
$stmtTontine = $pdo->prepare("SELECT * FROM tontines WHERE id = ? LIMIT 1");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

$abonnement_valide = true;
if ($tontine) {
    $fin_essai = !empty($tontine['date_fin_essai']) ? strtotime($tontine['date_fin_essai']) : 0;
    if ($tontine['statut_abonnement'] === 'expire' || ($tontine['statut_abonnement'] === 'essai' && $fin_essai < temps_actuel())) {
        $abonnement_valide = false;
    }
}

// 2. Récupérer tous les membres actifs et validés de la tontine
$stmtMembres = $pdo->prepare("SELECT id, code_public, nom_complet, role, telephone FROM users WHERE tontine_id = ? AND statut_membre = 'actif' AND statut_validation = 'accepte' ORDER BY role ASC, nom_complet ASC");
$stmtMembres->execute([$tontine_id]);
$membres_actifs = $stmtMembres->fetchAll();

// 3. TRAITEMENT DES ACTIONS POST (Créer, Activer, Supprimer brouillon, Clôturer un cycle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    // ACTION A : Créer un nouveau cycle (en brouillon) avec pénalité en pourcentage (%) (Point 2 & Point 10)
    if ($action === 'creer_cycle') {
        $titre                = trim($_POST['titre'] ?? '');
        $montant_cotisation   = (float)($_POST['montant_cotisation'] ?? 0);
        $frequence            = $_POST['frequence'] ?? 'mensuel';
        $pourcentage_penalite = filter_var($_POST['pourcentage_penalite'] ?? 5, FILTER_VALIDATE_FLOAT);
        $date_debut           = $_POST['date_debut'] ?? date_actuelle('Y-m-d');
        $participants         = $_POST['participants'] ?? [];

        if ($tontine && $tontine['statut'] === 'fermee') {
            message_flash('danger', 'Impossible de créer un cycle : cette tontine est définitivement fermée.');
        } elseif ($montant_cotisation <= 0) {
            message_flash('danger', 'Le montant de la cotisation doit être supérieur à 0 FCFA.');
        } elseif (!in_array($frequence, ['hebdo', 'bimensuel', 'mensuel'], true)) {
            message_flash('danger', 'La fréquence choisie est invalide.');
        } elseif ($pourcentage_penalite === false || $pourcentage_penalite < 0 || $pourcentage_penalite > 100) {
            message_flash('danger', 'Le pourcentage de pénalité doit être compris entre 0 % et 100 %.');
        } elseif (count($participants) < 2) {
            message_flash('danger', 'Veuillez sélectionner au moins 2 membres participants pour créer un cycle.');
        } else {
            try {
                $pdo->beginTransaction();

                $stmtNum = $pdo->prepare("SELECT COALESCE(MAX(numero_cycle), 0) + 1 FROM cycles WHERE tontine_id = ?");
                $stmtNum->execute([$tontine_id]);
                $prochain_numero = (int)$stmtNum->fetchColumn();

                if ($titre === '') {
                    $titre = 'Cycle #' . $prochain_numero;
                }

                $nb_participants = count($participants);
                // Conversion du pourcentage en montant FCFA par séance (Point 2)
                $penalite_retard = round($montant_cotisation * ($pourcentage_penalite / 100));
                // Date de fin = échéance de la dernière séance (Point 10 : Séance 1 = J0, Séance N = J0 + (N-1) périodes)
                $date_fin_prevue = calculer_echeance_seance($date_debut, $nb_participants, $frequence);

                $stmtInsert = $pdo->prepare("
                    INSERT INTO cycles (
                        tontine_id, numero_cycle, titre, montant_cotisation, frequence,
                        montant_penalite_retard, date_debut, date_fin, statut, tirage_verrouille
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'brouillon', 0)
                ");
                $stmtInsert->execute([
                    $tontine_id,
                    $prochain_numero,
                    $titre,
                    $montant_cotisation,
                    $frequence,
                    $penalite_retard,
                    $date_debut,
                    $date_fin_prevue
                ]);
                $nouveau_cycle_id = (int)$pdo->lastInsertId();

                $pot_attendu = $montant_cotisation * $nb_participants;
                $stmtCM = $pdo->prepare("
                    INSERT INTO cycle_members (
                        cycle_id, user_id, rang_beneficiaire, montant_pot_attendu, montant_pot_recu, pot_totalement_recu
                    ) VALUES (?, ?, NULL, ?, 0.00, 0)
                ");

                foreach ($participants as $membre_id) {
                    $stmtCM->execute([$nouveau_cycle_id, (int)$membre_id, $pot_attendu]);
                }

                $pdo->commit();
                message_flash('success', "Le {$titre} a été créé en brouillon ({$nb_participants} participants, pénalité fixée à {$pourcentage_penalite}% soit " . format_fcfa($penalite_retard) . ").");
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                message_flash('danger', 'Erreur lors de la création du cycle : ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/admin/cycles.php');
        exit;
    }

    // ACTION B : Activer un cycle en brouillon
    if ($action === 'activer_cycle') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);

        if (!$abonnement_valide) {
            message_flash('danger', 'Activation bloquée : votre période d\'essai ou votre abonnement SaaS a expiré. Renouvelez votre abonnement (2 000 FCFA/mois).');
            header('Location: ' . BASE_URL . '/admin/subscription.php');
            exit;
        }

        $stmtActif = $pdo->prepare("SELECT id FROM cycles WHERE tontine_id = ? AND statut = 'actif' LIMIT 1");
        $stmtActif->execute([$tontine_id]);
        if ($stmtActif->fetch()) {
            message_flash('warning', 'Un cycle est déjà actif dans votre tontine. Clôturez le cycle en cours avant d\'en activer un nouveau.');
        } else {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM cycle_members WHERE cycle_id = ?");
            $stmtCount->execute([$cycle_id]);
            $nb_inscrits = (int)$stmtCount->fetchColumn();

            if ($nb_inscrits < 2) {
                message_flash('danger', 'Ce cycle doit compter au moins 2 participants pour être activé.');
            } else {
                $stmtUpd = $pdo->prepare("UPDATE cycles SET statut = 'actif' WHERE id = ? AND tontine_id = ? AND statut = 'brouillon'");
                $stmtUpd->execute([$cycle_id, $tontine_id]);
                message_flash('success', 'Le cycle est maintenant ACTIF ! Rendez-vous sur la page « Tirage » pour tirer au sort l\'ordre des bouffeurs.');
            }
        }
        header('Location: ' . BASE_URL . '/admin/cycles.php');
        exit;
    }

    // ACTION C : Supprimer un cycle en statut 'brouillon' (Point 3)
    if ($action === 'supprimer_cycle') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        try {
            $stmtDel = $pdo->prepare("DELETE FROM cycles WHERE id = ? AND tontine_id = ? AND statut = 'brouillon'");
            $stmtDel->execute([$cycle_id, $tontine_id]);
            if ($stmtDel->rowCount() === 1) {
                message_flash('success', 'Le cycle en brouillon a été supprimé avec succès.');
            } else {
                message_flash('warning', 'Impossible de supprimer ce cycle : seuls les cycles en statut « Brouillon » peuvent être supprimés.');
            }
        } catch (PDOException $e) {
            message_flash('danger', 'Erreur lors de la suppression du brouillon.');
        }
        header('Location: ' . BASE_URL . '/admin/cycles.php');
        exit;
    }

    // ACTION D : Clôturer un cycle actif en fin de parcours
    if ($action === 'cloturer_cycle') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $stmtUpd = $pdo->prepare("UPDATE cycles SET statut = 'termine' WHERE id = ? AND tontine_id = ? AND statut = 'actif'");
        $stmtUpd->execute([$cycle_id, $tontine_id]);

        message_flash('info', 'Le cycle a été clôturé avec succès. Tout son historique financier reste consultable dans « Bilans & Historique ».');
        header('Location: ' . BASE_URL . '/admin/cycles.php');
        exit;
    }
}

// 4. Récupérer la liste de tous les cycles de la tontine avec statistiques
$stmtCycles = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM cycle_members cm WHERE cm.cycle_id = c.id) AS nb_participants,
           (SELECT COALESCE(SUM(co.montant_attendu), 0)
            FROM contributions co
            INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
            WHERE cm.cycle_id = c.id) AS total_attendu,
           (SELECT COALESCE(SUM(co.montant_paye), 0)
            FROM contributions co
            INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
            WHERE cm.cycle_id = c.id) AS total_collecte
    FROM cycles c
    WHERE c.tontine_id = ?
    ORDER BY c.numero_cycle DESC
");
$stmtCycles->execute([$tontine_id]);
$cycles = $stmtCycles->fetchAll();

$titre_page = "Gestion des Cycles";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
                    <i class="bi bi-arrow-repeat me-1"></i>CYCLES SUCCESSIFS & HISTORIQUE PRÉSERVÉ
                </span>
                <h1 class="fw-bold h2 mb-1">Gestion des Cycles de Cotisation</h1>
                <p class="text-muted small mb-0">
                    Configurez le montant, la fréquence, le pourcentage de pénalité et la liste des participants pour chaque cycle.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="<?= BASE_URL ?>/admin/draws.php" class="btn btn-glow-emerald btn-sm px-3 py-2">
                    <i class="bi bi-shuffle me-1"></i>Aller au Tirage au Sort
                </a>
                <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-glow-blue btn-sm px-3 py-2 ms-1">
                    <i class="bi bi-bar-chart-line me-1"></i>Bilans
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$abonnement_valide): ?>
        <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2 shadow-sm mb-4">
            <div>
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Abonnement SaaS expiré :</strong> Vous pouvez consulter tous vos cycles existants, mais l'activation d'un nouveau cycle nécessite un abonnement actif (2 000 FCFA/mois).
            </div>
            <a href="<?= BASE_URL ?>/admin/subscription.php" class="btn btn-sm btn-warning fw-bold text-dark">
                Renouveler l'abonnement
            </a>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- COLONNE GAUCHE : FORMULAIRE DE CRÉATION D'UN NOUVEAU CYCLE -->
        <div class="col-lg-5">
            <div class="card card-djangui hover-lift p-4 border-left-emerald">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge bg-success rounded-circle p-2"><i class="bi bi-plus-lg"></i></span>
                    <h5 class="fw-bold mb-0">Préparer un nouveau cycle</h5>
                </div>

                <form method="POST" action="cycles.php">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="creer_cycle">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Titre du cycle (optionnel)</label>
                        <input type="text" name="titre" class="form-control" placeholder="Ex: Cycle Rentrée Scolaire">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold small">Cotisation par séance (FCFA) *</label>
                            <input type="number" name="montant_cotisation" id="inputMontantCotis" class="form-control fw-bold" min="500" step="500" value="50000" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold small">Fréquence des séances *</label>
                            <select name="frequence" class="form-select">
                                <option value="mensuel">Mensuelle (1 fois/mois)</option>
                                <option value="bimensuel">Bimensuelle (Tous les 15 j.)</option>
                                <option value="hebdo">Hebdomadaire (Chaque sem.)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Date de lancement (1re séance) *</label>
                        <input type="date" name="date_debut" class="form-control" value="<?= date_actuelle('Y-m-d') ?>" required>
                    </div>

                    <!-- Point 2 : Définition en POURCENTAGE (%) de la somme à cotiser -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Taux de pénalité de retard (%) *</label>
                        <div class="input-group">
                            <input type="number" name="pourcentage_penalite" id="inputPourcentagePenalite" class="form-control fw-bold" min="0" max="100" step="0.5" value="5" required>
                            <span class="input-group-text">%</span>
                            <span class="input-group-text text-danger fw-semibold" id="aperçuPenaliteFcfa">= 2 500 FCFA</span>
                        </div>
                        <div class="form-text small">
                            Calculé sur la cotisation d'une séance. Modifiable ensuite dans <a href="<?= BASE_URL ?>/admin/penalties.php">Caisse & Pénalités</a>.
                        </div>
                    </div>

                    <!-- Sélection des membres participants au cycle -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-semibold small mb-0">
                                Membres participants (<?= count($membres_actifs) ?> actifs) *
                            </label>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnToggleAll">
                                Tout cocher / décocher
                            </button>
                        </div>
                        <div class="p-3 rounded-3 box-adaptive" style="max-height: 220px; overflow-y: auto;">
                            <?php foreach ($membres_actifs as $m): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input js-participant-check" type="checkbox"
                                           name="participants[]" value="<?= (int)$m['id'] ?>"
                                           id="membre_<?= (int)$m['id'] ?>" checked>
                                    <label class="form-check-label small d-flex justify-content-between align-items-center" for="membre_<?= (int)$m['id'] ?>">
                                        <span>
                                            <strong><?= e($m['nom_complet']) ?></strong>
                                            <?php if ($m['role'] === 'admin'): ?>
                                                <span class="badge bg-primary-subtle text-primary ms-1">Secrétaire</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-muted"><?= e($m['code_public']) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-glow-emerald w-100 py-2">
                        <i class="bi bi-save2-fill me-2"></i>Enregistrer le cycle (Brouillon)
                    </button>
                </form>
            </div>
        </div>

        <!-- COLONNE DROITE : LISTE DES CYCLES (ACTIF, BROUILLONS, TERMINÉS) -->
        <div class="col-lg-7">
            <div class="card card-djangui p-4 border-left-navy">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-layers-fill text-primary me-2"></i>Cycles de la tontine</h5>
                    <span class="badge bg-primary rounded-pill"><?= count($cycles) ?> cycle(s)</span>
                </div>

                <?php if (empty($cycles)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        Aucun cycle n'a encore été créé pour cette tontine.
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($cycles as $c): ?>
                            <?php
                            $badgeStatut = match ($c['statut']) {
                                'actif'     => '<span class="badge bg-success px-3 py-2 rounded-pill"><i class="bi bi-play-circle-fill me-1"></i>Actif</span>',
                                'brouillon' => '<span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="bi bi-pencil-square me-1"></i>Brouillon</span>',
                                default     => '<span class="badge bg-secondary px-3 py-2 rounded-pill"><i class="bi bi-archive-fill me-1"></i>Terminé</span>',
                            };
                            $pot_unitaire = (float)$c['montant_cotisation'] * (int)$c['nb_participants'];
                            $taux = ($c['total_attendu'] > 0) ? round(($c['total_collecte'] / $c['total_attendu']) * 100, 1) : 0;
                            $pct_pen = ((float)$c['montant_cotisation'] > 0)
                                ? round(((float)$c['montant_penalite_retard'] / (float)$c['montant_cotisation']) * 100, 1)
                                : 0;
                            ?>
                            <div class="p-3 rounded-4 box-adaptive">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                    <div>
                                        <span class="text-primary fw-bold small">CYCLE #<?= (int)$c['numero_cycle'] ?></span>
                                        <h5 class="fw-bold mb-0"><?= e($c['titre'] ?? ('Cycle #' . $c['numero_cycle'])) ?></h5>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <?= $badgeStatut ?>
                                        <?php if ((int)$c['tirage_verrouille'] === 1): ?>
                                            <span class="badge bg-info text-dark rounded-pill" title="Tirage au sort scellé">
                                                <i class="bi bi-lock-fill"></i> Tirage verrouillé
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row g-2 small my-2">
                                    <div class="col-6 col-sm-3">
                                        <span class="text-muted d-block">Cotisation</span>
                                        <strong><?= format_fcfa($c['montant_cotisation']) ?></strong>
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <span class="text-muted d-block">Participants</span>
                                        <strong><?= (int)$c['nb_participants'] ?> membres</strong>
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <span class="text-muted d-block">Pot par séance</span>
                                        <strong class="text-success"><?= format_fcfa($pot_unitaire) ?></strong>
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <span class="text-muted d-block">Pénalité retard</span>
                                        <strong class="text-danger"><?= $pct_pen ?>% (<?= format_fcfa($c['montant_penalite_retard']) ?>)</strong>
                                    </div>
                                </div>

                                <?php if ((float)$c['total_attendu'] > 0): ?>
                                    <div class="mt-2 mb-3">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Progression globale des cotisations du cycle :</span>
                                            <span class="fw-bold"><?= format_fcfa($c['total_collecte']) ?> / <?= format_fcfa($c['total_attendu']) ?> (<?= $taux ?> %)</span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-success" style="width: <?= min(100, $taux) ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top">
                                    <span class="small text-muted">
                                        <i class="bi bi-calendar-event me-1"></i>Début : <?= date('d/m/Y', strtotime($c['date_debut'])) ?>
                                        <?php if (!empty($c['date_fin'])): ?>
                                            → Fin : <?= date('d/m/Y', strtotime($c['date_fin'])) ?>
                                        <?php endif; ?>
                                        | <strong><?= e(ucfirst($c['frequence'])) ?></strong>
                                    </span>

                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if ($c['statut'] === 'brouillon'): ?>
                                            <form method="POST" action="cycles.php" class="d-inline">
                                                <?= champ_csrf() ?>
                                                <input type="hidden" name="action" value="activer_cycle">
                                                <input type="hidden" name="cycle_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-glow-emerald">
                                                    <i class="bi bi-play-fill me-1"></i>Activer
                                                </button>
                                            </form>
                                            <!-- Point 3 : Bouton de suppression d'un cycle en brouillon -->
                                            <form method="POST" action="cycles.php" class="d-inline" onsubmit="return confirm('Voulez-vous vraiment supprimer définitivement ce cycle en brouillon ?');">
                                                <?= champ_csrf() ?>
                                                <input type="hidden" name="action" value="supprimer_cycle">
                                                <input type="hidden" name="cycle_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash3-fill me-1"></i>Supprimer le brouillon
                                                </button>
                                            </form>
                                        <?php elseif ($c['statut'] === 'actif'): ?>
                                            <?php if ((int)$c['tirage_verrouille'] === 0): ?>
                                                <a href="<?= BASE_URL ?>/admin/draws.php" class="btn btn-sm btn-glow-blue">
                                                    <i class="bi bi-shuffle me-1"></i>Lancer le tirage au sort
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" action="cycles.php" class="d-inline" onsubmit="return confirm('Confirmez-vous la clôture de ce cycle ? Son historique restera intact dans les bilans.');">
                                                <?= champ_csrf() ?>
                                                <input type="hidden" name="action" value="cloturer_cycle">
                                                <input type="hidden" name="cycle_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-check2-all me-1"></i>Clôturer le cycle
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <a href="<?= BASE_URL ?>/admin/reports.php?cycle_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark-bar-graph me-1"></i>Voir le bilan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnToggle = document.getElementById('btnToggleAll');
    if (btnToggle) {
        btnToggle.addEventListener('click', function () {
            const checkboxes = document.querySelectorAll('.js-participant-check');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => cb.checked = !allChecked);
        });
    }

    const inputMontant = document.getElementById('inputMontantCotis');
    const inputPct     = document.getElementById('inputPourcentagePenalite');
    const apercuFcfa   = document.getElementById('aperçuPenaliteFcfa');

    function majApercuPenalite() {
        if (!inputMontant || !inputPct || !apercuFcfa) return;
        const m = parseFloat(inputMontant.value) || 0;
        const p = parseFloat(inputPct.value) || 0;
        const fcfa = Math.round(m * (p / 100));
        apercuFcfa.textContent = '= ' + fcfa.toLocaleString('fr-FR') + ' FCFA';
    }

    if (inputMontant && inputPct) {
        inputMontant.addEventListener('input', majApercuPenalite);
        inputPct.addEventListener('input', majApercuPenalite);
        majApercuPenalite();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>