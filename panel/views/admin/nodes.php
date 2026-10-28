<div class="page-head"><div><h1>Nodes</h1><p class="muted">Machines running the daemon and Docker.</p></div><?php if (App\Roles::can($user, 'nodes.create')): ?><a class="btn" href="/admin/nodes/new">+ New node</a><?php endif; ?></div>
<div class="card">
  <table class="table">
    <thead><tr><th>Node</th><th>Location</th><th>Address</th><th class="r">Memory</th><th class="r">Disk</th><th class="r">Servers</th></tr></thead>
    <tbody>
    <?php foreach ($nodes as $n): ?>
      <tr><td><a href="/admin/nodes/<?= (int)$n['id'] ?>"><b><?= e($n['name']) ?></b></a><?= !empty($n['maintenance']) ? ' <span class="badge" data-state="stopped">maintenance</span>' : '' ?><?php if (!empty($n['description'])): ?><br><small class="muted"><?= e($n['description']) ?></small><?php endif; ?></td><td><?= e($n['location']) ?></td><td class="mono small"><?= e($n['scheme']) ?>://<?= e($n['fqdn']) ?>:<?= (int)$n['daemon_port'] ?></td>
        <td class="r"><?= e(fmt_mb($n['memory'])) ?></td><td class="r"><?= e(fmt_mb($n['disk'])) ?></td><td class="r"><?= (int)$n['servers'] ?></td></tr>
    <?php endforeach; if (!$nodes): ?><tr><td colspan="6" class="muted center">You do not have any nodes. <a href="/admin/nodes/new">Click here to create one.</a></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
