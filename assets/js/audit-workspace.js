(() => {
  let pending, timer;
  async function load(url, live = false, historyMode = 'pushState') {
    clearTimeout(timer);
    if (pending) pending.abort();
    const controller = new AbortController();
    pending = controller;
    const current = document.getElementById('audit-workspace');
    current.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
      if (response.redirected) { location.assign(response.url); return; }
      if (!response.ok) throw new Error('Unable to load audit logs. Please try again.');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const replacement = page.getElementById('audit-workspace');
      if (!replacement) throw new Error('Refresh the page to continue viewing audit logs.');
      if (controller.signal.aborted) return;
      const input = current.querySelector('input[name="q"]');
      const focused = live && document.activeElement === input;
      const start = input.selectionStart, end = input.selectionEnd;
      if (live) replacement.querySelector('input[name="q"]').replaceWith(input);
      // Preserve the sidebar button's listener attached by the shared layout.
      const toggle = current.querySelector('#sidebar-toggle');
      if (toggle) replacement.querySelector('#sidebar-toggle').replaceWith(toggle);
      // Keep the shared menus and their listeners across workspace refreshes.
      const headerActions = current.querySelector('.header-actions-group');
      if (headerActions) replacement.querySelector('.topbar__actions').appendChild(headerActions);
      const rowFocused = !!current.querySelector('.audit-event-row:focus');
      current.replaceWith(replacement);
      if (rowFocused) {
        replacement.querySelector('.audit-event-row.is-selected')?.focus({preventScroll:true});
      }
      if (focused) { input.focus({preventScroll:true}); input.setSelectionRange(start, end); }
      if (historyMode) history[live ? 'replaceState' : historyMode](null, '', url);
    } catch (error) {
      if (error.name === 'AbortError') return;
      let message = current.querySelector('.audit-request-error');
      if (!message) { message = document.createElement('p'); message.className = 'audit-request-error'; message.setAttribute('role','alert'); current.prepend(message); }
      message.textContent = error.message;
    } finally {
      if (pending === controller) document.getElementById('audit-workspace').removeAttribute('aria-busy');
    }
  }
  function submit(form, live = false) {
    load('audit_trail.php?' + new URLSearchParams(new FormData(form)), live);
  }
  document.addEventListener('submit', event => {
    if (!event.target.matches('#audit-workspace .audit-filter-card')) return;
    event.preventDefault(); event.stopImmediatePropagation(); submit(event.target);
  }, true);
  document.addEventListener('change', event => {
    if (event.target.matches('#audit-workspace .audit-select')) submit(event.target.form);
  });
  function search(event) {
    if (!event.target.matches('#audit-workspace input[name="q"]')) return;
    clearTimeout(timer);
    if (pending) pending.abort();
    if (event.isComposing) return;
    const input = event.target;
    timer = setTimeout(() => { if (input.isConnected) submit(input.form, true); }, 300);
  }
  document.addEventListener('input', search);
  document.addEventListener('compositionend', search);
  document.addEventListener('click', event => {
    const row = event.target.closest('#audit-workspace .audit-event-row');
    if (row && event.button === 0 && !event.target.closest('a,button,input,select')) {
      row.focus({preventScroll:true});
      load(row.dataset.eventUrl);
      return;
    }
    const link = event.target.closest('#audit-workspace .view-toggle a, #audit-workspace .page-link, #audit-workspace .audit-search-clear');
    if (!link || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || event.button !== 0) return;
    event.preventDefault();
    if (!link.closest('.disabled')) load(link.href);
  });
  document.addEventListener('keydown', event => {
    if (!event.target.matches('#audit-workspace .audit-event-row') || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    load(event.target.dataset.eventUrl);
  });
  window.addEventListener('popstate', () => load(location.href, false, null));
})();
