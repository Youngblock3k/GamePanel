<?php require __DIR__ . '/_header.php'; ?>
<div id="srv" data-base="/server/<?= e($s['uuid']) ?>" data-memory="<?= (int)$s['memory'] ?>" data-status="<?= e($s['status']) ?>">
  <div class="grid stats">
    <div class="card stat"><label>CPU</label><b data-stat="cpu">–</b><svg class="spark" data-chart="cpu" viewBox="0 0 120 32" preserveAspectRatio="none"></svg></div>
    <div class="card stat"><label>Memory</label><b data-stat="mem">–</b><small class="muted">of <?= e(fmt_mb($s['memory'])) ?></small><svg class="spark" data-chart="mem" viewBox="0 0 120 32" preserveAspectRatio="none"></svg></div>
    <div class="card stat"><label>Disk</label><b data-stat="disk">–</b><small class="muted">of <?= e(fmt_mb($s['disk'])) ?></small><div class="bar"><i data-w="0" data-bar="disk"></i></div></div>
    <div class="card stat"><label>Network</label><b data-stat="net">–</b><small class="muted">↓ in / ↑ out</small></div>
  </div>

  <div class="card">
    <div class="row between wrap">
      <b>Console</b>
      <div class="row gap">
        <button class="btn sm ok" data-power="start">Start</button>
        <button class="btn sm" data-power="restart">Restart</button>
        <button class="btn sm ghost" data-power="stop">Stop</button>
        <button class="btn sm danger" data-power="kill">Kill</button>
      </div>
    </div>
    <pre id="console" class="console" tabindex="0" aria-live="polite"></pre>
    <form id="cmd-form" class="row gap" autocomplete="off">
      <input id="cmd" class="mono grow" type="text" maxlength="512" placeholder="Type a command and press Enter" <?= $s['status'] !== 'active' ? 'disabled' : '' ?>>
      <button class="btn" type="submit">Send</button>
    </form>
    <p id="install-note" class="small warn"></p>
  </div>
</div>
