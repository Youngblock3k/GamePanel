<?php
use App\Theme;
$looks = Theme::LOOKS;
$looksJson = [];
foreach ($looks as $k => $l) {
    $looksJson[$k] = ['btn' => $l[2], 'card' => $l[3], 'dens' => $l[4], 'font' => $l[5], 'caps' => $l[6], 'radius' => $l[7]];
}
$pickers = ['btn' => 'Button style', 'card' => 'Card style', 'dens' => 'Spacing', 'font' => 'Font'];
?>
<div class="page-head"><div><h1>Branding</h1><p class="muted">Your name and logo, and how the whole panel looks. The copyright footer is fixed.</p></div></div>

<div class="brand-grid">
  <form class="card" method="post" action="/admin/branding">
    <?= csrf_field() ?><input type="hidden" name="action" value="name">
    <h3>Brand name</h3>
    <label>Shown top-left and in the browser tab <input name="brand_name" required maxlength="32" value="<?= e($brandName) ?>"></label>
    <p class="muted small">Letters, numbers, spaces and . &amp; _ - (max 32).</p>
    <button class="btn" type="submit">Save name</button>
  </form>

  <form class="card" method="post" action="/admin/branding" enctype="multipart/form-data" id="logo-form">
    <?= csrf_field() ?>
    <h3>Logo</h3>
    <div class="logo-row">
      <div class="logo-box"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="Current logo"><?php else: ?><svg class="logo"><use href="#logo-v"/></svg><?php endif; ?></div>
      <div><b><?= e($brandName) ?></b><br><small class="muted"><?= $logo ? 'Custom logo' : 'Default icon' ?> · PNG, JPEG or WebP · square works best · max 512 KB</small></div>
    </div>
    <label class="file-pick"><span class="fp-btn">Choose image</span><span class="fp-name" id="logo-name">No file chosen</span>
      <input type="file" name="logo" id="logo-file" accept="image/png,image/jpeg,image/webp"></label>
    <div class="row gap mt">
      <button class="btn" type="submit" name="action" value="logo" id="logo-up" disabled>Upload logo</button>
      <?php if ($logo): ?><button class="btn ghost" type="submit" name="action" value="remove_logo" formnovalidate>Use the default icon</button><?php endif; ?>
    </div>
  </form>
</div>

