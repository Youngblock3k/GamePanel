<?php require __DIR__ . '/_header.php'; ?>
<div class="card">
  <h3>Recent activity</h3>
  <?php if (!$rows): ?><p class="muted">Nothing has happened on this server yet.</p><?php endif; ?>
  <ul class="feed">
    <?php foreach ($rows as $r): $m = json_decode((string)$r['meta'], true) ?: []; ?>
      <li><span class="dot"></span><div><b><?= e(ucfirst(str_replace(['server.', '.'], ['', ' '], $r['action']))) ?></b>
        <?php if (!empty($m['file'])): ?><span class="mono small"><?= e($m['file']) ?></span><?php endif; ?>
        <small class="muted">by <?= e($r['username'] ?? 'system') ?> · <?= time_tag((int)$r['created_at']) ?></small></div></li>
    <?php endforeach; ?>
  </ul>
</div>
