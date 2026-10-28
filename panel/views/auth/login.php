<h2>Sign in</h2>
<form method="post" action="/login" autocomplete="on">
  <?= csrf_field() ?>
  <label>Username or email
    <input name="login" type="text" required autofocus autocomplete="username" maxlength="191">
  </label>
  <label>Password
    <input name="password" type="password" required autocomplete="current-password" maxlength="200">
  </label>
  <?php if (App\Captcha::enabled()): ?>
    <div class="g-recaptcha" data-sitekey="<?= e(cfg('recaptcha_site')) ?>" data-theme="dark"></div>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php endif; ?>
  <button class="btn block" type="submit">Sign in</button>
</form>
<p class="center small"><a href="/forgot">Forgot your password?</a></p>
<p class="muted small center">Accounts are created by an administrator.</p>
