(() => {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const shell = byId('dashboardShell');
  const sidebar = document.querySelector('.dashboard-sidebar');
  const sidebarToggle = byId('sidebarToggle');
  const sidebarClose = byId('sidebarClose');
  const mobile = window.matchMedia('(max-width: 760px)');
  const main = byId('dashboardMain');
  const scroll = byId('conversationScroll');
  const messages = byId('conversationMessages');
  const welcome = byId('chatWelcome');
  const form = byId('dashboardChatForm');
  const input = byId('dashboardMessage');
  const send = byId('dashboardSend');
  const composerStatus = byId('composerStatus');
  const counter = byId('messageCounter');
  const historyStatus = byId('conversationStatus');
  const historyRetry = byId('historyRetry');
  const recent = byId('recentQuestions');
  const searchDialog = byId('conversationSearch');
  const searchInput = byId('conversationSearchInput');
  const searchResults = byId('conversationSearchResults');
  const logout = byId('dashboardLogout');
  const logoutStatus = byId('dashboardLogoutStatus');
  const csrf = document.querySelector('meta[name="csrf-token"]');
  const entries = [];
  let sending = false;
  let loadingHistory = false;
  let transcriptHasNewMessages = false;
  let nextMessageId = 0;
  let searchReturnFocus = null;
  let searchSelectedEntry = null;
  let assistantScrollTop = 0;
  let activeWorkspace = 'assistant';
  const workspaceViews = {
    assistant: { panel: byId('assistantPanel'), title: 'Your insurance assistant' },
    coverage: { panel: byId('coveragePanel'), title: 'Coverage guide' },
    checklist: { panel: byId('checklistPanel'), title: 'Registration checklist' }
  };

  function showWorkspace(view, { updateHash = false, focus = false } = {}) {
    if (!Object.prototype.hasOwnProperty.call(workspaceViews, view)) view = 'assistant';
    if (activeWorkspace === 'assistant' && view !== 'assistant' && scroll) {
      assistantScrollTop = scroll.scrollTop;
    }
    Object.entries(workspaceViews).forEach(([name, workspace]) => {
      if (workspace.panel) workspace.panel.hidden = name !== view;
    });
    document.querySelectorAll('.dashboard-nav [data-workspace-view]').forEach((link) => {
      const selected = link.dataset.workspaceView === view;
      link.classList.toggle('is-active', selected);
      if (selected) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
    const subtitle = byId('workspaceSubtitle');
    if (subtitle) subtitle.textContent = workspaceViews[view].title;
    if (main) main.dataset.workspace = view;
    document.title = (view === 'assistant' ? 'Kaya AI' : workspaceViews[view].title) + ' | Kayasure Dashboard';
    if (updateHash && window.location.hash !== '#' + view) {
      window.history.pushState(null, '', '#' + view);
    }
    closeSidebar();
    if (view === 'assistant') {
      updateComposer();
      if (activeWorkspace !== 'assistant' && scroll) scroll.scrollTop = assistantScrollTop;
    }
    activeWorkspace = view;
    if (focus) {
      const target = view === 'assistant' ? input : workspaceViews[view].panel?.querySelector('h1');
      target?.focus({ preventScroll: true });
    }
  }

  document.querySelectorAll('[data-workspace-view]').forEach((link) => {
    link.addEventListener('click', (event) => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      showWorkspace(link.dataset.workspaceView, { updateHash: true, focus: true });
    });
  });
  window.addEventListener('hashchange', () => showWorkspace(window.location.hash.slice(1), { focus: true }));

  const checklistItems = Array.from(document.querySelectorAll('[data-checklist-item]'));
  function updateChecklist() {
    const progress = byId('checklistProgress');
    if (progress) progress.textContent = checklistItems.filter((item) => item.checked).length + ' of ' + checklistItems.length + ' prepared';
  }
  checklistItems.forEach((item) => item.addEventListener('change', updateChecklist));

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) window.location.reload();
  });

  function syncSidebar() {
    if (!shell || !sidebar) return;
    const open = mobile.matches
      ? shell.classList.contains('is-sidebar-open')
      : !shell.classList.contains('is-sidebar-collapsed');
    sidebarToggle?.setAttribute('aria-expanded', String(open));
    sidebarToggle?.setAttribute('aria-label', open ? 'Close sidebar' : 'Open sidebar');
    sidebar.inert = mobile.matches && !open;
    if (mobile.matches && !open) sidebar.setAttribute('aria-hidden', 'true');
    else sidebar.removeAttribute('aria-hidden');
  }

  function closeSidebar(returnFocus = false) {
    if (!shell || !mobile.matches) return;
    const wasOpen = shell.classList.contains('is-sidebar-open');
    shell.classList.remove('is-sidebar-open');
    if (wasOpen && (returnFocus || sidebar?.contains(document.activeElement))) sidebarToggle?.focus();
    syncSidebar();
  }

  sidebarToggle?.addEventListener('click', () => {
    if (!shell) return;
    if (mobile.matches) {
      shell.classList.toggle('is-sidebar-open');
      syncSidebar();
      if (shell.classList.contains('is-sidebar-open')) {
        (sidebarClose || sidebar?.querySelector('a, button'))?.focus();
      }
    } else {
      shell.classList.toggle('is-sidebar-collapsed');
      syncSidebar();
    }
  });
  sidebarClose?.addEventListener('click', () => {
    if (mobile.matches) closeSidebar(true);
    else {
      shell?.classList.add('is-sidebar-collapsed');
      syncSidebar();
      sidebarToggle?.focus();
    }
  });
  byId('sidebarBackdrop')?.addEventListener('click', () => closeSidebar(true));
  sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => closeSidebar()));
  mobile.addEventListener('change', () => {
    if (!mobile.matches) shell?.classList.remove('is-sidebar-open');
    syncSidebar();
  });
  syncSidebar();

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !searchDialog?.open) closeSidebar(true);
    if (event.key !== 'Tab' || !mobile.matches || !shell?.classList.contains('is-sidebar-open') || searchDialog?.open) return;
    const focusable = Array.from(sidebar?.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), summary, [tabindex="0"]') || [])
      .filter((element) => element.getClientRects().length > 0);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (!first || !last) return;
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  function scrollToBottom() {
    if (scroll) scroll.scrollTop = scroll.scrollHeight;
  }

  function characterCount(value) {
    return Array.from(value).length;
  }

  function updateComposer() {
    if (!input) return;
    const count = characterCount(input.value);
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 180) + 'px';
    input.style.overflowY = input.scrollHeight > 180 ? 'auto' : 'hidden';
    if (send) {
      send.disabled = sending || !input.value.trim() || count > 4000;
      send.setAttribute('aria-label', sending ? 'Waiting for Kaya' : 'Send message');
    }
    if (counter) {
      counter.textContent = count.toLocaleString() + ' / 4,000';
      counter.hidden = count < 3500;
      counter.classList.toggle('is-over-limit', count > 4000);
    }
    if (form) {
      if (sending || loadingHistory) form.setAttribute('aria-busy', 'true');
      else form.removeAttribute('aria-busy');
    }
  }

  function setDraft(text) {
    if (!input) return;
    showWorkspace('assistant', { updateHash: true });
    input.value = text;
    input.setCustomValidity('');
    closeSidebar();
    updateComposer();
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
  }

  document.querySelectorAll('[data-prompt]').forEach((button) => {
    button.addEventListener('click', () => setDraft(button.dataset.prompt || ''));
  });
  byId('newQuestion')?.addEventListener('click', () => {
    showWorkspace('assistant', { updateHash: true });
    closeSidebar();
    scrollToBottom();
    input?.focus();
  });
  input?.addEventListener('input', () => {
    input.setCustomValidity('');
    updateComposer();
  });
  input?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      if (!sending && input.value.trim()) form?.requestSubmit();
    }
  });

  function revealMessage(entry) {
    showWorkspace('assistant', { updateHash: true });
    closeSidebar();
    entry.element.scrollIntoView({ block: 'center', behavior: 'auto' });
    entry.element.focus({ preventScroll: true });
    entry.element.classList.add('is-highlighted');
    window.setTimeout(() => entry.element.classList.remove('is-highlighted'), 1800);
  }

  function refreshRecentQuestions() {
    if (!recent) return;
    const questions = entries.filter((entry) => entry.role === 'user').slice(-8).reverse();
    recent.replaceChildren();
    if (!questions.length) {
      const empty = document.createElement('li');
      empty.id = 'recentEmpty';
      empty.className = 'recent-empty';
      empty.textContent = 'Your questions will appear here.';
      recent.append(empty);
      return;
    }
    questions.forEach((entry) => {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'recent-question';
      button.textContent = entry.text;
      button.title = entry.text;
      button.addEventListener('click', () => revealMessage(entry));
      item.append(button);
      recent.append(item);
    });
  }

  function appendCopyButton(entry) {
    if (entry.element.querySelector('.message-copy')) return;
    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'message-copy';
    copy.textContent = 'Copy response';
    copy.setAttribute('aria-label', 'Copy Kaya’s response');
    copy.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(entry.text);
        copy.textContent = 'Copied';
      } catch {
        copy.textContent = 'Select text to copy';
      }
      window.setTimeout(() => { copy.textContent = 'Copy response'; }, 2200);
    });
    entry.body.append(copy);
  }

  function addRetryButton(entry, draft) {
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'message-retry';
    retry.textContent = 'Try this question again';
    retry.addEventListener('click', () => setDraft(draft));
    entry.body.append(retry);
  }

  function addMessage(role, text, pending = false, error = false) {
    if (!messages) return null;
    const element = document.createElement('article');
    element.id = 'conversation-message-' + (++nextMessageId);
    element.className = role + '-message' + (pending ? ' is-pending' : '') + (error ? ' is-error' : '');
    element.tabIndex = -1;
    const avatar = document.createElement('span');
    avatar.className = 'message-avatar';
    avatar.setAttribute('aria-hidden', 'true');
    avatar.textContent = role === 'user' ? 'Y' : 'K';
    const body = document.createElement('div');
    body.className = 'message-body';
    const author = document.createElement('span');
    author.className = 'message-author';
    author.textContent = role === 'user' ? 'You' : 'Kaya';
    const content = document.createElement('p');
    content.className = 'message-text';
    content.textContent = text;
    body.append(author, content);
    element.append(avatar, body);
    if (pending) element.setAttribute('aria-busy', 'true');
    messages.append(element);
    const entry = { role, text, element, body, content, pending, error };
    entries.push(entry);
    main?.classList.add('has-messages');
    if (welcome) welcome.hidden = true;
    if (role === 'user') refreshRecentQuestions();
    else if (!pending && !error) appendCopyButton(entry);
    scrollToBottom();
    return entry;
  }

  function finishMessage(entry, text, error = false) {
    if (!entry) return;
    entry.pending = false;
    entry.error = error;
    entry.text = text;
    entry.content.textContent = text;
    entry.element.classList.remove('is-pending');
    entry.element.classList.toggle('is-error', error);
    entry.element.removeAttribute('aria-busy');
    if (!error) appendCopyButton(entry);
    scrollToBottom();
  }

  function setHistoryStatus(text, retry = false) {
    if (historyStatus) {
      historyStatus.textContent = text;
      historyStatus.hidden = !text;
    }
    if (historyRetry) historyRetry.hidden = !retry;
  }

  function validSavedMessage(item) {
    return item !== null && typeof item === 'object' && !Array.isArray(item)
      && ['user', 'assistant'].includes(item.role) && typeof item.content === 'string'
      && ['pending', 'completed', 'failed'].includes(item.status)
      && (item.error_message === null || typeof item.error_message === 'string');
  }

  async function loadHistory() {
    if (!messages || !form || loadingHistory) return;
    loadingHistory = true;
    messages.setAttribute('aria-busy', 'true');
    setHistoryStatus('Loading your saved conversation…');
    updateComposer();
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch('chat.php', {
        method: 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json' }, signal: controller.signal
      });
      if (!response.ok) throw new Error('History unavailable.');
      const data = await response.json();
      if (!data || !Array.isArray(data.messages) || !data.messages.every(validSavedMessage)
        || typeof data.history_limited !== 'boolean') throw new Error('Invalid saved history.');
      if (!transcriptHasNewMessages) {
        messages.replaceChildren();
        entries.length = 0;
        main?.classList.toggle('has-messages', data.messages.length > 0);
        if (welcome) welcome.hidden = data.messages.length > 0;
        data.messages.forEach((item) => {
          addMessage(item.role, item.content);
          if (item.role === 'user' && item.status !== 'completed') {
            const notice = addMessage('assistant', item.status === 'pending'
              ? 'A previous reply was interrupted. You can try your question again.'
              : item.error_message?.trim() || 'That reply could not be completed. Please try your question again.', false, true);
            if (notice) addRetryButton(notice, item.content);
          }
        });
        refreshRecentQuestions();
      }
      setHistoryStatus(data.history_limited ? 'Showing your 100 most recent saved messages from this browser session.' : '');
    } catch {
      setHistoryStatus('Your saved conversation could not be loaded. You can retry or ask a question below.', true);
    } finally {
      window.clearTimeout(timeout);
      loadingHistory = false;
      messages.removeAttribute('aria-busy');
      updateComposer();
    }
  }

  let historyReady = loadHistory();
  historyRetry?.addEventListener('click', () => {
    if (loadingHistory || sending) return;
    historyReady = loadHistory();
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (sending || !input || !messages || !send) return;
    const message = input.value.trim();
    if (!message) { input.focus(); return; }
    if (characterCount(message) > 4000) {
      input.setCustomValidity('Please keep your message to 4,000 characters or fewer.');
      input.reportValidity();
      return;
    }
    sending = true;
    input.value = '';
    if (composerStatus) composerStatus.textContent = 'Kaya is thinking…';
    updateComposer();
    await historyReady;
    transcriptHasNewMessages = true;
    addMessage('user', message);
    const pending = addMessage('assistant', 'Thinking…', true);
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 70000);
    try {
      const response = await fetch('chat.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ message }), signal: controller.signal
      });
      let data;
      try { data = await response.json(); }
      catch { throw new Error('The assistant could not respond. Please try again shortly.'); }
      if (!response.ok) throw new Error(typeof data?.error === 'string' && data.error.trim()
        ? data.error : 'The assistant is unavailable right now. Please try again shortly.');
      if (!data || typeof data.reply !== 'string' || !data.reply.trim()) {
        throw new Error('No reply arrived. Please try again.');
      }
      finishMessage(pending, data.reply);
      if (composerStatus) composerStatus.textContent = 'Kaya’s response is ready.';
    } catch (error) {
      const messageText = error.name === 'AbortError'
        ? 'The assistant is taking longer than expected. Please try again.'
        : error instanceof TypeError ? 'We could not connect. Please check your connection and try again.'
          : error.message || 'Something went wrong. Please try again.';
      finishMessage(pending, messageText, true);
      if (pending) addRetryButton(pending, message);
      if (!input.value) input.value = message;
      if (composerStatus) composerStatus.textContent = 'The reply could not be completed. Your question is ready to try again.';
    } finally {
      window.clearTimeout(timeout);
      sending = false;
      updateComposer();
      if (document.activeElement && form.contains(document.activeElement)) input.focus();
    }
  });

  function renderSearch() {
    if (!searchResults || !searchInput) return;
    searchResults.replaceChildren();
    const query = searchInput.value.trim().toLocaleLowerCase();
    const matches = entries.filter((entry) => !entry.pending && entry.text.toLocaleLowerCase().includes(query));
    if (!query || !matches.length) {
      const empty = document.createElement('li');
      empty.className = 'search-empty';
      empty.textContent = !query ? 'Search the messages in this browser session.' : 'No matching messages found.';
      searchResults.append(empty);
      return;
    }
    matches.slice(-30).reverse().forEach((entry) => {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      const author = document.createElement('span');
      author.className = 'search-result-author';
      author.textContent = entry.role === 'user' ? 'You' : 'Kaya';
      const excerpt = document.createElement('span');
      const start = Math.max(0, entry.text.toLocaleLowerCase().indexOf(query) - 35);
      excerpt.textContent = (start ? '…' : '') + entry.text.slice(start, start + 160) + (entry.text.length > start + 160 ? '…' : '');
      button.append(author, excerpt);
      button.addEventListener('click', () => {
        searchSelectedEntry = entry;
        searchDialog?.close();
      });
      item.append(button);
      searchResults.append(item);
    });
  }

  byId('searchConversation')?.addEventListener('click', () => {
    if (!searchDialog || !searchInput) return;
    searchReturnFocus = document.activeElement;
    searchSelectedEntry = null;
    closeSidebar();
    searchInput.value = '';
    renderSearch();
    searchDialog.showModal();
    searchInput.focus();
  });
  byId('closeSearch')?.addEventListener('click', () => searchDialog?.close());
  searchInput?.addEventListener('input', renderSearch);
  searchDialog?.addEventListener('click', (event) => {
    if (event.target !== searchDialog) return;
    const bounds = searchDialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
      searchDialog.close();
    }
  });
  searchDialog?.addEventListener('close', () => {
    if (searchSelectedEntry) {
      revealMessage(searchSelectedEntry);
      searchSelectedEntry = null;
    } else if (mobile.matches && sidebar?.contains(searchReturnFocus)) sidebarToggle?.focus();
    else searchReturnFocus?.focus();
  });

  async function authRequest(options = {}) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch('auth.php', {
        ...options, credentials: 'same-origin',
        headers: { Accept: 'application/json', ...options.headers }, signal: controller.signal
      });
      const data = await response.json();
      if (!data || typeof data !== 'object') throw new Error('Invalid response.');
      if (typeof data.csrfToken === 'string' && csrf) csrf.content = data.csrfToken;
      return { response, data };
    } finally {
      window.clearTimeout(timeout);
    }
  }

  function endSession() { window.location.replace('./'); }
  function postLogout() {
    return authRequest({
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content },
      body: JSON.stringify({ action: 'logout' })
    });
  }

  logout?.addEventListener('click', async () => {
    if (logout.disabled || !logoutStatus || !csrf) return;
    logout.disabled = true;
    logout.setAttribute('aria-busy', 'true');
    const label = logout.querySelector('span');
    if (label) label.textContent = 'Logging out…';
    logoutStatus.hidden = true;
    logoutStatus.textContent = '';
    try {
      let result = await postLogout();
      if (result.response.status === 401 || result.response.status === 403) {
        const refreshed = await authRequest();
        if (!refreshed.response.ok) throw new Error('Session refresh failed.');
        if (!refreshed.data.user) { endSession(); return; }
        result = await postLogout();
      }
      if (!result.response.ok) throw new Error('Logout failed.');
      endSession();
    } catch {
      logoutStatus.textContent = 'We could not log you out. Check your connection and try again.';
      logoutStatus.hidden = false;
      logout.disabled = false;
      logout.removeAttribute('aria-busy');
      if (label) label.textContent = 'Log out';
    }
  });
  showWorkspace(window.location.hash.slice(1));
  updateChecklist();
  updateComposer();
})();
