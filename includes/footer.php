<?php
// includes/footer.php — Pied de page + Barre de contrôle temporel (Simulation)
$decalage_sec = function_exists('obtenir_decalage_temps') ? obtenir_decalage_temps() : 0;
$date_sim_str = function_exists('date_actuelle') ? date_actuelle('d/m/Y') : date('d/m/Y');
$date_sim_iso = function_exists('date_actuelle') ? date_actuelle('Y-m-d') : date('Y-m-d');
$jours_ecart  = (int)round($decalage_sec / 86400);
?>
<footer class="mt-auto py-4 footer-djangui text-center small">
    <div class="container">
        <!-- BARRE DE SIMULATION TEMPORELLE (AVANCER / RECULER LE TEMPS) -->
        <div class="p-3 mb-3 rounded-3 box-adaptive border">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-center gap-2">
                <!-- Affichage de la date active -->
                <div class="d-flex align-items-center gap-2">
                    <span class="badge <?= $decalage_sec !== 0 ? 'bg-warning text-dark' : 'bg-secondary' ?> px-2 py-1">
                        <i class="bi bi-clock-history me-1"></i><?= $decalage_sec !== 0 ? 'TEMPS SIMULÉ' : 'TEMPS RÉEL' ?>
                    </span>
                    <span>
                        Date active : <strong class="text-primary fs-6"><?= e($date_sim_str) ?></strong>
                        <?php if ($jours_ecart !== 0): ?>
                            <span class="text-muted">(<?= $jours_ecart > 0 ? '+' . $jours_ecart : $jours_ecart ?> j.)</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Boutons rapides pour reculer / avancer le temps -->
                <form method="POST" class="d-flex flex-wrap justify-content-center align-items-center gap-1 m-0">
                    <?= champ_csrf() ?>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Reculer le temps">
                        <button type="submit" name="djangui_time_action" value="-1m" class="btn btn-outline-secondary" title="Reculer d'un mois">-1 Mois</button>
                        <button type="submit" name="djangui_time_action" value="-15d" class="btn btn-outline-secondary" title="Reculer de 15 jours">-15j</button>
                        <button type="submit" name="djangui_time_action" value="-7d" class="btn btn-outline-secondary" title="Reculer d'une semaine">-1 Sem</button>
                        <button type="submit" name="djangui_time_action" value="-1d" class="btn btn-outline-secondary" title="Reculer d'un jour">-1j</button>
                    </div>

                    <?php if ($decalage_sec !== 0): ?>
                        <button type="submit" name="djangui_time_action" value="reset" class="btn btn-sm btn-danger px-2" title="Revenir à la date réelle d'aujourd'hui">
                            <i class="bi bi-arrow-counterclockwise"></i> Aujourd'hui
                        </button>
                    <?php endif; ?>

                    <div class="btn-group btn-group-sm" role="group" aria-label="Avancer le temps">
                        <button type="submit" name="djangui_time_action" value="+1d" class="btn btn-outline-primary" title="Avancer d'un jour">+1j</button>
                        <button type="submit" name="djangui_time_action" value="+7d" class="btn btn-outline-primary" title="Avancer d'une semaine">+1 Sem</button>
                        <button type="submit" name="djangui_time_action" value="+15d" class="btn btn-outline-primary" title="Avancer de 15 jours">+15j</button>
                        <button type="submit" name="djangui_time_action" value="+1m" class="btn btn-outline-primary fw-bold" title="Avancer d'un mois">+1 Mois</button>
                    </div>
                </form>

                <!-- Aller directement à une date précise -->
                <form method="POST" class="d-flex align-items-center gap-1 m-0">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="djangui_time_action" value="goto_date">
                    <input type="date" name="target_date" value="<?= e($date_sim_iso) ?>" class="form-control form-control-sm" style="width: 140px;">
                    <button type="submit" class="btn btn-sm btn-glow-blue px-2">Aller</button>
                </form>
            </div>
        </div>

        <!-- Informations classiques du footer -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
            <div>
                <strong>DJANGUI</strong> &copy; <?= date_actuelle('Y') ?> — Gestion transparente de tontines au Cameroun.
            </div>
            <div class="mt-2 mt-md-0">
                <a href="<?= BASE_URL ?>/pricing.php" class="text-decoration-none text-muted me-3">Tarifs (2 000 FCFA/mois)</a>
                <a href="<?= BASE_URL ?>/about-terms.php" class="text-decoration-none text-muted">Conditions d'utilisation</a>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js?v=<?= time() ?>"></script>
</html>