<div class="page-head">
  <div><h1>Servers</h1><p class="muted">Your game servers and their live status.</p></div>
  <div class="row gap">
    <?php if ($user['is_admin']): ?>
      <a class="btn ghost" href="/?all=<?= $showAll ? '0' : '1' ?>"><?= $showAll ? 'Show mine' : 'Show all servers' ?></a>
    <?php endif; ?>
    <div class="seg" role="group" aria-label="Layout">
      <button type="button" class="seg-btn on" data-view="grid" aria-label="Grid view" title="Grid"><svg class="ico"><use href="#i-grid"/></svg></button>
      <button type="button" class="seg-btn" data-view="list" aria-label="List view" title="List"><svg class="ico"><use href="#i-list"/></svg></button>
    </div>
  </div>
</div>
<?php if (!$servers): ?>
  <div class="card empty">No servers yet. <?= $user['is_admin'] ? '<a href="/admin/servers">Create one</a>.' : 'Ask an administrator to create one for you.' ?></div>
<?php else: ?>
<div class="grid" id="srv-grid">
  <?php foreach ($servers as $s): ?>
  <a class="server-card" href="/server/<?= e($s['uuid']) ?>" data-state-url="/server/<?= e($s['uuid']) ?>/state">
    <div class="sc-banner ban-<?= crc32($s['uuid']) % 8 ?>"><span class="badge" data-state="unknown">…</span><span class="sc-initial"><?= e(mb_strtoupper(mb_substr($s['name'], 0, 1))) ?></span></div>
    <div class="sc-body">
      <b class="sc-name"><?= e($s['name']) ?></b>
      <div class="muted mono small"><?= e(App\ServerService::addr($s['fqdn'], $s['port'])) ?> · <?= e($s['node_name']) ?></div>
      <div class="meter"><label>CPU <span data-stat="cpu">–</span></label><div class="bar"><i data-w="0"></i></div></div>
      <div class="meter"><label>Memory <span data-stat="mem">–</span> / <?= e(fmt_mb($s['memory'])) ?></label><div class="bar"><i data-w="0" data-bar="mem"></i></div></div>
      <?php if ($s['status'] !== 'active'): ?><div class="small warn"><?= e(ucfirst(str_replace('_', ' ', $s['status']))) ?></div><?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
