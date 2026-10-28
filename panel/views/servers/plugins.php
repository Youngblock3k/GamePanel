<?php require __DIR__ . '/_header.php'; ?>
<div id="plugins" data-base="/server/<?= e($s['uuid']) ?>" data-mc="<?= e($mc) ?>">
  <div class="card">
    <form id="plug-form" class="plug-bar" autocomplete="off">
      <div class="seg src-seg" role="group" aria-label="Plugin source">
        <?php foreach (App\PluginSources::SOURCES as $k => $label): ?><button type="button" class="seg-btn<?= $k === 'modrinth' ? ' on' : '' ?>" data-source="<?= e($k) ?>"><?= e($label) ?></button><?php endforeach; ?>
      </div>
      <input id="plug-q" type="search" maxlength="60" placeholder="Search plugins, e.g. EssentialsX, LuckPerms…">
      <label class="opt plug-ver"><input type="checkbox" id="plug-any"> <span>Any version</span></label>
      <button class="btn" type="submit">Search</button>
    </form>
    <p class="muted small" id="plug-hint">Showing builds for <b>Minecraft <?= e($mc ?: 'any version') ?></b>. Files land in <span class="mono">/plugins</span>; restart the server to load them.<?= $curse ? '' : ' CurseForge needs an API key from an administrator.' ?></p>
  </div>
  <div id="plug-grid" class="plug-grid"></div>
  <p id="plug-empty" class="muted center" hidden>Nothing found.</p>

  <div class="card">
    <div class="row between wrap"><b>Installed in /plugins</b><button class="btn sm ghost" id="plug-refresh" type="button">Refresh</button></div>
    <table class="table"><tbody id="plug-installed"></tbody></table>
    <p id="plug-none" class="muted small" hidden>No plugins installed yet.</p>
  </div>
</div>
