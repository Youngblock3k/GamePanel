<?php require __DIR__ . '/_header.php'; ?>
<div id="files" class="card" data-base="/server/<?= e($s['uuid']) ?>">
  <div class="row between wrap">
    <div id="crumbs" class="crumbs mono"></div>
    <div class="row gap">
      <button class="btn sm ghost" id="new-file">New file</button>
      <button class="btn sm ghost" id="new-dir">New folder</button>
      <button class="btn sm ghost" id="pull-url">Pull URL</button>
      <button class="btn sm ghost" id="refresh">Refresh</button>
    </div>
  </div>
  <table class="table" id="file-table">
    <thead><tr><th>Name</th><th class="r">Size</th><th>Modified</th><th></th></tr></thead>
    <tbody></tbody>
  </table>
  <p id="file-empty" class="muted center" hidden>This folder is empty.</p>
</div>

<div id="editor" class="card" hidden>
  <div class="row between wrap">
    <b id="editor-name" class="mono"></b>
    <div class="row gap">
      <button class="btn sm" id="editor-save">Save</button>
      <button class="btn sm ghost" id="editor-close">Close</button>
    </div>
  </div>
  <textarea id="editor-text" class="mono editor" spellcheck="false"></textarea>
</div>
