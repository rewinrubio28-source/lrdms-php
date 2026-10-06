<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/organization.php';
require_permission('access', 'manage_organization');
$user = current_user();
$pdo = get_db();
$errors = [];
$types = ['offices' => 'Office / Parent unit', 'divisions' => 'Division / Section', 'positions' => 'Position / Designation'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $officeId = (int)($_POST['office_id'] ?? 0);
    if (!validate_csrf()) $errors[] = 'Security token expired. Refresh the page and try again.';
    if (!isset($types[$type])) $errors[] = 'Choose a valid category.';
    $maxLength = 180;
    if ($name === '' || mb_strlen($name) > $maxLength) $errors[] = 'Enter a name of up to ' . $maxLength . ' characters.';
    if ($type === 'divisions') {
        $stmt = $pdo->prepare('SELECT id FROM offices WHERE id=?');
        $stmt->execute([$officeId]);
        if (!$stmt->fetchColumn()) $errors[] = 'Select an office for the division.';
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if ($type === 'divisions') {
                $pdo->prepare('INSERT INTO divisions (name, office_id) VALUES (?, ?)')->execute([$name, $officeId]);
            } else {
                $pdo->prepare("INSERT INTO $type (name) VALUES (?)")->execute([$name]);
            }
            log_action('access', 'created_organization_reference', $types[$type] . ': ' . $name);
            $pdo->commit();
            header('Location: organization.php?saved=1');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[1] ?? 0) === 1062) $errors[] = 'That name already exists in this category or office.';
            else throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
$lists = organization_lists($pdo);
$officeNames = array_column($lists['offices'], 'name', 'id');
include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div><h1 class="topbar__title">Organizational identity</h1>
      <p class="module-banner-description">Manage offices, divisions, and organizational identity.</p></div>
  <a class="btn btn-outline-primary btn-sm" href="users.php">Back to Users</a>
</div>
<p class="text-muted">Maintain official office, division, and position names for staff assignments.</p>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Entry added.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
<div class="row g-3">
<?php foreach ($types as $type => $label): ?>
  <div class="col-md-6">
    <section class="card h-100">
      <h2 class="h6"><?= $label ?></h2>
      <form method="post" class="mb-3">
        <?php csrf_field(); ?>
        <input type="hidden" name="type" value="<?= $type ?>">
        <?php if ($type === 'divisions'): ?>
        <label class="form-label small" for="division-office">Office</label>
        <select name="office_id" id="division-office" class="form-select mb-2" required>
          <option value="">Select an office</option>
          <?php foreach ($lists['offices'] as $office): ?><option value="<?= (int)$office['id'] ?>" <?= ($type === ($_POST['type'] ?? '') && (int)$office['id'] === (int)($_POST['office_id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars($office['name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="form-label small" for="name-<?= $type ?>"><?= $label ?> name</label>
        <input id="name-<?= $type ?>" name="name" class="form-control mb-2" maxlength="180" required value="<?= htmlspecialchars($errors && $type === ($_POST['type'] ?? '') ? $name : '') ?>">
        <button class="btn btn-primary btn-sm">Add <?= $label ?></button>
      </form>
      <ul class="list-group list-group-flush">
        <?php foreach ($lists[$type] as $item): ?>
        <li class="list-group-item"><?= htmlspecialchars($item['name']) ?><?php if ($type === 'divisions'): ?><div class="small text-muted"><?= htmlspecialchars($officeNames[$item['office_id']] ?? 'No office assigned') ?></div><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$lists[$type]): ?><p class="small text-muted">No entries yet.</p><?php endif; ?>
    </section>
  </div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
