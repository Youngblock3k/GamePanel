<div class="page-head"><div><h1>Settings</h1><p class="muted">Panel-wide options.</p></div></div>
<form class="card narrow" method="post" action="/admin/settings">
  <?= csrf_field() ?>
  <h3>Require 2-factor authentication</h3>
  <p class="muted small">Accounts that must use 2FA are sent to the set-up page until it is enabled, and cannot turn it off.</p>
  <?php foreach (['0' => ['Not required', '2FA is optional for everyone.'], '1' => ['Administrators only', 'Only administrator accounts must enable 2FA.'], '2' => ['All users', 'Every account must enable 2FA.']] as $v => [$t, $d]): ?>
    <label class="opt"><input type="radio" name="require_2fa" value="<?= $v ?>" <?= $mode === (string)$v ? 'checked' : '' ?>> <b><?= e($t) ?></b> <span class="muted small"><?= e($d) ?></span></label>
  <?php endforeach; ?>
  <h3>Profile pictures</h3>
  <label class="opt"><input type="checkbox" name="avatar_uploads" value="1" <?= $avatars ? 'checked' : '' ?>> <b>Let users upload their own picture</b> <span class="muted small">Administrators can always upload. Others keep the first-letter avatar when this is off.</span></label>
  <h3>Country flags on IP addresses</h3>
  <label class="opt"><input type="checkbox" name="geo_lookup" value="1" <?= $geo ? 'checked' : '' ?>> <b>Look up the country of public IPs</b> <span class="muted small">Sends each public IP once to ipwho.is and caches the result. Off by default for privacy; private/local addresses are never sent.</span></label>
  <h3>Plugin browser</h3>
  <p class="muted small">Modrinth and Spigot work out of the box. CurseForge asks every site for its own free API key (console.curseforge.com).</p>
  <label>CurseForge API key <small class="muted"><?= $curse ? 'A key is saved. Type a new one to replace it.' : 'Not set' ?></small><input type="password" name="curseforge_key" autocomplete="off" maxlength="200" placeholder="<?= $curse ? '••••••••' : 'paste key' ?>"></label>
  <?php if ($curse): ?><label class="opt"><input type="checkbox" name="curseforge_clear" value="1"> <b>Remove the saved key</b></label><?php endif; ?>
  <button class="btn" type="submit">Save</button>
</form>
