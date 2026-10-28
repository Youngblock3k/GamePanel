<div class="page-head"><div><h1>Users</h1><p class="muted">Accounts are created here only. There is no public sign-up.</p></div><?php if (App\Roles::can($user, 'users.create')): ?><a class="btn" href="/admin/users/new">+ New user</a><?php endif; ?></div>

<div class="card">
  <table class="table">
    <thead><tr><th>User</th><th>Role</th><th>2FA</th><th>Status</th><th>Created</th><th>Manage</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $locked = $u['is_admin'] && !$user['is_admin']; ?>
      <tr>
        <td><?= user_chip($u['username']) ?><br><small class="muted"><?= e($u['email']) ?></small></td>
        <td><?= $u['is_admin'] ? '<span class="pill ok">Administrator</span>' : ($u['role_name'] ? '<span class="role-pill" data-rc="' . e($u['role_color'] ?? '#9aa0a6') . '">' . e($u['role_name']) . '</span>' : '<span class="muted">User</span>') ?></td>
        <td><span class="badge" data-state="<?= $u['totp_enabled'] ? 'running' : 'offline' ?>"><?= $u['totp_enabled'] ? 'On' : 'Off' ?></span></td>
        <td><?= $u['disabled'] ? '<span class="badge" data-state="offline">Disabled</span>' : 'Active' ?></td>
        <td><?= time_tag((int)$u['created_at']) ?></td>
        <td>
          <?php if ($locked): ?><span class="muted small">Administrator</span><?php else: ?>
          <form method="post" action="/admin/users/action" class="row gap wrap" data-confirm="Apply this action?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <select name="action">
              <?php if ($user['is_admin']): ?><?= $u['is_admin'] ? '<option value="demote">Remove admin</option>' : '<option value="promote">Make admin</option><option value="set_role">Set role…</option>' ?><?php endif; ?>
              <?= $u['disabled'] ? '<option value="enable">Enable</option>' : '<option value="disable">Disable</option>' ?>
              <option value="reset_2fa">Reset 2FA</option>
              <option value="delete">Delete</option>
            </select>
            <?php if ($user['is_admin'] && !$u['is_admin']): ?><select name="role_id" title="Used with “Set role…”"><option value="">No role</option><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$u['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
            <input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password">
            <button class="btn sm" type="submit">Apply</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
