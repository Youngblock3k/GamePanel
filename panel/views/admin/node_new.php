<?php $hasLocal = (bool)App\DB::val("SELECT COUNT(*) FROM nodes WHERE fqdn = '127.0.0.1'"); ?>
<?php if (!$hasLocal): ?>
<form class="card local-card" method="post" action="/admin/nodes/local">
  <?= csrf_field() ?>
  <div><h3>Use this machine (localhost)</h3><p class="muted">One click: creates a node for the computer the panel runs on. Memory and disk are measured for you, a location called "local" is made, and one game port (25565) is added. More ports are added automatically as you create servers.</p></div>
  <button class="btn" type="submit">Add localhost node</button>
</form>
<?php endif; ?>
<div class="page-head"><div><h1>New node</h1><p class="muted">Register a machine that runs the daemon and Docker. Capacity, port pool and rules are set here in one go.</p></div><a class="btn ghost" href="/admin/nodes">Cancel</a></div>
<?php if (!$locations): ?><div class="card empty">You need a location first. <a href="/admin/locations/new">Click here to create one.</a></div><?php else: ?>
<form method="post" action="/admin/nodes" id="node-wizard" class="wiz">
  <?= csrf_field() ?>
  <div class="wiz-main">
    <section class="card">
      <h3><span class="step">1</span> Identity</h3>
      <div class="grid two">
        <label>Node name <input name="name" required maxlength="100" placeholder="e.g. Frankfurt-1"></label>
        <label>Location <select name="location_id" required><?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['short']) ?></option><?php endforeach; ?></select></label>
      </div>
      <label>Notes <small class="muted">optional - who owns it, what it is for</small><input name="description" maxlength="255"></label>
    </section>

    <section class="card">
      <h3><span class="step">2</span> Connection</h3>
      <div class="grid three">
        <label>FQDN or IP <input name="fqdn" required class="mono" placeholder="node1.example.com"></label>
        <label>Scheme <select name="scheme"><option value="https">https (recommended)</option><option value="http">http (localhost only)</option></select></label>
        <label>Daemon port <input name="daemon_port" type="number" value="8443" min="1" max="65535"></label>
      </div>
      <p class="muted small">After creating the node you get a one-time secret and a ready-to-paste install command for the machine.</p>
    </section>

    <section class="card">
      <h3><span class="step">3</span> Capacity</h3>
      <div class="tiles sizes">
        <button type="button" class="tile nsize" data-mem="4096" data-disk="50000"><b>4 GB</b><small class="muted">50 GB disk</small></button>
        <button type="button" class="tile nsize" data-mem="8192" data-disk="100000"><b>8 GB</b><small class="muted">100 GB disk</small></button>
        <button type="button" class="tile nsize" data-mem="16384" data-disk="200000"><b>16 GB</b><small class="muted">200 GB disk</small></button>
        <button type="button" class="tile nsize" data-mem="32768" data-disk="500000"><b>32 GB</b><small class="muted">500 GB disk</small></button>
        <button type="button" class="tile nsize" data-mem="65536" data-disk="1000000"><b>64 GB</b><small class="muted">1 TB disk</small></button>
      </div>
      <div class="grid two">
        <label>Memory (MB) <input name="memory" type="number" required min="128" value="8192"></label>
        <label>Disk (MB) <input name="disk" type="number" required min="128" value="100000"></label>
        <label>Memory over-allocation: <b id="nv-mo">0%</b> <input name="mem_over" type="range" min="0" max="100" value="0"></label>
        <label>Disk over-allocation: <b id="nv-do">0%</b> <input name="disk_over" type="range" min="0" max="100" value="0"></label>
      </div>
      <p class="muted small">Over-allocation lets you sell more than the machine has, because servers rarely use their full limit.</p>
    </section>

    <section class="card">
      <h3><span class="step">4</span> Game ports</h3>
      <div class="tiles sizes">
        <button type="button" class="tile pool" data-ports="25565"><b>Minecraft</b><small class="muted">25565</small></button>
        <button type="button" class="tile pool" data-ports="27015"><b>Source / CS2</b><small class="muted">27015</small></button>
        <button type="button" class="tile pool" data-ports="28015"><b>Rust</b><small class="muted">28015</small></button>
        <button type="button" class="tile pool" data-ports="30000"><b>General</b><small class="muted">30000</small></button>
      </div>
      <div class="grid two">
        <label>Bind IP <input name="pool_ip" class="mono" placeholder="0.0.0.0"></label>
        <label>Port or range <input name="pool_ports" class="mono" placeholder="25565-25575"></label>
      </div>
      <p class="muted small">Optional. Up to 100 ports are created now; add more any time on the node page.</p>
    </section>

    <section class="card">
      <h3><span class="step">5</span> Rules</h3>
      <div class="grid two">
        <label>Server limit <small class="muted">0 = unlimited</small><input name="max_servers" type="number" min="0" max="1000" value="0"></label>
        <label class="opt"><input type="checkbox" name="maintenance" value="1"> <b>Start in maintenance mode</b> <span class="muted small">Closed to new servers until you switch it on.</span></label>
      </div>
    </section>
  </div>

  <aside class="wiz-side">
    <div class="card sticky">
      <h3>Summary</h3>
      <dl class="sum">
        <dt>Name</dt><dd id="ns-name">–</dd>
        <dt>Daemon</dt><dd id="ns-addr" class="mono">–</dd>
        <dt>Memory</dt><dd id="ns-mem">–</dd>
        <dt>Disk</dt><dd id="ns-disk">–</dd>
        <dt>Ports</dt><dd id="ns-ports">none yet</dd>
        <dt>Limit</dt><dd id="ns-max">unlimited</dd>
        <dt>Maintenance</dt><dd id="ns-maint">No, accepting servers</dd>
      </dl>
      <button class="btn block" type="submit">Create node</button>
    </div>
  </aside>
</form>
<?php endif; ?>
