<?php require __DIR__ . '/_header.php'; ?>
<div class="grid two">
  <form class="card" method="post" action="/server/<?= e($s['uuid']) ?>/settings">
    <?= csrf_field() ?>
    <h3>General</h3>
    <label>Server name <input name="name" required maxlength="100" value="<?= e($s['name']) ?>"></label>
    <label>Description <input name="description" maxlength="255" value="<?= e($s['description']) ?>"></label>
    <?php if ($vars): ?><h3>Startup variables</h3><?php endif; ?>
    <?php foreach ($vars as $v):
      $canEdit = $user['is_admin'] || !empty($v['editable']); ?>
      <label><?= e($v['name']) ?> <small class="muted mono"><?= e($v['key']) ?></small>
        <input name="env[<?= e($v['key']) ?>]" value="<?= e($env[$v['key']] ?? ($v['default'] ?? '')) ?>" maxlength="64" <?= $canEdit ? '' : 'disabled' ?>>
      </label>
    <?php endforeach; ?>
    <p class="muted small">Changing startup variables recreates the container; stop the server first.</p>
    <button class="btn" type="submit">Save changes</button>
  </form>

  <div class="card">
    <h3>Server information</h3>
    <dl class="kv">
      <dt>Address</dt><dd class="mono"><?= e($s['addr']) ?></dd>
      <dt>Node</dt><dd><?= e($s['node_name']) ?></dd>
      <dt>Egg</dt><dd><?= e($s['egg_name']) ?></dd>
      <dt>Image</dt><dd class="mono"><?= e($s['docker_image']) ?></dd>
      <dt>Memory</dt><dd><?= e(fmt_mb($s['memory'])) ?></dd>
      <dt>CPU limit</dt><dd><?= $s['cpu'] ? e($s['cpu']) . '%' : 'Unlimited' ?></dd>
      <dt>Disk</dt><dd><?= e(fmt_mb($s['disk'])) ?></dd>
      <dt>Owner</dt><dd><?= e($s['owner_name']) ?></dd>
      <dt>Status</dt><dd><?= e($s['status']) ?></dd>
      <dt>UUID</dt><dd class="mono small"><?= e($s['uuid']) ?></dd>
    </dl>
  </div>
</div>
