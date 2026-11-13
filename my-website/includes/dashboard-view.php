<?php
declare(strict_types=1);

$escapeDashboard = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#152c35">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= $escapeDashboard($dashboardCsrf) ?>">
  <title>Dashboard | Kayasure</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Crect width='48' height='48' rx='12' fill='%23152c35'/%3E%3Cpath d='M24 8 38 14v10c0 9-14 16-14 16S10 33 10 24V14Z' fill='%23ff905b'/%3E%3Cpath d='m17 24 5 5 9-11' fill='none' stroke='%23152c35' stroke-width='3'/%3E%3C/svg%3E">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="dashboard.css">
  <script src="dashboard.js" defer></script>
</head>
<body class="dashboard-page">
  <a class="skip-link" href="#dashboard-main">Skip to dashboard</a>
  <svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="d-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
    <symbol id="d-shield" viewBox="0 0 24 24"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z"/><path d="m8 12 3 3 5-6"/></symbol>
    <symbol id="d-list" viewBox="0 0 24 24"><path d="m3 6 1.5 1.5L7 5M10 6h11M3 12l1.5 1.5L7 11m3 1h11M3 18l1.5 1.5L7 17m3 1h11"/></symbol>
    <symbol id="d-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
    <symbol id="d-diagonal" viewBox="0 0 24 24"><path d="M6 18 18 6M6 6h12v12"/></symbol>
    <symbol id="d-route" viewBox="0 0 24 24"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M8 5h7a4 4 0 0 1 0 8H9a3 3 0 0 0 0 6h7"/></symbol>
    <symbol id="d-logout" viewBox="0 0 24 24"><path d="M9 4H4v16h5m5-12 4 4-4 4m-6-4h12"/></symbol>
    <symbol id="d-person" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></symbol>
  </svg>

  <div class="dashboard-shell">
    <aside class="dashboard-sidebar" aria-label="Account navigation">
      <a class="brand dashboard-brand" href="./" aria-label="Kayasure Insurance home">
        <svg class="brand-mark" viewBox="0 0 42 48" aria-hidden="true"><path d="M21 3 38 10v13c0 11-17 22-17 22S4 34 4 23V10Z" fill="currentColor"/><path d="M14 14v17m0-7 12-10M14 24l13 8" fill="none" stroke="#fbf9f4" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="brand-word">kayasure<span class="brand-sub">INSURANCE</span></span>
      </a>
      <p class="dashboard-nav-label">YOUR ACCOUNT</p>
      <nav class="dashboard-nav" aria-label="Dashboard navigation">
        <a href="dashboard.php" aria-current="page"><svg class="icon" aria-hidden="true"><use href="#d-grid"/></svg>Dashboard</a>
        <a href="./#coverage"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg>CTPL basics</a>
        <a href="./#requirements"><svg class="icon" aria-hidden="true"><use href="#d-list"/></svg>Registration checklist</a>
      </nav>
      <div class="dashboard-sidebar-bottom">
        <button id="dashboardLogout" class="dashboard-logout" type="button"><svg class="icon" aria-hidden="true"><use href="#d-logout"/></svg><span>Log out</span></button>
        <p id="dashboardLogoutStatus" class="dashboard-logout-status" role="alert" hidden></p>
      </div>
    </aside>

    <div class="dashboard-content">
      <header class="dashboard-topbar"><span class="dashboard-topbar-label">MY KAYASURE</span><a href="./">Visit Kayasure <svg class="icon" aria-hidden="true"><use href="#d-diagonal"/></svg></a></header>
      <main id="dashboard-main" class="dashboard-main">
        <div class="dashboard-heading">
          <p class="eyebrow">YOUR NEXT DRIVE STARTS HERE</p>
          <h1>Dashboard</h1>
          <p class="dashboard-welcome">Welcome back, <strong><?= $escapeDashboard($dashboardUser['username']) ?></strong>.</p>
        </div>

        <section class="dashboard-feature" aria-labelledby="dashboard-feature-title">
          <div class="dashboard-feature-copy">
            <span class="dashboard-feature-label">A LITTLE CLARITY GOES A LONG WAY.</span>
            <h2 id="dashboard-feature-title">A clearer start to<br>your next registration.</h2>
            <p>Get familiar with CTPL and bring your registration questions together, one step at a time.</p>
            <a class="button button-orange" href="./#coverage">Explore CTPL basics <svg class="icon" aria-hidden="true"><use href="#d-arrow"/></svg></a>
          </div>
          <svg class="dashboard-feature-art" viewBox="0 0 220 240" aria-hidden="true"><circle cx="110" cy="120" r="105"/><path class="dashboard-art-shield" d="m110 38 57 22v57c0 40-57 76-57 76s-57-36-57-76V60Z"/><path class="dashboard-art-check" d="m82 116 19 19 37-43"/><path class="dashboard-art-spark" d="M183 22v22m-11-11h22M32 181v18m-9-9h18"/></svg>
        </section>

        <div class="dashboard-details-grid">
          <section class="dashboard-next" aria-labelledby="dashboard-next-title">
            <div class="dashboard-section-heading"><p class="eyebrow">MAKE YOUR NEXT STEP EASIER</p><h2 id="dashboard-next-title">Start with the essentials.</h2></div>
            <a class="dashboard-resource" href="./#coverage"><span class="dashboard-resource-icon"><svg class="icon" aria-hidden="true"><use href="#d-shield"/></svg></span><span><strong>Understand CTPL</strong><span>A clear place to begin with the basics.</span></span><svg class="icon dashboard-resource-arrow" aria-hidden="true"><use href="#d-diagonal"/></svg></a>
            <a class="dashboard-resource" href="./#requirements"><span class="dashboard-resource-icon"><svg class="icon" aria-hidden="true"><use href="#d-list"/></svg></span><span><strong>Prepare your checklist</strong><span>Bring your vehicle and registration details together.</span></span><svg class="icon dashboard-resource-arrow" aria-hidden="true"><use href="#d-diagonal"/></svg></a>
            <a class="dashboard-resource" href="./#how-it-works"><span class="dashboard-resource-icon"><svg class="icon" aria-hidden="true"><use href="#d-route"/></svg></span><span><strong>See how it works</strong><span>Plan your CTPL enquiry, one step at a time.</span></span><svg class="icon dashboard-resource-arrow" aria-hidden="true"><use href="#d-diagonal"/></svg></a>
          </section>

          <section class="dashboard-account" aria-labelledby="dashboard-account-title">
            <span class="dashboard-account-icon"><svg class="icon" aria-hidden="true"><use href="#d-person"/></svg></span>
            <h2 id="dashboard-account-title">Your account</h2>
            <p>Your Kayasure details, in one place.</p>
            <dl><div><dt>Username</dt><dd><?= $escapeDashboard($dashboardUser['username']) ?></dd></div><div><dt>Account created</dt><dd><?= $escapeDashboard($dashboardCreatedAt) ?></dd></div></dl>
          </section>
        </div>
        <footer class="dashboard-footer"><span>Kayasure Insurance</span><span>Here for your next drive.</span></footer>
      </main>
    </div>
  </div>
</body>
</html>
