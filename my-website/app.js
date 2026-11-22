(() => {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const menuToggle = byId('menuToggle');
  const mainNav = byId('mainNav');
  const chatLauncher = byId('chatLauncher');
  const chatPanel = byId('chatPanel');
  const chatForm = byId('chatForm');
  const messageInput = byId('message');
  const sendButton = byId('sendButton');
  const messages = byId('messages');
  const historyStatus = byId('chatHistoryStatus');
  const coverageDialog = byId('coverageDialog');
  const quoteDialog = byId('quoteDialog');
  const quoteForm = byId('quoteForm');
  const quoteResult = byId('quoteResult');
  const quoteCoverage = byId('quoteCoverage');

  let selectedCoverage = 'CTPL basics';
  let quoteDraft = '';
  let sending = false;
  let loadingHistory = false;
  let transcriptHasNewMessages = false;

  function setMenu(open, restoreFocus = false) {
    if (!menuToggle || !mainNav) return;
    menuToggle.setAttribute('aria-expanded', String(open));
    menuToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    mainNav.classList.toggle('is-open', open);
    if (restoreFocus) menuToggle.focus();
  }

  menuToggle?.addEventListener('click', () => {
    setMenu(menuToggle.getAttribute('aria-expanded') !== 'true');
  });

  mainNav?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => setMenu(false));
  });

  function setChat(open, draft) {
    if (!chatPanel || !messageInput) return;
    chatPanel.hidden = !open;
    chatLauncher?.setAttribute('aria-expanded', String(open));
    chatLauncher?.setAttribute('aria-label', open ? 'Close Kayasure assistant' : 'Open Kayasure assistant');
    if (open) {
      setMenu(false);
      if (typeof draft === 'string') messageInput.value = draft;
      messageInput.focus();
    } else {
      chatLauncher?.focus();
    }
  }

  chatLauncher?.addEventListener('click', () => setChat(Boolean(chatPanel?.hidden)));
  byId('closeChat')?.addEventListener('click', () => setChat(false));

  document.querySelectorAll('[data-open-chat]').forEach((button) => {
    button.addEventListener('click', () => setChat(true));
  });

  document.querySelectorAll('[data-chat-prompt]').forEach((button) => {
    button.addEventListener('click', () => setChat(true, button.dataset.chatPrompt));
  });

  const coverageContent = {
    'CTPL basics': {
      description: 'Understand the purpose of compulsory third-party liability cover for your vehicle.',
      details: [
        'CTPL concerns eligible claims involving third-party death or bodily injury.',
        'Ask about eligibility, exclusions, and the terms of the policy.',
        'Check that the vehicle details and cover period are correct.'
      ],
      prompt: 'Help me understand CTPL basics for a vehicle in the Philippines. What third-party death or bodily injury protection does it concern, and which policy terms should I check?'
    },
    'Coverage boundaries': {
      description: 'Be clear about the scope of CTPL before taking your next step.',
      details: [
        'Ask who qualifies as a covered third party under the policy.',
        'Review how eligibility, exclusions, and claims requirements apply.',
        'Understand the distinction between CTPL bodily-injury liability and separate vehicle or property-damage cover.'
      ],
      prompt: 'Help me understand CTPL coverage boundaries, eligibility, and exclusions, including how CTPL differs from vehicle or property-damage insurance.'
    },
    'Registration checklist': {
      description: 'Prepare the questions to ask about your CTPL documents before vehicle registration.',
      details: [
        'Check that the name and vehicle details on your documents are accurate.',
        'Verify the certificate of cover with the issuing insurer before using it for registration.',
        'Confirm current registration requirements with the relevant office.'
      ],
      prompt: 'What should I check on a CTPL certificate of cover before using it for vehicle registration, and which details should I verify with the insurer?'
    }
  };

  function showDialog(dialog) {
    if (!dialog || dialog.open) return;
    setMenu(false);
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function closeDialog(dialog) {
    if (!dialog?.open) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  document.querySelectorAll('[data-explore]').forEach((button) => {
    button.addEventListener('click', () => {
      const topic = button.dataset.explore;
      selectedCoverage = Object.prototype.hasOwnProperty.call(coverageContent, topic) ? topic : 'CTPL basics';
      const content = coverageContent[selectedCoverage];
      const title = byId('coverageTitle');
      const description = byId('coverageDescription');
      const details = byId('coverageDetails');
      if (title) title.textContent = selectedCoverage;
      if (description) description.textContent = content.description;
      if (details) {
        details.replaceChildren(...content.details.map((text) => {
          const item = document.createElement('li');
          item.textContent = text;
          return item;
        }));
      }
      showDialog(coverageDialog);
    });
  });

  byId('closeCoverage')?.addEventListener('click', () => closeDialog(coverageDialog));
  byId('coverageChat')?.addEventListener('click', () => {
    closeDialog(coverageDialog);
    setChat(true, coverageContent[selectedCoverage].prompt);
  });

  document.querySelectorAll('[data-open-quote]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!quoteForm || !quoteResult) return;
      quoteForm.reset();
      quoteForm.querySelectorAll('input, select, textarea').forEach((field) => field.setCustomValidity(''));
      quoteForm.hidden = false;
      quoteResult.hidden = true;
      quoteDraft = '';
      const summary = byId('quoteSummary');
      if (summary) summary.textContent = '';
      const vehicleType = button.dataset.quoteType;
      if (quoteCoverage && [...quoteCoverage.options].some((option) => option.value === vehicleType)) {
        quoteCoverage.value = vehicleType;
      }
      showDialog(quoteDialog);
    });
  });

  byId('closeQuote')?.addEventListener('click', () => closeDialog(quoteDialog));

  quoteForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    const nameField = byId('quoteName');
    if (nameField && !nameField.value.trim()) {
      nameField.setCustomValidity('Please enter your first name.');
    }
    if (!quoteForm.reportValidity()) return;
    const name = nameField?.value.trim() || 'Hello';
    const vehicleType = quoteCoverage?.value || 'Not sure yet';
    const vehicleDescriptions = {
      Motorcycle: 'a motorcycle',
      'Private car': 'a private car',
      'SUV / van': 'an SUV or van',
      'Light commercial': 'a light commercial vehicle'
    };
    const vehicle = Object.prototype.hasOwnProperty.call(vehicleDescriptions, vehicleType)
      ? vehicleDescriptions[vehicleType]
      : '';
    const topic = vehicle ? `CTPL for ${vehicle}` : 'CTPL and your vehicle classification';
    const summary = byId('quoteSummary');
    if (summary) {
      summary.textContent = `${name}, start a conversation about ${topic}. You can review your message in chat before sending it.`;
    }
    quoteDraft = vehicle
      ? `I'd like to ask about CTPL for ${vehicle}. What should I verify about eligibility, policy terms, and the certificate of cover for registration?`
      : "I'd like to ask about CTPL, but I'm not sure how to classify my vehicle. What information should I prepare and verify with the insurer?";
    quoteForm.hidden = true;
    if (quoteResult) quoteResult.hidden = false;
    byId('continueQuote')?.focus();
  });

  byId('continueQuote')?.addEventListener('click', () => {
    closeDialog(quoteDialog);
    setChat(true, quoteDraft || "I'd like help understanding CTPL for my vehicle.");
  });

  [coverageDialog, quoteDialog].forEach((dialog) => {
    dialog?.addEventListener('click', (event) => {
      if (event.target !== dialog) return;
      const bounds = dialog.getBoundingClientRect();
      const outside = event.clientX < bounds.left || event.clientX > bounds.right ||
        event.clientY < bounds.top || event.clientY > bounds.bottom;
      if (outside) closeDialog(dialog);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (document.querySelector('dialog[open]')) return;
    if (chatPanel && !chatPanel.hidden) {
      event.preventDefault();
      setChat(false);
    } else if (menuToggle?.getAttribute('aria-expanded') === 'true') {
      event.preventDefault();
      setMenu(false, true);
    }
  });

  function addMessage(sender, text, pending = false) {
    if (!messages) return null;
    const entry = document.createElement('div');
    entry.className = `chat-message message ${sender}${pending ? ' is-pending' : ''}`;
    const label = document.createElement('span');
    label.className = 'chat-message-label';
    label.textContent = sender === 'user' ? 'You' : 'Kayasure assistant';
    const body = document.createElement('p');
    body.className = 'chat-message-text';
    body.textContent = text;
    entry.append(label, body);
    if (pending) entry.setAttribute('role', 'status');
    messages.append(entry);
    messages.scrollTop = messages.scrollHeight;
    return entry;
  }

  function updateChatBusy() {
    if (!chatForm) return;
    if (loadingHistory || sending) chatForm.setAttribute('aria-busy', 'true');
    else chatForm.removeAttribute('aria-busy');
  }

  function setHistoryStatus(text = '') {
    if (!historyStatus) return;
    historyStatus.textContent = text;
    historyStatus.hidden = !text;
  }

  function validSavedMessage(item) {
    return item !== null && typeof item === 'object' && !Array.isArray(item) &&
      ['user', 'assistant'].includes(item.role) && typeof item.content === 'string' &&
      ['pending', 'completed', 'failed'].includes(item.status) &&
      (item.error_message === null || typeof item.error_message === 'string');
  }

  async function restoreHistory() {
    if (!messages || !chatForm) return;
    loadingHistory = true;
    updateChatBusy();
    messages.setAttribute('aria-busy', 'true');
    setHistoryStatus('Loading saved conversation...');
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeout = controller ? window.setTimeout(() => controller.abort(), 15000) : null;

    try {
      const response = await fetch('chat.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        ...(controller ? { signal: controller.signal } : {})
      });
      if (!response.ok) throw new Error('History unavailable');
      const data = await response.json();
      if (!data || !Array.isArray(data.messages) ||
          !data.messages.every(validSavedMessage) || typeof data.history_limited !== 'boolean') {
        throw new Error('Invalid history');
      }

      // Submitted messages wait for this load. Never replace a newer local transcript or a draft.
      if (data.messages.length && !transcriptHasNewMessages) {
        messages.replaceChildren();
        data.messages.forEach((item) => {
          addMessage(item.role, item.content);
          if (item.role === 'user' && (item.status === 'failed' || item.status === 'pending')) {
            const text = item.status === 'pending'
              ? 'A previous reply was interrupted. You can send your question again.'
              : item.error_message?.trim() || 'That reply could not be completed. You can send your question again.';
            const notice = addMessage('assistant', text);
            notice?.classList.add('is-error');
          }
        });
      }
      setHistoryStatus(data.history_limited ? 'Showing your most recent saved messages. Earlier messages are not shown here.' : '');
    } catch {
      setHistoryStatus('Saved history could not be loaded. Please try again later.');
    } finally {
      if (timeout !== null) window.clearTimeout(timeout);
      loadingHistory = false;
      messages.removeAttribute('aria-busy');
      updateChatBusy();
    }
  }

  if (messages) {
    messages.setAttribute('aria-live', 'polite');
    messages.setAttribute('aria-relevant', 'additions text');
  }

  const historyReady = restoreHistory();

  chatForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (sending || !messageInput || !messages || !sendButton) return;
    const message = messageInput.value.trim();
    if (!message) {
      messageInput.focus();
      return;
    }
    if (Array.from(message).length > 4000) {
      messageInput.setCustomValidity('Please keep your message to 4000 characters or fewer.');
      messageInput.reportValidity();
      return;
    }

    sending = true;
    sendButton.disabled = true;
    updateChatBusy();
    messageInput.value = '';

    // History only reads saved messages; the POST still sends this message alone.
    await historyReady;
    transcriptHasNewMessages = true;
    addMessage('user', message);
    const pending = addMessage('assistant', 'Thinking...', true);
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeout = controller ? window.setTimeout(() => controller.abort(), 70000) : null;

    try {
      const response = await fetch('chat.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ message }),
        ...(controller ? { signal: controller.signal } : {})
      });
      let data;
      try {
        data = await response.json();
      } catch {
        throw new Error('The assistant could not respond. Please try again shortly.');
      }
      if (!response.ok) {
        throw new Error(typeof data?.error === 'string' && data.error.trim()
          ? data.error
          : 'The assistant is unavailable right now. Please try again shortly.');
      }
      if (!data || typeof data.reply !== 'string' || !data.reply.trim()) {
        throw new Error('No reply arrived. Please try again.');
      }
      if (pending) {
        pending.classList.remove('is-pending');
        pending.removeAttribute('role');
        pending.querySelector('.chat-message-text').textContent = data.reply;
      }
    } catch (error) {
      const errorMessage = error.name === 'AbortError'
        ? 'The assistant is taking longer than expected. Please try again.'
        : error instanceof TypeError
          ? 'We could not connect. Please check your connection and try again.'
          : error.message || 'Something went wrong. Please try again.';
      if (pending) {
        pending.classList.remove('is-pending');
        pending.classList.add('is-error');
        pending.setAttribute('role', 'status');
        pending.querySelector('.chat-message-text').textContent = errorMessage;
      }
      if (!messageInput.value) messageInput.value = message;
    } finally {
      if (timeout !== null) window.clearTimeout(timeout);
      sending = false;
      sendButton.disabled = false;
      updateChatBusy();
      messages.scrollTop = messages.scrollHeight;
      if (!chatPanel?.hidden && document.activeElement && chatPanel.contains(document.activeElement)) {
        messageInput.focus();
      }
    }
  });

  messageInput?.addEventListener('input', () => messageInput.setCustomValidity(''));
  byId('quoteName')?.addEventListener('input', (event) => event.target.setCustomValidity(''));
})();
