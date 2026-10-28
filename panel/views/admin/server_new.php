<div class="page-head"><div><h1>New server</h1><p class="muted">Pick a game, choose a size, drop it on a node. Everything is editable later.</p></div><a class="btn ghost" href="/admin/servers">Cancel</a></div>

<?php if (!$nodes || !$eggs): ?>
  <div class="card empty">You need at least one <a href="/admin/nodes">node</a> and one <a href="/admin/eggs">egg</a> first.</div>
<?php else: ?>
<form method="post" action="/admin/servers" id="create-server" class="wiz"
      data-eggs='<?= e(json_encode(array_column($eggs, 'variables', 'id'))) ?>' data-free='<?= e(json_encode($free)) ?>' data-nodes='<?= e(json_encode($nodes)) ?>'>
  <?= csrf_field() ?>
  <div class="wiz-main">
    <section class="card">
      <h3><span class="step">1</span> Choose an egg</h3>
      <div class="grid two egg-sel">
        <label>Nest <small class="muted">the group the egg belongs to</small><select id="nest-sel"><option value="">All nests</option><?php foreach (array_unique(array_column($eggs, 'category')) as $nest): ?><option value="<?= e($nest) ?>"><?= e(ucfirst($nest)) ?></option><?php endforeach; ?></select></label>
        <label>Egg <small class="muted">what the server runs</small><select id="egg-sel"><?php foreach ($eggs as $g): ?><option value="<?= (int)$g['id'] ?>" data-nest="<?= e($g['category']) ?>"><?= e($g['name']) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="muted small" id="egg-desc"></p>
      <div class="tiles">
        <?php foreach ($eggs as $i => $g): [$gcls, $gsym] = App\Ui\Assets::game($g); ?>
          <label class="tile game"><input type="radio" name="egg_id" value="<?= (int)$g['id'] ?>" data-name="<?= e($g['name']) ?>" data-nest="<?= e($g['category']) ?>" data-desc="<?= e($g['description'] ?? '') ?>" <?= $i === 0 ? 'checked' : '' ?>>
            <span class="tile-body"><span class="g-banner <?= e($gcls) ?>"><svg class="ico g-ico<?= preg_match('/^[sa]-/', $gsym) ? ' g-fill' : '' ?>"><use href="#<?= e($gsym) ?>"/></svg></span><span class="g-text"><b><?= e($g['name']) ?></b></span></span></label>
        <?php endforeach; ?>
      </div>
      <div id="egg-vars" class="grid three"></div>
    </section>

    <section class="card">
      <h3><span class="step">2</span> Name it</h3>
      <div class="grid two">
        <label>Server name <span class="row gap"><input name="name" id="srv-name" required maxlength="100" placeholder="e.g. Friday Survival"><button type="button" class="btn ghost" id="rand-name">Random</button></span></label>
        <label>Owner <select name="owner_id"><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$user['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?></select></label>
      </div>
      <label>Description <small class="muted">optional</small><input name="description" maxlength="255"></label>
    </section>

    <section class="card">
      <h3><span class="step">3</span> Choose a size</h3>
      <div class="tiles sizes">
        <button type="button" class="tile size" data-mem="1024" data-cpu="100" data-disk="5000"><b>Small</b><small class="muted">1 GB · 1 core · 5 GB</small></button>
        <button type="button" class="tile size" data-mem="2048" data-cpu="200" data-disk="10000"><b>Medium</b><small class="muted">2 GB · 2 cores · 10 GB</small></button>
        <button type="button" class="tile size" data-mem="4096" data-cpu="300" data-disk="20000"><b>Large</b><small class="muted">4 GB · 3 cores · 20 GB</small></button>
        <button type="button" class="tile size" data-mem="8192" data-cpu="400" data-disk="40000"><b>Huge</b><small class="muted">8 GB · 4 cores · 40 GB</small></button>
      </div>
      <div class="grid three">
        <label>Memory (MB) <input type="number" name="memory" id="f-mem" min="64" required value="1024"></label>
        <label>CPU limit (%) <small class="muted">100 = 1 core, 0 = unlimited</small><input type="number" name="cpu" id="f-cpu" min="0" max="6400" required value="100"></label>
        <label>Disk (MB) <input type="number" name="disk" id="f-disk" min="128" required value="5000"></label>
      </div>
    </section>

    <section class="card">
      <h3><span class="step">4</span> Place it</h3>
      <div class="grid two">
        <label>Node <select name="node_id" id="sel-node"><?php foreach ($nodes as $n): ?><option value="<?= (int)$n['id'] ?>" <?= !empty($n['maintenance']) ? 'disabled' : '' ?> <?= $n['name'] === 'localhost' ? 'selected' : '' ?>><?= e($n['name']) ?><?= $n['loc'] ? ' · ' . e($n['loc']) : '' ?><?= !empty($n['maintenance']) ? ' (maintenance)' : '' ?></option><?php endforeach; ?></select></label>
        <label>Port <select name="allocation_id" id="sel-alloc"></select></label>
      </div>
      <label id="custom-port-row" hidden>Custom port <small class="muted">1024-65535, created on the node if it does not exist yet</small><input type="number" name="custom_port" id="custom-port" min="1024" max="65535" placeholder="e.g. 27015"></label>
      <label class="opt"><input type="checkbox" name="oom_kill" value="1" checked> <span><b>Out-of-memory killer</b><br><small class="muted">If the server goes over its RAM limit, it is stopped. Turn off to let it hang instead of being killed.</small></span></label>
    </section>
  </div>

  <aside class="wiz-side">
    <div class="card sticky">
      <h3>Summary</h3>
      <dl class="sum">
        <dt>Game</dt><dd id="s-egg">–</dd>
        <dt>Name</dt><dd id="s-name">–</dd>
        <dt>Size</dt><dd id="s-size">–</dd>
        <dt>Address</dt><dd id="s-addr" class="mono">–</dd>
      </dl>
      <div class="meter"><label>Node memory after this <span id="s-mem-t"></span></label><div class="bar"><i id="s-mem" data-w="0"></i></div></div>
      <div class="meter"><label>Node disk after this <span id="s-disk-t"></span></label><div class="bar"><i id="s-disk" data-w="0"></i></div></div>
      <p class="small warn" id="s-warn" hidden>This node does not have enough room.</p>
      <button class="btn block" type="submit">Create server</button>
    </div>
  </aside>
</form>
<?php endif; ?>
