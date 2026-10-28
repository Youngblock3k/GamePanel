<?php $have = $role['perms'] ?? []; $color = $role['color'] ?? App\Roles::COLORS[0]; ?>
<div class="page-head"><div><h1><?= $role ? 'Edit role' : 'New role' ?></h1><p class="muted">Choose a colour and tick what members of this role may do. Anything unticked stays hidden or blocked.</p></div><a class="btn ghost" href="/admin/roles">Cancel</a></div>

<form class="card" method="post" action="<?= $role ? '/admin/roles/' . (int)$role['id'] : '/admin/roles' ?>" id="role-form">
  <?= csrf_field() ?>
  <h3>Identity</h3>
  <div class="grid two">
    <label>Name <input name="name" required maxlength="60" value="<?= e($role['name'] ?? '') ?>" placeholder="e.g. Support"></label>
    <label>Description <small class="muted">optional</small><input name="description" maxlength="191" value="<?= e($role['description'] ?? '') ?>"></label>
  </div>
  <div class="color-pick">
    <span class="muted small">Colour</span>
    <div class="swatches"><?php foreach (App\Roles::COLORS as $c): ?><button type="button" class="swatch<?= strcasecmp($c, $color) === 0 ? ' on' : '' ?>" data-color="<?= e($c) ?>" aria-label="<?= e($c) ?>"><i data-c="<?= e($c) ?>"></i></button><?php endforeach; ?></div>
    <label class="color-custom">Custom <input type="color" name="color" id="role-color" value="<?= e($color) ?>"></label>
    <span class="role-pill" id="role-pill" data-rc="<?= e($color) ?>"><?= e($role['name'] ?? 'Role name') ?></span>
  </div>

  <h3>Permissions</h3>
  <div class="row gap wrap mb">
    <span class="muted small">Quick start:</span>
    <?php foreach (App\Roles::PRESETS as $label => $list): ?><button type="button" class="btn sm ghost perm-preset" data-perms="<?= e(implode(',', $list)) ?>"><?= e($label) ?></button><?php endforeach; ?>
    <button type="button" class="btn sm ghost perm-preset" data-perms="*">Select all</button>
    <button type="button" class="btn sm ghost perm-preset" data-perms="">Clear</button>
  </div>
  <div class="perm-groups">
    <?php foreach (App\Roles::GROUPS as $gname => $keys): ?>
      <fieldset class="perm-group"><legend><?= e($gname) ?></legend>
        <?php foreach ($keys as $k): [$label, $help] = App\Roles::PERMS[$k]; ?>
          <label class="perm<?= str_contains($k, '.') ? ' sub' : '' ?>"><input type="checkbox" name="perms[]" value="<?= e($k) ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>><span><b><?= e($label) ?></b><small><?= e($help) ?></small></span></label>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Ticking an action also ticks the matching "View" permission, because people need to see the page first.</p>
  <button class="btn" type="submit"><?= $role ? 'Save role' : 'Create role' ?></button>
</form>
