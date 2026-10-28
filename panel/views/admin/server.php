<?php $sid = (int)$s['id']; ?>
<div class="page-head"><div><h1><?= e($s['name']) ?> <span class="pill <?= $s['status'] === 'suspended' ? 'warn' : 'ok' ?>"><?= e(str_replace('_', ' ', $s['status'])) ?></span></h1>
  <p class="muted"><?= e($s['egg_name']) ?> · owned by <?= e($s['owner_name']) ?> · node <?= e($s['node_name']) ?></p></div>
  <div class="row gap"><a class="btn ghost" href="/server/<?= e($s['uuid']) ?>">Open console</a><a class="btn ghost" href="/admin/servers">← All servers</a></div></div>

<div class="mon-top">
  <div class="card stat"><label>Memory</label><b><?= e(fmt_mb($s['memory'])) ?></b></div>
  <div class="card stat"><label>CPU</label><b><?= (int)$s['cpu'] === 0 ? 'Unlimited' : (int)$s['cpu'] . '%' ?></b></div>
  <div class="card stat"><label>Disk</label><b><?= e(fmt_mb($s['disk'])) ?></b></div>
  <div class="card stat"><label>Address</label><b class="mono"><?= e($s['fqdn']) ?>:<?= (int)$s['a_port'] ?></b></div>
</div>

<div class="card">
  <h3>Databases</h3>
  <?php foreach ($dbs as $d): $pw = \App\Security::decrypt($d['db_pass']); $fresh = (int)$newDb === (int)$d['id']; ?>
    <div class="card inner">
      <div class="row between"><b class="mono"><?= e($d['db_name']) ?></b><span class="muted small">on <?= e($d['host_name']) ?> · allowed from <span class="mono"><?= e($d['remote']) ?></span> · <?= time_tag((int)$d['created_at']) ?></span></div>
      <dl class="kv">
        <dt>Host</dt><dd class="mono"><?= e($d['host']) ?>:<?= (int)$d['port'] ?></dd>
        <dt>User</dt><dd class="mono"><?= e($d['db_user']) ?></dd>
        <dt>Password</dt><dd class="mono"><details <?= $fresh ? 'open' : '' ?>><summary>Show</summary><?= e($pw) ?></details></dd>
        <dt>Connection</dt><dd class="mono small break">mysql://<?= e($d['db_user']) ?>:••••@<?= e($d['host']) ?>:<?= (int)$d['port'] ?>/<?= e($d['db_name']) ?></dd>
      </dl>
      <div class="row gap wrap">
        <form method="post" action="/admin/servers/<?= $sid ?>/databases/<?= (int)$d['id'] ?>/rotate" class="row gap" data-confirm="Generate a new password? The old one stops working.">
          <?= csrf_field() ?><input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password"><button class="btn sm ghost" type="submit">New password</button></form>
        <form method="post" action="/admin/servers/<?= $sid ?>/databases/<?= (int)$d['id'] ?>/delete" class="row gap" data-confirm="Delete this database and everything in it?">
          <?= csrf_field() ?><input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password">
          <label class="check"><input type="checkbox" name="force" value="1"> Forget it anyway</label><button class="btn sm danger" type="submit">Delete</button></form>
      </div>
    </div>
  <?php endforeach; if (!$dbs): ?><p class="muted">This server has no databases yet.</p><?php endif; ?>
</div>

<form class="card" method="post" action="/admin/servers/<?= $sid ?>/databases">
  <?= csrf_field() ?>
  <h3>Create a database</h3>
  <?php if (!$hosts): ?><p class="muted">Add a <a href="/admin/databases">database host</a> first.</p>
  <?php else: ?>
  <div class="grid three">
    <label>Host <select name="host_id"><?php foreach ($hosts as $h): ?><option value="<?= (int)$h['id'] ?>"><?= e($h['name']) ?> (<?= e($h['host']) ?>)</option><?php endforeach; ?></select></label>
    <label>Name <small class="muted">becomes s<?= $sid ?>_name</small><input name="label" required maxlength="24" pattern="[a-z0-9_]{1,24}" class="mono" placeholder="data"></label>
    <label>Allowed from <small class="muted">% = anywhere</small><input name="remote" value="%" class="mono" maxlength="45"></label>
  </div>
  <button class="btn" type="submit">Create database</button>
  <?php endif; ?>
</form>
