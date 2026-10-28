<div class="page-head"><div><h1>Audit log</h1><p class="muted">Logins, security changes and administrative actions.</p></div></div>
<div class="card">
  <table class="table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= time_tag((int)$r['created_at']) ?></td><td><?= user_chip($r['username'] ?? null) ?></td><td class="mono"><?= e($r['action']) ?></td><td class="small"><?= e($r['target']) ?></td><td><?= ip_chip((string)$r['ip']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="row gap">
    <?php if ($page > 1): ?><a class="btn sm ghost" href="/admin/audit?page=<?= $page - 1 ?>">← Newer</a><?php endif; ?>
    <?php if (count($rows) === 50): ?><a class="btn sm ghost" href="/admin/audit?page=<?= $page + 1 ?>">Older →</a><?php endif; ?>
  </div>
</div>
