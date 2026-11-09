// Applied in <head> to avoid a flash of the wrong theme.
(function () {
  var theme = null;
  try { theme = localStorage.getItem('us-theme'); } catch (e) {}
  if (!theme) theme = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', theme);
})();
