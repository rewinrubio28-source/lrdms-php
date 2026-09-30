(() => {
  const form = document.getElementById('createRoleForm');
  if (!form) return;
  form.addEventListener('click', event => {
    const toggle = event.target.closest('.perm-toggle');
    if (!toggle) return;
    const boxes = [...form.querySelectorAll('input[data-module]')].filter(box => box.dataset.module === toggle.dataset.module);
    const checked = boxes.some(box => !box.checked);
    boxes.forEach(box => { box.checked = checked; });
  });
  document.addEventListener('submit', async event => {
    if (event.target !== form) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const button = form.querySelector('[type=submit]');
    if (button.disabled) return;
    const error = document.getElementById('createRoleError');
    error.hidden = true;
    button.disabled = true;
    button.textContent = 'Creating…';
    let created = false;
    try {
      const response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
      if (response.redirected) { location.assign(response.url); return; }
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Unable to create role.');
      created = true;
      if (result.assignable) {
        const select = document.querySelector('#addUserModal select[name="role_id"]');
        select.add(new Option(result.name, result.id));
      }
      // Fetch the current role list without replacing either modal or its inputs.
      const listing = await fetch(location.href, {credentials: 'same-origin'});
      if (!listing.ok || listing.redirected) throw new Error('The role was created. Refresh Users to see it in the list.');
      const page = new DOMParser().parseFromString(await listing.text(), 'text/html');
      const updated = page.getElementById('users-directory');
      if (!updated) throw new Error('The role was created. Refresh Users to see it in the list.');
      document.getElementById('users-directory').replaceWith(updated);
      const message = updated.querySelector('.users-directory-message');
      message.textContent = 'Role “' + result.name + '” created.';
      message.style.color = '#18733f';
      message.hidden = false;
      form.reset();
      bootstrap.Modal.getOrCreateInstance(document.getElementById('createRoleModal')).hide();
    } catch (failure) {
      if (created) form.reset();
      error.textContent = created ? 'The role was created. Refresh Users to see the updated list.' : failure.message;
      error.hidden = false;
      error.scrollIntoView({block: 'nearest'});
    } finally {
      button.disabled = false;
      button.textContent = 'Create role';
    }
  }, true);
})();
