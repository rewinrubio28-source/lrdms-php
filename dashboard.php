<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard_data.php';
require_login();
header('Cache-Control: no-store');
$user = current_user();
$pdo = get_db();
try { $filters = dashboard_filters($_GET); }
catch (InvalidArgumentException $e) { http_response_code(422); exit(htmlspecialchars($e->getMessage())); }
$data = dashboard_snapshot($pdo, $user, $filters);
extract($data, EXTR_SKIP);
// ------------------------------------------------------------
// View
// ------------------------------------------------------------
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = trim($user['full_name'] ?: $user['username']);
$firstName = explode(' ', $firstName)[0];
$dateStr = date('l, F j, Y');
$timeStr = date('g:i A');

include __DIR__ . '/includes/layout_top.php';
?>
<div class="dash-header" data-banner-date="">
  <div class="d-flex align-items-start gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="module-banner-eyebrow">DASHBOARD</div>
      <h1 class="dash-header__title"><span id="greeting"><?= htmlspecialchars($greeting) ?></span>, <?= htmlspecialchars($firstName) ?> 👋</h1>
      <p class="dash-header__date"><?= htmlspecialchars($dateStr) ?> · <span id="clock" class="dash-header__clock"><?= htmlspecialchars($timeStr) ?></span></p>
    </div>
  </div>
  <div class="dash-header__actions">
  </div>
</div>

<p id="dashboard-refresh-status" class="visually-hidden" role="status"></p>
<div id="dashboard-live" data-query="<?= htmlspecialchars(http_build_query($filters), ENT_QUOTES, 'UTF-8') ?>"><?php include __DIR__ . '/includes/dashboard_panels.php'; ?></div>
<script src="assets/js/dashboard-live.js?v=2" defer></script>
<!-- Notification Bell Dropdown Panel -->
<div class="notif-dropdown" id="notif-dropdown">
  <div class="notif-dropdown__header">
    <span class="notif-dropdown__title">Notifications</span>
    <button type="button" class="notif-dropdown__mark-all" id="notif-mark-all" aria-label="Mark all as read">
      <i class="bi bi-check2-all"></i> Mark all read
    </button>
  </div>
  <ul class="notif-list" id="notif-list">
    <li class="notif-list__empty" id="notif-empty">
      <i class="bi bi-bell-slash"></i>
      <span>No new notifications</span>
    </li>
  </ul>
  <div class="notif-dropdown__footer" id="notif-footer" style="display:none;">
    <a class="notif-dropdown__view-all action-link" href="audit_trail.php">View all activity →</a>
  </div>
</div>

<script>
  // Live dashboard clock + greeting — ticks every 15s so the greeting
  // and time stay correct even if the page is left open all day.
  (function () {
    var clock = document.getElementById('clock');
    var greeting = document.getElementById('greeting');
    if (!clock && !greeting) return;
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function tick() {
      var now = new Date();
      var h24 = now.getHours();
      var h12 = h24 % 12 || 12;
      var ampm = h24 < 12 ? 'AM' : 'PM';
      if (clock) clock.textContent = h12 + ':' + pad(now.getMinutes()) + ' ' + ampm;
      if (greeting) {
        var g = h24 < 12 ? 'Good morning' : (h24 < 17 ? 'Good afternoon' : 'Good evening');
        if (greeting.textContent !== g) greeting.textContent = g;
      }
    }
    tick();
    setInterval(tick, 15000);
  })();
</script>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
