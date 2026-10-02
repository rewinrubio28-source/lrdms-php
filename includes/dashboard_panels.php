<?php if ($canEncode): ?>
<nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Dashboard shortcuts">
  <a class="btn btn-primary btn-sm" href="encoding.php">Incoming Records <span class="badge text-bg-light ms-1"><?= $awaitingVerificationCount ?></span></a>
  <a class="btn btn-outline-primary btn-sm" href="encoding.php?tab=followups">Pending &amp; Follow-up</a>
</nav>
<?php endif; ?>

<div class="kpi-row">
  <div class="stat-tile kpi-primary">
    <div class="stat-tile__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    </div>
    <div class="stat-tile__label">Total Records</div>
    <div class="stat-tile__value"><?= number_format($totalDocs) ?></div>
    <div class="stat-tile__sub">Visible to your role</div>
  </div>

  <div class="stat-tile kpi-success">
    <div class="stat-tile__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <div class="stat-tile__label">Enacted Records</div>
    <div class="stat-tile__value"><?= number_format($enactedCount) ?></div>
    <div class="stat-tile__sub">Finalized legislation on file</div>
  </div>

  <div class="stat-tile kpi-accent">
    <div class="stat-tile__icon">
      <?php if ($canEncode): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
      <?php endif; ?>
    </div>
    <?php if ($canEncode): ?>
      <div class="stat-tile__label">Awaiting Verification</div>
      <div class="stat-tile__value"><?= number_format($awaitingVerificationCount) ?></div>
      <div class="stat-tile__sub">Incoming, not yet reviewed</div>
    <?php else: ?>
      <div class="stat-tile__label">Rejected Records</div>
      <div class="stat-tile__value"><?= number_format($rejectedCount) ?></div>
      <div class="stat-tile__sub">Records rejected by the source</div>
    <?php endif; ?>
  </div>

  <?php if ($canAccess): ?>
    <div class="stat-tile kpi-warning">
      <div class="stat-tile__icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div class="stat-tile__label">Active Users</div>
      <div class="stat-tile__value"><?= number_format($activeUsers) ?></div>
      <div class="stat-tile__sub">Enabled accounts</div>
    </div>
  <?php else: ?>
    <div class="stat-tile kpi-warning">
      <div class="stat-tile__icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      </div>
      <div class="stat-tile__label">Public Records</div>
      <div class="stat-tile__value"><?= number_format($publicCount) ?></div>
      <div class="stat-tile__sub">Enacted &amp; publicly visible</div>
    </div>
  <?php endif; ?>
</div>

