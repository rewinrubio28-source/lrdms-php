<?php require_once __DIR__ . '/records_role_policy.php'; ?>
<details class="mb-3">
  <summary>Position and system access guide</summary>
  <p class="small text-muted mt-2">Proposed assignments based on the supplied draft organizational chart. Position describes the job; system role controls access. Select the role for the person's assigned task.</p>
  <div class="table-responsive"><table class="table table-sm">
    <thead><tr><th>Unit</th><th>Position / assignment</th><th>Suggested system role</th></tr></thead>
    <tbody><?php foreach (records_position_guidance() as $row): ?><tr><?php foreach ($row as $cell): ?><td><?= htmlspecialchars($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="small">Administrator manages accounts and organizational lists. Records roles do not manage users or permissions. ITTS positions do not automatically receive administrator access.</p>
</details>
