<?php /** @var array $egg */ $g = fn(string $k, string $d = '') => e($egg[$k] ?? $d); ?>
<div class="grid three">
  <label>Name <input name="name" required maxlength="100" value="<?= $g('name') ?>"></label>
  <label>Category <input name="category" maxlength="40" placeholder="game / runtime / custom" value="<?= $g('category', 'custom') ?>"></label>
  <label>Stop command <small class="muted">sent to console before stopping</small><input name="stop_command" maxlength="100" placeholder="stop" value="<?= $g('stop_command') ?>"></label>
</div>
<label>Description <input name="description" maxlength="255" value="<?= $g('description') ?>"></label>
<div class="grid two">
  <label>Runtime docker image <input name="docker_image" required class="mono" placeholder="ghcr.io/pterodactyl/yolks:java_21" value="<?= $g('docker_image') ?>"></label>
  <label>Install docker image <small class="muted">(optional)</small><input name="install_image" class="mono" placeholder="ghcr.io/ptero-eggs/installers:alpine" value="<?= $g('install_image') ?>"></label>
</div>
<label>Startup command <small class="muted">Variables: {{YOUR_VAR}}, {{SERVER_MEMORY}}, {{SERVER_MEMORY_JVM}}, {{SERVER_PORT}}, {{SERVER_IP}}</small>
  <input name="startup" required maxlength="1000" class="mono" value="<?= $g('startup') ?>"></label>
<label>Variables <small class="muted">JSON list: key (UPPER_CASE), name, default, editable (true = owners may change), regex</small>
  <textarea name="variables" rows="8" class="mono" spellcheck="false"><?= e(json_encode(json_decode($egg['variables'] ?? '[]', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea></label>
<label>Install script <small class="muted">sh; runs once in a locked-down container with the server folder at /mnt/server. Variables are available as env vars.</small>
  <textarea name="install_script" rows="9" class="mono" spellcheck="false"><?= $g('install_script') ?></textarea></label>
