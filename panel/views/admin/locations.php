<div class="page-head"><div><h1>Locations</h1><p class="muted">Group nodes by region or datacenter.</p></div><?php if (App\Roles::can($user, 'locations.edit')): ?><a class="btn" href="/admin/locations/new">+ New location</a><?php endif; ?></div>
<div class="card">
  <table class="table">
    <thead><tr><th>Code</th><th>Description</th><th class="r">Nodes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($locations as $l): ?>
      <tr><td class="mono"><?= e($l['short']) ?></td><td><?= e($l['description']) ?></td><td class="r"><?= (int)$l['nodes'] ?></td>
        <td class="r"><form method="post" action="/admin/locations/delete" data-confirm="Delete location?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn sm danger" type="submit">Delete</button></form></td></tr>
    <?php endforeach; if (!$locations): ?><tr><td colspan="4" class="muted center">You do not have any locations. <a href="/admin/locations/new">Click here to create one.</a></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
