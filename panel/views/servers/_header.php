<div class="page-head">
  <div>
    <h1><?= e($s['name']) ?> <span class="badge" id="state-badge" data-state="unknown">…</span></h1>
    <p class="muted"><span class="mono"><?= e($s['addr']) ?></span> <button type="button" class="copy-btn" data-copy="<?= e($s['addr']) ?>" aria-label="Copy address" title="Copy address"><svg class="ico"><use href="#i-copy"/></svg></button> · <?= e($s['node_name']) ?> · <?= e($s['egg_name']) ?></p>
  </div>
</div>
