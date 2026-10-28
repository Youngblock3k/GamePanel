<div class="page-head"><div><h1>Servers</h1><p class="muted">Create and manage every server on every node.</p></div><?php if (App\Roles::can($user, 'servers.create')): ?><a class="btn" href="/admin/servers/new">+ New server</a><?php endif; ?></div>


<div class="card">
  <table class="table">
    <thead><tr><th>Server</th><th>Owner</th><th>Node</th><th>Address</th><th>Status</th><th>Manage</th></tr></thead>
    <tbody>
    <?php foreach ($servers as $s): ?>
      <tr>
        <td><a href="/admin/servers/<?= (int)$s['id'] ?>"><b><?= e($s['name']) ?></b></a> <a class="small" href="/server/<?= e($s['uuid']) ?>">open</a><br><small class="muted"><?= e($s['egg_name']) ?> · <?= e(fmt_mb($s['memory'])) ?></small></td>
        <td><?= e($s['username']) ?></td><td><?= e($s['node_name']) ?></td><td class="mono small"><?= e($s['fqdn']) ?>:<?= (int)$s['port'] ?></td>
        <td><?= e(str_replace('_', ' ', $s['status'])) ?></td>
        <td>
          <form method="post" action="/admin/servers/action" class="row gap wrap" data-confirm="Apply this action?">
            <?= csrf_field() ?><input type="hidden" name="uuid" value="<?= e($s['uuid']) ?>">
            <select name="action"><option value="<?= $s['status'] === 'suspended' ? 'unsuspend' : 'suspend' ?>"><?= $s['status'] === 'suspended' ? 'Unsuspend' : 'Suspend' ?></option><option value="delete">Delete</option></select>
            <label class="check"><input type="checkbox" name="force" value="1"> force</label>
            <input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password">
            <button class="btn sm" type="submit">Apply</button>
          </form>
        </td>
      </tr>
    <?php endforeach; if (!$servers): ?><tr><td colspan="6" class="muted center">You do not have any servers. <a href="/admin/servers/new">Click here to create one.</a></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
