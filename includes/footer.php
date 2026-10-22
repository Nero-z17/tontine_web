<?php
// includes/footer.php — Pied de page adaptatif Clair / Sombre
?>
<footer class="mt-auto py-4 footer-djangui text-center small">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center">
        <div>
            <strong>DJANGUI</strong> &copy; <?= date('Y') ?> — Gestion transparente de tontines au Cameroun.
        </div>
        <div class="mt-2 mt-md-0">
            <a href="<?= BASE_URL ?>/pricing.php" class="text-decoration-none text-muted me-3">Tarifs (2 000 FCFA/mois)</a>
            <a href="<?= BASE_URL ?>/about-terms.php" class="text-decoration-none text-muted">Conditions d'utilisation</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js"></script>
</body>
</html>