/**
 * GRASP Theme Manager
 *
 * Handles day/night theme switching.
 * Theme is stored in localStorage.
 * Falls back to system preference.
 *
 * Usage: included before app.js
 *   <script src="/assets/theme.js"></script>
 */

(function () {
    'use strict';

    const THEME_KEY = 'grasp-theme';
    const THEME_DAY = 'day';
    const THEME_NIGHT = 'night';

    /**
     * Get the current theme.
     * Priority: localStorage > system preference > default (night)
     */
    function getStoredTheme() {
        const stored = localStorage.getItem(THEME_KEY);
        if (stored === THEME_DAY || stored === THEME_NIGHT) {
            return stored;
        }

        // Detect system preference
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
            return THEME_DAY;
        }

        return THEME_NIGHT;
    }

    /**
     * Apply a theme immediately (before page render if possible).
     * Sets the `data-theme` attribute on <html>.
     */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(THEME_KEY, theme);
        updateToggleButton(theme);
        updateMetaThemeColor(theme);
    }

    /**
     * Toggle between day and night.
     */
    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme') || THEME_NIGHT;
        const next = current === THEME_DAY ? THEME_NIGHT : THEME_DAY;
        applyTheme(next);
    }

    /**
     * Update the toggle button icon based on current theme.
     */
    function updateToggleButton(theme) {
        const btn = document.getElementById('btnToggleTheme');
        if (!btn) return;

        const sunIcon = btn.querySelector('.icon-sun');
        const moonIcon = btn.querySelector('.icon-moon');

        if (theme === THEME_DAY) {
            if (sunIcon) sunIcon.style.display = 'none';
            if (moonIcon) moonIcon.style.display = '';
            btn.setAttribute('title', 'Переключить на ночную тему');
        } else {
            if (sunIcon) sunIcon.style.display = '';
            if (moonIcon) moonIcon.style.display = 'none';
            btn.setAttribute('title', 'Переключить на дневную тему');
        }
    }

    /**
     * Update <meta name="theme-color"> for mobile browsers.
     */
    function updateMetaThemeColor(theme) {
        let meta = document.querySelector('meta[name="theme-color"]');
        if (!meta) {
            meta = document.createElement('meta');
            meta.name = 'theme-color';
            document.head.appendChild(meta);
        }
        meta.content = theme === THEME_DAY ? '#ffffff' : '#0d1117';
    }

    /**
     * Initialize theme on page load.
     * Runs synchronously to prevent FOUC (flash of unstyled content).
     */
    function init() {
        const theme = getStoredTheme();
        applyTheme(theme);

        // Bind toggle button when DOM is ready
        function bindButton() {
            const btn = document.getElementById('btnToggleTheme');
            if (btn) {
                btn.addEventListener('click', toggleTheme);
                updateToggleButton(theme);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindButton);
        } else {
            bindButton();
        }

        // Listen for system theme changes
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
                // Only auto-switch if user hasn't manually set a preference
                if (!localStorage.getItem(THEME_KEY)) {
                    applyTheme(e.matches ? THEME_NIGHT : THEME_DAY);
                }
            });
        }
    }

    // Expose for debugging
    window.GraspTheme = {
        get: getStoredTheme,
        set: applyTheme,
        toggle: toggleTheme,
        DAY: THEME_DAY,
        NIGHT: THEME_NIGHT,
    };

    // Run immediately
    init();
})();