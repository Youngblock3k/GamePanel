<div class="page-head"><div><h1>API keys</h1><p class="muted">Use <span class="mono">Authorization: Bearer &lt;key&gt;</span> with <span class="mono">/api/v1/servers</span>.</p></div></div>


<?php if ($newKey): ?>
<div class="card warn-card"><h3>Your new key</h3><p>Copy it now — it is stored only as a hash and cannot be shown again.</p>
<pre class="mono codes break"><?= e($newKey) ?></pre></div>
<?php endif; ?>

<form class="card" method="post" action="/account/api" autocomplete="off">
  <?= csrf_field() ?>
  <h3>Create key</h3>
  <div class="grid three">
    <label>Description <input name="memo" maxlength="100" required></label>
    <label>Allowed IPs <small class="muted">(optional, comma separated)</small><input name="allowed_ips" placeholder="203.0.113.5"></label>
    <label>Confirm password <input type="password" name="current_password" required autocomplete="current-password"></label>
  </div>
  <button class="btn" type="submit">Create</button>
</form>

<div class="card">
  <table class="table">
    <thead><tr><th>Description</th><th>Key ID</th><th>Allowed IPs</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr><td><?= e($k['memo']) ?></td><td class="mono">gp_<?= e($k['identifier']) ?></td><td class="small"><?= e($k['allowed_ips'] ?: 'any') ?></td>
        <td><?= time_tag($k['last_used'] ? (int)$k['last_used'] : null) ?></td>
        <td class="r"><form method="post" action="/account/api/delete" data-confirm="Delete this key?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="btn sm danger" type="submit">Delete</button></form></td></tr>
    <?php endforeach; if (!$keys): ?><tr><td colspan="5" class="muted center">No keys yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
