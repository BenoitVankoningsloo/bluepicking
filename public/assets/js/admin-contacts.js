(function () {
  'use strict';
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else { fn(); }
  }
  onReady(function () {
    document.querySelectorAll('form.js-confirm-delete').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var msg = form.getAttribute('data-confirm') || 'Confirmer la suppression ?';
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      });
    });
  });
})();

