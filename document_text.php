<?php
/**
 * TEXT AS FILED — full-text reader view
 * ───────────────────────────────────────
 * A clean, printable/readable view of a single document's full extracted
 * text (ocr_text) — modeled after the "Text As Filed" button on
 * congress.gov.ph's legislative documents listing. Deliberately a separate
 * page (not a modal) so it gets its own URL/permalink, reads comfortably
 * for long bills, and can be printed or downloaded on its own.
 *
 * Uses the same visibility rules as document.php (can_view_document) —
 * no separate permission model, and no verified_at gate, since reviewing
 * the full text is exactly what a Records Officer needs to do BEFORE
 * verifying an incoming document, not just after.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/database.php';

require_login();
$user = current_user();
$pdo = get_db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc || !can_view_document($user, $doc)) {
    http_response_code(404);
    include __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning">Document not found, or you do not have access to view it.</div>';
    include __DIR__ . '/includes/layout_bottom.php';
    exit;
}

if (empty($doc['ocr_text'])) {
    header('Location: document.php?id=' . $doc['id']);
    exit;
}

// Split into pages the same way a tester can produce them in the dev tool's
// OCR Text field: a line containing only [PAGE BREAK], or a real form-feed
// character (\f) if the text came from a tool that already emits one. If
// neither marker is present, the whole thing is treated as a single page —
// still gets line numbers, just no page divider. This mirrors how the
// reference viewer (congress.gov.ph's PDF pages) numbers lines 1.. per
// page rather than continuously through the whole document.
$rawText = str_replace("\r\n", "\n", $doc['ocr_text']);
$pages = preg_split('/\n[ \t]*\[PAGE BREAK\][ \t]*\n|\x0C/i', $rawText);
$pages = array_map('trim', $pages);
$pages = array_values(array_filter($pages, function ($p) { return $p !== ''; }));
if (!$pages) $pages = [trim($rawText)];
$totalPages = count($pages);

// The page a "Back" link should return to depends on where the document
// currently lives in the workflow — an unverified incoming document's
// natural home is the review page, not the (unreachable, per document.php's
// own guard) document detail page.
$backUrl = ($doc['verified_at'] === null && $doc['source_system'] !== 'Manual Encoding')
    ? 'document_review.php?id=' . $doc['id']
    : 'document.php?id=' . $doc['id'];

if (($_GET['download'] ?? '') === '1') {
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $doc['doc_number']) . '_as_filed.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $doc['ocr_text'];
    exit;
}

include __DIR__ . '/includes/layout_top.php';
?>
<div class="mb-2">
  <a href="<?= htmlspecialchars($backUrl) ?>" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to document</a>
</div>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="topbar__eyebrow"><?= htmlspecialchars($doc['doc_type']) ?> · <?= htmlspecialchars($doc['doc_number']) ?> · Text As Filed<?= $totalPages > 1 ? ' · ' . $totalPages . ' pages' : '' ?></div>
      <h1 class="topbar__title" style="font-size:21px;"><?= htmlspecialchars($doc['title']) ?></h1>
    </div>
  </div>
</div>

<div class="card">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted small">Full extracted text, as filed. Reproduced exactly as recognized — spacing or line breaks may not perfectly match the original scan.</span>
    <a href="document_text.php?id=<?= (int)$doc['id'] ?>&download=1" class="btn btn-outline-secondary btn-sm flex-shrink-0 ms-2">Download as .txt</a>
  </div>

  <?php foreach ($pages as $pageIndex => $pageText): ?>
    <?php $lines = explode("\n", $pageText); ?>
    <div class="as-filed-page">
      <?php if ($totalPages > 1): ?>
        <div class="as-filed-page__label">Page <?= $pageIndex + 1 ?> of <?= $totalPages ?></div>
      <?php endif; ?>
      <div class="as-filed-page__body">
        <?php foreach ($lines as $lineIndex => $line): ?>
          <div class="as-filed-line">
            <span class="as-filed-line__num"><?= $lineIndex + 1 ?></span>
            <span class="as-filed-line__text"><?= $line === '' ? '&nbsp;' : htmlspecialchars($line) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<style>
  .as-filed-page { border: 1px solid #E3E8EF; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
  .as-filed-page__label { background:#EEF2F7; color:#0B2E59; font-size:12px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; padding:6px 14px; border-bottom:1px solid #E3E8EF; }
  .as-filed-page__body { padding: 14px 0; }
  .as-filed-line { display:flex; padding: 1px 14px; }
  .as-filed-line:hover { background:#F7F9FC; }
  .as-filed-line__num { flex: 0 0 34px; text-align:right; padding-right:12px; color:#9AA5B1; font-size:12px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; user-select:none; }
  .as-filed-line__text { flex:1; font-size:14px; line-height:1.6; color:#1a2b3c; white-space:pre-wrap; word-break:break-word; }
</style>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>