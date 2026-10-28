<?php
$nid = (int)$node['id'];
$base = '/admin/nodes/' . $nid;
$tabs = ['overview' => 'Overview', 'allocations' => 'Allocations (' . count($allocations) . ')', 'servers' => 'Servers (' . count($servers) . ')', 'settings' => 'Settings', 'config' => 'Configuration'];
$local = in_array($node['fqdn'], ['127.0.0.1', 'localhost'], true);
$capM = $node['memory'] * (100 + (int)$node['mem_over']) / 100;
$capD = $node['disk'] * (100 + (int)$node['disk_over']) / 100;
$ip = $panelIp !== '' ? $panelIp : '<this panel IP>';
?>
<div class="page-head"><div><h1><?= e($node['name']) ?> <span class="pill <?= $sys ? 'ok' : 'bad' ?>"><?= $sys ? 'Online' : 'Offline' ?></span><?php if (!empty($node['maintenance'])): ?> <span class="pill warn">Maintenance</span><?php endif; ?></h1>
  <p class="muted mono"><?= e($node['scheme']) ?>://<?= e($node['fqdn']) ?>:<?= (int)$node['daemon_port'] ?> · <?= e($node['location']) ?></p></div>
  <div class="row gap"><form method="post" action="<?= e($base) ?>/maintenance"><?= csrf_field() ?><button class="btn ghost" type="submit"><?= !empty($node['maintenance']) ? 'End maintenance' : 'Maintenance mode' ?></button></form><a class="btn ghost" href="/admin/nodes">← All nodes</a></div></div>

<nav class="tabbar" aria-label="Node sections"><?php foreach ($tabs as $k => $label): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= e($base) ?>?tab=<?= e($k) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>

<?php if ($tab === 'overview'): ?>
<div class="grid stats">
  <div class="card stat"><label>Memory allocated</label><b><?= e(fmt_mb($used['mem'])) ?></b><small class="muted">of <?= e(fmt_mb($capM)) ?> sellable</small>
    <div class="bar"><i data-w="<?= min(100, round($used['mem'] / max(1, $capM) * 100)) ?>"></i></div></div>
  <div class="card stat"><label>Disk allocated</label><b><?= e(fmt_mb($used['disk'])) ?></b><small class="muted">of <?= e(fmt_mb($capD)) ?> sellable</small>
    <div class="bar"><i data-w="<?= min(100, round($used['disk'] / max(1, $capD) * 100)) ?>"></i></div></div>
  <div class="card stat"><label>Servers</label><b><?= count($servers) ?><?= (int)$node['max_servers'] ? ' / ' . (int)$node['max_servers'] : '' ?></b><small class="muted"><?= (int)($sys['running'] ?? 0) ?> running</small></div>
  <div class="card stat"><label>Free ports</label><b><?= count(array_filter($allocations, static fn($a) => $a['server_id'] === null)) ?></b><small class="muted">of <?= count($allocations) ?></small></div>
  <div class="card stat"><label>Docker</label><b><?= e($sys['docker'] ?? '—') ?></b><small class="muted">daemon v<?= e($sys['version'] ?? '—') ?></small></div>
  <div class="card stat"><label>Free disk on node</label><b><?= isset($sys['disk_free_mb']) ? e(fmt_mb($sys['disk_free_mb'])) : '—' ?></b></div>
</div>
<?php if ($sysErr): ?><div class="alert err">Cannot reach the daemon: <?= e($sysErr) ?>. See the <a href="<?= e($base) ?>?tab=config">Configuration</a> tab to install or reconnect it.</div><?php endif; ?>
<?php if ($node['description']): ?><div class="card"><h3>Notes</h3><p><?= e($node['description']) ?></p></div><?php endif; ?>

<?php elseif ($tab === 'allocations'): ?>
<form method="post" action="<?= e($base) ?>/allocations" class="card">
  <?= csrf_field() ?>
  <h3>Add ports</h3>
  <p class="muted small">One port or a range. You rarely need this: when you create a server and its port is taken, the next free number is added for you.</p>
  <div class="row gap wrap">
    <input name="ip" class="mono" placeholder="IP (e.g. 0.0.0.0)" value="<?= e((string)($allocations[0]['ip'] ?? '0.0.0.0')) ?>" required>
    <input name="ports" class="mono" placeholder="25565 or 25565-25570" required>
    <button class="btn" type="submit">Add</button>
  </div>
</form>
<div class="card">
  <table class="table">
    <thead><tr><th>IP</th><th>Port</th><th>Assigned to</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($allocations as $a): ?>
      <tr><td class="mono"><?= e($a['ip']) ?></td><td class="mono"><?= (int)$a['port'] ?></td>
        <td><?= $a['server_name'] ? '<a href="/admin/servers/' . (int)$a['server_id'] . '">' . e($a['server_name']) . '</a>' : '<span class="muted">free</span>' ?></td>
        <td class="r"><?php if ($a['server_id'] === null): ?><form method="post" action="<?= e($base) ?>/allocations/<?= (int)$a['id'] ?>/delete"><?= csrf_field() ?><button class="btn sm ghost" type="submit">Remove</button></form><?php endif; ?></td></tr>
    <?php endforeach; if (!$allocations): ?><tr><td colspan="4" class="muted center">No ports yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'servers'): ?>
