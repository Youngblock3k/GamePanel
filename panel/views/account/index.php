<div class="page-head"><div><h1>Settings</h1><p class="muted">Signed in as <b><?= e($user['username']) ?></b> (<?= e($user['email']) ?>)</p></div></div>
<?php require ROOT . '/views/partials/account_tabs.php'; ?>

<div class="card">
  <h3>Profile picture</h3>
  <div class="row gap wrap">
    <?= avatar_html($user['username'], 'xl') ?>
    <?php if (\App\Avatar::allowed($user)): ?>
    <form method="post" action="/account/avatar" enctype="multipart/form-data" class="row gap wrap">
      <?= csrf_field() ?>
      <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required>
      <button class="btn" type="submit">Save picture</button>
    </form>
    <?php if (\App\Avatar::url($user['username'])): ?>
      <form method="post" action="/account/avatar"><?= csrf_field() ?><input type="hidden" name="remove" value="1"><button class="btn ghost" type="submit">Use my initial</button></form>
    <?php endif; ?>
    <?php else: ?><span class="muted">Uploading pictures is turned off by an administrator.</span><?php endif; ?>
  </div>
  <p class="muted small">PNG, JPEG or WebP, up to 1 MB. Without a picture your avatar is the first letter of your username.</p>
</div>

<form class="card narrow" method="post" action="/account/password" autocomplete="off">
  <?= csrf_field() ?>
  <h3>Change password</h3>
  <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
  <label>New password <input type="password" name="new_password" required minlength="12" maxlength="200" autocomplete="new-password"></label>
  <label>Confirm new password <input type="password" name="confirm_password" required minlength="12" maxlength="200" autocomplete="new-password"></label>
  <p class="muted small">12+ characters with upper-case, lower-case and a number. Changing it signs out all other sessions.</p>
  <button class="btn" type="submit">Update password</button>
</form>
