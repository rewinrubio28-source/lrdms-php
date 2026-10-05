<div class="users-directory" id="users-directory">
  <?php $directoryStats = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(u.is_active = 1),0) AS active, COALESCE(SUM(u.is_active = 0),0) AS disabled FROM users u WHERE ' . user_directory_clause())->fetch(); ?>
  <div class="users-overview">
    <div class="users-stat"><i class="bi bi-people" aria-hidden="true"></i><div><span>Total users</span><strong><?= (int)$directoryStats['total'] ?></strong></div><small>Across all roles</small></div>
    <div class="users-stat users-stat--green"><i class="bi bi-person-check" aria-hidden="true"></i><div><span>Active accounts</span><strong><?= (int)$directoryStats['active'] ?></strong></div><small>Enabled to sign in</small></div>
    <div class="users-stat users-stat--amber"><i class="bi bi-person-dash" aria-hidden="true"></i><div><span>Disabled accounts</span><strong><?= (int)$directoryStats['disabled'] ?></strong></div><small>Sign-in disabled</small></div>
    <div class="users-stat users-stat--purple"><i class="bi bi-shield-check" aria-hidden="true"></i><div><span>Defined roles</span><strong><?= count($roles) ?></strong></div><small>Access by responsibility</small></div>
  </div>
  <aside class="users-role-rail">
    <h2>Defined roles</h2>
    <nav aria-label="Filter users by role">
      <a class="users-role-link <?= !$roleFilter ? 'is-selected' : '' ?>" href="users.php?role_id=0" <?= !$roleFilter ? 'aria-current="page"' : '' ?>><span>All Users</span><small><?= array_sum($roleCounts) ?> users</small></a>
      <?php foreach ($roles as $r): ?>
      <a class="users-role-link <?= $roleFilter === (int)$r['id'] ? 'is-selected' : '' ?>" href="users.php?role_id=<?= (int)$r['id'] ?>" <?= $roleFilter === (int)$r['id'] ? 'aria-current="page"' : '' ?>>
        <span><?= htmlspecialchars($r['name']) ?></span><small><?= (int)($roleCounts[$r['id']] ?? 0) ?> users</small>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php if (has_permission('access', 'manage_roles')): ?><button type="button" class="users-create-role w-100 bg-transparent" data-bs-toggle="modal" data-bs-target="#createRoleModal"><i class="bi bi-plus" aria-hidden="true"></i> Create New Role</button><?php endif; ?>
  </aside>
  <section class="users-members" aria-label="Users in selected role">
    <div class="users-members-heading">
      <div><h2><?= htmlspecialchars($selectedRoleName) ?> <span><?= count($allUsers) ?></span></h2><p class="users-section-hint">Manage your team and account access.</p></div>
      <form method="get" class="users-directory-filters">
        <input type="hidden" name="role_id" value="<?= $roleFilter ?>">
        <label class="users-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name or email..." aria-label="Search users"><button type="submit" class="visually-hidden">Search</button></label>
        <select name="status" aria-label="Account status"><option value="all">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option></select>
        <select name="committee_id" aria-label="Committee"><option value="0">All committees</option><?php foreach ($committees as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $committeeFilter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
      </form>
    </div>
    <div class="table-responsive">
      <table class="users-members-table">
        <thead><tr><th>User details</th><th>Position</th><th>Section</th><th>Access Role</th><th>Email address</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($allUsers as $u): ?>
          <tr style="--avatar-hue:<?= ((int)$u['id'] * 47 + 190) % 360 ?>;">
            <td><div class="users-person"><?php if (!empty($u['has_profile_photo'])): ?><img class="users-avatar" src="profile_photo.php?id=<?= (int)$u['id'] ?>" alt="" width="34" height="34" loading="lazy" style="object-fit:cover;"><?php else: ?><span class="users-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span><?php endif; ?><div><strong><?= htmlspecialchars($u['full_name']) ?></strong><small><?= htmlspecialchars($u['username']) ?> · Created <?= !empty($u['created_at']) ? date('M j, Y', strtotime($u['created_at'])) : '—' ?></small><?php if ($u['totp_enabled']): ?><small>2FA enabled</small><?php endif; ?></div></div></td>
            <td><?= htmlspecialchars($u['position_name'] ?: 'Not assigned') ?></td>
            <td><?= htmlspecialchars($u['section_name'] ?: 'Not assigned') ?></td>
            <td><?= htmlspecialchars($u['role_name']) ?></td>
            <td><?= htmlspecialchars($u['email'] ?: 'Not provided') ?></td>
            <td><span class="users-status <?= $u['is_active'] ? 'is-active' : 'is-disabled' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
            <td><?= $u['last_login_at'] ? date('M j, Y · g:i A', strtotime($u['last_login_at'])) : 'Never' ?></td>
            <td><div class="users-row-actions">
              <?php if (can_manage_user($u)): ?>
              <a href="user_view.php?id=<?= (int)$u['id'] ?>" title="Edit user" aria-label="Edit <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
              <?php if ((int)$u['id'] !== (int)$user['id']): ?>
              <form method="post"><?php csrf_field(); ?><input type="hidden" name="form_action" value="toggle_active"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button type="submit" title="<?= $u['is_active'] ? 'Disable account' : 'Enable account' ?>" aria-label="<?= $u['is_active'] ? 'Disable' : 'Enable' ?> <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"><i class="bi <?= $u['is_active'] ? 'bi-person-dash' : 'bi-person-check' ?>" aria-hidden="true"></i></button></form>
              <?php endif; ?>
              <?php else: ?><span class="text-muted small">Restricted</span><?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$allUsers): ?><tr><td colspan="8" class="users-empty"><i class="bi bi-person-search" aria-hidden="true"></i><strong>No users found</strong><p>Try another role or adjust your search and filters.</p></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="users-directory-message" role="status" hidden></p>
  </section>
</div>
