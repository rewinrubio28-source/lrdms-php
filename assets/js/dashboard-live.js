(() => {
  'use strict';
  const form = document.getElementById('dashboard-filters');
  const region = document.getElementById('dashboard-live');
  const status = document.getElementById('dashboard-refresh-status');
  if (!region || !status) return;
  let applied = new URLSearchParams(form ? new FormData(form) : region.dataset.query);
  let controller = null;
  let stopped = false;
  function links(query) {
    document.querySelectorAll('[data-dashboard-export]').forEach(link => {
      const params = new URLSearchParams(query);
      params.set('format', link.dataset.dashboardExport);
      link.href = 'api/export_dashboard.php?' + params;
    });
    const recordsLink = document.getElementById('dashboard-records-link');
    if (recordsLink) recordsLink.href = 'dashboard_records.php?' + query;
  }
  async function refresh(query, apply = false) {
    if (stopped) return;
    if (controller) { if (!apply) return; controller.abort(); }
    const request = new AbortController(); controller = request;
    const timeout = setTimeout(() => request.abort(), 12000);
    region.setAttribute('aria-busy', 'true');
    status.textContent = 'Updating dashboard…';
    try {
      const response = await fetch('api/dashboard.php?' + query, {signal: request.signal, cache: 'no-store', credentials: 'same-origin'});
      if (controller !== request) return;
      if (response.status === 401 || response.status === 403) {
        stopped = true; region.replaceChildren();
        document.querySelectorAll('[data-dashboard-export], #dashboard-records-link').forEach(link => { link.removeAttribute('href'); });
        status.textContent = 'Your access changed or session expired. Sign in again.'; return;
      }
      const data = await response.json();
      if (!response.ok || typeof data.html !== 'string') throw new Error(data.error || 'Refresh failed.');
      if (controller !== request) return;
      // HTML comes only from the session-authenticated, escaped PHP view.
      const focused = region.contains(document.activeElement) ? document.activeElement.getAttribute('href') : null;
      region.innerHTML = data.html;
      if (focused) Array.from(region.querySelectorAll('a')).find(a => a.getAttribute('href') === focused)?.focus({preventScroll: true});
      if (apply) { applied = new URLSearchParams(query); links(applied); history.replaceState(null, '', 'dashboard.php?' + applied); }
      status.textContent = 'Updated ' + new Date(data.generated_at).toLocaleString() + '. Auto-refresh every 15 seconds.';
    } catch (error) {
      if (controller === request) status.textContent = 'Showing the last successful update. ' + (error.name === 'AbortError' ? 'Request timed out.' : error.message);
    } finally {
      clearTimeout(timeout);
      if (controller === request) { controller = null; region.removeAttribute('aria-busy'); }
    }
  }
  form?.addEventListener('submit', event => { event.preventDefault(); refresh(new URLSearchParams(new FormData(form)), true); });
  document.getElementById('dashboard-refresh')?.addEventListener('click', () => refresh(applied));
  setInterval(() => { if (!document.hidden) refresh(applied); }, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(applied); });
})();
