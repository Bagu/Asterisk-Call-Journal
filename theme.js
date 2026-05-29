/**
 * theme.js — Gestion du thème clair/sombre.
 *
 * - Lit la préférence stockée dans localStorage (clé "theme").
 * - En l'absence de préférence, détecte celle du système via prefers-color-scheme.
 * - Applique immédiatement data-theme sur <html> pour éviter le flash (FOUC).
 * - Câble le bouton #btn-theme (s'il existe) pour basculer et persister le choix.
 */
(function () {
    /**
     * Retourne le thème à appliquer : préférence stockée ou détection OS.
     * @returns {'light'|'dark'}
     */
    function getStoredTheme() {
        var stored = localStorage.getItem('theme');
        if (stored === 'dark' || stored === 'light') return stored;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    /**
     * Applique le thème sur <html> et met à jour l'icône du bouton toggle.
     * @param {'light'|'dark'} theme
     */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        var btn = document.getElementById('btn-theme');
        if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
    }

    /* Application immédiate sur <html> pour éviter le flash avant le paint */
    applyTheme(getStoredTheme());

    document.addEventListener('DOMContentLoaded', function () {
        /* Re-applique après chargement complet du DOM (bouton peut être présent) */
        applyTheme(getStoredTheme());

        var btn = document.getElementById('btn-theme');
        if (!btn) return;

        /** Bascule le thème et persiste le choix dans localStorage. */
        btn.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next    = current === 'dark' ? 'light' : 'dark';
            localStorage.setItem('theme', next);
            applyTheme(next);
        });
    });
})();
