/**
 * UX-K4 (внешний отчёт, 02.09.2026) — замена нативных браузерных confirm() на модальное окно в стиле
 * приложения (native confirm() на планшете кассира выглядит чужеродно). Общий скрипт на все страницы
 * (подключается в layout_bottom.php, как и no-double-submit.js).
 *
 * appConfirm(message) — открывает модалку, возвращает Promise<boolean> (true = подтвердили).
 *
 * appConfirmSubmit(form, message) — заменяет собой старое `onsubmit="return confirm('...')"`. Так как
 * модалка асинхронна (в отличие от синхронного confirm()), а <form onsubmit> должен ответить
 * true/false СРАЗУ — используется двухпроходная схема:
 *   1) первый submit — form.dataset.confirmed ещё не '1' → показываем модалку → return false (блокируем
 *      именно ЭТУ отправку).
 *   2) если подтвердили — ставим form.dataset.confirmed='1' и зовём form.requestSubmit() (в отличие от
 *      form.submit(), requestSubmit() заново вызывает onsubmit И порождает настоящее событие 'submit',
 *      которое видит assets/no-double-submit.js — кнопка корректно блокируется на этом, "настоящем" проходе).
 *   3) на этом втором проходе onsubmit видит dataset.confirmed==='1' → return true → браузер реально
 *      отправляет форму.
 * Если нажали "Отмена" — dataset.confirmed никогда не ставится, форма просто не отправляется.
 */
(function () {
  let overlay = null;

  function ensureOverlay() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML =
      '<div class="confirm-dialog" role="alertdialog" aria-modal="true">' +
        '<div class="confirm-message"></div>' +
        '<div class="confirm-actions">' +
          '<button type="button" class="secondary confirm-cancel">Отмена</button>' +
          '<button type="button" class="confirm-ok">Подтвердить</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    return overlay;
  }

  window.appConfirm = function (message) {
    return new Promise(function (resolve) {
      const el = ensureOverlay();
      el.querySelector('.confirm-message').textContent = message;
      el.style.display = 'flex';
      const okBtn = el.querySelector('.confirm-ok');
      const cancelBtn = el.querySelector('.confirm-cancel');

      function cleanup(result) {
        el.style.display = 'none';
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
        el.removeEventListener('click', onOverlayClick);
        document.removeEventListener('keydown', onKey);
        resolve(result);
      }
      function onOk() { cleanup(true); }
      function onCancel() { cleanup(false); }
      function onOverlayClick(e) { if (e.target === el) cleanup(false); }
      function onKey(e) { if (e.key === 'Escape') cleanup(false); if (e.key === 'Enter') cleanup(true); }

      okBtn.addEventListener('click', onOk);
      cancelBtn.addEventListener('click', onCancel);
      el.addEventListener('click', onOverlayClick);
      document.addEventListener('keydown', onKey);
      okBtn.focus();
    });
  };

  window.appConfirmSubmit = function (form, message) {
    if (form.dataset.confirmed === '1') {
      delete form.dataset.confirmed;
      return true;
    }
    window.appConfirm(message).then(function (ok) {
      if (ok) {
        form.dataset.confirmed = '1';
        form.requestSubmit();
      }
    });
    return false;
  };
})();
