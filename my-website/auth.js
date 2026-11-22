(() => {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const dialog = byId('authDialog');
  if (!dialog) return;
  const title = byId('authTitle');
  const description = byId('authDescription');
  const status = byId('authStatus');
  const remember = byId('authRemember');
  const rememberedUsernameKey = 'kayasureRememberedUsername';
  const usernamePattern = /^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/;
  const state = { csrfToken: '', user: null, registrationAvailable: false };
  const views = [...dialog.querySelectorAll('[data-auth-view]')];
  let currentView = 'login';
  let busy = false;
  let loaded = false;
  let loadError = '';
  let loadingState = null;
  let lastOpener = null;

  try { sessionStorage.removeItem('kayasureAuthResult'); } catch { /* Clean up an old sign-in notice when storage is available. */ }

  // Discard old authentication callback flags without starting another flow.
  const url = new URL(window.location.href);
  const requestedLogin = url.searchParams.get('login') === '1';
  if (url.searchParams.has('auth') || requestedLogin) {
    url.searchParams.delete('auth');
    if (requestedLogin) url.searchParams.delete('login');
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
  }

  try {
    const savedUsername = localStorage.getItem(rememberedUsernameKey) || '';
    if (usernamePattern.test(savedUsername)) {
      byId('authIdentifier').value = savedUsername.toLowerCase();
      remember.checked = true;
    }
  } catch { /* Browser storage is optional. */ }

  function announce(message = '', kind = 'info') {
    status.textContent = message;
    status.hidden = !message;
    status.dataset.kind = kind;
    status.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    status.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');
  }

  function setBusy(value) {
    busy = value;
    dialog.setAttribute('aria-busy', String(value));
    dialog.querySelectorAll('form input, .auth-view button').forEach((element) => {
      element.disabled = value;
    });
    updateAvailability();
  }

  function updateAvailability() {
    byId('authCreateButton').disabled = busy || !loaded || !state.registrationAvailable;
    byId('authRegistrationNotice').hidden = !loaded || state.registrationAvailable;
  }

  function applyState(data) {
    if (!data || typeof data !== 'object') return;
    if (typeof data.csrfToken === 'string') state.csrfToken = data.csrfToken;
    if (Object.prototype.hasOwnProperty.call(data, 'user')) state.user = data.user;
    if (typeof data.registrationAvailable === 'boolean') state.registrationAvailable = data.registrationAvailable;
    document.querySelectorAll('.auth-guest').forEach((element) => { element.hidden = Boolean(state.user); });
    document.querySelectorAll('.auth-member').forEach((element) => { element.hidden = !state.user; });
    const headerAccount = document.querySelector('.header-account');
    if (headerAccount) {
      headerAccount.textContent = 'Dashboard';
      headerAccount.title = state.user?.username ? `Open dashboard for ${state.user.username}` : 'Open dashboard';
    }
    updateAvailability();
  }

  function clearInvalid() {
    dialog.querySelectorAll('input').forEach((input) => {
      input.removeAttribute('aria-invalid');
      input.setCustomValidity('');
    });
  }

  function render(view, moveFocus = true) {
    currentView = view === 'signup' ? 'signup' : 'login';
    const content = {
      login: ['Welcome back.', 'Log in with your username and password. Your account is saved for your next visit.'],
      signup: ['A clearer start.', 'Choose a username and password to create your Kayasure account.']
    }[currentView];
    title.textContent = content[0];
    description.textContent = content[1];
    views.forEach((element) => { element.hidden = element.dataset.authView !== currentView; });
    clearInvalid();
    updateAvailability();
    if (moveFocus && dialog.open) title.focus();
  }

  async function fetchJson(options = {}) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 35000);
    try {
      const response = await fetch('auth.php', {
        credentials: 'same-origin', cache: 'no-store', ...options,
        headers: { Accept: 'application/json', ...options.headers }, signal: controller.signal
      });
      let data;
      try { data = await response.json(); }
      catch { throw new Error('Account services are unavailable right now. Please try again shortly.'); }
      applyState(data);
      if (!response.ok || data.error) {
        const error = new Error(typeof data.error === 'string' ? data.error : 'We could not complete that request. Please try again.');
        error.field = data.field;
        throw error;
      }
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('This is taking longer than expected. Please try again.');
      if (error instanceof TypeError) throw new Error('We could not connect. Check your connection and try again.');
      throw error;
    } finally { window.clearTimeout(timeout); }
  }

  async function loadState() {
    if (loadingState) return loadingState;
    loadingState = (async () => {
      try {
        const data = await fetchJson();
        loaded = true;
        loadError = '';
        updateAvailability();
        return data;
      } catch (error) {
        loaded = false;
        loadError = error.message;
        throw error;
      }
    })();
    try { return await loadingState; }
    finally { loadingState = null; }
  }

  async function perform(action, values = {}, onSuccess) {
    if (busy) return;
    clearInvalid();
    announce('Please wait…');
    setBusy(true);
    try {
      if (!loaded || loadError || !state.csrfToken) await loadState();
      if (action === 'create-account' && !state.registrationAvailable) {
        throw new Error('Account registration is temporarily unavailable. Please try again later.');
      }
      const data = await fetchJson({
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrfToken },
        body: JSON.stringify({ action, ...values })
      });
      setBusy(false);
      announce();
      if (onSuccess) onSuccess(data);
    } catch (error) {
      setBusy(false);
      announce(error.message || 'Something went wrong. Please try again.', 'error');
      const fields = {
        username: currentView === 'signup' ? 'authSignupUsername' : 'authIdentifier',
        password: currentView === 'signup' ? 'authNewPassword' : 'authLoginPassword',
        passwordConfirm: 'authConfirmPassword'
      };
      const field = fields[error.field] && byId(fields[error.field]);
      if (field && !field.closest('[hidden]')) {
        field.setAttribute('aria-invalid', 'true');
        field.focus();
      }
    } finally { setBusy(false); }
  }

  function saveUsername(username) {
    try {
      if (remember.checked && usernamePattern.test(username)) localStorage.setItem(rememberedUsernameKey, username.toLowerCase());
      else localStorage.removeItem(rememberedUsernameKey);
    } catch { /* Browser storage is optional; the account is still saved on the server. */ }
  }

  function goToDashboard() {
    window.location.assign('dashboard.php');
  }

  function close() {
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  const ready = loadState().catch(() => null);
  if (requestedLogin) ready.then(() => open('login'));

  async function open(mode, opener = null) {
    if (state.user) { goToDashboard(); return; }
    lastOpener = opener || lastOpener;
    document.querySelectorAll('dialog[open]').forEach((other) => { if (other !== dialog) other.close(); });
    byId('mainNav')?.classList.remove('is-open');
    byId('menuToggle')?.setAttribute('aria-expanded', 'false');
    byId('menuToggle')?.setAttribute('aria-label', 'Open navigation');
    render(mode, false);
    if (!dialog.open) {
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    }
    const needsLoad = !loaded || Boolean(loadError);
    if (needsLoad) { announce('Loading your account…'); setBusy(true); }
    await ready;
    if (!loaded || loadError) {
      try { await loadState(); } catch { /* Show the safe error below and allow another retry. */ }
    }
    if (needsLoad) setBusy(false);
    if (state.user) { goToDashboard(); return; }
    if (!dialog.open) return;
    render(mode);
    announce(loadError, loadError ? 'error' : 'info');
  }

  document.querySelectorAll('[data-auth-open]').forEach((button) => {
    button.addEventListener('click', () => { if (!busy) open(button.dataset.authOpen, button); });
  });
  byId('authClose').addEventListener('click', close);
  dialog.addEventListener('close', () => {
    dialog.querySelectorAll('.auth-password input').forEach((input) => { input.value = ''; });
    dialog.querySelectorAll('[data-auth-reveal]').forEach((button) => {
      byId(button.dataset.authReveal).type = 'password';
      button.textContent = 'Show';
      button.setAttribute('aria-pressed', 'false');
      button.setAttribute('aria-label', 'Show password');
    });
    announce();
    lastOpener?.focus();
  });

  byId('authSignupForm').addEventListener('submit', (event) => {
    event.preventDefault();
    const username = byId('authSignupUsername');
    const password = byId('authNewPassword');
    const confirmation = byId('authConfirmPassword');
    username.setCustomValidity(usernamePattern.test(username.value.trim()) ? '' : 'Use 3–32 letters, numbers, dots, underscores, or hyphens. Start with a letter or number.');
    password.setCustomValidity(new TextEncoder().encode(password.value).length > 72 ? 'Please use a password of 72 bytes or fewer.' : '');
    confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Your passwords do not match.');
    if (!event.currentTarget.reportValidity()) return;
    perform('create-account', { username: username.value.trim(), password: password.value, passwordConfirm: confirmation.value }, (data) => {
      saveUsername(data.user?.username || username.value.trim());
      goToDashboard();
    });
  });
  byId('authLoginForm').addEventListener('submit', (event) => {
    event.preventDefault();
    if (!event.currentTarget.reportValidity()) return;
    const username = byId('authIdentifier').value.trim();
    perform('login', { username, password: byId('authLoginPassword').value }, (data) => {
      saveUsername(data.user?.username || username);
      goToDashboard();
    });
  });
  remember.addEventListener('change', () => {
    if (remember.checked) return;
    try { localStorage.removeItem(rememberedUsernameKey); } catch { /* Browser storage is optional. */ }
  });
  dialog.querySelectorAll('[data-auth-reveal]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = byId(button.dataset.authReveal);
      const reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      button.textContent = reveal ? 'Hide' : 'Show';
      button.setAttribute('aria-pressed', String(reveal));
      button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    });
  });
  dialog.querySelectorAll('input').forEach((input) => {
    input.addEventListener('input', () => { input.setCustomValidity(''); input.removeAttribute('aria-invalid'); });
  });
  byId('authNewPassword').addEventListener('input', () => byId('authConfirmPassword').setCustomValidity(''));

})();
