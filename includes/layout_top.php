<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';

function current_page($name) {
    return (basename($_SERVER['PHP_SELF'], '.php') === $name) ? 'is-active' : '';
}

ensure_csrf_token();
$__user = current_user();
$__repoOpen = current_page('repository') === 'is-active' || current_page('integrations') === 'is-active';
$__sys = (int)($_GET['sys'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
<title>LRDMS — Legislative Records &amp; Document Management System</title>
<script>
  // Apply saved theme before paint to avoid a flash of the wrong mode.
  // Default is LIGHT (Blue Sky). v2 key resets the old violet-dark pref.
  // Scoped per logged-in account (below) so one user's Dark Mode choice
  // never carries over to a different account signing in on the same
  // browser — each user id gets its own storage key.
  (function () {
    var uid = <?= json_encode($__user['id'] ?? 'guest') ?>;
    var key = 'lrdms-theme-v2:' + uid;
    var saved = null;
    try { saved = localStorage.getItem(key); } catch (e) {}
    document.documentElement.classList.toggle('dark', saved === 'dark');
  })();
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= isset($__inSubfolder) ? '../' : '' ?>assets/css/style.css">
<link rel="stylesheet" href="<?= isset($__inSubfolder) ? '../' : '' ?>assets/css/orbit.css?v=6">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">
      <img class="brand__seal" src="<?= isset($__inSubfolder) ? '../' : '' ?>Arsha/assets/img/manila logo.png" alt="Manila City Hall">
      <div>
        <div class="brand__name">MANILA CITY HALL</div>
        <div class="brand__sub">Records Registry</div>
      </div>
    </div>

    <?php if ($__user): ?>
    <div class="user-box">
      <div class="user-box__name"><?= htmlspecialchars($__user['full_name']) ?></div>
      <div class="user-box__role"><?= htmlspecialchars($__user['role_name']) ?></div>
    </div>

    <ul class="nav-list">
      <li><a class="nav-item <?= current_page('dashboard') ?>" href="dashboard.php">Overview</a></li>
      <?php if (has_permission('encoding', 'create')): ?>
        <li class="d-flex align-items-center gap-1">
          <a class="nav-item flex-grow-1 <?= current_page('encoding') ?>" href="encoding.php">Encoding &amp; Submission</a>
          <?php if (current_user()): $navPdo = get_db(); $navDrafts = $navPdo->query("SELECT COUNT(*) FROM documents WHERE status = 'Draft' AND owner_id = " . (int)current_user()['id'])->fetchColumn(); if ($navDrafts > 0): ?>
            <a href="repository.php?status=Draft" class="badge text-bg-warning flex-shrink-0" style="font-size:10px; text-decoration:none; padding:3px 7px;"><?= (int)$navDrafts ?> draft<?= $navDrafts > 1 ? 's' : '' ?></a>
          <?php endif; endif; ?>
        </li>
      <?php endif; ?>
      <li class="d-flex align-items-center gap-1">
        <a class="nav-item flex-grow-1 <?= current_page('version') ?>" href="version.php">Version Control</a>
        <?php if (current_user()): $navReview = get_db()->query("SELECT COUNT(*) FROM documents WHERE status = 'Under Review'")->fetchColumn(); if ($navReview > 0): ?>
          <span class="badge text-bg-info flex-shrink-0" style="font-size:10px; padding:3px 7px;"><?= (int)$navReview ?></span>
        <?php endif; endif; ?>
      </li>
      <li>
        <details class="nav-group" <?= $__repoOpen ? 'open' : '' ?>>
          <summary class="nav-item <?= $__repoOpen ? 'is-active' : '' ?>">
            <span>Repository</span>
            <svg class="nav-group__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
          </summary>
          <div class="nav-group__sub">
            <a class="nav-item nav-item--sub <?= !$__sys ? 'is-active' : '' ?>" href="repository.php">All Records<?php if (current_user() && has_permission('repository', 'view_all')): $navTotal = get_db()->query('SELECT COUNT(*) FROM documents')->fetchColumn(); ?> <span class="badge text-bg-primary" style="font-size:10px;"><?= (int)$navTotal ?></span><?php endif; ?></a>
            <a class="nav-item nav-item--sub <?= $__sys === 1 ? 'is-active' : '' ?>" href="integrations.php?sys=1">Ordinance &amp; Resolution Lifecycle</a>
            <a class="nav-item nav-item--sub <?= $__sys === 2 ? 'is-active' : '' ?>" href="integrations.php?sys=2">Session &amp; Legislative Meeting</a>
            <a class="nav-item nav-item--sub <?= $__sys === 3 ? 'is-active' : '' ?>" href="integrations.php?sys=3">Agenda &amp; Calendar</a>
            <a class="nav-item nav-item--sub <?= $__sys === 4 ? 'is-active' : '' ?>" href="integrations.php?sys=4">Committee Management</a>
            <a class="nav-item nav-item--sub <?= $__sys === 5 ? 'is-active' : '' ?>" href="integrations.php?sys=5">Voting &amp; Decision</a>
            <a class="nav-item nav-item--sub <?= $__sys === 7 ? 'is-active' : '' ?>" href="integrations.php?sys=7">Public Hearing</a>
            <a class="nav-item nav-item--sub <?= $__sys === 9 ? 'is-active' : '' ?>" href="integrations.php?sys=9">Research</a>
            <a class="nav-item nav-item--sub <?= $__sys === 10 ? 'is-active' : '' ?>" href="integrations.php?sys=10">Citizen Engagement</a>
            <a class="nav-item nav-item--sub <?= $__sys === 8 ? 'is-active' : '' ?>" href="integrations.php?sys=8">Archives</a>
          </div>
        </details>
      </li>
      <li><a class="nav-item <?= current_page('search') ?>" href="search.php">Search</a></li>
      <?php if (has_permission('access', 'manage_users')): ?>
        <li><a class="nav-item <?= current_page('users') ?>" href="users.php">Users</a></li>
      <?php endif; ?>
      <?php if (has_permission('access', 'manage_roles')): ?>
        <li><a class="nav-item <?= current_page('roles') ?>" href="roles.php">Roles &amp; Permissions</a></li>
      <?php endif; ?>
      <?php if (has_permission('audit', 'view')): ?>
        <li><a class="nav-item <?= current_page('audit_trail') ?>" href="audit_trail.php">Audit Trail</a></li>
      <?php endif; ?>
    </ul>

    <?php endif; ?>
  </aside>

  <?php if ($__user): ?>
  <!--
    Account menu (avatar → My Profile / Dark Mode / Log out).
    Kept as an inert <template> here so it lives inside the shared layout,
    but is relocated by JS (see layout_bottom.php) into whichever page's
    topbar / dash-header actions row is on screen, next to buttons like
    "+ New Encoding". The <template> content is never rendered in place.
  -->
  <template id="account-menu-tpl">
    <div class="account-menu">
      <button type="button" class="account-menu__toggle" id="account-menu-toggle" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
        <i class="bi bi-person-circle"></i>
      </button>
      <div class="account-menu__dropdown" id="account-menu-dropdown" role="menu">
        <div class="account-menu__header">
          <div class="account-menu__name"><?= htmlspecialchars($__user['full_name']) ?></div>
          <div class="account-menu__role"><?= htmlspecialchars($__user['role_name']) ?></div>
        </div>
        <a href="<?= isset($__inSubfolder) ? '../' : '' ?>profile.php" class="account-menu__item" role="menuitem">
          <i class="bi bi-person"></i> My Profile
        </a>
        <button type="button" class="account-menu__item account-menu__item--button" id="theme-toggle" role="menuitem" aria-label="Toggle dark / light theme">
          <i class="bi bi-moon-stars-fill" id="account-menu-theme-icon"></i>
          <span>Dark Mode</span>
          <span class="account-menu__switch" id="account-menu-switch" aria-hidden="true"><span class="account-menu__switch-knob"></span></span>
        </button>
        <a href="<?= isset($__inSubfolder) ? '../' : '' ?>logout.php" class="account-menu__item account-menu__item--danger" role="menuitem">
          <i class="bi bi-box-arrow-right"></i> Log out
        </a>
      </div>
    </div>
  </template>
  <?php endif; ?>

  <div class="sidebar-overlay" id="sidebar-overlay"></div>

  <!-- Toast notification container (floating, auto-dismiss) -->
  <div id="lrdms-toast-container" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1100;"></div>

  <main class="main">