<!-- ── Row 1: Role-aware intake and repository overview ─────────── -->
<div class="dash-grid">
  <?php if ($canEncode): ?>
  <section class="module-card span-2">
    <header class="module-card__header">
      <div>
        <h3>Encoding &amp; Submission</h3>
        <p class="module-card__subtitle">Document intake &amp; status pipeline</p>
      </div>
      <a class="module-card__link action-link" href="encoding.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <div class="status-chips">
        <?php foreach (['Enacted', 'Amended', 'Rejected'] as $i => $s): ?>
          <div class="status-chip <?= $s === 'Enacted' ? 'is-emphasis' : '' ?>">
            <span class="status-chip__num"><?= $statusCounts[$s] ?? 0 ?></span>
            <span class="status-chip__label"><?= htmlspecialchars($s) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($recentDocs): ?>
        <ul class="mini-list">
          <?php foreach ($recentDocs as $d): ?>
            <li>
              <div class="mini-list__main">
                <a class="mini-list__title" href="document.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a>
                <span class="mini-list__meta"><?= htmlspecialchars($d['doc_number']) ?> · <?= htmlspecialchars($d['doc_type']) ?></span>
              </div>
              <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No recent records available.</p>
      <?php endif; ?>

      <div class="module-note <?= $pendingDigit > 0 ? 'is-warning' : '' ?>">
        <span>
          <?= $pendingDigit > 0
              ? '<strong>' . number_format($pendingDigit) . '</strong> record(s) still need digitization (no source file or OCR text).'
              : 'All visible records have source files and OCR text on file.' ?>
        </span>
      </div>
    </div>
  </section>

  <!-- ── My Recent Encodings ─────────── -->
  <section class="module-card">
    <header class="module-card__header">
      <div>
        <h3>My Recent Records</h3>
        <p class="module-card__subtitle">Recently filed records assigned to you</p>
      </div>
    </header>
    <div class="module-card__body">
      <?php if ($myRecentDocs): ?>
        <ul class="mini-list">
          <?php foreach ($myRecentDocs as $d): ?>
            <li>
              <div class="mini-list__main">
                <a class="mini-list__title" href="document.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a>
                <span class="mini-list__meta"><?= htmlspecialchars($d['doc_number']) ?> · <?= htmlspecialchars($d['doc_type']) ?></span>
              </div>
              <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No records are assigned to you yet.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── Encoding Activity Trend ─────────── -->
  <?php endif; ?>
  <section class="module-card span-2">
    <header class="module-card__header">
      <div>
        <h3>Record History</h3>
        <p class="module-card__subtitle">Visible registered records by creation month; select a bar to view records</p>
      </div>
    </header>
    <div class="module-card__body">
      <?php if ($monthData): ?>
        <?php
          $maxMonth = max(array_column($monthData, 'n'));
          $chartH = 120;
        ?>
        <div style="display:flex;align-items:flex-end;gap:6px;min-height:190px;padding:8px 0;overflow-x:auto;">
          <?php foreach ($monthData as $m): ?>
            <?php $h = $maxMonth > 0 ? round((int)$m['n'] / $maxMonth * $chartH) : 0; ?>
            <?php $monthFrom=max($filters['from'],$m['month'].'-01'); $monthTo=min($filters['to'],date('Y-m-t',strtotime($m['month'].'-01'))); ?>
            <a href="<?= htmlspecialchars(dashboard_drill_url($filters,['from'=>$monthFrom,'to'=>$monthTo])) ?>" aria-label="<?= htmlspecialchars($m['month'].' - '.$m['n'].' records') ?>" style="flex:1;min-width:52px;display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;">
              <span style="font-size:11px;font-weight:600;color:var(--text-primary,#172b4d);"><?= (int)$m['n'] ?></span>
              <div style="width:100%;max-width:48px;height:<?= $h ?>px;background:linear-gradient(180deg,#4c7db2,#24466c);border-radius:4px 4px 0 0;transition:height .3s;"></div>
              <span style="font-size:10px;color:#6b7690;white-space:nowrap;"><?= date('M Y', strtotime($m['month'] . '-01')) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="module-empty">No encoding activity yet.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="module-card">
    <header class="module-card__header">
      <div>
        <h3>Legislative Repository</h3>
        <p class="module-card__subtitle">Collection by document type</p>
      </div>
      <a class="module-card__link action-link" href="repository.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <?php foreach ($typeCounts as $t): ?>
        <div class="bar-row">
          <a class="bar-label" href="<?= htmlspecialchars(dashboard_drill_url($filters,['type'=>$t['doc_type']])) ?>"><?= htmlspecialchars($t['doc_type']) ?></a>
          <div class="bar-track"><div class="bar-fill bar-fill--primary" style="width: <?= _dash_pct((int)$t['n'], $totalDocs) ?>%"></div></div>
          <span class="bar-value"><?= (int)$t['n'] ?></span>
        </div>
      <?php endforeach; ?>

      <div class="split-stats">
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($pubCount) ?></div>
          <div class="split-stat__label">Public</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($restrictedCount) ?></div>
          <div class="split-stat__label">Not public</div>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ── Row 2: Version Control + Retrieval & Search ───────── -->
