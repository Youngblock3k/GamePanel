<div class="page-head"><div><h1>Roles</h1><p class="muted">A role lets someone who is not an administrator work in chosen parts of the admin panel.</p></div><a class="btn" href="/admin/roles/new">+ New role</a></div>

<div class="card">
  <table class="table">
    <thead><tr><th>Role</th><th>Can access</th><th>Members</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roles as $r): $perms = json_decode((string)$r['perms'], true) ?: []; ?>
      <tr>
        <td><span class="role-pill" data-rc="<?= e($r['color'] ?? '#9aa0a6') ?>"><?= e($r['name']) ?></span><?= $r['description'] ? '<br><small class="muted">' . e($r['description']) . '</small>' : '' ?></td>
        <td><?php $n = count($perms); ?><?= $n ? '<b>' . $n . '</b> of ' . count(App\Roles::PERMS) . ' permissions' : '<span class="muted">Nothing</span>' ?><br><small class="muted"><?= e(implode(', ', array_map(static fn($k) => App\Roles::PERMS[$k][0], array_slice(array_values(array_filter($perms, static fn($k) => isset(App\Roles::PERMS[$k]))), 0, 4)))) ?><?= $n > 4 ? '…' : '' ?></small></td>
        <td><?= (int)$r['members'] ?></td>
        <td class="r"><div class="row gap end">
          <a class="btn sm ghost" href="/admin/roles/<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" action="/admin/roles/<?= (int)$r['id'] ?>/delete" class="row gap" data-confirm="Delete this role? Members become ordinary users."><?= csrf_field() ?>
            <input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password"><button class="btn sm danger" type="submit">Delete</button></form></div></td>
      </tr>
    <?php endforeach; if (!$roles): ?><tr><td colspan="4" class="muted center">No roles yet. Use "+ New role" to make one, then give it to a user on the Users page.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="muted small">Administrators always have every permission. Roles are managed by administrators only.</p>
