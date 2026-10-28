<?php
$uptime = static function (?int $s): string {
    if ($s === null) { return '—'; }
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    return ($d ? $d . ' d ' : '') . ($h || $d ? $h . ' h ' : '') . $m . ' min';
};
$pct = static fn(float $a, float $b): float => $b > 0 ? $a / $b * 100 : 0;
$hwList = static function (array $h): string {
    $o = '<dl class="hw">';
    if (!empty($h['cpu'])) { $o .= '<dt>Processor</dt><dd>' . e($h['cpu']) . '</dd>'; }
    if (!empty($h['cores'])) { $o .= '<dt>Threads</dt><dd>' . (int)$h['cores'] . '</dd>'; }
    if (!empty($h['ram_mb'])) { $o .= '<dt>Memory</dt><dd>' . e(fmt_mb($h['ram_mb'])) . '</dd>'; }
    $o .= '<dt>Graphics</dt><dd>' . (!empty($h['gpus']) ? e(implode(', ', $h['gpus'])) : 'None detected') . '</dd>';
    if (!empty($h['os'])) { $o .= '<dt>System</dt><dd>' . e($h['os']) . '</dd>'; }
    return $o . '</dl>';
};
?>
<div class="page-head"><div><h1>Monitoring</h1><p class="muted">Health of the panel, its nodes and servers. Reload for fresh numbers.</p></div><a class="btn ghost" href="/admin/monitoring">Refresh</a></div>

<div class="mon-top">
  <div class="card stat"><label>Servers running</label><b><?= (int)$running ?> / <?= (int)$servers ?></b><small class="muted"><?= (int)$suspended ?> suspended</small></div>
  <div class="card stat <?= $online === count($nodes) ? 'good' : 'bad' ?>"><label>Nodes reachable</label><b><?= (int)$online ?> / <?= count($nodes) ?></b></div>
  <div class="card stat <?= $alerts ? '' : 'good' ?>"><label>Open alerts</label><b><?= count(array_filter($alerts, static fn($a) => $a[0] !== 'dim')) ?></b></div>
  <div class="card stat"><label>Failed sign-ins, 24 h</label><b><?= (int)$failed ?></b></div>
  <div class="card stat"><label>Accounts · Databases</label><b><?= (int)$users ?> · <?= (int)$dbs ?></b></div>
</div>

<h3>Nodes</h3>
<div class="mon-nodes">
  <?php foreach ($nodes as $n): $s = $n['sys']; $h = $s['host'] ?? []; $capM = $n['memory'] * (100 + (int)$n['mem_over']) / 100; $capD = $n['disk'] * (100 + (int)$n['disk_over']) / 100; ?>
    <div class="card">
      <div class="row between"><h3><a href="/admin/nodes/<?= (int)$n['id'] ?>"><?= e($n['name']) ?></a></h3>
        <span><?php if (!empty($n['maintenance'])): ?><span class="pill warn">Maintenance</span> <?php endif; ?><span class="pill <?= $s ? 'ok' : 'bad' ?>"><?= $s ? 'Online' : 'Offline' ?></span></span></div>
      <p class="muted small"><?= e($n['location']) ?> · <?= (int)$n['servers'] ?> server<?= (int)$n['servers'] === 1 ? '' : 's' ?><?= $s ? ' · ' . (int)($s['running'] ?? 0) . ' running' : '' ?></p>
      <div class="donuts">
        <?= donut($pct((float)$n['used_mem'], (float)$capM), 'Memory sold', fmt_mb($n['used_mem']) . ' of ' . fmt_mb($capM)) ?>
        <?= donut($pct((float)$n['used_disk'], (float)$capD), 'Disk sold', fmt_mb($n['used_disk']) . ' of ' . fmt_mb($capD)) ?>
        <?php if ($s && isset($h['mem_total_mb'], $h['mem_avail_mb'])): ?><?= donut($pct((float)($h['mem_total_mb'] - $h['mem_avail_mb']), (float)$h['mem_total_mb']), 'Host memory', fmt_mb($h['mem_total_mb'] - $h['mem_avail_mb']) . ' used') ?><?php endif; ?>
        <?php if ($s && isset($s['disk_total_mb'])): ?><?= donut($pct((float)($s['disk_total_mb'] - $s['disk_free_mb']), (float)$s['disk_total_mb']), 'Host disk', fmt_mb($s['disk_total_mb'] - $s['disk_free_mb']) . ' used') ?><?php endif; ?>
        <?php if ($s && !empty($s['load']) && !empty($h['cpus'])): ?><?= donut($pct((float)$s['load'][0], (float)$h['cpus']), 'CPU load', number_format((float)$s['load'][0], 2) . ' of ' . (int)$h['cpus'] . ' threads') ?><?php endif; ?>
      </div>
      <?php if ($s): ?>
        <?= $hwList(['cpu' => $h['cpu'] ?? '', 'cores' => $h['cpus'] ?? 0, 'ram_mb' => $h['mem_total_mb'] ?? 0, 'gpus' => $h['gpus'] ?? [], 'os' => $h['os'] ?? '']) ?>
        <dl class="kv"><dt>Uptime</dt><dd><?= e($uptime($h['uptime_s'] ?? null)) ?></dd><dt>Docker · daemon</dt><dd><?= e($s['docker'] ?? '—') ?> · v<?= e($s['version'] ?? '—') ?></dd></dl>
      <?php else: ?>
        <div class="note bad">The daemon did not answer. Check that it is running and that the panel IP is allowed through the firewall.</div>
      <?php endif; ?>
    </div>
  <?php endforeach; if (!$nodes): ?><div class="card empty">No nodes yet. <a href="/admin/nodes/new">Create one.</a></div><?php endif; ?>
