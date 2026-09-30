<?php
$photoQuery = $pdo->prepare('SELECT 1 FROM user_profile_photos WHERE user_id = ?');
$photoQuery->execute([$user['id']]);
$hasProfilePhoto = (bool)$photoQuery->fetchColumn();
?>
<div class="card mb-3">
  <h3 class="h6">Profile photo</h3>
  <div class="d-flex align-items-center gap-3 mb-3">
    <?php if ($hasProfilePhoto): ?>
      <img src="profile_photo.php?id=<?= (int)$user['id'] ?>" alt="Your profile photo" width="80" height="80" style="border-radius:50%;object-fit:cover;">
    <?php else: ?>
      <span style="display:grid;place-items:center;width:80px;height:80px;border-radius:50%;background:#e7efff;color:#3265ac;font-size:28px;"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
    <?php endif; ?>
    <p class="small text-muted mb-0">Your photo appears beside your name in Users &amp; Roles.</p>
  </div>
  <form method="post" enctype="multipart/form-data">
    <?php csrf_field(); ?>
    <input type="hidden" name="form_action" value="upload_profile_photo">
    <label for="profile-photo" class="form-label small">Choose photo</label>
    <input type="file" name="profile_photo" id="profile-photo" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required aria-describedby="photo-help">
    <p id="photo-help" class="form-text">JPG, PNG, or WebP. Maximum 2 MB and 4096 × 4096 pixels.</p>
    <button class="btn btn-primary btn-sm">Save photo</button>
  </form>
  <?php if ($hasProfilePhoto): ?>
  <form method="post" class="mt-2">
    <?php csrf_field(); ?><input type="hidden" name="form_action" value="remove_profile_photo">
    <button class="btn btn-outline-secondary btn-sm">Remove photo</button>
  </form>
  <?php endif; ?>
</div>
