</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Auto-inject CSRF token into every POST form (set by layout_top.php).
  (function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;
    var token = meta.content;
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
      if (!form.querySelector('input[name="csrf_token"]')) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = token;
        form.appendChild(input);
      }
    });
  })();
</script>
<script>
  // Header actions group: relocate the notification bell + avatar/profile/
  // dark-mode/logout template into the current page's action row (next to
  // "+ New Encoding" on the dashboard, or at the end of the .topbar on
  // every other page), then wire both up.
  (function () {
    var tpl = document.getElementById('account-menu-tpl');
    if (!tpl) return;

    var node = tpl.content.firstElementChild.cloneNode(true);
    // Prefer an explicit action row (dashboard's header actions, or a
    // page's own .topbar__actions button group like on users.php) so the
    // group lands next to those buttons. Falling back straight to
    // .topbar would make it a 3rd flex child there, and with
    // justify-content: space-between that shoves the existing button
    // group away from the edge instead of sitting flush next to it.
    var target = document.querySelector('.dash-header__actions')
      || document.querySelector('.topbar__actions')
      || document.querySelector('.topbar');
    if (target) {
      target.appendChild(node);
    } else {
      // Fallback: shouldn't normally happen, but never lose the menu.
      document.body.appendChild(node);
    }
    tpl.remove();

    var toggleBtn = node.querySelector('.account-menu__toggle');
    var dropdown  = node.querySelector('.account-menu__dropdown');
    var themeBtn  = node.querySelector('#theme-toggle');
    var themeIcon = node.querySelector('#account-menu-theme-icon');

    function syncThemeIcon() {
      if (!themeIcon) return;
      var isDark = document.documentElement.classList.contains('dark');
      themeIcon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }
    syncThemeIcon();

    function closeMenu() {
      dropdown.classList.remove('is-open');
      toggleBtn.setAttribute('aria-expanded', 'false');
    }
    function openMenu() {
      dropdown.classList.add('is-open');
      toggleBtn.setAttribute('aria-expanded', 'true');
    }

    toggleBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (dropdown.classList.contains('is-open')) { closeMenu(); } else { openMenu(); }
    });
    document.addEventListener('click', function (e) {
      if (!node.contains(e.target)) closeMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMenu();
    });

    // Dark mode switch — same .dark class as before, now scoped to this
    // account's own localStorage key so it never leaks into another
    // user's session on the same browser (see layout_top.php).
    if (themeBtn) {
      var themeKey = 'lrdms-theme-v2:' + <?= json_encode($__user['id'] ?? 'guest') ?>;
      themeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var root = document.documentElement;
        var next = root.classList.contains('dark') ? 'light' : 'dark';
        root.classList.toggle('dark', next === 'dark');
        try { localStorage.setItem(themeKey, next); } catch (err) {}
        syncThemeIcon();
      });
    }

    // ── Notification bell ──────────────────────────────────────────
    var bell         = node.querySelector('#notif-bell');
    if (!bell) return;
    var bellToggle    = bell.querySelector('#notif-bell-toggle');
    var bellDropdown  = bell.querySelector('#notif-bell-dropdown');
    var bellBadge     = bell.querySelector('#notif-bell-badge');
    var bellList      = bell.querySelector('#notif-bell-list');
    var bellMarkAll   = bell.querySelector('#notif-bell-mark-all');
    var apiUrl        = <?= json_encode((isset($__inSubfolder) ? '../' : '') . 'api/notifications.php') ?>;
    var docUrl        = <?= json_encode((isset($__inSubfolder) ? '../' : '') . 'document.php') ?>;
    var reviewUrl     = <?= json_encode((isset($__inSubfolder) ? '../' : '') . 'document_review.php') ?>;
    var csrfMeta      = document.querySelector('meta[name="csrf-token"]');
    var csrfToken     = csrfMeta ? csrfMeta.content : '';
    var loaded        = false;

    var TYPE_ICON = { incoming_document: 'bi-inbox-fill', review_request: 'bi-hourglass-split' };

    function timeAgo(iso) {
      var then = new Date(iso.replace(' ', 'T'));
      var diffSec = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000));
      if (diffSec < 60) return 'just now';
      var m = Math.floor(diffSec / 60); if (m < 60) return m + 'm ago';
      var h = Math.floor(m / 60); if (h < 24) return h + 'h ago';
      var d = Math.floor(h / 24); if (d < 7) return d + 'd ago';
      return then.toLocaleDateString();
    }

    function setBadge(count) {
      if (count > 0) {
        bellBadge.textContent = count > 99 ? '99+' : String(count);
        bellBadge.hidden = false;
      } else {
        bellBadge.hidden = true;
      }
    }

    function refreshCount() {
      fetch(apiUrl + '?action=count', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { setBadge(data.unread || 0); })
        .catch(function () {});
    }

    function renderList(items) {
      if (!items.length) {
        bellList.innerHTML = '<div class="notif-bell__empty">You\'re all caught up.</div>';
        return;
      }
      bellList.innerHTML = items.map(function (n) {
        var icon = TYPE_ICON[n.type] || 'bi-bell';
        var unreadCls = n.is_read ? '' : ' notif-bell__item--unread';
        var href = n.document_id ? ((n.type === 'incoming_document' ? reviewUrl : docUrl) + '?id=' + n.document_id) : '#';
        return (
          '<a href="' + href + '" class="notif-bell__item' + unreadCls + '" data-id="' + n.id + '">' +
            '<i class="bi ' + icon + '"></i>' +
            '<span class="notif-bell__item-body">' +
              '<span class="notif-bell__item-msg"></span>' +
              '<span class="notif-bell__item-time">' + timeAgo(n.created_at) + '</span>' +
            '</span>' +
          '</a>'
        );
      }).join('');
      // Set message text via textContent (not the template string above) to
      // avoid ever interpreting a document title/message as HTML.
      var msgEls = bellList.querySelectorAll('.notif-bell__item-msg');
      items.forEach(function (n, i) { msgEls[i].textContent = n.message; });
    }

    function loadList() {
      bellList.innerHTML = '<div class="notif-bell__empty">Loading…</div>';
      fetch(apiUrl + '?action=list&limit=10', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          setBadge(data.unread || 0);
          renderList(data.notifications || []);
          loaded = true;
        })
        .catch(function () {
          bellList.innerHTML = '<div class="notif-bell__empty">Couldn\'t load notifications.</div>';
        });
    }

    function closeBell() {
      bellDropdown.classList.remove('is-open');
      bellToggle.setAttribute('aria-expanded', 'false');
    }
    function openBell() {
      closeMenu(); // don't show both dropdowns at once
      bellDropdown.classList.add('is-open');
      bellToggle.setAttribute('aria-expanded', 'true');
      if (!loaded) loadList();
    }

    bellToggle.addEventListener('click', function (e) {
      e.stopPropagation();
      if (bellDropdown.classList.contains('is-open')) { closeBell(); } else { openBell(); }
    });
    document.addEventListener('click', function (e) {
      if (!bell.contains(e.target)) closeBell();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeBell();
    });
    // Opening the account menu should close the bell, and vice versa.
    toggleBtn.addEventListener('click', function () { closeBell(); });

    bellList.addEventListener('click', function (e) {
      var item = e.target.closest('.notif-bell__item');
      if (!item) return;
      var id = item.getAttribute('data-id');
      fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: 'action=mark_read&id=' + encodeURIComponent(id),
      }).catch(function () {});
      // Let the click continue through to document.php — no preventDefault.
    });

    bellMarkAll.addEventListener('click', function (e) {
      e.stopPropagation();
      fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: 'action=mark_all_read',
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          setBadge(0);
          bell.querySelectorAll('.notif-bell__item--unread').forEach(function (el) {
            el.classList.remove('notif-bell__item--unread');
          });
        })
        .catch(function () {});
    });

    refreshCount();
    setInterval(refreshCount, 60000);
  })();
