<h2>Two-factor authentication</h2>
<p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
<form method="post" action="/login/2fa">
  <?= csrf_field() ?>
  <label>Code
    <input name="code" type="text" inputmode="text" required autofocus autocomplete="one-time-code" maxlength="20" class="mono code-input">
  </label>
  <button class="btn block" type="submit">Verify</button>
</form>
<p class="center small"><a href="/login">Cancel</a></p>
