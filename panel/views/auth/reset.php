<h2>Choose a new password</h2>
<form method="post" action="/reset/<?= e($token) ?>" autocomplete="off">
  <?= csrf_field() ?>
  <label>New password
    <input name="new_password" type="password" required minlength="12" maxlength="200" autocomplete="new-password" autofocus>
  </label>
  <label>Confirm new password
    <input name="confirm_password" type="password" required minlength="12" maxlength="200" autocomplete="new-password">
  </label>
  <p class="muted small">12+ characters with upper-case, lower-case and a number. You will be signed out everywhere.</p>
  <button class="btn block" type="submit">Set new password</button>
</form>
