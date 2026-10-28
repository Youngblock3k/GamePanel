<div class="page-head"><div><h1>Eggs</h1><p class="muted">The recipes your servers are cooked from. Install a preset, build your own, or bring an egg from Pterodactyl.</p></div></div>

<div class="egg-bar">
  <input type="search" id="egg-q" placeholder="Search eggs..." autocomplete="off">
  <div class="chips" id="egg-chips">
    <button type="button" class="chip on" data-cat="">All</button>
    <?php foreach (array_unique(array_column($eggs, 'category')) as $cat): ?><button type="button" class="chip" data-cat="<?= e($cat) ?>"><?= e($cat) ?></button><?php endforeach; ?>
  </div>
</div>

<div class="egg-grid" id="egg-grid">
  <?php foreach ($eggs as $g): $vars = json_decode($g['variables'], true) ?: []; ?>
  <article class="egg" data-cat="<?= e($g['category']) ?>" data-text="<?= e(strtolower($g['name'] . ' ' . $g['description'] . ' ' . $g['docker_image'])) ?>">
    <div class="egg-top ban-<?= crc32($g['name']) % 8 ?>"><span class="egg-mark"><?= e(mb_strtoupper(mb_substr($g['name'], 0, 1))) ?></span><span class="badge"><?= e($g['category']) ?></span></div>
    <div class="egg-body">
      <a href="/admin/eggs/<?= (int)$g['id'] ?>" class="egg-name"><?= e($g['name']) ?></a>
      <p class="muted small"><?= e($g['description'] ?: 'No description.') ?></p>
      <div class="mono small egg-img"><?= e($g['docker_image']) ?></div>
      <div class="egg-facts"><span><b><?= count($vars) ?></b> variables</span><span><b><?= (int)$g['in_use'] ?></b> servers</span></div>
    </div>
    <div class="egg-actions">
      <a class="btn sm ghost" href="/admin/eggs/<?= (int)$g['id'] ?>">Edit</a>
      <a class="btn sm ghost" href="/admin/eggs/<?= (int)$g['id'] ?>/export">Export</a>
      <form method="post" action="/admin/eggs/<?= (int)$g['id'] ?>/clone"><?= csrf_field() ?><button class="btn sm ghost" type="submit">Clone</button></form>
      <form method="post" action="/admin/eggs/<?= (int)$g['id'] ?>/delete" data-confirm="Delete this egg?"><?= csrf_field() ?><button class="btn sm danger" type="submit">Delete</button></form>
    </div>
  </article>
  <?php endforeach; ?>
  <?php if (!$eggs): ?><div class="card empty">You do not have any eggs. Install a preset below or build one.</div><?php endif; ?>
</div>

<?php if ($presets): ?>
<section class="card">
  <div class="row between wrap"><h3>Ready-made presets</h3>
    <form method="post" action="/admin/eggs/preset"><?= csrf_field() ?><input type="hidden" name="key" value="all"><button class="btn sm" type="submit">Install all</button></form></div>
  <div class="grid">
    <?php foreach ($presets as $key => $p): ?>
      <form method="post" action="/admin/eggs/preset" class="preset">
        <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>">
        <div class="row between"><b><?= e($p['name']) ?></b><span class="badge"><?= e($p['category']) ?></span></div>
        <p class="muted small"><?= e($p['description']) ?></p>
        <button class="btn sm ghost" type="submit">Install</button>
      </form>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="grid two">
  <details class="reveal card" id="import-egg">
    <summary class="btn ghost">Import an egg (Pterodactyl or Game Panel)</summary>
    <form method="post" action="/admin/eggs/import">
      <?= csrf_field() ?>
      <p class="muted small">Pterodactyl egg files (PTDL_v1 / PTDL_v2) work as they are: image, startup, variables and install script are converted. Install scripts run in a locked-down container.</p>
      <label>Pick a .json file <input type="file" id="egg-file" accept=".json,application/json"></label>
      <label>...or paste the JSON <textarea name="json" id="egg-json" rows="6" class="mono" spellcheck="false" required></textarea></label>
      <button class="btn" type="submit">Import</button>
    </form>
  </details>
  <details class="reveal card" id="build-egg">
    <summary class="btn ghost">Build a custom egg</summary>
    <form method="post" action="/admin/eggs">
      <?= csrf_field() ?>
      <?php $egg = ['category' => 'custom', 'variables' => '[]']; require __DIR__ . '/_egg_form.php'; ?>
      <button class="btn" type="submit">Create egg</button>
    </form>
  </details>
</div>
