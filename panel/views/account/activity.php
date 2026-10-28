<div class="page-head"><div><h1>Activity</h1><p class="muted">Recent actions on your account.</p></div></div>
<?php require ROOT . '/views/partials/account_tabs.php'; ?>
<div class="card">
  <table class="table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= time_tag((int)$r['created_at']) ?></td><td><?= user_chip($user['username']) ?></td><td class="mono"><?= e($r['action']) ?></td><td class="small"><?= e($r['target']) ?></td><td><?= ip_chip((string)$r['ip']) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted center">No activity yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
