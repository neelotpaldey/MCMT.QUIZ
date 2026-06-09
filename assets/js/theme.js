// developed by @neelotpal.dey
(function () {
  const STORAGE_KEY = 'exam_theme';

  function applyTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') {
      theme = 'dark';
    }
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(STORAGE_KEY, theme);

    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      const icon = btn.querySelector('.theme-toggle-icon');
      const label = btn.querySelector('.theme-toggle-label');
      if (icon) {
        icon.textContent = theme === 'light' ? '☀️' : '🌙';
      }
      if (label) {
        label.textContent = theme === 'light' ? 'Light' : 'Dark';
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    applyTheme(document.documentElement.getAttribute('data-theme') || 'dark');

    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const current = document.documentElement.getAttribute('data-theme');
        applyTheme(current === 'light' ? 'dark' : 'light');
      });
    });
  });
})();
