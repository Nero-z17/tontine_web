<?php
// admin/subscription.php — Suivi et renouvellement simulé de l'abonnement SaaS
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

verifier_admin();

$tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
$montant_mensuel = 2000.00;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    if (($_POST['action'] ?? '') !== 'renouveler_abonnement') {
        message_flash('danger', 'Action non reconnue.');
        header('Location: ' . BASE_URL . '/admin/subscription.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtTontine = $pdo->prepare("
            SELECT statut_abonnement, date_fin_essai, abonnement_valide_jusqua, NOW() AS maintenant
            FROM tontines
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmtTontine->execute([$tontine_id]);
        $tontine = $stmtTontine->fetch();

        if (!$tontine) {
            throw new RuntimeException('Tontine introuvable.');
        }

        $date_debut = new DateTimeImmutable($tontine['maintenant']);
        if (
            $tontine['statut_abonnement'] === 'essai'
            && strtotime($tontine['date_fin_essai']) > strtotime($tontine['maintenant'])
        ) {
            $date_debut = new DateTimeImmutable($tontine['date_fin_essai']);
        } elseif (
            $tontine['statut_abonnement'] === 'actif'
            && !empty($tontine['abonnement_valide_jusqua'])
            && strtotime($tontine['abonnement_valide_jusqua']) > strtotime($tontine['maintenant'])
        ) {
            $date_debut = new DateTimeImmutable($tontine['abonnement_valide_jusqua']);
        }

        $date_fin = $date_debut->modify('+1 month');
        $reference = 'SIM-' . date('YmdHis', strtotime($tontine['maintenant'])) . '-' . strtoupper(bin2hex(random_bytes(3)));

        $stmtInsert = $pdo->prepare("
            INSERT INTO subscriptions (
                tontine_id, type_periode, montant, date_debut, date_fin,
                statut_paiement, reference_transaction, paye_par_id
            ) VALUES (?, 'mensuel', ?, ?, ?, 'simule', ?, ?)
        ");
        $stmtInsert->execute([
            $tontine_id,
            $montant_mensuel,
            $date_debut->format('Y-m-d H:i:s'),
            $date_fin->format('Y-m-d H:i:s'),
            $reference,
            $user_id,
        ]);

        $stmtUpdate = $pdo->prepare("
            UPDATE tontines
            SET statut_abonnement = 'actif',
                abonnement_valide_jusqua = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([$date_fin->format('Y-m-d H:i:s'), $tontine_id]);

        $pdo->commit();
        message_flash(
            'success',
            'Renouvellement simulé enregistré pour 2 000 FCFA. L’abonnement est valide jusqu’au '
            . $date_fin->format('d/m/Y à H:i')
            . ' (référence : '
            . $reference
            . '). Aucun paiement réel n’a été effectué.'
        );
    } catch (PDOException | RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        message_flash('danger', 'Impossible d’enregistrer le renouvellement simulé. Veuillez réessayer.');
    }

    header('Location: ' . BASE_URL . '/admin/subscription.php');
    exit;
}

$stmtSynchroniser = $pdo->prepare("
    UPDATE tontines
    SET statut_abonnement = 'expire'
    WHERE id = ?
      AND (
          (statut_abonnement = 'essai' AND date_fin_essai <= NOW())
          OR (statut_abonnement = 'actif' AND (abonnement_valide_jusqua IS NULL OR abonnement_valide_jusqua <= NOW()))
      )
");
$stmtSynchroniser->execute([$tontine_id]);

$stmtTontine = $pdo->prepare("
    SELECT nom, statut_abonnement, date_debut_essai, date_fin_essai,
           abonnement_valide_jusqua, NOW() AS maintenant
    FROM tontines
    WHERE id = ?
    LIMIT 1
");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

$stmtHistorique = $pdo->prepare("
    SELECT type_periode, montant, date_debut, date_fin,
           statut_paiement, reference_transaction, created_at
    FROM subscriptions
    WHERE tontine_id = ?
    ORDER BY date_debut DESC, id DESC
");
$stmtHistorique->execute([$tontine_id]);
$abonnements = $stmtHistorique->fetchAll();

$date_validite = $tontine['statut_abonnement'] === 'essai'
    ? $tontine['date_fin_essai']
    : $tontine['abonnement_valide_jusqua'];
$abonnement_valide = !empty($date_validite) && strtotime($date_validite) > strtotime($tontine['maintenant']);

$libelles_statut = [
    'essai' => 'Période d’essai',
    'actif' => 'Actif',
    'expire' => 'Expiré',
    'paye' => 'Payé',
    'en_attente' => 'En attente',
    'simule' => 'Simulé (démo)',
];
$classes_statut = [
    'essai' => 'text-bg-info',
    'actif' => 'text-bg-success',
    'expire' => 'text-bg-danger',
    'paye' => 'text-bg-success',
    'en_attente' => 'text-bg-warning',
    'simule' => 'text-bg-secondary',
];

$titre_page = 'Abonnement SaaS';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            <i class="bi bi-gem me-1"></i>ABONNEMENT DE LA TONTINE
        </span>
        <h1 class="fw-bold h2 mb-1">Abonnement SaaS</h1>
        <p class="text-muted small mb-0">Consultez la période d’accès de <?= e($tontine['nom'] ?? 'votre tontine') ?> et son historique.</p>
    </div>
</div>

<main class="container pb-5">
    <?php if (!empty($_SESSION['flash_texte'])): ?>
        <div class="alert alert-<?= e($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show mt-4" role="alert">
            <?= e($_SESSION['flash_texte']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
        <?php unset($_SESSION['flash_type'], $_SESSION['flash_texte']); ?>
    <?php endif; ?>

    <div class="row g-4 mt-1">
        <section class="col-lg-5" aria-labelledby="etat-abonnement-title">
            <div class="card card-djangui p-4 h-100">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 id="etat-abonnement-title" class="h5 fw-bold mb-1">État actuel</h2>
                        <p class="small text-muted mb-0"><?= e($tontine['nom'] ?? '') ?></p>
                    </div>
                    <span class="badge <?= e($classes_statut[$tontine['statut_abonnement'] ?? ''] ?? 'text-bg-secondary') ?>">
                        <?= e($libelles_statut[$tontine['statut_abonnement'] ?? ''] ?? 'Inconnu') ?>
                    </span>
                </div>

                <?php if (($tontine['statut_abonnement'] ?? '') === 'essai'): ?>
                    <p class="mb-2">Essai gratuit de deux mois, démarré le <?= date('d/m/Y', strtotime($tontine['date_debut_essai'])) ?>.</p>
                    <p class="text-muted small mb-4">Fin de l’essai : <strong><?= date('d/m/Y à H:i', strtotime($tontine['date_fin_essai'])) ?></strong></p>
                <?php elseif ($abonnement_valide): ?>
                    <p class="mb-2">Votre tontine conserve l’accès jusqu’au :</p>
                    <p class="h5 fw-bold text-primary mb-4"><?= date('d/m/Y à H:i', strtotime($date_validite)) ?></p>
                <?php else: ?>
                    <p class="text-danger fw-semibold mb-4">La période d’accès est expirée. Le renouvellement est nécessaire pour activer un nouveau cycle.</p>
                <?php endif; ?>

                <div class="box-adaptive rounded-3 p-3 mb-3">
                    <div class="fw-bold">Forfait mensuel</div>
                    <div class="h4 fw-bold text-primary my-2"><?= format_fcfa($montant_mensuel) ?> / mois</div>
                    <p class="small text-muted mb-0">Un abonnement pour toute la tontine. Le renouvellement ci-dessous est une simulation de démonstration, sans paiement réel.</p>
                </div>

                <form method="POST" action="subscription.php" class="mt-auto">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="renouveler_abonnement">
                    <button type="submit" class="btn btn-glow-blue w-100">
                        <i class="bi bi-arrow-repeat me-1"></i>Simuler le renouvellement (2 000 FCFA)
                    </button>
                </form>
            </div>
        </section>

        <section class="col-lg-7" aria-labelledby="historique-abonnements-title">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 id="historique-abonnements-title" class="h5 fw-bold mb-0">Historique</h2>
                <span class="badge rounded-pill text-bg-light"><?= count($abonnements) ?> période<?= count($abonnements) > 1 ? 's' : '' ?></span>
            </div>

            <?php if (empty($abonnements)): ?>
                <div class="card card-djangui p-4 text-center text-muted">
                    Aucun abonnement n’est encore enregistré.
                </div>
            <?php else: ?>
                <div class="card card-djangui p-3 p-md-4">
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Période</th>
                                    <th>Dates</th>
                                    <th>Montant</th>
                                    <th>Statut</th>
                                    <th>Référence</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($abonnements as $abonnement): ?>
                                    <tr>
                                        <td><?= $abonnement['type_periode'] === 'essai' ? 'Essai gratuit' : 'Mensuel' ?></td>
                                        <td class="small">
                                            <?= date('d/m/Y', strtotime($abonnement['date_debut'])) ?>
                                            <span class="text-muted">au</span>
                                            <?= date('d/m/Y', strtotime($abonnement['date_fin'])) ?>
                                        </td>
                                        <td class="fw-semibold"><?= format_fcfa($abonnement['montant']) ?></td>
                                        <td>
                                            <span class="badge <?= e($classes_statut[$abonnement['statut_paiement']] ?? 'text-bg-secondary') ?>">
                                                <?= e($libelles_statut[$abonnement['statut_paiement']] ?? $abonnement['statut_paiement']) ?>
                                            </span>
                                        </td>
                                        <td class="small text-break"><?= e($abonnement['reference_transaction'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="alert alert-info mt-4 mb-0" role="note">
        <i class="bi bi-info-circle-fill me-2"></i>
        En mode démo, le renouvellement est enregistré avec le statut <strong>simulé</strong>. Aucun service Mobile Money, paiement bancaire ou prélèvement de la caisse n’est effectué.
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
