<?php
// includes/navbar.php — Barre de navigation adaptative (Mode Clair & Sombre | Mobile & PC)
$uri_actuelle = $_SERVER['REQUEST_URI'] ?? '';
$page_courante = basename(parse_url($uri_actuelle, PHP_URL_PATH));
$est_dans_admin = str_contains($uri_actuelle, '/admin/');
$est_connecte = !empty($_SESSION['user_id']);
$est_admin = ($est_connecte && ($_SESSION['role'] ?? '') === 'admin');
?>

<!-- 1. BARRE SUPÉRIEURE (S'adapte en Blanc/Azur nacré en Mode Clair et Bleu Nuit en Mode Sombre) -->
<nav class="navbar navbar-expand-lg navbar-djangui sticky-top">
    <div class="container">
        <!-- Logo Djangui -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE_URL ?>/index.php">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 p-1">
                <i class="bi bi-safe2-fill text-primary fs-4"></i>
            </span>
            <span>DJANGUI</span>
        </a>

        <!-- Actions rapides visibles sur Smartphone (Thème Clair/Sombre + Bascule Admin/Cotisant + Déconnexion) -->
        <div class="d-flex align-items-center gap-2 ms-auto d-lg-none">
            <button type="button" class="btn-theme-toggle js-theme-toggle" title="Changer le thème (Clair / Sombre)">
                <i class="bi bi-moon-stars-fill js-theme-icon"></i>
                <span class="js-theme-label">Sombre</span>
            </button>

            <?php if ($est_admin): ?>
                <?php if ($est_dans_admin): ?>
                    <a href="<?= BASE_URL ?>/member/dashboard.php" class="btn btn-glow-emerald btn-sm py-1 px-2 small">
                        <i class="bi bi-wallet2"></i> Cotiser
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-warning btn-sm py-1 px-2 small text-dark fw-bold">
                        <i class="bi bi-shield-lock"></i> Admin
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($est_connecte): ?>
                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-logout-djangui btn-sm py-1 px-2" title="Déconnexion">
                    <i class="bi bi-power"></i>
                </a>
            <?php endif; ?>
        </div>

        <!-- Bouton hamburger masqué sur smartphone (la navigation mobile se fait par la barre du bas) -->
        <button class="navbar-toggler border-0 d-none" type="button" data-bs-toggle="collapse" data-bs-target="#menuDjangui">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Menu complet sur grand écran PC -->
        <div class="collapse navbar-collapse d-none d-lg-block" id="menuDjangui">
            <?php if (!$est_connecte): ?>
                <!-- 1. MENU VISITEUR PUBLIC -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= ($page_courante === 'index.php' || $page_courante === '') ? 'active' : '' ?>" href="<?= BASE_URL ?>/index.php">Accueil</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $page_courante === 'pricing.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/pricing.php">Tarifs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $page_courante === 'about-terms.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/about-terms.php">Fonctionnement & CGU</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn-theme-toggle js-theme-toggle">
                        <i class="bi bi-moon-stars-fill js-theme-icon"></i>
                        <span class="js-theme-label">Mode Sombre</span>
                    </button>
                    <a href="<?= BASE_URL ?>/login.php" class="btn btn-glow-blue btn-sm px-3 py-2">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Connexion
                    </a>
                </div>

            <?php elseif ($est_admin && $est_dans_admin): ?>
                <!-- 2. MENU ESPACE SECRÉTAIRE / ADMINISTRATEUR -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 small">
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'dashboard.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'members.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/members.php"><i class="bi bi-people me-1"></i>Membres</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'cycles.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/cycles.php"><i class="bi bi-arrow-repeat me-1"></i>Cycles</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'draws.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/draws.php"><i class="bi bi-shuffle me-1"></i>Tirage</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'contributions.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/contributions.php"><i class="bi bi-cash-stack me-1"></i>Cotisations</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'penalties.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/penalties.php"><i class="bi bi-piggy-bank me-1"></i>Caisse & Pénalités</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'meetings.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/meetings.php"><i class="bi bi-camera-video me-1"></i>Réunions</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Plus</a>
                        <ul class="dropdown-menu shadow border-0">
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/tontine-info.php">Infos de la tontine</a></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/reports.php">Bilans & Historique</a></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/subscription.php">Abonnement SaaS</a></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/transfer.php">Passation d'administration</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/settings.php">Paramètres Admin</a></li>
                        </ul>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn-theme-toggle js-theme-toggle">
                        <i class="bi bi-moon-stars-fill js-theme-icon"></i>
                        <span class="js-theme-label">Mode Sombre</span>
                    </button>
                    <a href="<?= BASE_URL ?>/member/dashboard.php" class="btn btn-glow-emerald btn-sm">
                        <i class="bi bi-wallet2 me-1"></i>Mon Espace Cotisant
                    </a>
                    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-logout-djangui btn-sm" title="Déconnexion">
                        <i class="bi bi-power"></i>
                    </a>
                </div>

            <?php else: ?>
                <!-- 3. MENU ESPACE MEMBRE COTISANT -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 small">
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'dashboard.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/member/dashboard.php"><i class="bi bi-house-door me-1"></i>Accueil</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'contributions.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/member/contributions.php"><i class="bi bi-credit-card-2-front me-1"></i>Cotiser</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'payments.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/member/payments.php"><i class="bi bi-clock-history me-1"></i>Historique</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'draw-rank.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/member/draw-rank.php"><i class="bi bi-trophy me-1"></i>Mon Rang</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'penalties.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/member/penalties.php"><i class="bi bi-exclamation-circle me-1"></i>Pénalités</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page_courante === 'meetings.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/member/meetings.php"><i class="bi bi-camera-video me-1"></i>Réunions</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Ma Tontine</a>
                        <ul class="dropdown-menu shadow border-0">
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/member/tontine.php">Infos de la tontine</a></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/member/calendar.php">Calendrier des échéances</a></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/member/card.php">Ma Carte QR Code</a></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/member/notifications.php">Notifications</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/member/profile.php">Mon Profil & MoMo</a></li>
                        </ul>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn-theme-toggle js-theme-toggle">
                        <i class="bi bi-moon-stars-fill js-theme-icon"></i>
                        <span class="js-theme-label">Mode Sombre</span>
                    </button>
                    <?php if ($est_admin): ?>
                        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-warning btn-sm fw-bold text-dark">
                            <i class="bi bi-shield-lock me-1"></i>Gestion Admin
                        </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-logout-djangui btn-sm" title="Déconnexion">
                        <i class="bi bi-power"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- 2. BARRE D'ONGLETS MOBILE EN BAS DE L'ÉCRAN (S'adapte aussi en Clair / Sombre) -->
<?php if (!$est_connecte): ?>
    <div class="mobile-bottom-nav d-lg-none">
        <a href="<?= BASE_URL ?>/index.php" class="<?= ($page_courante === 'index.php' || $page_courante === '') ? 'active' : '' ?>">
            <i class="bi bi-house-door"></i><span>Accueil</span>
        </a>
        <a href="<?= BASE_URL ?>/about-terms.php" class="<?= $page_courante === 'about-terms.php' ? 'active' : '' ?>">
            <i class="bi bi-journal-check"></i><span>Fonctionnement</span>
        </a>
        <a href="<?= BASE_URL ?>/pricing.php" class="<?= $page_courante === 'pricing.php' ? 'active' : '' ?>">
            <i class="bi bi-tag"></i><span>Tarifs</span>
        </a>
        <a href="<?= BASE_URL ?>/login.php" class="<?= $page_courante === 'login.php' ? 'active' : '' ?>">
            <i class="bi bi-box-arrow-in-right"></i><span>Connexion</span>
        </a>
    </div>
<?php elseif ($est_admin && $est_dans_admin): ?>
    <div class="mobile-bottom-nav d-lg-none">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="<?= $page_courante === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/members.php" class="<?= $page_courante === 'members.php' ? 'active' : '' ?>">
            <i class="bi bi-people"></i><span>Membres</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/draws.php" class="<?= $page_courante === 'draws.php' ? 'active' : '' ?>">
            <i class="bi bi-shuffle"></i><span>Tirage</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/contributions.php" class="<?= $page_courante === 'contributions.php' ? 'active' : '' ?>">
            <i class="bi bi-cash-stack"></i><span>Cotisations</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/penalties.php" class="<?= $page_courante === 'penalties.php' ? 'active' : '' ?>">
            <i class="bi bi-piggy-bank"></i><span>Caisse</span>
        </a>
    </div>
<?php else: ?>
    <div class="mobile-bottom-nav d-lg-none">
        <a href="<?= BASE_URL ?>/member/dashboard.php" class="<?= $page_courante === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-house-door"></i><span>Accueil</span>
        </a>
        <a href="<?= BASE_URL ?>/member/contributions.php" class="<?= $page_courante === 'contributions.php' ? 'active' : '' ?>">
            <i class="bi bi-credit-card-2-front"></i><span>Cotiser</span>
        </a>
        <a href="<?= BASE_URL ?>/member/draw-rank.php" class="<?= $page_courante === 'draw-rank.php' ? 'active' : '' ?>">
            <i class="bi bi-trophy"></i><span>Mon Rang</span>
        </a>
        <a href="<?= BASE_URL ?>/member/card.php" class="<?= $page_courante === 'card.php' ? 'active' : '' ?>">
            <i class="bi bi-qr-code"></i><span>Carte QR</span>
        </a>
        <a href="<?= BASE_URL ?>/member/notifications.php" class="<?= $page_courante === 'notifications.php' ? 'active' : '' ?>">
            <i class="bi bi-bell"></i><span>Alertes</span>
        </a>
    </div>
<?php endif; ?>

<!-- Affichage automatique des messages d'alerte (Flash) -->
<?php if (!empty($_SESSION['flash_texte'])): ?>
    <div class="container mt-3">
        <div class="alert alert-<?= e($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= e($_SESSION['flash_texte']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    </div>
    <?php unset($_SESSION['flash_type'], $_SESSION['flash_texte']); ?>
<?php endif; ?>