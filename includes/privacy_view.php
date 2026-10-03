<?php
// Rendered by privacy.php after authorization and request handling.
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$posted = static fn($name) => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
if ($user) include __DIR__ . '/layout_top.php';
else { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Privacy and data requests | LRDMS</title><link rel="stylesheet" href="Arsha/assets/vendor/bootstrap/css/bootstrap.min.css"></head><body><main id="main-content">
<?php } ?>
<link rel="stylesheet" href="assets/css/privacy.css?v=1">
<div class="privacy-page">
  <header class="privacy-heading">
    <div><p class="privacy-eyebrow">YOUR INFORMATION, YOUR CHOICES</p><h1>Privacy and data requests</h1><p>Understand how your information is used and manage your privacy requests.</p></div>
    <div class="privacy-header-actions">
      <a class="btn btn-sm privacy-secondary" href="<?= $user?'profile.php':'public.php' ?>">Back</a>
      <span class="privacy-version">Notice version <?= $escape(PRIVACY_NOTICE_VERSION) ?></span>
    </div>
  </header>
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><div class="alert alert-success" role="status">Your update has been recorded.</div><?php endif; ?>
  <nav class="privacy-nav" aria-label="Privacy sections"><a class="btn privacy-secondary" href="#privacy-notice">Privacy notice</a><?php if ($user && $available): ?><a class="btn privacy-secondary" href="#data-request">Submit a request</a><a class="btn privacy-secondary" href="#request-history"><?= $reviewer?'Review queue':'Your requests' ?></a><a class="btn privacy-secondary" href="#consent-history">Consent history</a><?php endif; ?></nav>
  <div class="privacy-grid">
    <section class="privacy-card" id="privacy-notice" aria-labelledby="notice-heading">
      <p class="privacy-eyebrow">01 / PRIVACY NOTICE</p><h2 id="notice-heading">How we handle your information</h2>
      <h3>Information we use</h3><p><?= $escape($controller) ?> uses account identity, contact details, role/office information, documents, request history and security activity to provide records management, access control, recovery and accountability.</p>
      <h3>Access and service providers</h3><p>Authorized staff can access information according to their duties. Publicly released documents may be visible to the public. Email verification uses the configured mail provider; document text and files may be processed by the configured OCR, search and storage services.</p>
      <h3>Cookies and retention</h3><p>Account/session cookies support sign-in and request protection. Records, audit history and backups follow the operating office's approved retention rules. The office must confirm the applicable retention periods and processing basis; this application does not set a blanket deletion deadline.</p>
      <h3>Your optional profile photo</h3><p>A profile photo is optional. Upload consent is recorded, and removing the photo withdraws permission for its current display. Historical backups may retain earlier copies until the approved backup retention period ends.</p>
      <h3>Your data requests</h3><p>You can request access, correction or deletion. Requests are reviewed by authorized staff; submitting a request does not automatically erase legislative records or audit history. Staff must explain any retention restriction and record the action taken.</p>
      <?php if ($user && $available): ?><form method="post" class="privacy-ack"><?php csrf_field(); ?><input type="hidden" name="action" value="acknowledge"><button class="btn privacy-secondary" type="submit">I have read this notice</button><p class="privacy-help">Acknowledgement records receipt of this notice. It is not blanket consent to all processing.</p></form><?php endif; ?>
    </section>
    <div class="privacy-side">
      <section class="privacy-card privacy-contact"><p class="privacy-eyebrow">NEED ASSISTANCE?</p><h2>Privacy contact</h2><p><?= $escape($contact) ?></p><p class="privacy-help">You can use this contact even if you cannot sign in.</p></section>
      <section class="privacy-card" id="data-request" aria-labelledby="request-heading"><p class="privacy-eyebrow">02 / DATA REQUEST</p><h2 id="request-heading">Submit a request</h2>
      <?php if (!$user): ?><p>Sign in to submit a request and track its progress.</p><a class="btn privacy-primary" href="public.php">Sign in to continue</a>
      <?php elseif (!$available): ?><div class="alert alert-warning" role="status">Request tracking is not available yet. Please use the privacy contact above.</div>
      <?php else: ?>
      <form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="request">
        <label for="request-type">Request type</label><select id="request-type" name="request_type" class="form-select" aria-describedby="request-type-help"><?php foreach (['Access','Correction','Deletion'] as $type): ?><option value="<?= $type ?>" <?= $posted('request_type')===$type?'selected':'' ?>><?= $type ?></option><?php endforeach; ?></select>
        <p id="request-type-help" class="privacy-help">Access your information, correct a detail, or ask for deletion.</p>
        <label for="request-details">Request details <span class="privacy-help">(required)</span></label><textarea id="request-details" name="details" class="form-control" required maxlength="2000" rows="6" aria-describedby="details-help" placeholder="Describe the information you need or the change you are requesting."><?= $posted('action')==='request'?$escape($posted('details')):'' ?></textarea>
        <p id="details-help" class="privacy-help">Maximum 2,000 characters. Do not include passwords or verification codes.</p>
        <button class="btn privacy-primary" type="submit">Submit request</button><p class="privacy-help">Your request will be reviewed by authorized staff. Check its progress below.</p>
      </form><?php endif; ?></section>
    </div>
  </div>
  <?php if ($user && $available): ?>
  <section class="privacy-card privacy-section" id="request-history" aria-labelledby="history-heading"><div class="privacy-section-heading"><div><p class="privacy-eyebrow">03 / REQUEST TRACKING</p><h2 id="history-heading"><?= $reviewer?'Request review queue':'Your requests' ?></h2></div><span class="privacy-help">Latest 100 requests &middot; <?= count($requests) ?> shown</span></div>
    <?php if (!$requests): ?><div class="privacy-empty"><h3>No requests yet</h3><p><?= $reviewer?'Submitted privacy requests will appear here for review.':'Your submitted requests and staff responses will appear here.' ?></p></div><?php endif; ?>
    <?php foreach ($requests as $request): $requestId=(int)$request['id']; $statusClass=['Open'=>'open','Under Review'=>'review','Completed'=>'completed','Declined'=>'declined'][$request['status']]??'open'; ?>
    <article class="privacy-request"><div class="privacy-section-heading"><h3>#<?= $requestId ?> &middot; <?= $escape($request['request_type']) ?> request</h3><span class="privacy-status privacy-status-<?= $statusClass ?>"><?= $escape($request['status']) ?></span></div><p class="privacy-help"><?= $reviewer?'User #'.(int)$request['user_id'].' &middot; ':'' ?>Submitted <?= $escape($request['created_at']) ?></p><p class="privacy-text"><?= nl2br($escape($request['details'])) ?></p>
    <?php if ($request['response']): ?><div class="privacy-response"><h4>Staff response</h4><p class="privacy-text"><?= nl2br($escape($request['response'])) ?></p></div><?php endif; ?>
    <?php if ($reviewer && in_array($request['status'],['Open','Under Review'],true)): $retry=$posted('action')==='review' && (int)$posted('id')===$requestId; ?>
      <details class="privacy-review" <?= $retry?'open':'' ?>><summary>Review request #<?= $requestId ?></summary><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= $requestId ?>"><label for="status-<?= $requestId ?>">New status</label><select id="status-<?= $requestId ?>" name="status" class="form-select"><?php foreach (['Under Review','Completed','Declined'] as $status): ?><option <?= $retry && $posted('status')===$status?'selected':'' ?>><?= $status ?></option><?php endforeach; ?></select><label for="response-<?= $requestId ?>">Action taken or reason (required)</label><textarea id="response-<?= $requestId ?>" name="response" class="form-control" required maxlength="2000" rows="3" aria-describedby="review-help-<?= $requestId ?>"><?= $retry?$escape($posted('response')):'' ?></textarea><p class="privacy-help" id="review-help-<?= $requestId ?>">Only mark completed after performing the approved action. Maximum 2,000 characters.</p><button class="btn privacy-primary" type="submit">Save review</button></form></details>
    <?php endif; ?></article><?php endforeach; ?>
  </section>
  <section class="privacy-card privacy-section" id="consent-history" aria-labelledby="consent-heading"><div class="privacy-section-heading"><div><p class="privacy-eyebrow">04 / YOUR PREFERENCES</p><h2 id="consent-heading">Notice and consent history</h2></div><span class="privacy-help">Latest 20 events</span></div>
    <?php if (!$events): ?><div class="privacy-empty"><p>No activity recorded yet. Notice acknowledgements and profile photo consent changes will appear here.</p></div><?php else: ?><ul class="privacy-events"><?php foreach ($events as $event): ?><li><div><strong><?= $event['purpose']==='notice'?'Privacy notice':'Profile photo consent' ?></strong><p><?= $escape(ucfirst($event['decision'])) ?> &middot; Notice <?= $escape($event['notice_version']) ?></p></div><time><?= $escape($event['created_at']) ?></time></li><?php endforeach; ?></ul><?php endif; ?>
  </section><?php endif; ?>
</div>
<?php if ($user) include __DIR__ . '/layout_bottom.php'; else echo '</main></body></html>'; ?>
