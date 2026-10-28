<div class="page-head"><div><h1>Admin overview</h1><p class="muted">Everything across your installation.</p></div></div>
<?php $php = App\PhpGuard::status(); ?>
<div class="alert <?= $php['ok'] ? 'ok' : 'err' ?>"><?= e($php['msg']) ?></div>
<div class="grid stats">
  <?php foreach ($counts as $label => $n): ?>
    <div class="card stat"><label><?= e($label) ?></label><b><?= (int)$n ?></b></div>
  <?php endforeach; ?>
</div>
<?php if (App\Roles::can($user, 'audit')): ?><div class="card">
  <div class="row between"><h3>Recent activity</h3><a href="/admin/audit">View all</a></div>
  <table class="table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= time_tag((int)$r['created_at']) ?></td><td><?= user_chip($r['username'] ?? null) ?></td><td class="mono"><?= e($r['action']) ?></td><td class="small"><?= e($r['target']) ?></td><td><?= ip_chip((string)$r['ip']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
