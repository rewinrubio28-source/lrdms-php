(function () {
  'use strict';
  document.querySelectorAll('.nav-list a.is-active').forEach(function (link) {
    link.setAttribute('aria-current', 'page');
  });
  // Native disclosure controls keep ordinary Tab navigation, without menu roles.
  [['account-menu-toggle', 'account-menu-dropdown'], ['notif-bell-toggle', 'notif-bell-dropdown']].forEach(function (ids) {
    var button = document.getElementById(ids[0]);
    var panel = document.getElementById(ids[1]);
    if (!button || !panel) return;
    document.addEventListener('focusin', function (event) {
      if (event.target === button || panel.contains(event.target)) return;
      panel.classList.remove('is-open');
      button.setAttribute('aria-expanded', 'false');
    });
  });
  // Retain server feedback so users can read it at their own pace.
  document.querySelectorAll('.alert-danger, .alert-success').forEach(function (alert) {
    if (!alert.hasAttribute('role')) alert.setAttribute('role', alert.classList.contains('alert-danger') ? 'alert' : 'status');
  });
  // Scrollable data tables must also be reachable without a mouse.
  document.querySelectorAll('.table-responsive').forEach(function (region) {
    region.setAttribute('tabindex', '0');
    if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
    if (!region.hasAttribute('aria-label') && !region.hasAttribute('aria-labelledby')) {
      var caption = region.querySelector('caption');
      region.setAttribute('aria-label', caption ? caption.textContent.trim() : 'Data table; scroll horizontally to see more columns');
    }
  });
  var pending = new Map();
  function reset(form) {
    var state = pending.get(form);
    if (!state) return;
    form.removeAttribute('aria-busy');
    if (state.button) {
      state.button.innerHTML = state.html;
      if (state.aria === null) state.button.removeAttribute('aria-disabled');
      else state.button.setAttribute('aria-disabled', state.aria);
    }
    state.status.remove();
    pending.delete(form);
  }
  // Do not disable the submitter: its name/value may select the server action.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (event.defaultPrevented || form.method.toLowerCase() !== 'post' || (form.target && form.target !== '_self')) return;
    if (pending.has(form)) { event.preventDefault(); return; }
    var button = event.submitter;
    if (button && (button.formTarget && button.formTarget !== '_self')) return;
    var status = document.createElement('p');
    status.className = 'submit-status';
    status.setAttribute('role', 'status');
    status.textContent = 'Submitting. Please wait…';
    var state = { button: button && button.tagName === 'BUTTON' ? button : null, status: status };
    if (state.button) {
      state.html = button.innerHTML;
      state.aria = button.getAttribute('aria-disabled');
      button.setAttribute('aria-disabled', 'true');
      var spinner = document.createElement('span');
      spinner.className = 'lrdms-submit-loading';
      spinner.setAttribute('aria-hidden', 'true');
      button.prepend(spinner);
    }
    form.appendChild(status);
    form.setAttribute('aria-busy', 'true');
    pending.set(form, state);
    // Later handlers can still cancel submission (validation/AJAX/confirmation).
    queueMicrotask(function () { if (event.defaultPrevented) reset(form); });
  });
  window.addEventListener('pageshow', function () { Array.from(pending.keys()).forEach(reset); });
})();
