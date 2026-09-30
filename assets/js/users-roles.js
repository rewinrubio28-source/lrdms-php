(() => {
  let pending;
  let searchTimer;
  async function navigate(url, liveSearch = false) {
    clearTimeout(searchTimer);
    if (pending) pending.abort();
    const controller = new AbortController();
    pending = controller;
    const current = document.getElementById('users-directory');
    current.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
      if (response.redirected) { location.assign(response.url); return; }
      if (!response.ok) throw new Error('Unable to load users. Please try again.');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const replacement = page.getElementById('users-directory');
      if (!replacement) throw new Error('Unable to load users. Refresh the page to continue.');
      if (controller.signal.aborted) return;
      // Keep the live form node so typing, cursor, and composition are preserved.
      const focused = document.activeElement;
      const restoreFocus = liveSearch && focused && current.querySelector('.users-directory-filters').contains(focused);
      const start = restoreFocus ? focused.selectionStart : null;
      const end = restoreFocus ? focused.selectionEnd : null;
      if (liveSearch) {
        replacement.querySelector('.users-directory-filters').replaceWith(current.querySelector('.users-directory-filters'));
      }
      current.replaceWith(replacement);
      if (restoreFocus) {
        focused.focus({preventScroll: true});
        if (typeof start === 'number' && typeof end === 'number') focused.setSelectionRange(start, end);
      }
      history[liveSearch ? 'replaceState' : 'pushState'](null, '', url);
    } catch (error) {
      if (error.name !== 'AbortError') {
        const message = current.querySelector('.users-directory-message');
        message.textContent = error.message;
        message.hidden = false;
      }
    } finally {
      if (pending === controller) document.getElementById('users-directory').removeAttribute('aria-busy');
    }
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('.users-role-link');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    navigate(link.href);
  });
  document.addEventListener('submit', event => {
    if (!event.target.matches('.users-directory-filters')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    navigate('users.php?' + new URLSearchParams(new FormData(event.target)));
  }, true);
  document.addEventListener('change', event => {
    if (event.target.matches('.users-directory-filters select')) event.target.form.requestSubmit();
  });
  function queueSearch(event) {
    const input = event.target;
    if (!input.matches('.users-directory-filters input[name="q"]')) return;
    clearTimeout(searchTimer);
    if (pending) pending.abort();
    if (event.isComposing) return;
    searchTimer = setTimeout(() => {
      if (input.isConnected) navigate('users.php?' + new URLSearchParams(new FormData(input.form)), true);
    }, 300);
  }
  document.addEventListener('input', queueSearch);
  document.addEventListener('compositionend', queueSearch);
  window.addEventListener('popstate', () => location.reload());
})();
