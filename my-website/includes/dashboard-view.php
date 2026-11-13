<?php
declare(strict_types=1);
$escapeDashboard = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$dashboardInitial = function_exists('mb_substr') ? mb_substr($dashboardUser['username'], 0, 1, 'UTF-8') : substr($dashboardUser['username'], 0, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#faf9f6">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= $escapeDashboard($dashboardCsrf) ?>">
  <title>Kaya AI · Dashboard | Kayasure</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Crect width='48' height='48' rx='12' fill='%23152c35'/%3E%3Cpath d='M24 8 38 14v10c0 9-14 16-14 16S10 33 10 24V14Z' fill='%23ff905b'/%3E%3Cpath d='m17 24 5 5 9-11' fill='none' stroke='%23152c35' stroke-width='3'/%3E%3C/svg%3E">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="dashboard.css">
  <script src="dashboard.js" defer></script>
</head>
<body class="dashboard-page">
  <a class="skip-link" href="#dashboardMain">Skip to workspace</a>
  <svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="d-panel" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16"/></symbol>
    <symbol id="d-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="d-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></symbol>
    <symbol id="d-spark" viewBox="0 0 24 24"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5ZM20 2v4m-2-2h4"/></symbol>
    <symbol id="d-shield" viewBox="0 0 24 24"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z"/><path d="m8 12 3 3 5-6"/></symbol>
    <symbol id="d-list" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 9h6M9 13h6M9 17h4"/></symbol>
    <symbol id="d-bike" viewBox="0 0 24 24"><circle cx="5" cy="17" r="3.5"/><circle cx="19" cy="17" r="3.5"/><path d="m5 17 4-7h5l-3 7H5m6 0 5-10h-3m3 0h2l2 4M8 10H5"/></symbol>
    <symbol id="d-car" viewBox="0 0 24 24"><path d="m4 10 2-6h12l2 6M3 17v3m18-3v3M3 10h18v7H3ZM6 13h2m8 0h2"/></symbol>
    <symbol id="d-arrow-up" viewBox="0 0 24 24"><path d="M12 19V5m-6 6 6-6 6 6"/></symbol>
    <symbol id="d-diagonal" viewBox="0 0 24 24"><path d="M6 18 18 6M6 6h12v12"/></symbol>
    <symbol id="d-chevron" viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"/></symbol>
    <symbol id="d-chat" viewBox="0 0 24 24"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-2 2v-9.5A8.5 8.5 0 0 1 10.5 4h2A8.5 8.5 0 0 1 21 11.5Z"/></symbol>
    <symbol id="d-home" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v11h-7v-7h-4v7H3Z"/></symbol>
    <symbol id="d-logout" viewBox="0 0 24 24"><path d="M9 4H4v16h5m5-12 4 4-4 4m-6-4h12"/></symbol>
    <symbol id="d-close" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
    <symbol id="d-copy" viewBox="0 0 24 24"><rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V3H3v13h5"/></symbol>
  </svg>
  <div id="dashboardShell" class="dashboard-shell">
    <button id="sidebarBackdrop" class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
    <aside id="dashboardSidebar" class="dashboard-sidebar" aria-label="Workspace navigation">
      <div class="sidebar-brand-row">
        <a class="brand" href="./" aria-label="Kayasure Insurance home">
          <svg class="brand-mark" viewBox="0 0 42 48" aria-hidden="true"><path d="M21 3 38 10v13c0 11-17 22-17 22S4 34 4 23V10Z" fill="currentColor"/><path d="M14 14v17m0-7 12-10M14 24l13 8" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="brand-word">kayasure<span class="brand-sub">INSURANCE</span></span>
        </a>
        <button id="sidebarClose" class="icon-button" type="button" aria-label="Collapse sidebar" title="Collapse sidebar"><svg class="icon" aria-hidden="true"><use href="#d-panel"/></svg></button>
      </div>
      <button id="newQuestion" class="new-question" type="button" title="Ask another question in this conversation"><svg class="icon" aria-hidden="true"><use href="#d-plus"/></svg><span>New question</span><span class="new-question-spark">✦</span></button>
      <button id="searchConversation" class="sidebar-search" type="button"><svg class="icon" aria-hidden="true"><use href="#d-search"/></svg>Search conversation</button>
      <p class="nav-label">WORKSPACE</p>
      <nav class="dashboard-nav" aria-label="Dashboard navigation">
        <a class="is-active" href="#assistant" data-workspace-view="assistant" aria-controls="assistantPanel" aria-current="page"><svg class="icon" aria-hidden="true"><use href="#d-spark"/></svg><span>Kaya assistant</span><span class="nav-active-dot"></span></a>
        <a href="#coverage" data-workspace-view="coverage" aria-controls="coveragePanel"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg><span>Coverage guide</span><span class="nav-active-dot"></span></a>
        <a href="#checklist" data-workspace-view="checklist" aria-controls="checklistPanel"><svg class="icon" aria-hidden="true"><use href="#d-list"/></svg><span>Registration checklist</span><span class="nav-active-dot"></span></a>
      </nav>
      <div class="sidebar-history">
        <p class="nav-label">IN THIS CONVERSATION</p>
        <ul id="recentQuestions" class="recent-questions"><li id="recentEmpty" class="recent-empty">Your questions will appear here.</li></ul>
      </div>
      <div class="sidebar-bottom">
        <div class="sidebar-guide"><span class="guide-symbol"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg></span><div><strong>A little clarity. A lot of confidence.</strong><p>Your guide to the road ahead.</p></div></div>
        <details class="account-menu">
          <summary><span class="account-avatar"><?= $escapeDashboard(strtoupper($dashboardInitial)) ?></span><span class="account-name"><strong><?= $escapeDashboard($dashboardUser['username']) ?></strong><span>Personal workspace</span></span><svg class="icon" aria-hidden="true"><use href="#d-chevron"/></svg></summary>
          <div class="account-popover"><p>YOUR KAYASURE ACCOUNT</p><strong><?= $escapeDashboard($dashboardUser['username']) ?></strong><span>Member since <?= $escapeDashboard($dashboardCreatedAt) ?></span><a href="./"><svg class="icon" aria-hidden="true"><use href="#d-home"/></svg>Visit Kayasure</a><button id="dashboardLogout" type="button"><svg class="icon" aria-hidden="true"><use href="#d-logout"/></svg><span>Log out</span></button><p id="dashboardLogoutStatus" class="logout-status" role="alert" hidden></p></div>
        </details>
      </div>
    </aside>
    <div class="dashboard-content">
      <header class="dashboard-topbar">
        <div class="topbar-title"><button id="sidebarToggle" class="icon-button" type="button" aria-label="Open sidebar" aria-controls="dashboardSidebar" aria-expanded="true" title="Toggle sidebar"><svg class="icon" aria-hidden="true"><use href="#d-panel"/></svg></button><span class="assistant-title">Kaya <span>AI</span></span><span class="topbar-divider"></span><span id="workspaceSubtitle" class="topbar-subtitle">Your insurance assistant</span></div>
        <a class="workspace-badge" href="#coverage" data-workspace-view="coverage" aria-controls="coveragePanel"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg><span>CTPL workspace</span></a>
      </header>
      <main id="dashboardMain" class="dashboard-main" aria-label="Insurance assistant dashboard">
        <div id="assistantPanel" class="assistant-panel" aria-label="Kaya assistant">
        <div id="conversationScroll" class="conversation-scroll">
          <section id="chatWelcome" class="chat-welcome" aria-labelledby="welcomeTitle">
            <div class="welcome-emblem"><svg viewBox="0 0 42 48" aria-hidden="true"><path d="M21 3 38 10v13c0 11-17 22-17 22S4 34 4 23V10Z" fill="currentColor"/><path d="M14 14v17m0-7 12-10M14 24l13 8" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg><span>✦</span></div>
            <p class="welcome-eyebrow">A CLEARER ROAD STARTS WITH A CONVERSATION</p>
            <h1 id="welcomeTitle">What can I help you with?</h1>
            <p class="welcome-description">Hi, <strong><?= $escapeDashboard($dashboardUser['username']) ?></strong>. I'm Kaya, your AI insurance guide.<br>Let’s make your next step a little simpler.</p>
            <div class="welcome-tags"><span><span class="tag-dot"></span>Motorcycles</span><span>Cars</span><span>Vans &amp; other vehicles</span></div>
          </section>
          <div id="conversationMessages" class="conversation-messages" role="log" aria-label="Conversation with Kaya" aria-live="polite" aria-relevant="additions text"></div>
          <div class="conversation-status-wrap"><p id="conversationStatus" class="conversation-status" role="status" hidden></p><button id="historyRetry" class="history-retry" type="button" hidden>Try again</button></div>
        </div>
        <div class="composer-dock">
          <div class="composer-inner">
            <div id="promptSuggestions" class="prompt-suggestions" aria-label="Suggested questions">
              <button type="button" data-prompt="Explain what CTPL insurance covers and what it does not cover."><span class="prompt-icon prompt-peach"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg></span><strong>Understand CTPL</strong><span>Know what’s covered</span><svg class="icon prompt-arrow" aria-hidden="true"><use href="#d-diagonal"/></svg></button>
              <button type="button" data-prompt="Help me prepare a general checklist for my vehicle registration and CTPL enquiry."><span class="prompt-icon prompt-sage"><svg class="icon" aria-hidden="true"><use href="#d-list"/></svg></span><strong>Get registration ready</strong><span>Make a simple checklist</span><svg class="icon prompt-arrow" aria-hidden="true"><use href="#d-diagonal"/></svg></button>
              <button type="button" data-prompt="What should I know about CTPL insurance for my motorcycle?"><span class="prompt-icon prompt-lilac"><svg class="icon" aria-hidden="true"><use href="#d-bike"/></svg></span><strong>Cover my motorcycle</strong><span>Start with the essentials</span><svg class="icon prompt-arrow" aria-hidden="true"><use href="#d-diagonal"/></svg></button>
              <button type="button" data-prompt="What CTPL questions should I ask my insurer about a car, van, or another type of vehicle?"><span class="prompt-icon prompt-blue"><svg class="icon" aria-hidden="true"><use href="#d-car"/></svg></span><strong>Explore other vehicles</strong><span>Cars, vans &amp; more</span><svg class="icon prompt-arrow" aria-hidden="true"><use href="#d-diagonal"/></svg></button>
            </div>
            <form id="dashboardChatForm" class="dashboard-chat-form">
              <label class="sr-only" for="dashboardMessage">Message Kaya about CTPL or vehicle registration</label>
              <textarea id="dashboardMessage" name="message" rows="1" maxlength="4000" placeholder="Ask anything about your CTPL insurance…" aria-describedby="composerHint" required></textarea>
              <div class="composer-toolbar"><span class="composer-mode"><svg class="icon" aria-hidden="true"><use href="#d-spark"/></svg>Kaya AI<span class="mode-divider">·</span><span class="mode-description">Here to help</span></span><span id="messageCounter" class="message-counter" hidden></span><button id="dashboardSend" class="dashboard-send" type="submit" aria-label="Send message" title="Send message" disabled><svg class="icon" aria-hidden="true"><use href="#d-arrow-up"/></svg></button></div>
            </form>
            <p id="composerStatus" class="composer-status" role="status"></p>
            <p id="composerHint" class="composer-hint"><span>Kaya can make mistakes. Confirm policy details with your insurer.</span><span class="keyboard-hint">Enter to send <span>↵</span></span></p>
          </div>
        </div>
        </div>
        <section id="coveragePanel" class="workspace-panel" aria-labelledby="coveragePanelTitle" hidden>
          <div class="workspace-panel-inner">
            <p class="workspace-eyebrow"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg>YOUR COVERAGE GUIDE</p>
            <h1 id="coveragePanelTitle" tabindex="-1">A clearer look at CTPL.</h1>
            <p class="workspace-intro">Understand the essentials and prepare the right questions for your insurer, all from your workspace.</p>
            <div class="workspace-guide-grid">
              <article class="workspace-guide-card"><span class="prompt-icon prompt-sage"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg></span><h2>Start with the basics</h2><p>Compulsory Third Party Liability (CTPL) relates to eligible third-party death and bodily injury claims arising from a motor vehicle accident, subject to policy terms.</p></article>
              <article class="workspace-guide-card"><span class="prompt-icon prompt-peach"><svg class="icon" aria-hidden="true"><use href="#d-car"/></svg></span><h2>Know the boundaries</h2><p>Do not assume CTPL pays for repairs to your own vehicle or third-party property damage. Ask your insurer to explain the policy's limits and exclusions.</p></article>
              <article class="workspace-guide-card"><span class="prompt-icon prompt-lilac"><svg class="icon" aria-hidden="true"><use href="#d-bike"/></svg></span><h2>Ask about your vehicle</h2><p>Tell your insurer whether you ride a motorcycle or drive a car, SUV, van, or another vehicle. Confirm which policy and registration details apply to you.</p></article>
              <article class="workspace-guide-card"><span class="prompt-icon prompt-blue"><svg class="icon" aria-hidden="true"><use href="#d-list"/></svg></span><h2>Check before you proceed</h2><p>Ask who qualifies as a third party, which benefits and limits apply, and how to check your Certificate of Cover. Confirm policy details directly with your insurer.</p></article>
            </div>
            <div class="workspace-callout"><svg class="icon" aria-hidden="true"><use href="#d-spark"/></svg><div><h2>Have a question about your cover?</h2><p>Bring it to Kaya and prepare your next enquiry.</p></div><button class="workspace-action" type="button" data-prompt="Explain what CTPL insurance covers and what it does not cover.">Ask Kaya<svg class="icon" aria-hidden="true"><use href="#d-chat"/></svg></button></div>
            <p class="workspace-fineprint">General information only. Coverage, eligibility, and exclusions depend on the policy. Kayasure helps prepare enquiries; it does not issue quotes, policies, or certificates.</p>
            <a class="workspace-text-link" href="#checklist" data-workspace-view="checklist">Prepare your registration checklist<svg class="icon" aria-hidden="true"><use href="#d-diagonal"/></svg></a>
          </div>
        </section>
        <section id="checklistPanel" class="workspace-panel" aria-labelledby="checklistPanelTitle" hidden>
          <div class="workspace-panel-inner">
            <p class="workspace-eyebrow"><svg class="icon" aria-hidden="true"><use href="#d-list"/></svg>YOUR REGISTRATION CHECKLIST</p>
            <h1 id="checklistPanelTitle" tabindex="-1">Get your next step together.</h1>
            <p class="workspace-intro">Use this enquiry checklist to organise your questions. Confirm the actual documents and registration requirements with your insurer and the relevant office.</p>
            <div class="checklist-progress"><span id="checklistProgress" role="status" aria-live="polite">0 of 5 prepared</span><span>Your preparation checklist</span></div>
            <div class="workspace-checklist" aria-label="CTPL enquiry preparation">
              <label class="workspace-checklist-item"><input type="checkbox" data-checklist-item><span><strong>Identify your vehicle type</strong><span>Motorcycle, private car, SUV, van, or another vehicle.</span></span></label>
              <label class="workspace-checklist-item"><input type="checkbox" data-checklist-item><span><strong>Know your registration plans</strong><span>Prepare to explain whether this is a new registration or a renewal.</span></span></label>
              <label class="workspace-checklist-item"><input type="checkbox" data-checklist-item><span><strong>Ask which details and documents to prepare</strong><span>Confirm what is needed through your insurer's official channels. Keep personal identifiers and documents out of chat.</span></span></label>
              <label class="workspace-checklist-item"><input type="checkbox" data-checklist-item><span><strong>Prepare your policy questions</strong><span>Ask about the policy period, eligible third parties, limits, and exclusions.</span></span></label>
              <label class="workspace-checklist-item"><input type="checkbox" data-checklist-item><span><strong>Ask how to check your Certificate of Cover</strong><span>Confirm the verification and issuance process directly with your insurer.</span></span></label>
            </div>
            <div class="workspace-callout"><svg class="icon" aria-hidden="true"><use href="#d-spark"/></svg><div><h2>Make the checklist your own.</h2><p>Kaya can help you put your questions into words.</p></div><button class="workspace-action" type="button" data-prompt="Help me prepare a general checklist for my vehicle registration and CTPL enquiry.">Ask Kaya<svg class="icon" aria-hidden="true"><use href="#d-chat"/></svg></button></div>
            <p class="workspace-fineprint">This checklist helps you prepare an enquiry. Checking these items does not submit an application, confirm insurance, or complete a registration. Your selections stay while this dashboard is open.</p>
          </div>
        </section>
        <footer class="dashboard-footer"><span>Made for the way you move.</span><span class="footer-spark">✦</span><span>Powered by Kayasure</span></footer>
      </main>
    </div>
  </div>
  <dialog id="conversationSearch" class="conversation-search" aria-labelledby="searchTitle">
    <div class="search-heading"><h2 id="searchTitle">Search this conversation</h2><button id="closeSearch" class="icon-button" type="button" aria-label="Close search"><svg class="icon" aria-hidden="true"><use href="#d-close"/></svg></button></div>
    <label class="sr-only" for="conversationSearchInput">Find a message</label><div class="search-input-wrap"><svg class="icon" aria-hidden="true"><use href="#d-search"/></svg><input id="conversationSearchInput" type="search" placeholder="Search your questions and Kaya’s replies" autocomplete="off"></div>
    <ul id="conversationSearchResults" class="search-results"></ul>
    <p class="search-footnote">Searches the messages loaded in this browser session.</p>
  </dialog>
</body>
</html>
