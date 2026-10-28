<?php require __DIR__ . '/_header.php'; ?>
<div class="grid two">
  <form class="card" method="post" action="/server/<?= e($s['uuid']) ?>/subusers">
    <?= csrf_field() ?>
    <h3>Share this server</h3>
    <p class="muted small">Give another account access. Adding someone who already has access replaces their permissions.</p>
    <label>Username or email <input name="who" required maxlength="191" autocomplete="off"></label>
    <div class="perm-grid">
      <?php foreach (App\ServerService::SUBPERMS as $k => $label): ?>
        <label class="perm"><input type="checkbox" name="perms[]" value="<?= e($k) ?>" <?= in_array($k, ['console', 'power'], true) ? 'checked' : '' ?>><span><b><?= e($label) ?></b></span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Add user</button>
  </form>
  <div class="card">
    <h3>People with access</h3>
    <?php if (!$subs): ?><p class="muted">Only you (and administrators) can open this server.</p><?php endif; ?>
    <table class="table"><tbody>
      <?php foreach ($subs as $u): $have = json_decode($u['perms'], true) ?: []; ?>
        <tr>
          <td><b><?= e($u['username']) ?></b><br><small class="muted"><?= e($u['email']) ?></small></td>
          <td><?php foreach ($have as $k): if (isset(App\ServerService::SUBPERMS[$k])): ?><span class="chip-s"><?= e(App\ServerService::SUBPERMS[$k]) ?></span> <?php endif; endforeach; ?></td>
          <td class="r"><form method="post" action="/server/<?= e($s['uuid']) ?>/subusers/<?= (int)$u['id'] ?>/remove"><?= csrf_field() ?><button class="btn sm danger" type="submit">Remove</button></form></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
