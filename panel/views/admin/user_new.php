<div class="page-head"><div><h1>New user</h1><p class="muted">Accounts are created here only. There is no public sign-up.</p></div><a class="btn ghost" href="/admin/users">Cancel</a></div>

<form class="card" method="post" action="/admin/users" autocomplete="off">
  <?= csrf_field() ?>
  <h3>Account</h3>
  <div class="grid three">
    <label>Username <input name="username" required pattern="[A-Za-z0-9_.\-]{3,32}"></label>
    <label>Email <input type="email" name="email" required maxlength="191"></label>
    <label>Password <small class="muted">at least 12 characters</small><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
  </div>
  <?php if ($canGrant): ?>
  <h3>Access</h3>
  <div class="grid two">
    <label>Role <select name="role_id"><option value="">No role (ordinary user)</option><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
    <label class="opt"><input type="checkbox" name="is_admin" value="1"> <span><b>Administrator</b><br><small class="muted">Full access to everything, including roles.</small></span></label>
  </div>
  <?php endif; ?>
  <button class="btn" type="submit">Create user</button>
</form>
