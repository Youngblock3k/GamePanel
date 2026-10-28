<div class="page-head"><div><h1>New database host</h1><p class="muted">A MariaDB or MySQL server that game servers get their databases from.</p></div><a class="btn ghost" href="/admin/databases">Cancel</a></div>

<form class="card" method="post" action="/admin/databases/hosts" autocomplete="off">
  <?= csrf_field() ?>
  <h3>Connection</h3>
  <p class="muted small">Use an account that can create databases and users (for example a dedicated <span class="mono">gamepanel_admin</span> user with <span class="mono">GRANT ALL ... WITH GRANT OPTION</span>). The panel tests the login before saving and stores the password encrypted.</p>
  <div class="grid three">
    <label>Name <input name="name" required maxlength="100" placeholder="Main database"></label>
    <label>Host <input name="host" required class="mono" placeholder="127.0.0.1"></label>
    <label>Port <input type="number" name="port" value="3306" min="1" max="65535" required></label>
    <label>Admin user <input name="admin_user" required class="mono"></label>
    <label>Admin password <input type="password" name="admin_pass" autocomplete="new-password"></label>
    <label>Max databases <small class="muted">0 = unlimited</small><input type="number" name="max_dbs" value="0" min="0"></label>
  </div>
  <h3>Confirm</h3>
  <label>Your panel password <input type="password" name="current_password" required autocomplete="current-password"></label>
  <button class="btn" type="submit">Test and add host</button>
</form>