<div class="dash-grid">
  <section class="module-card <?= $canSearch ? 'span-2' : 'span-3' ?>">
    <header class="module-card__header">
      <div>
        <h3>Version Control</h3>
        <p class="module-card__subtitle">Revision chains &amp; amendment history</p>
      </div>
      <a class="module-card__link action-link" href="version.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <div class="split-stats" style="grid-template-columns: repeat(3, 1fr); margin-top:0; margin-bottom:14px;">
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($versionChains) ?></div>
          <div class="split-stat__label">Current revision heads</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($totalRevisions) ?></div>
          <div class="split-stat__label">Total revisions</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($totalDocs - $totalRevisions) ?></div>
          <div class="split-stat__label">Original records</div>
        </div>
      </div>

      <?php if ($versionedHeads): ?>
        <ul class="mini-list">
          <?php foreach ($versionedHeads as $vh): ?>
            <li>
              <div class="mini-list__main">
                <a class="mini-list__title" href="document.php?id=<?= (int)$vh['id'] ?>"><?= htmlspecialchars($vh['title']) ?></a>
                <span class="mini-list__meta"><?= htmlspecialchars($vh['doc_number']) ?> · <?= (int)$vh['versions'] ?> version(s) in period</span>
              </div>
              <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $vh['status'])) ?>"><?= htmlspecialchars($vh['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No amended / versioned records yet.</p>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($canSearch): ?>
  <section class="module-card">
    <header class="module-card__header">
      <div>
        <h3>Search and Document Retrieval</h3>
        <p class="module-card__subtitle"><?= $canAudit ? 'Search activity for the selected dates' : 'Your search activity for the selected dates' ?></p>
      </div>
      <a class="module-card__link action-link" href="search.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <div class="bar-row">
        <span class="bar-label">Keyword</span>
        <div class="bar-track"><div class="bar-fill bar-fill--accent" style="width: <?= _dash_pct($keywordSearches, $totalSearches) ?>%"></div></div>
        <span class="bar-value"><?= number_format($keywordSearches) ?></span>
      </div>
      <div class="bar-row">
        <span class="bar-label">Semantic</span>
        <div class="bar-track"><div class="bar-fill bar-fill--success" style="width: <?= _dash_pct($semanticSearches, $totalSearches) ?>%"></div></div>
        <span class="bar-value"><?= number_format($semanticSearches) ?></span>
      </div>
      <p class="module-empty" style="margin-top:10px;"><?= number_format($totalSearches) ?> total search<?= $totalSearches === 1 ? '' : 'es' ?> recorded.</p>

      <?php if ($recentSearches): ?>
        <ul class="mini-list" style="margin-top:6px;">
          <?php foreach ($recentSearches as $s): ?>
            <li>
              <div class="mini-list__main">
                <span class="mini-list__title" style="font-weight:400;">"<?= htmlspecialchars($s['query']) ?>"</span>
                <span class="mini-list__meta"><?= htmlspecialchars(ucfirst($s['search_type'])) ?> · <?= (int)$s['results_count'] ?> result(s)</span>
              </div>
              <span class="mini-list__time"><?= htmlspecialchars(date('M j, g:i A', strtotime($s['created_at']))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php if ($canAccess || $canAudit): ?>
<!-- ── Row 3: Access Control & Security + Recent Activity ── -->
<div class="dash-grid">
  <?php if ($canAccess): ?>
  <section class="module-card <?= $canAudit ? 'span-2' : 'span-3' ?>">
    <header class="module-card__header">
      <div>
        <h3>Access Control &amp; Security</h3>
        <p class="module-card__subtitle">Current user totals (date filters apply to records and activity)</p>
      </div>
      <a class="module-card__link action-link" href="users.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <?php foreach ($usersByRole as $ur): ?>
        <div class="bar-row">
          <span class="bar-label"><?= htmlspecialchars($ur['name']) ?></span>
          <div class="bar-track"><div class="bar-fill bar-fill--success" style="width: <?= _dash_pct((int)$ur['n'], $totalUsers) ?>%"></div></div>
          <span class="bar-value"><?= (int)$ur['n'] ?></span>
        </div>
      <?php endforeach; ?>

      <div class="split-stats">
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($totalUsers - $inactiveUsers) ?></div>
          <div class="split-stat__label">Active</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($inactiveUsers) ?></div>
          <div class="split-stat__label">Disabled</div>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($canAudit): ?>
  <section class="module-card <?= $canAccess ? '' : 'span-3' ?>">
    <header class="module-card__header">
      <div>
        <h3>Recent Activity</h3>
        <p class="module-card__subtitle">Latest audit trail events</p>
      </div>
      <a class="module-card__link action-link" href="audit_trail.php">View trail →</a>
    </header>
    <div class="module-card__body">
      <?php if ($recent): ?>
        <ul class="activity-list">
          <?php foreach ($recent as $a): ?>
            <li>
              <span class="activity-dot"></span>
              <span class="activity-body">
                <span class="activity-actor"><?= htmlspecialchars($a['username_snapshot'] ?? 'system') ?></span>
                <?= htmlspecialchars($a['action']) ?><?= $a['detail'] ? ' — ' . htmlspecialchars($a['detail']) : '' ?>
              </span>
              <time><?= htmlspecialchars(date('M j, g:i A', strtotime($a['created_at']))) ?></time>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No activity logged yet.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>

