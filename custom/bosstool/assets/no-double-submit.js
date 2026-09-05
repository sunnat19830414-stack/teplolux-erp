/**
 * Защита от повторного нажатия — блокирует кнопку отправки формы сразу после первого клика, пока
 * страница не перезагрузится (нормальная отправка формы всегда либо уводит на другую страницу, либо
 * перезагружает эту же — так что "включать обратно" кнопку не нужно, новая загрузка страницы и так
 * даёт свежее, включённое состояние). Общий скрипт на все страницы (подключается в layout_bottom.php).
 *
 * Если у формы был свой onsubmit="return confirm(...)" (или, с 02.09.2026, "return appConfirmSubmit(...)"
 * — см. assets/confirm-modal.js) и пользователь нажал "Отмена"/ещё не подтвердил — событие submit всё
 * равно доходит до этого обработчика, но браузер помечает его defaultPrevented=true, поэтому проверяем
 * это в первую очередь и ничего не блокируем в таком случае (кнопка блокируется на ВТОРОМ, настоящем
 * проходе submit — после подтверждения, см. appConfirmSubmit()).
 */
document.addEventListener('submit', function (e) {
  if (e.defaultPrevented) return;
  var form = e.target;
  if (!(form instanceof HTMLFormElement)) return;
  form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    if (!btn.dataset.origText) btn.dataset.origText = btn.textContent;
    btn.textContent = 'Обрабатывается…';
  });
});
