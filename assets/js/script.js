/**
 * assets/js/script.js
 * 1. Bascule Mode Clair (pour les aînés / plein jour) <-> Mode Sombre Bleu Nuit
 * 2. Fonctions interactives Djangui
 */

document.addEventListener('DOMContentLoaded', function () {
    const boutonsTheme = document.querySelectorAll('.js-theme-toggle');

    // Met à jour le texte et l'icône du bouton selon le thème actif
    function mettreAJourBoutonsTheme(themeActuel) {
        boutonsTheme.forEach(function (btn) {
            const icone = btn.querySelector('.js-theme-icon');
            const label = btn.querySelector('.js-theme-label');

            if (themeActuel === 'dark') {
                // Si on est en Mode Sombre, le bouton propose de passer en Mode Clair
                if (icone) icone.className = 'bi bi-sun-fill text-warning js-theme-icon';
                if (label) label.textContent = 'Mode Clair';
            } else {
                // Si on est en Mode Clair, le bouton propose de passer en Mode Sombre
                if (icone) icone.className = 'bi bi-moon-stars-fill text-info js-theme-icon';
                if (label) label.textContent = 'Mode Sombre';
            }
        });
    }

    // Initialisation à l'ouverture de la page
    const themeInitial = document.documentElement.getAttribute('data-theme') || 'light';
    mettreAJourBoutonsTheme(themeInitial);

    // Action au clic sur le bouton Clair / Sombre
    boutonsTheme.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const themeCourant = document.documentElement.getAttribute('data-theme') || 'light';
            const nouveauTheme = (themeCourant === 'dark') ? 'light' : 'dark';

            document.documentElement.setAttribute('data-theme', nouveauTheme);
            document.documentElement.setAttribute('data-bs-theme', nouveauTheme);
            localStorage.setItem('djangui_theme', nouveauTheme);

            mettreAJourBoutonsTheme(nouveauTheme);
        });
    });
});