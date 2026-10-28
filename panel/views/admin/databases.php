<div class="page-head"><div><h1>Databases</h1><p class="muted">MariaDB or MySQL servers that game servers get their databases from. Create the databases themselves on each server's page.</p></div><?php if (App\Roles::can($user, 'databases.manage')): ?><a class="btn" href="/admin/databases/new">+ New database host</a><?php endif; ?></div>
<?php if (!$driver): ?><div class="alert err">The PHP <span class="mono">pdo_mysql</span> extension is not installed on this panel, so databases cannot be created yet. On Ubuntu: <span class="mono">apt install php-mysql</span>.</div><?php endif; ?>

<div class="card">
  <h3>Database hosts</h3>
  <table class="table">
    <thead><tr><th>Name</th><th>Address</th><th>Admin user</th><th>Databases</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($hosts as $h): ?>
      <tr><td><b><?= e($h['name']) ?></b></td><td class="mono"><?= e($h['host']) ?>:<?= (int)$h['port'] ?></td><td class="mono"><?= e($h['admin_user']) ?></td>
        <td><?= (int)$h['dbs'] ?><?= (int)$h['max_dbs'] > 0 ? ' / ' . (int)$h['max_dbs'] : '' ?></td>
        <td><form method="post" action="/admin/databases/hosts/<?= (int)$h['id'] ?>/delete" class="row gap" data-confirm="Remove this database host?"><?= csrf_field() ?>
          <input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password"><button class="btn sm danger" type="submit">Remove</button></form></td></tr>
    <?php endforeach; if (!$hosts): ?><tr><td colspan="5" class="muted center">No database host yet. Use “+ New database host”.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