</div>

<h3>Servers</h3>
<div class="srv-mon">
  <?php foreach ($srv as $sv): $r = $sv['res']; $cpuCap = (int)$sv['cpu'] > 0 ? (int)$sv['cpu'] : 100 * max(1, $sv['node_cores']); ?>
    <div class="card">
      <div class="row between"><h3><a href="/admin/servers/<?= (int)$sv['id'] ?>"><?= e($sv['name']) ?></a></h3>
        <span class="pill <?= $r ? (($r['state'] ?? '') === 'running' ? 'ok' : 'warn') : 'bad' ?>"><?= $r ? e($r['state'] ?? 'unknown') : 'no data' ?></span></div>
      <?php if ($r): ?>
        <div class="donuts">
          <?= donut($pct((float)($r['mem'] ?? 0), (float)($r['mem_limit'] ?: $sv['memory'])), 'Memory', fmt_mb($r['mem'] ?? 0) . ' of ' . fmt_mb($r['mem_limit'] ?: $sv['memory'])) ?>
          <?= donut($pct((float)($r['cpu'] ?? 0), (float)$cpuCap), 'CPU', number_format((float)($r['cpu'] ?? 0), 1) . '%' . ((int)$sv['cpu'] > 0 ? ' of ' . (int)$sv['cpu'] . '%' : ' (no limit)')) ?>
          <?= donut($pct((float)($r['disk'] ?? 0), (float)$sv['disk']), 'Disk', fmt_mb($r['disk'] ?? 0) . ' of ' . fmt_mb($sv['disk'])) ?>
        </div>
      <?php else: ?><p class="muted small">Live numbers are not available: the node is offline or too many servers were queried. Limits: <?= e(fmt_mb($sv['memory'])) ?> RAM, <?= e(fmt_mb($sv['disk'])) ?> disk.</p><?php endif; ?>
    </div>
  <?php endforeach; if (!$srv): ?><div class="card empty">No servers yet.</div><?php endif; ?>
</div>

<h3>Alerts</h3>
<div class="card">
  <?php foreach ($alerts as [$lvl, $msg]): ?><div class="note <?= e($lvl) ?>"><svg class="ico"><use href="#i-alert"/></svg><span><?= e($msg) ?></span></div>
  <?php endforeach; if (!$alerts): ?><p class="muted center">No alerts. Everything is fine.</p><?php endif; ?>
</div>

<div class="card">
  <h3>This machine (the panel)</h3>
  <?php $pn = $panel; ?>
  <div class="donuts">
    <?php if ($pn['mem']): ?><?= donut($pct((float)$pn['mem'][0], (float)$pn['mem'][1]), 'Memory', fmt_mb($pn['mem'][0]) . ' of ' . fmt_mb($pn['mem'][1])) ?><?php endif; ?>
    <?= donut($pct((float)$pn['disk'][0], (float)$pn['disk'][1]), 'Storage', fmt_mb($pn['disk'][0]) . ' of ' . fmt_mb($pn['disk'][1])) ?>
    <?php if ($pn['load'] && !empty($hw['cores'])): ?><?= donut($pct((float)$pn['load'][0], (float)$hw['cores']), 'CPU load', number_format((float)$pn['load'][0], 2) . ' of ' . (int)$hw['cores'] . ' threads') ?><?php endif; ?>
  </div>
  <?= $hwList($hw) ?>
  <dl class="kv"><dt>Panel version</dt><dd><?= e($pn['version']) ?></dd><dt>PHP</dt><dd><?= e($pn['php']) ?></dd><dt>Database</dt><dd><?= e($pn['db']) ?></dd><dt>Host uptime</dt><dd><?= e($uptime($pn['up'])) ?></dd></dl>
</div>
