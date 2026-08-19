(() => {
  const shell = document.querySelector('.dashboard-shell');

  document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => shell?.classList.toggle('sidebar-open'));
  });

  document.querySelectorAll('.dashboard-nav a').forEach((link) => {
    link.addEventListener('click', () => shell?.classList.remove('sidebar-open'));
  });

  document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const input = toggle.closest('.input-wrap')?.querySelector('input');
      if (!input) return;
      const willShow = input.type === 'password';
      input.type = willShow ? 'text' : 'password';
      toggle.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');
      toggle.classList.toggle('is-visible', willShow);
    });
  });

  document.querySelectorAll('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.toast')?.remove());
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm || 'Confirm this action?')) event.preventDefault();
    });
  });

  const commandDialog = document.querySelector('[data-command-dialog]');
  const commandInput = commandDialog?.querySelector('[data-command-input]');
  const openCommand = () => {
    if (!commandDialog) return;
    commandDialog.showModal();
    commandInput?.focus();
  };

  document.querySelectorAll('[data-command-open]').forEach((button) => {
    button.addEventListener('click', openCommand);
  });

  commandInput?.addEventListener('input', () => {
    const query = commandInput.value.trim().toLocaleLowerCase('fr');
    let visible = 0;
    commandDialog.querySelectorAll('[data-command-item]').forEach((item) => {
      const matches = item.textContent.toLocaleLowerCase('fr').includes(query);
      item.hidden = !matches;
      if (matches) visible += 1;
    });
    const empty = commandDialog.querySelector('[data-command-empty]');
    if (empty) empty.hidden = visible > 0;
  });

  commandDialog?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => commandDialog.close());
  });

  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = button.parentElement?.querySelector('[data-copy-value]')?.dataset.copyValue;
      if (!value) return;
      await navigator.clipboard.writeText(value);
      button.textContent = 'Key copied';
    });
  });

  const adminSearch = document.querySelector('[data-admin-search]');
  adminSearch?.addEventListener('input', () => {
    const query = adminSearch.value.trim().toLocaleLowerCase('fr');
    document.querySelectorAll('[data-admin-user]').forEach((row) => {
      row.hidden = !row.dataset.adminUser.includes(query);
    });
  });

  document.addEventListener('click', (event) => {
    document.querySelectorAll('.user-menu[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
    document.querySelectorAll('.row-actions[open], .notification-menu[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      shell?.classList.remove('sidebar-open');
      document.querySelectorAll('.user-menu[open]').forEach((menu) => menu.removeAttribute('open'));
    }
    if ((event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase() === 'k') {
      event.preventDefault();
      openCommand();
    }
  });
})();
