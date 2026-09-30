// Progressive enhancement: PHP still handles permissions, validation and saves.
(function () {
  const container = document.getElementById('encoding-content');
  if (!container) return;
  let busy = false;
  async function update(url, options = {}, push = true, button = null) {
    if (busy) return;
    busy = true;
    const original = button ? button.innerHTML : '';
    container.setAttribute('aria-busy', 'true');
    if (button) { button.disabled = true; button.textContent = options.method === 'POST' ? 'Saving…' : 'Loading…'; }
    container.querySelector('[data-request-error]')?.remove();
    try {
      const response = await fetch(url, {credentials: 'same-origin', ...options});
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      const content = parsed.getElementById('encoding-content');
      if (!content) {
        if (response.redirected && new URL(response.url).pathname !== location.pathname) {
          location.assign(response.url); return;
        }
        throw new Error('Unexpected response');
      }
      if (!response.ok) throw new Error('Request failed');
      container.replaceChildren(...content.childNodes);
      if (push && response.url !== location.href) history.pushState(null, '', response.url);
      // Replaced forms retain their server-generated CSRF tokens.
      const notice = container.querySelector('.alert');
      if (notice) { notice.setAttribute('role', 'status'); notice.setAttribute('tabindex', '-1'); notice.focus({preventScroll: true}); }
    } catch (error) {
      const notice = document.createElement('div');
      notice.className = 'alert alert-danger';
      notice.dataset.requestError = '1';
      notice.setAttribute('role', 'alert');
      notice.textContent = options.method === 'POST'
        ? 'Could not confirm the save. Your entries are still here. Check the follow-up history before retrying.'
        : 'Could not load records. Please try again.';
      container.prepend(notice);
    } finally {
      busy = false;
      container.removeAttribute('aria-busy');
      if (button && button.isConnected) { button.disabled = false; button.innerHTML = original; }
    }
  }
  // Capture avoids the shared full-page form loading handler disabling submitters.
  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!container.contains(form)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (busy) return;
    const data = new FormData(form);
    if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
    const url = new URL(form.getAttribute('action') || location.href, location.href);
    if (form.method.toLowerCase() === 'post') update(url, {method: 'POST', body: data}, true, event.submitter);
    else { url.search = new URLSearchParams(data).toString(); update(url, {}, true, event.submitter); }
  }, true);
  container.addEventListener('click', function (event) {
    const toggle = event.target.closest('[data-followup-toggle]');
    if (toggle) {
      const row = document.getElementById(toggle.dataset.followupToggle);
      row.hidden = !row.hidden;
      toggle.setAttribute('aria-expanded', String(!row.hidden));
      if (!row.hidden) row.querySelector('input:not([type="hidden"])').focus({preventScroll: true});
      return;
    }
    const link = event.target.closest('a[href]');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    const url = new URL(link.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname) return;
    event.preventDefault();
    update(url);
  });
  window.addEventListener('popstate', function () { if (!busy) update(location.href, {}, false); });
})();
