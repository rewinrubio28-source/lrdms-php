<?php
/**
 * Public document reader — no login required.
 * Only finalized & public documents are shown (is_public = 1 AND
 * status IN Enacted/Amended/Superseded), matching the landing section.
 */
require_once __DIR__ . '/config/database.php';

// Public-facing labels for each stored doc_type (DB values stay unchanged).
$typeDisplayLabels = [
    'Ordinance'        => 'Ordinance',
    'Resolution'       => 'Resolution',
    'Committee Report' => 'Committee Report',
    'Minutes'          => 'Session Minutes',
];

$id = (int)($_GET['id'] ?? 0);
$doc = null;
if ($id > 0) {
    $stmt = get_db()->prepare(
        "SELECT d.* FROM documents d
         WHERE d.id = ? AND d.is_public = 1
           AND d.status IN ('Enacted','Amended','Superseded')"
    );
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
}

if ($doc) {
    $title = $doc['title'];
    $content = !empty($doc['body']) ? $doc['body'] : ($doc['ocr_text'] ?? '');

    // Fetch the next version (for amendment history sidebar)
    $nextVersion = null;
    if (!empty($doc['next_version_id'])) {
        $nxt = get_db()->prepare(
            "SELECT id, doc_number, title, status, enactment_date
             FROM documents WHERE id = ?"
        );
        $nxt->execute([$doc['next_version_id']]);
        $nextVersion = $nxt->fetch();
    }

    // Fetch committee name for document details
    $committeeName = null;
    if (!empty($doc['committee_id'])) {
        $cmt = get_db()->prepare("SELECT name FROM committees WHERE id = ?");
        $cmt->execute([$doc['committee_id']]);
        $committeeName = $cmt->fetchColumn();
    }
} else {
    http_response_code(404);
    $title = 'Document not found';
    $content = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — LRDMS</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root { --lrdms-navy:#37517e; --lrdms-navy-dark:#2a3f63; --lrdms-gold:#c9a227; --lrdms-gold-light:#e0c15a; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:'Inter', 'Segoe UI', system-ui, sans-serif; background:#f4f7fa; color:#3a4356; line-height:1.65; }
  a { color: var(--lrdms-navy); }

  .topbar { background: linear-gradient(135deg, var(--lrdms-navy) 0%, var(--lrdms-navy-dark) 100%); color:#fff; padding:14px 0; }
  .topbar__inner { max-width:860px; margin:0 auto; padding:0 20px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
  .topbar__brand { font-weight:700; letter-spacing:.5px; font-size:15px; display:flex; align-items:center; gap:12px; }
  .topbar__brand small { display:block; font-weight:400; opacity:.85; font-size:12px; }
  .topbar__logo { width:44px; height:44px; object-fit:contain; border-radius:50%; flex-shrink:0; }
  .topbar__back { color:#fff; text-decoration:none; font-size:14px; opacity:.95; font-weight:600; }
  .topbar__back:hover { color: var(--lrdms-gold); }

  .doc-wrap { max-width:860px; margin:0 auto; padding:36px 20px 60px; }
  .doc-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
  .badge-type { font-size:11px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:var(--lrdms-navy); background:#e7ecf4; border:1px solid #d3dcec; padding:4px 10px; border-radius:999px; }
  .badge-status { font-size:11px; font-weight:700; color:#fff; background:var(--lrdms-gold); padding:4px 10px; border-radius:999px; }

  h1 { margin:0 0 6px; font-size:26px; line-height:1.3; color:var(--lrdms-navy); }
  .doc-sub { color:#6b7690; font-size:14px; margin:0 0 24px; }
  .doc-sub strong { color:var(--lrdms-navy); font-weight:600; }

  .doc-body { background:#fff; border:1px solid #e7ecf4; border-radius:14px; padding:28px; }
  .doc-body h3 { color:var(--lrdms-navy); margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.6px; }
  .doc-text { font-size:15px; color:#3a4356; white-space:pre-wrap; }

  .doc-file { margin-top:16px; }
  .doc-file a {
    display:inline-block;
    background:var(--lrdms-navy);
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    font-size:14px;
  }
  .doc-file a:hover { background:var(--lrdms-navy-dark); }

  .not-found { text-align:center; padding:60px 20px; }
  .not-found h1 { font-size:24px; margin-bottom:8px; }
  .not-found p { color:#6b7690; }
  .not-found a { font-weight:600; }

  /* Description */
  .doc-desc { background:#f6f8fc; border-left:3px solid var(--lrdms-gold); padding:14px 18px; border-radius:0 8px 8px 0; margin-bottom:24px; font-size:14px; color:#4a5568; line-height:1.6; }

  /* Action buttons */
  .doc-actions { display:flex; gap:10px; margin-bottom:24px; flex-wrap:wrap; }
  .doc-btn {
    display:inline-flex; align-items:center; gap:6px;
    background:#fff; border:1px solid #d3dcec; color:var(--lrdms-navy);
    padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600;
    cursor:pointer; text-decoration:none; transition:background .2s,border-color .2s;
  }
  .doc-btn:hover { background:#f0f4fa; border-color:var(--lrdms-navy); }
  .doc-btn.copied { background:#d1fae5; border-color:#10b981; color:#065f46; }

  /* Amendment history */
  .doc-amendment { margin-top:24px; padding:16px 20px; background:#fffbeb; border:1px solid rgba(245,158,11,.2); border-radius:10px; }
  .doc-amendment h4 { font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:#92400e; margin:0 0 8px; }
  .doc-amendment p { margin:0; font-size:14px; color:#78350f; }
  .doc-amendment a { color:var(--lrdms-navy); font-weight:600; }

  /* Back to top */
  .back-to-top {
    position:fixed; bottom:24px; right:24px; width:44px; height:44px; border-radius:50%;
    background:var(--lrdms-navy); color:#fff; border:none; cursor:pointer; font-size:20px;
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 4px 12px rgba(55,81,126,.3); opacity:0; transition:opacity .3s; z-index:100;
  }
  .back-to-top.visible { opacity:1; }
  .back-to-top:hover { background:var(--lrdms-navy-dark); }

  /* ── Document details grid ── */
  .doc-details {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px 28px;
    background: #f8fafc; border: 1px solid #e7ecf4; border-radius: 10px;
    padding: 16px 20px; margin-bottom: 24px;
  }
  .doc-detail__label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #8a94a6; font-weight: 600; margin-bottom: 2px; }
  .doc-detail__value { font-size: 14px; color: var(--lrdms-navy); font-weight: 500; }

  /* ── Better reading typography ── */
  .doc-text { font-size: 15.5px; line-height: 1.85; letter-spacing: .01em; }

  /* ── Enhanced 404 ── */
  .not-found { text-align:center; padding:80px 20px; }
  .not-found-icon { font-size:56px; color:var(--lrdms-gold); opacity:.55; margin-bottom:18px; display:block; }
  .not-found h1 { font-size:24px; margin-bottom:8px; color:var(--lrdms-navy); }
  .not-found p { color:#6b7690; max-width:380px; margin:0 auto; }

  /* ── Print styles ── */
  @media print {
    .topbar, .back-to-top, .doc-actions { display:none !important; }
    body { background:#fff; -webkit-print-color-adjust:exact; }
    .doc-wrap { padding:0; max-width:100%; margin:0; }
    .doc-body { border:none; box-shadow:none; padding:0; background:transparent; }
    .doc-body h3 { color:#000; font-size:13pt; }
    .doc-text { font-size:12pt; line-height:1.7; }
    .doc-details { border:1px solid #ccc; background:transparent; }
    .doc-detail__value { color:#000; }
    h1 { font-size:22pt; page-break-after:avoid; color:#000; }
    .doc-desc, .doc-amendment, .doc-details { page-break-inside:avoid; }
    .badge-type, .badge-status { border:1px solid #999; color:#000; background:transparent !important; }
  }

  @media (max-width:576px) {
    .doc-details { grid-template-columns:1fr; }
    h1 { font-size:22px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar__inner">
    <div class="topbar__brand">
      <img src="assets/img/manila logo.png" alt="Manila City Seal" class="topbar__logo">
      <div>MANILA CITY HALL<small>Legislative Records &amp; Document Management</small></div>
    </div>
    <a class="topbar__back" href="public.php#legislation">← Back to home</a>
  </div>
</div>

<?php if (!$doc): ?>
  <div class="doc-wrap">
    <div class="not-found">
      <i class="bi bi-file-earmark-x not-found-icon"></i>
      <h1>Document not found</h1>
      <p>The document you are looking for is not available for public viewing, or may have been removed.</p>
      <p style="margin-top:16px"><a href="public.php#legislation" style="font-weight:600">← Return to published laws</a></p>
    </div>
  </div>
<?php else: ?>
  <div class="doc-wrap">
    <div class="doc-meta">
      <span class="badge-type"><?= htmlspecialchars($typeDisplayLabels[$doc['doc_type']] ?? $doc['doc_type']) ?></span>
      <span class="badge-status"><?= htmlspecialchars($doc['status']) ?></span>
      <span class="doc-sub" style="margin:0"><?= htmlspecialchars($doc['doc_number']) ?></span>
    </div>

    <h1><?= htmlspecialchars($doc['title']) ?></h1>
    <p class="doc-sub">
      <?php if (!empty($doc['sponsor'])): ?>Sponsored by <strong><?= htmlspecialchars($doc['sponsor']) ?></strong> · <?php endif; ?>
      <?= $doc['enactment_date'] ? 'Enacted ' . date('M j, Y', strtotime($doc['enactment_date'])) : '' ?>
    </p>

    <?php if (!empty($doc['description'])): ?>
      <div class="doc-desc"><?= nl2br(htmlspecialchars($doc['description'])) ?></div>
    <?php endif; ?>

    <div class="doc-details">
      <?php if ($committeeName): ?>
        <div class="doc-detail">
          <div class="doc-detail__label">Committee</div>
          <div class="doc-detail__value"><?= htmlspecialchars($committeeName) ?></div>
        </div>
      <?php endif; ?>
      <?php if (!empty($doc['source_system'])): ?>
        <div class="doc-detail">
          <div class="doc-detail__label">Source</div>
          <div class="doc-detail__value"><?= htmlspecialchars($doc['source_system']) ?></div>
        </div>
      <?php endif; ?>
      <?php if (!empty($doc['enactment_date'])): ?>
        <div class="doc-detail">
          <div class="doc-detail__label">Enactment Date</div>
          <div class="doc-detail__value"><?= date('F j, Y', strtotime($doc['enactment_date'])) ?></div>
        </div>
      <?php endif; ?>
      <div class="doc-detail">
        <div class="doc-detail__label">Last Updated</div>
        <div class="doc-detail__value"><?= date('F j, Y', strtotime($doc['updated_at'] ?? $doc['created_at'])) ?></div>
      </div>
    </div>

    <div class="doc-actions">
      <button type="button" class="doc-btn" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      <button type="button" class="doc-btn" id="copyBtn" onclick="copyLink()"><i class="bi bi-link-45deg"></i> Copy link</button>
    </div>

    <div class="doc-body">
      <?php if (trim($content) !== ''): ?>
        <h3><?= empty($doc['body']) ? 'Extracted text' : 'Document content' ?></h3>
        <div class="doc-text"><?= nl2br(htmlspecialchars($content)) ?></div>
      <?php else: ?>
        <p class="text-muted" style="color:#8a94a6;margin:0">No text content is available for this document yet.</p>
      <?php endif; ?>
    </div>

    <?php if ($nextVersion): ?>
      <div class="doc-amendment">
        <h4><i class="bi bi-arrow-repeat"></i> Amendment History</h4>
        <p>This document has been <?= strtolower($nextVersion['status']) === 'amended' ? 'amended' : 'superseded' ?> by
          <a href="public_view.php?id=<?= (int)$nextVersion['id'] ?>"><?= htmlspecialchars($nextVersion['doc_number'] ?? $nextVersion['title']) ?></a>
          <?= $nextVersion['enactment_date'] ? ' · ' . date('M j, Y', strtotime($nextVersion['enactment_date'])) : '' ?>.
        </p>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Back to top -->
<button type="button" class="back-to-top" id="backToTop" onclick="window.scrollTo({top:0,behavior:'smooth'})"><i class="bi bi-arrow-up"></i></button>

<script>
// Copy link to clipboard
function copyLink() {
  navigator.clipboard.writeText(window.location.href).then(function () {
    var btn = document.getElementById('copyBtn');
    btn.classList.add('copied');
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
    setTimeout(function () {
      btn.classList.remove('copied');
      btn.innerHTML = '<i class="bi bi-link-45deg"></i> Copy link';
    }, 2000);
  });
}
// Back-to-top visibility
window.addEventListener('scroll', function () {
  document.getElementById('backToTop').classList.toggle('visible', window.scrollY > 400);
});
</script>

</body>
</html>