<form method="post" action="/admin/branding" id="look-form"
      data-looks='<?= e(json_encode($looksJson)) ?>'>
  <?= csrf_field() ?><input type="hidden" name="action" value="theme"><input type="hidden" name="look" id="look-key" value="<?= e($theme['look']) ?>">
  <div class="card">
    <h3>Pick a look</h3>
    <p class="muted small">Start from one of three ready-made looks, then adjust anything below. Every change previews live on the right.</p>
    <div class="look-grid">
      <?php foreach ($looks as $k => $l): ?>
        <label class="look-card"><input type="radio" name="look_pick" value="<?= e($k) ?>" <?= $theme['look'] === $k ? 'checked' : '' ?>>
          <div class="mini m-<?= e($k) ?>"><i></i><u></u></div><b><?= e($l[0]) ?></b><small><?= e($l[1]) ?></small></label>
      <?php endforeach; ?>
      <label class="look-card"><input type="radio" name="look_pick" value="custom" <?= $theme['look'] === 'custom' ? 'checked' : '' ?>>
        <div class="mini m-custom">Your own mix</div><b>Custom</b><small>Set it up yourself. Any change below switches to this.</small></label>
    </div>
  </div>

  <div class="look-layout">
    <div>
      <div class="card">
        <h3>Shape</h3>
        <div class="round-ctl">
          <div class="round-head"><span>Corner roundness</span><b><span id="r-val"><?= (int)$theme['corners'][0] ?></span> px</b></div>
          <input type="range" id="r-all" min="0" max="32" step="1" value="<?= (int)$theme['corners'][0] ?>" aria-label="Corner roundness">
          <div class="round-ticks"><span>Square</span><span>Soft</span><span>Round</span></div>
          <label class="opt mt"><input type="checkbox" id="r-split" <?= count(array_unique($theme['corners'])) > 1 ? 'checked' : '' ?>> <b>Set each corner separately</b></label>
          <div class="corner-split" id="r-corners" <?= count(array_unique($theme['corners'])) > 1 ? '' : 'hidden' ?>>
            <div class="grid two">
              <?php foreach (['rtl' => ['Top left', 0], 'rtr' => ['Top right', 1], 'rbl' => ['Bottom left', 3], 'rbr' => ['Bottom right', 2]] as $n => [$lab, $i]): ?>
                <label><?= $lab ?>: <b data-rv="<?= $n ?>"><?= (int)$theme['corners'][$i] ?></b> px <input type="range" name="<?= $n ?>" min="0" max="32" value="<?= (int)$theme['corners'][$i] ?>"></label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="opt-grid">
          <?php foreach ($pickers as $k => $lab): ?>
            <fieldset><legend><?= e($lab) ?></legend>
              <div class="seg-pick"><?php foreach (Theme::OPTIONS[$k] as $val => $name): ?><label><input type="radio" name="<?= e($k) ?>" value="<?= e($val) ?>" <?= $theme[$k] === $val ? 'checked' : '' ?>><span><?= e($name) ?></span></label><?php endforeach; ?></div>
            </fieldset>
          <?php endforeach; ?>
          <fieldset><legend>Headings &amp; buttons</legend>
            <div class="seg-pick"><label><input type="radio" name="caps" value="0" <?= !$theme['caps'] ? 'checked' : '' ?>><span>Normal case</span></label><label><input type="radio" name="caps" value="1" <?= $theme['caps'] ? 'checked' : '' ?>><span>UPPERCASE</span></label></div>
          </fieldset>
        </div>
      </div>

      <div class="card">
        <h3>Colours</h3>
        <div class="look-grid">
          <?php foreach (Theme::PRESETS as $key => [$label, $pbg, $ppanel, $ptext, $pacc]): ?>
            <label class="look-card"><input type="radio" name="preset" value="<?= e($key) ?>" data-bg="<?= e($pbg) ?>" data-panel="<?= e($ppanel) ?>" data-text="<?= e($ptext) ?>" data-accent="<?= e($pacc) ?>" <?= $theme['preset'] === $key ? 'checked' : '' ?>>
              <span class="sw"><i data-c="<?= e($pbg) ?>"></i><i data-c="<?= e($ppanel) ?>"></i><i data-c="<?= e($pacc) ?>"></i></span><b><?= e($label) ?></b></label>
          <?php endforeach; ?>
        </div>
        <div class="colour-row">
          <label>Background <input type="color" name="bg" value="<?= e($theme['bg']) ?>"></label>
          <label>Panels &amp; cards <input type="color" name="panel" value="<?= e($theme['panel']) ?>"></label>
          <label>Text <input type="color" name="text" value="<?= e($theme['text']) ?>"></label>
          <label>Accent <input type="color" name="accent" value="<?= e($theme['accent']) ?>"></label>
        </div>
        <p class="muted small">Text must stay readable, so very low-contrast colours are refused.</p>
      </div>
      <div class="row gap mb"><button class="btn" type="submit">Save look</button></div>
    </div>

    <aside class="look-prev" id="look-prev" aria-label="Live preview">
      <div class="lp-nav"><span class="on">Servers</span><span>Nodes</span><span>Eggs</span></div>
      <div class="lp-card"><h4>Survival world</h4><p>Minecraft · 4 GB · 25565</p><span class="lp-badge">Running</span></div>
      <div class="lp-card"><h4>Create server</h4><span class="lp-in">Server name</span><span class="lp-btn">Create</span><span class="lp-btn g">Cancel</span></div>
    </aside>
  </div>
</form>

<div class="card row gap">
  <form method="post" action="/admin/branding" data-confirm="Reset colours, corners and style to the default look?"><?= csrf_field() ?><input type="hidden" name="action" value="theme_reset"><button class="btn ghost" type="submit">Reset look only</button></form>
  <form method="post" action="/admin/branding" data-confirm="Reset the name, logo and look to the defaults?"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><button class="btn danger" type="submit">Reset everything to default</button></form>
</div>
