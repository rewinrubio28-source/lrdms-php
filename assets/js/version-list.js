(() => {
  if (!document.getElementById('version-list-workspace')) return;
  let timer, controller, sequence = 0;
  const workspace = () => document.getElementById('version-list-workspace');
  function cancel() {
    clearTimeout(timer);
    sequence++;
    controller?.abort();
  }
  async function load(url, historyMode = 'replace') {
    cancel();
    const request = sequence;
    controller = new AbortController();
    workspace().setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {credentials: 'same-origin', signal: controller.signal});
      if (!response.ok) throw new Error('Request failed');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (request !== sequence) return;
      const fresh = parsed.getElementById('version-list-workspace');
      if (!fresh) { location.assign(response.url); return; }
      const active = document.activeElement;
      const field = active.closest('.version-filters') ? active.name : null;
      const start = active.selectionStart, end = active.selectionEnd;
      workspace().replaceWith(fresh);
      if (field) {
        const input = fresh.querySelector('.version-filters').elements.namedItem(field);
        input?.focus({preventScroll: true});
        if (input?.setSelectionRange && start !== null) input.setSelectionRange(start, end);
      }
      if (historyMode === 'push') history.pushState(null, '', url);
      else if (historyMode === 'replace') history.replaceState(null, '', url);
    } catch (error) {
      if (request !== sequence || error.name === 'AbortError') return;
      workspace().querySelector('[data-search-error]')?.remove();
      const notice = document.createElement('p');
      notice.dataset.searchError = '1';
      notice.className = 'alert alert-danger';
      notice.setAttribute('role', 'alert');
      notice.textContent = 'Could not refresh the list. Press Filter to retry.';
      workspace().prepend(notice);
    } finally {
      if (request === sequence) workspace()?.removeAttribute('aria-busy');
    }
  }
  function filter() {
    const url = new URL(location.pathname, location.origin);
    url.search = new URLSearchParams(new FormData(workspace().querySelector('.version-filters')));
    load(url);
  }
  document.addEventListener('input', event => {
    if (!event.target.matches('.version-filters input[name="q"]')) return;
    cancel();
    if (!event.isComposing) timer = setTimeout(filter, 250);
  });
  document.addEventListener('compositionend', event => {
    if (event.target.matches('.version-filters input[name="q"]')) { cancel(); timer = setTimeout(filter, 250); }
  });
  document.addEventListener('change', event => {
    if (event.target.matches('.version-filters select')) filter();
  });
  document.addEventListener('submit', event => {
    if (!event.target.matches('.version-filters')) return;
    event.preventDefault();
    filter();
  });
  document.addEventListener('click', event => {
    if (!workspace()?.contains(event.target)) return;
    const exportButton = event.target.closest('#version-export');
    if (exportButton) {
      const rows = JSON.parse(document.getElementById('version-export-data').textContent);
      const csv = rows.map(row => row.map(value => '"' + String(value).replace(/^[=+@\-\t\r]/, "'$&").replace(/"/g, '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8'}));
      const a = document.createElement('a'); a.href = url; a.download = 'version-history.csv'; a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      return;
    }
    let link = event.target.closest('a[href]');
    if (!link && !event.target.closest('button, input, select, textarea, label') && !window.getSelection().toString()) {
      link = event.target.closest('[data-version-row]')?.querySelector('.version-record-title');
      if (link && (event.ctrlKey || event.metaKey || event.shiftKey)) { window.open(link.href, '_blank', 'noopener'); return; }
    }
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    const url = new URL(link.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname || url.searchParams.has('doc')) return;
    event.preventDefault();
    load(url, 'push');
  });
  window.addEventListener('popstate', () => load(location.href, 'none'));
})();
