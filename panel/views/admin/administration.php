<div class="page-head"><div><h1>Administration</h1><p class="muted">Manage your users, infrastructure, servers and panel settings.</p></div></div>

<div class="grid stats">
  <div class="card stat"><label>Servers active</label><b><?= $stats['servers'][0] ?> / <?= $stats['servers'][1] ?></b></div>
  <div class="card stat"><label>Nodes</label><b><?= $stats['nodes'] ?></b></div>
  <div class="card stat"><label>Accounts</label><b><?= $stats['users'] ?></b></div>
  <div class="card stat"><label>2FA enabled</label><b><?= $stats['twofa'][0] ?> / <?= $stats['twofa'][1] ?></b></div>
</div>

<div class="card">
  <div class="row between"><h3>Node capacity</h3><a href="/admin/nodes" class="small">Details</a></div>
  <?php if (!$nodes): ?><p class="muted">You do not have any nodes. <a href="/admin/nodes">Click here to create one.</a></p><?php endif; ?>
  <?php foreach ($nodes as $n):
      $pm = $n['memory'] ? min(100, (int)round($n['used_mem'] / $n['memory'] * 100)) : 0;
      $pd = $n['disk'] ? min(100, (int)round($n['used_disk'] / $n['disk'] * 100)) : 0; ?>
    <div class="cap">
      <b><?= e($n['name']) ?></b>
      <div class="meter"><label>Memory <span><?= e(fmt_mb((int)$n['used_mem'])) ?> / <?= e(fmt_mb((int)$n['memory'])) ?> allocated</span></label><div class="bar"><i data-fill="<?= $pm ?>"></i></div></div>
      <div class="meter"><label>Disk <span><?= e(fmt_mb((int)$n['used_disk'])) ?> / <?= e(fmt_mb((int)$n['disk'])) ?> allocated</span></label><div class="bar"><i data-fill="<?= $pd ?>"></i></div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="alert ok"><b>Your panel is up to date.</b> You are running <?= e(App\Brand\Seal::NAME) ?> v<?= e(App\Brand\Seal::VERSION) ?> on PHP <?= e(PHP_VERSION) ?>.</div>

<div class="help-grid">
  <a class="hcard" href="https://discord.gg/dsXFazHAJ" target="_blank" rel="noopener noreferrer"><span class="hc-ico"><svg class="ico"><use href="#i-help"/></svg></span><span class="hc-t"><b>Get Help</b><small>Join our Discord</small></span></a>
  <div class="hcard off" aria-disabled="true"><span class="hc-ico"><svg class="ico"><use href="#i-book"/></svg></span><span class="hc-t"><b>Documentation</b><small>Coming soon</small></span></div>
  <div class="hcard off" aria-disabled="true"><span class="hc-ico"><svg class="ico"><use href="#i-code"/></svg></span><span class="hc-t"><b>GitHub</b><small>Coming soon</small></span></div>
  <a class="hcard" href="<?= e(cfg('support_url', 'https://ko-fi.com/')) ?>" target="_blank" rel="noopener noreferrer"><span class="hc-ico"><svg class="ico"><use href="#i-heart"/></svg></span><span class="hc-t"><b>Support the project</b><small>Opens Ko-fi</small></span></a>
</div>

<div class="grid three tiles-links">
  <?php if (App\Roles::can($user, 'users')): ?><a class="card link-tile" href="/admin/users"><b>Accounts</b><span>Create, edit and remove user accounts.</span></a><?php endif; ?>
  <?php if (App\Roles::can($user, 'locations')): ?><a class="card link-tile" href="/admin/locations"><b>Locations</b><span>Group nodes into regions.</span></a><?php endif; ?>
  <?php if (App\Roles::can($user, 'nodes')): ?><a class="card link-tile" href="/admin/nodes"><b>Nodes</b><span>Manage daemons and ports.</span></a><?php endif; ?>
  <?php if (App\Roles::can($user, 'servers')): ?><a class="card link-tile" href="/admin/servers"><b>Servers</b><span>Provision and manage game servers.</span></a><?php endif; ?>
  <?php if (App\Roles::can($user, 'eggs')): ?><a class="card link-tile" href="/admin/eggs"><b>Eggs</b><span>Server templates and variables.</span></a><?php endif; ?>
  <?php if (App\Roles::can($user, 'settings')): ?><a class="card link-tile" href="/admin/settings"><b>Settings</b><span>Two-factor rules and options.</span></a><?php endif; ?>
  <?php if (App\Roles::can($user, 'branding')): ?><a class="card link-tile" href="/admin/branding"><b>Branding</b><span>Name and logo of the panel.</span></a><?php endif; ?>
  <?php if (App\Roles::can($user, 'audit')): ?><a class="card link-tile" href="/admin/audit"><b>Audit log</b><span>Everything that happened and who did it.</span></a><?php endif; ?>
  <a class="card link-tile" href="/account/api"><b>API keys</b><span>Issue credentials for the API.</span></a>
</div>
