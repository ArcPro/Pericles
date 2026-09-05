(() => {
  const shell = document.querySelector('.app-shell, .dashboard-shell');
  const commerceNavigation = document.querySelector('[data-commerce-nav]');

  document.querySelectorAll('[data-commerce-menu]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      commerceNavigation?.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', commerceNavigation?.classList.contains('is-open') ? 'true' : 'false');
    });
  });

  commerceNavigation?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => commerceNavigation.classList.remove('is-open'));
  });

  document.querySelectorAll('[data-stripe-payment]').forEach(async (payment) => {
    const form = payment.querySelector('[data-stripe-payment-form]');
    const mountPoint = payment.querySelector('[data-stripe-element]');
    const loading = payment.querySelector('[data-stripe-loading]');
    const submit = payment.querySelector('[data-stripe-submit]');
    const buttonText = payment.querySelector('[data-stripe-button-text]');
    const errorBox = payment.querySelector('[data-stripe-error]');
    const originalButtonText = buttonText?.textContent || 'Pay now';

    const showError = (message) => {
      if (!errorBox) return;
      errorBox.textContent = message || 'Payment could not be initialized. Please try again.';
      errorBox.hidden = false;
    };
    const setBusy = (busy) => {
      payment.classList.toggle('is-busy', busy);
      if (submit) submit.disabled = busy;
      if (buttonText) buttonText.textContent = busy ? 'Processing…' : originalButtonText;
    };

    try {
      if (!form || !mountPoint || !submit || typeof window.Stripe !== 'function') {
        throw new Error('The secure Stripe form could not be loaded.');
      }
      const response = await fetch(payment.dataset.sessionUrl || '', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ csrf: payment.dataset.csrf || '' }),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.client_secret) {
        throw new Error(payload.message || 'Stripe payment could not be initialized.');
      }

      const stripe = window.Stripe(payment.dataset.publishableKey || '');
      const checkout = stripe.initCheckoutElementsSdk({
        clientSecret: payload.client_secret,
        elementsOptions: {
          appearance: {
            theme: 'night',
            variables: {
              colorPrimary: '#9d64f4',
              colorBackground: '#17141e',
              colorText: '#f2eef8',
              colorDanger: '#f07c88',
              borderRadius: '7px',
              fontFamily: 'Figtree, system-ui, sans-serif',
            },
          },
        },
      });
      checkout.createPaymentElement().mount(mountPoint);
      const loaded = await checkout.loadActions();
      if (loaded.type === 'error' || !loaded.actions) {
        throw new Error(loaded.error?.message || 'Stripe payment could not be initialized.');
      }
      const actions = loaded.actions;
      if (loading) loading.hidden = true;
      checkout.on('change', (session) => {
        if (!payment.classList.contains('is-busy')) submit.disabled = !session.canConfirm;
      });

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (errorBox) errorBox.hidden = true;
        setBusy(true);
        try {
          const result = await actions.confirm();
          if (result.type === 'error') {
            showError(result.error?.message || 'Payment could not be confirmed.');
          }
        } catch (error) {
          showError(error instanceof Error ? error.message : 'Payment could not be confirmed.');
        } finally {
          setBusy(false);
        }
      });
    } catch (error) {
      if (loading) loading.hidden = true;
      showError(error instanceof Error ? error.message : 'Payment could not be initialized.');
    }
  });

  document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => shell?.classList.toggle('sidebar-open'));
  });

  document.querySelectorAll('.app-nav a, .dashboard-nav a').forEach((link) => {
    link.addEventListener('click', () => shell?.classList.remove('sidebar-open'));
  });

  document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const input = toggle.closest('.password-field, .input-wrap')?.querySelector('input');
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

  document.querySelectorAll('[data-table-search]').forEach((input) => {
    input.addEventListener('input', () => {
      const query = input.value.trim().toLocaleLowerCase('en');
      const panel = input.closest('.data-panel');
      panel?.querySelectorAll('[data-search-row]').forEach((row) => {
        row.hidden = !row.textContent.toLocaleLowerCase('en').includes(query);
      });
    });
  });

  document.querySelectorAll('[data-global-search]').forEach((input) => {
    input.addEventListener('input', () => {
      const query = input.value.trim().toLocaleLowerCase('en');
      document.querySelectorAll('[data-search-row]').forEach((row) => {
        row.hidden = !row.textContent.toLocaleLowerCase('en').includes(query);
      });
    });
  });

  document.querySelectorAll('[data-activity-filters]').forEach((filters) => {
    filters.querySelectorAll('[data-activity-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        const selected = button.dataset.activityFilter || 'all';
        filters.querySelectorAll('[data-activity-filter]').forEach((candidate) => candidate.classList.toggle('active', candidate === button));
        const panel = filters.closest('.data-panel');
        panel?.querySelectorAll('[data-activity-category]').forEach((row) => {
          row.hidden = selected !== 'all' && row.dataset.activityCategory !== selected;
        });
      });
    });
  });

  document.querySelectorAll('[data-ticket-filters]').forEach((filters) => {
    filters.querySelectorAll('[data-ticket-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        const selected = button.dataset.ticketFilter || 'all';
        filters.querySelectorAll('[data-ticket-filter]').forEach((candidate) => candidate.classList.toggle('active', candidate === button));
        const panel = filters.closest('.data-panel');
        panel?.querySelectorAll('[data-ticket-status]').forEach((row) => {
          row.hidden = selected !== 'all' && row.dataset.ticketStatus !== selected;
        });
      });
    });
  });

  document.querySelectorAll('[data-ticket-priority]').forEach((select) => {
    const warning = select.closest('label')?.querySelector('[data-urgent-warning]');
    const update = () => { if (warning) warning.hidden = select.value !== 'urgent'; };
    select.addEventListener('change', update);
    update();
  });

  document.querySelectorAll('[data-plan-selector]').forEach((selector) => {
    const options = [...selector.querySelectorAll('[data-plan-option]')];
    const section = selector.closest('#pricing') || selector.parentElement;
    const selectedPlan = section?.querySelector('[data-selected-plan]');
    const selectedSummary = section?.querySelector('[data-selected-summary]');

    options.forEach((option) => {
      option.addEventListener('click', () => {
        options.forEach((candidate) => {
          const active = candidate === option;
          candidate.classList.toggle('selected', active);
          candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (selectedPlan) selectedPlan.value = option.dataset.planOption || '';
        if (selectedSummary) selectedSummary.textContent = `${option.dataset.planLabel || ''} · ${option.dataset.planPrice || ''}`;
      });
    });
  });

  document.querySelectorAll('[data-media-carousel]').forEach((carousel) => {
    const slides = [...carousel.querySelectorAll('[data-media-slide]')];
    const thumbs = [...carousel.querySelectorAll('[data-media-thumb]')];
    const mediaSection = carousel.closest('.product-preview') || carousel;
    const lightbox = mediaSection.querySelector('[data-media-dialog]');
    const lightboxImage = lightbox?.querySelector('img');
    const currentCounter = carousel.querySelector('[data-media-current]');
    let activeIndex = Math.max(0, slides.findIndex((slide) => slide.classList.contains('active')));
    let touchStartX = null;

    const activate = (index) => {
      if (!slides.length) return;
      activeIndex = (index + slides.length) % slides.length;
      slides.forEach((slide, candidateIndex) => {
        const active = candidateIndex === activeIndex;
        slide.classList.toggle('active', active);
        slide.hidden = !active;
        const video = slide.querySelector('video');
        if (video && active && !video.getAttribute('src') && video.dataset.src) {
          video.setAttribute('src', video.dataset.src);
          video.load();
        }
        if (video && !active) video.pause();
      });
      thumbs.forEach((thumb, candidateIndex) => {
        const active = candidateIndex === activeIndex;
        thumb.classList.toggle('active', active);
        thumb.setAttribute('aria-current', active ? 'true' : 'false');
      });
      if (currentCounter) currentCounter.textContent = String(activeIndex + 1);
    };

    carousel.querySelector('[data-media-prev]')?.addEventListener('click', () => activate(activeIndex - 1));
    carousel.querySelector('[data-media-next]')?.addEventListener('click', () => activate(activeIndex + 1));
    thumbs.forEach((thumb, index) => thumb.addEventListener('click', () => activate(index)));
    slides.forEach((slide) => {
      slide.querySelector('[data-media-lightbox]')?.addEventListener('click', () => {
        const image = slide.querySelector('img');
        if (!lightbox || !lightboxImage || !image) return;
        lightboxImage.src = image.currentSrc || image.src;
        lightboxImage.alt = image.alt;
        lightbox.showModal();
      });
    });
    lightbox?.querySelector('[data-media-dialog-close]')?.addEventListener('click', () => lightbox.close());
    lightbox?.addEventListener('click', (event) => {
      if (event.target === lightbox) lightbox.close();
    });
    carousel.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowLeft') activate(activeIndex - 1);
      if (event.key === 'ArrowRight') activate(activeIndex + 1);
    });
    carousel.addEventListener('touchstart', (event) => {
      touchStartX = event.changedTouches[0]?.clientX ?? null;
    }, { passive: true });
    carousel.addEventListener('touchend', (event) => {
      if (touchStartX === null) return;
      const distance = (event.changedTouches[0]?.clientX ?? touchStartX) - touchStartX;
      if (Math.abs(distance) > 45) activate(activeIndex + (distance < 0 ? 1 : -1));
      touchStartX = null;
    }, { passive: true });
    activate(activeIndex);
  });

  document.addEventListener('click', (event) => {
    document.querySelectorAll('.user-menu[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
    document.querySelectorAll('.row-actions[open], .notification-menu[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
    document.querySelectorAll('.quick-buy[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      shell?.classList.remove('sidebar-open');
      commerceNavigation?.classList.remove('is-open');
      document.querySelectorAll('.user-menu[open]').forEach((menu) => menu.removeAttribute('open'));
    }
    if ((event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase() === 'k') {
      event.preventDefault();
      openCommand();
    }
  });
})();
