(function () {
  'use strict';
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else { fn(); }
  }
  onReady(function () {
    try {
      var params = new URLSearchParams(window.location.search);
      var qParam = params.get('q');
      var form = document.querySelector('form[action$="/admin/contacts"]');
      var input = form ? form.querySelector('input[name="q"]') : null;
      if (input && qParam && qParam.length > 0) {
        input.value = '';
      }
    } catch (e) {}
  });
})();

