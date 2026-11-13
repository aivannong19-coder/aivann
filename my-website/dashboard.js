(() => {
  'use strict';

  const logout = document.getElementById('dashboardLogout');
  const status = document.getElementById('dashboardLogoutStatus');
  const csrf = document.querySelector('meta[name="csrf-token"]');
  if (!logout || !status || !csrf) return;

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) window.location.reload();
  });

  async function request(options = {}) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch('auth.php', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...options.headers },
        ...options,
        signal: controller.signal
      });
      const data = await response.json();
      if (!data || typeof data !== 'object') throw new Error('Invalid response.');
      if (typeof data.csrfToken === 'string') csrf.content = data.csrfToken;
      return { response, data };
    } finally {
      window.clearTimeout(timeout);
    }
  }

  function endSession() {
    window.location.replace('./');
  }

  function postLogout() {
    return request({
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content },
      body: JSON.stringify({ action: 'logout' })
    });
  }

  logout.addEventListener('click', async () => {
    if (logout.disabled) return;
    logout.disabled = true;
    logout.setAttribute('aria-busy', 'true');
    logout.querySelector('span').textContent = 'Logging out...';
    status.hidden = true;
    status.textContent = '';
    try {
      let result = await postLogout();
      if (result.response.status === 401 || result.response.status === 403) {
        const refreshed = await request();
        if (!refreshed.response.ok) throw new Error('Session refresh failed.');
        if (!refreshed.data.user) {
          endSession();
          return;
        }
        result = await postLogout();
      }
      if (!result.response.ok) throw new Error('Logout failed.');
      endSession();
    } catch {
      status.textContent = 'We could not log you out. Check your connection and try again.';
      status.hidden = false;
      logout.disabled = false;
      logout.removeAttribute('aria-busy');
      logout.querySelector('span').textContent = 'Log out';
    }
  });
})();
