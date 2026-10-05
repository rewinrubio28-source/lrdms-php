(() => {
  document.querySelectorAll('[data-session-form]').forEach(form => {
    if (form.dataset.enhanced) return;
    form.dataset.enhanced = 'true';
    const file = form.querySelector('input[type="file"]');
    if (file) file.addEventListener('change', () => {
      const selected = file.files[0];
      file.setCustomValidity(selected && selected.size > 25 * 1024 * 1024 ? 'Choose a file up to 25 MB.' : '');
      if (!file.checkValidity()) file.reportValidity();
    });
    form.addEventListener('submit', event => {
      if (form.dataset.saving) { event.preventDefault(); return; }
      form.dataset.saving = 'true';
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      form.setAttribute('aria-busy', 'true');
      form.querySelector('.session-form-status').textContent = 'Saving your update. Please wait…';
    });
  });
  // Restore controls when returning via the browser's back/forward cache.
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-session-form]').forEach(form => {
      delete form.dataset.saving;
      form.removeAttribute('aria-busy');
      form.querySelector('button[type="submit"]').disabled = false;
      form.querySelector('.session-form-status').textContent = '';
    });
  });
})();