<div class="card">
  <table class="table">
    <thead><tr><th>Server</th><th>Owner</th><th>Port</th><th>Memory</th><th>Disk</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($servers as $s): ?>
      <tr><td><a href="/admin/servers/<?= (int)$s['id'] ?>"><b><?= e($s['name']) ?></b></a></td><td><?= e($s['username']) ?></td><td class="mono"><?= (int)$s['port'] ?></td><td><?= e(fmt_mb($s['memory'])) ?></td><td><?= e(fmt_mb($s['disk'])) ?></td><td><?= e(str_replace('_', ' ', $s['status'])) ?></td></tr>
    <?php endforeach; if (!$servers): ?><tr><td colspan="6" class="muted center">No servers on this node. <a href="/admin/servers/new">Create one.</a></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'settings'): ?>
<form class="card" method="post" action="<?= e($base) ?>/update">
  <?= csrf_field() ?>
  <h3>Identity</h3>
  <div class="grid two">
    <label>Name <input name="name" required maxlength="100" value="<?= e($node['name']) ?>"></label>
    <label>Location <select name="location_id"><?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === (int)$node['location_id'] ? 'selected' : '' ?>><?= e($l['short']) ?></option><?php endforeach; ?></select></label>
  </div>
  <label>Notes <input name="description" maxlength="255" value="<?= e($node['description']) ?>"></label>
  <h3>Daemon connection</h3>
  <div class="grid three">
    <label>Address <input name="fqdn" class="mono" required value="<?= e($node['fqdn']) ?>"></label>
    <label>Scheme <select name="scheme"><option value="https" <?= $node['scheme'] === 'https' ? 'selected' : '' ?>>https</option><option value="http" <?= $node['scheme'] === 'http' ? 'selected' : '' ?>>http (localhost only)</option></select></label>
    <label>Port <input type="number" name="daemon_port" min="1" max="65535" required value="<?= (int)$node['daemon_port'] ?>"></label>
  </div>
  <h3>Capacity</h3>
  <div class="grid three">
    <label>Memory (MB) <input type="number" name="memory" min="128" required value="<?= (int)$node['memory'] ?>"></label>
    <label>Disk (MB) <input type="number" name="disk" min="128" required value="<?= (int)$node['disk'] ?>"></label>
    <label>Server limit <small class="muted">0 = none</small><input type="number" name="max_servers" min="0" value="<?= (int)$node['max_servers'] ?>"></label>
    <label>Memory over-allocation % <input type="number" name="mem_over" min="0" max="100" value="<?= (int)$node['mem_over'] ?>"></label>
    <label>Disk over-allocation % <input type="number" name="disk_over" min="0" max="100" value="<?= (int)$node['disk_over'] ?>"></label>
  </div>
  <button class="btn" type="submit">Save settings</button>
</form>
<form class="card" method="post" action="/admin/nodes/<?= $nid ?>/delete" data-confirm="Delete this node? It must have no servers.">
  <?= csrf_field() ?><h3>Delete node</h3><p class="muted small">Only possible when no servers are on it.</p>
  <div class="row gap"><input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password"><button class="btn danger" type="submit">Delete node</button></div>
</form>

<?php else: ?>
<?php if ($secret): ?>
<div class="card warn-card">
  <h3>Install the daemon</h3>
  <p>Run this on the node (Ubuntu, as root, from the release folder). The secret is shown once.</p>
  <pre class="mono codes break"><?php if ($local): ?>sudo bash install/ubuntu.sh daemon --local --secret <?= e($secret) ?><?php else: ?>sudo bash install/ubuntu.sh daemon --fqdn <?= e($node['fqdn']) ?> --secret <?= e($secret) ?> --panel-ip <?= e($ip) ?><?php endif; ?></pre>
  <h3>Daemon secret</h3>
  <pre class="mono codes break"><?= e($secret) ?></pre>
  <p class="muted small">If you install by hand, put it in <span class="mono">daemon/config.php</span> as <span class="mono">'secret'</span>. It is stored encrypted in the panel and cannot be shown again.</p>
</div>
<?php endif; ?>
<div class="card">
  <h3>How to connect this node</h3>
  <ol class="steps-list">
    <li>Copy the release folder to <?= $local ? 'this machine' : 'the node (Ubuntu 22.04 or newer)' ?>.</li>
    <li>Run the install command <?= $secret ? 'above' : 'for this node (rotate the secret below to get a new one)' ?>. It installs Docker, the daemon, nginx<?= $local ? '' : ' with a certificate' ?> and the firewall rules.</li>
    <li>Come back here: the node turns <b>Online</b> on the Overview tab within a few seconds.</li>
  </ol>
  <?php if (!$local): ?><p class="muted small">Replace <span class="mono"><?= e($ip) ?></span> with this panel's public IP if it is wrong. Only that address may reach the daemon on port <?= (int)$node['daemon_port'] ?>.</p><?php endif; ?>
</div>
<form class="card" method="post" action="<?= e($base) ?>/rotate" data-confirm="Create a new secret? The node stops answering until you update it.">
  <?= csrf_field() ?>
  <h3>Rotate the secret</h3>
  <p class="muted small">Use this if the secret leaked or you lost it. The old one stops working immediately.</p>
  <div class="row gap"><input type="password" name="current_password" placeholder="Your password" required class="sm-input" autocomplete="current-password"><button class="btn ghost" type="submit">Generate a new secret</button></div>
</form>
<?php endif; ?>
