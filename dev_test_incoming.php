<?php
/**
 * DEV/TEST TOOL — Simulated Upstream Sender
 * ──────────────────────────────────────────
 * Stands in for the Ordinance & Resolution Lifecycle System (or Session
 * Management System) so you can test the "incoming document" flow end to
 * end WITHOUT needing that other subsystem actually built yet:
 *
 *   this tool → api/upload_document.php → is_public=0, Enacted
 *             → email alert to Records Officers
 *             → shows up in encoding.php's "Awaiting Verification" queue
 *
 * DELIBERATELY STANDALONE: no require_login(), no shared sidebar/topbar
 * layout. This simulates an external system — a real upstream sender has
 * no LRDMS account and never touches LRDMS's login — so this tool has to
 * be reachable and usable the same way: open the URL directly, in any
 * browser, no session needed. The real security boundary is the shared
 * X-API-Key that api/upload_document.php itself checks, exactly like it
 * would for the actual System 1 integration.
 *
 * This file is for testing only — remove it (or restrict it further)
 * before any real deployment.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/email.php'; // for BASE_URL
require_once __DIR__ . '/config/env.php';
load_env_file();
define('DEV_TEST_API_KEY', env_required('API_SHARED_KEY'));

// This page is deliberately standalone (no login/layout — see note above),
// so it doesn't get a session for free the way logged-in pages do via
// includes/auth.php. Start one ourselves, just to flash the result across
// a redirect (Post/Redirect/Get) so refreshing this page doesn't resubmit
// the form and push a second document + a duplicate notification.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = get_db();
$committees = $pdo->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();

$result = null;
$httpCode = null;
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $typePrefix = [
        'Ordinance' => 'ORD', 'Resolution' => 'RES',
        'Committee Report' => 'CR', 'Minutes' => 'MIN',
    ][$_POST['doc_type'] ?? 'Ordinance'] ?? 'DOC';

    // Auto-generate a throwaway doc number so repeated test sends don't collide.
    $docNumber = trim($_POST['doc_number'] ?? '') !== ''
        ? trim($_POST['doc_number'])
        : $typePrefix . '-' . date('Y') . '-TEST' . substr((string) time(), -4);

    $payload = [
        'title'          => trim($_POST['title'] ?? '') !== '' ? trim($_POST['title']) : 'Test Ordinance — Sample Push ' . date('H:i:s'),
        'doc_number'     => $docNumber,
        'doc_type'       => $_POST['doc_type'] ?? 'Ordinance',
        'sponsor'        => trim($_POST['sponsor'] ?? '') ?: 'Hon. Test Sponsor',
        'committee_id'   => ($_POST['committee_id'] ?? '') !== '' ? (int) $_POST['committee_id'] : null,
        'enactment_date' => $_POST['enactment_date'] ?? date('Y-m-d'),
        'source_system'  => trim($_POST['source_system'] ?? '') ?: 'System 1 – Ordinance & Resolution Lifecycle (TEST)',
        'is_public'      => 'true', // intentionally sent as true — the endpoint should override this to false anyway
        'ocr_text'       => trim($_POST['ocr_text'] ?? '') ?: null,
    ];

    // Multipart POSTFIELDS (unlike JSON) can't carry PHP null cleanly —
    // strip anything unset so curl only sends real values plus the file.
    $payload = array_filter($payload, function ($v) { return $v !== null; });

    // Call the app's own Apache locally (inside the container) instead of going
    // out through the public domain/Cloudflare and back in — avoids DNS/proxy
    // failures (502) on the self-request.
    $endpoint = 'http://127.0.0.1/api/upload_document.php';

    // Every selected file is sent now — no cap. Multiple files under the
    // same field name need distinct array-style keys ('attachment[0]',
    // 'attachment[1]', ...) for PHP's curl extension to send them all as
    // one multipart field; api/upload_document.php reassembles them.
    if (!empty($_FILES['attachment']['name'])) {
        foreach ($_FILES['attachment']['name'] as $i => $name) {
            if ($name === '' || $_FILES['attachment']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $payload['attachment[' . $i . ']'] = new CURLFile(
                $_FILES['attachment']['tmp_name'][$i],
                $_FILES['attachment']['type'][$i],
                $name
            );
        }
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload, // array (not json_encode'd) => cURL sends multipart/form-data automatically
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . DEV_TEST_API_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $errorMsg = 'Could not reach the endpoint: ' . curl_error($ch);
    }
    curl_close($ch);

    if ($response !== false) {
        $result = json_decode($response, true);
    }

    // PRG: stash the outcome in the session instead of rendering it on this
    // same POST response, then redirect to a plain GET of this page. A
    // refresh after this point just re-fetches the GET — no resubmission,
    // no duplicate document/notification.
    $_SESSION['dev_test_incoming_result'] = $result;
    $_SESSION['dev_test_incoming_http_code'] = $httpCode;
    $_SESSION['dev_test_incoming_error'] = $errorMsg;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Pick up a flashed result from a just-completed POST (see redirect above),
// then clear it so a further refresh shows a clean, empty form instead of
// replaying the same result forever.
if (isset($_SESSION['dev_test_incoming_result']) || isset($_SESSION['dev_test_incoming_error'])) {
    $result = $_SESSION['dev_test_incoming_result'] ?? null;
    $httpCode = $_SESSION['dev_test_incoming_http_code'] ?? null;
    $errorMsg = $_SESSION['dev_test_incoming_error'] ?? null;
    unset($_SESSION['dev_test_incoming_result'], $_SESSION['dev_test_incoming_http_code'], $_SESSION['dev_test_incoming_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dev Tool — Simulate Incoming Document</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#F2F4F7; color:#0B2E59; padding:32px 16px; }
  .devtool-wrap { max-width:820px; margin:0 auto; }
  .card { background:#fff; border:1px solid #E3E8EF; border-radius:10px; padding:20px; margin-bottom:16px; }
  h1 { font-size:20px; margin-bottom:4px; }
  h3 { font-size:16px; }
  code { background:#EEF2F7; padding:1px 5px; border-radius:3px; }
</style>
</head>
<body>
<div class="devtool-wrap">

  <h1>Dev Tool — Simulate Incoming Document</h1>
  <p class="text-muted small mb-3">Standalone test sender — no LRDMS login needed, same as a real external system.</p>

  <div class="alert" style="background:#FDF3DF;border-left:3px solid #D4AF37;color:#7a5c0a;font-size:13px;">
    <strong>Testing tool only.</strong> This page pretends to be the upstream Ordinance &amp; Resolution Lifecycle
    System and pushes a sample document to <code>api/upload_document.php</code>, exactly like a real integration
    would. It is intentionally kept outside LRDMS's own login/layout, the same way the real System 1 would be — it
    only talks to the API endpoint, authenticated with the shared API key, never with an LRDMS session. Use it to
    test the alert email and the "Awaiting Verification" queue in Encoding. Remove this file before real deployment.
  </div>

  <?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
  <?php elseif ($result !== null): ?>
    <div class="card" style="border-left:4px solid <?= $httpCode === 200 ? '#198754' : '#dc3545' ?>;">
      <h3>
        <?= $httpCode === 200 ? '✅ Sent successfully' : '⚠️ Send failed' ?>
        <span class="text-muted small">(HTTP <?= htmlspecialchars((string) $httpCode) ?>)</span>
      </h3>
      <?php if (!empty($result['document_id'])): ?>
        <div class="d-flex gap-2">
          <a href="document.php?id=<?= (int) $result['document_id'] ?>" class="btn btn-outline-primary btn-sm">View the document</a>
          <a href="encoding.php#awaiting-verification" class="btn btn-outline-secondary btn-sm">Go to Awaiting Verification queue</a>
        </div>
        <div class="form-text mt-2">Those two links open the real LRDMS app and will ask you to log in there — this tool itself never needs a login.</div>
      <?php endif; ?>
      <details class="mt-2">
        <summary class="text-muted small" style="cursor:pointer;">Show raw response (for debugging)</summary>
        <pre class="bg-light border rounded p-3 mt-2" style="white-space:pre-wrap;"><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)) ?></pre>
      </details>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3>Send a Test Document</h3>
    <p class="text-muted small">Leave fields blank to auto-fill sample values — good for a quick, one-click test.</p>

    <form method="post" enctype="multipart/form-data">
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Attach file(s) (PDF or image)</label>
          <input type="file" id="attachment" name="attachment[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple>
          <div class="form-text">All selected files are sent and stored — no limit on how many. With 2+ files, the document gets a gallery/carousel view instead of a single file preview. No OCR runs on any of them — stored as-is.</div>
          <div id="attachmentList" class="form-text mt-1"></div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Title</label>
          <input type="text" id="title" name="title" class="form-control" placeholder="Test Ordinance — Sample Push">
        </div>
        <div class="col-md-3">
          <label class="form-label">Doc. Number</label>
          <input type="text" name="doc_number" class="form-control" placeholder="auto (e.g. ORD-2026-TEST1234)">
        </div>
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="doc_type" class="form-select">
            <?php foreach (['Ordinance', 'Resolution', 'Committee Report', 'Minutes'] as $t): ?>
              <option><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Sponsor</label>
          <input type="text" name="sponsor" class="form-control" placeholder="Hon. Test Sponsor">
        </div>
        <div class="col-md-4">
          <label class="form-label">Committee</label>
          <select name="committee_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($committees as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Enactment Date</label>
          <input type="date" name="enactment_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Source System Label</label>
          <input type="text" name="source_system" class="form-control" placeholder="System 1 – Ordinance & Resolution Lifecycle (TEST)">
        </div>
        <div class="col-12">
          <label class="form-label">OCR Text (optional, simulates pre-extracted / As-Filed text)</label>
          <textarea name="ocr_text" class="form-control" rows="4" placeholder="Leave blank if not simulating OCR text. To simulate a multi-page bill, put a line containing only [PAGE BREAK] between each page's text — Text As Filed will number lines per page, same as the House's own PDF viewer."></textarea>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">Send Test Document →</button>
      </div>
      <div class="form-text mt-2">
        This submits straight to <code><?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/api/upload_document.php</code>
        with the shared API key already attached — just like a real upstream push.
      </div>
    </form>
  </div>

</div>
<script>
  // ALL selected files are uploaded and stored when you submit (see the
  // PHP handler above — one CURLFile per file). This just lists what's
  // attached — it no longer touches the Title field.
  document.getElementById('attachment').addEventListener('change', function (e) {
    var files = Array.from(e.target.files || []);
    var list = document.getElementById('attachmentList');
    if (files.length === 0) { list.textContent = ''; return; }

    list.textContent = files.length === 1
      ? 'Attached: ' + files[0].name
      : files.length + ' files attached: ' + files.map(function (f) { return f.name; }).join(', ');
  });
</script>
</body>
</html>