</script>
<script>
  // Preserve scroll position across in-app page navigations (sidebar
  // submodules, record links, filters) so a click doesn't jump to top.
  // Main content is keyed per URL; the sidebar nav scroll is shared.
  (function () {
    try {
      var ss = window.sessionStorage;
      var pageKey = 'lrdms-scroll:' + location.pathname + location.search;
      var NAV_KEY = 'lrdms-nav-scroll';
      var nav = document.querySelector('.nav-list');

      var savedY = parseInt(ss.getItem(pageKey) || '0', 10);
      if (savedY > 0) window.scrollTo(0, savedY);

      var savedNav = parseInt(ss.getItem(NAV_KEY) || '0', 10);
      if (nav && savedNav > 0) nav.scrollTop = savedNav;

      var ticking = false;
      function save() {
        try {
          ss.setItem(pageKey, String(window.scrollY || 0));
          if (nav) ss.setItem(NAV_KEY, String(nav.scrollTop || 0));
        } catch (e) {}
      }
      window.addEventListener('scroll', function () {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () { save(); ticking = false; });
      }, { passive: true });

      // Flush the latest position before unloading.
      window.addEventListener('pagehide', save);
    } catch (e) {}
  })();
</script>
<script>
  // Mobile sidebar toggle
  (function () {
    var toggle = document.getElementById('sidebar-toggle');
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    if (!toggle || !sidebar || !overlay) return;
    function openSidebar() { sidebar.classList.add('is-open'); overlay.classList.add('is-open'); }
    function closeSidebar() { sidebar.classList.remove('is-open'); overlay.classList.remove('is-open'); }
    toggle.addEventListener('click', openSidebar);
    overlay.addEventListener('click', closeSidebar);
  })();
</script>
<script>
  // Submit button loading state — show spinner + "Saving…" on all POST forms.
  (function () {
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
      form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"], button:not([type])');
        if (btn && !btn.disabled) {
          btn.disabled = true;
          btn.dataset.origHtml = btn.innerHTML;
          btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Saving…';
          setTimeout(function () { btn.disabled = false; if (btn.dataset.origHtml) btn.innerHTML = btn.dataset.origHtml; }, 12000);
        }
      });
    });
  })();
</script>
<script>
  // Toast notifications — convert existing .alert-success / .alert-danger
  // banners into floating Bootstrap toasts that auto-dismiss.
  (function () {
    var container = document.getElementById('lrdms-toast-container');
    if (!container) return;
    var alertSuccess = document.querySelector('.alert-success');
    var alertDanger = document.querySelector('.alert-danger');
    var alertEl = alertSuccess || alertDanger;
    if (!alertEl) return;
    var type = alertSuccess ? 'success' : 'danger';
    var icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
    var iconColor = type === 'success' ? 'text-success' : 'text-danger';
    var msg = alertEl.textContent.trim();
    alertEl.classList.add('d-none');
    var toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center border-0';
    toastEl.setAttribute('role', 'alert');
    toastEl.innerHTML =
      '<div class="d-flex">' +
        '<div class="toast-body"><i class="bi ' + icon + ' ' + iconColor + ' me-2"></i>' + msg + '</div>' +
        '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
      '</div>';
    toastEl.classList.add(type === 'success' ? 'bg-success' : 'bg-danger', 'text-white');
    container.appendChild(toastEl);
    var toast = new bootstrap.Toast(toastEl, { delay: 4000, autohide: true });
    toast.show();
  })();
</script>
</body>
</html>