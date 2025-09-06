(function () {
  'use strict';
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else { fn(); }
  }
  onReady(function () {
    try {
        const params = new URLSearchParams(window.location.search);
        const qParam = params.get('q');
        const form = document.querySelector('form[action$="/admin/contacts"]');
        const input = form ? form.querySelector('input[name="q"]') : null;
        if (input && qParam && qParam.length > 0) {
        input.value = '';
      }
    } catch (e) {}
  });
})();

