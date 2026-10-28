<h2>Forgot your password?</h2>
<p class="muted">Enter your account e-mail and we will send you a link to choose a new one.</p>
<form method="post" action="/forgot">
  <?= csrf_field() ?>
  <label>E-mail address
    <input name="email" type="email" required autofocus autocomplete="email" maxlength="191">
  </label>
  <?php if (App\Captcha::enabled()): ?>
    <div class="g-recaptcha" data-sitekey="<?= e(cfg('recaptcha_site')) ?>" data-theme="dark"></div>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php endif; ?>
  <button class="btn block" type="submit">Send reset link</button>
</form>
<p class="center small"><a href="/login">Back to sign in</a></p>
