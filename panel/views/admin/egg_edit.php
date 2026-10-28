<div class="page-head"><div><h1><?= e($egg['name']) ?></h1><p class="muted"><?= $inUse ?> server<?= $inUse === 1 ? '' : 's' ?> use this egg.</p></div>
  <a class="btn ghost" href="/admin/eggs">← All eggs</a></div>
<form class="card" method="post" action="/admin/eggs/<?= (int)$egg['id'] ?>">
  <?= csrf_field() ?>
  <?php require __DIR__ . '/_egg_form.php'; ?>
  <button class="btn" type="submit">Save egg</button>
</form>
