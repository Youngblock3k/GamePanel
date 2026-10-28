<div class="page-head"><div><h1>Theme</h1><p class="muted">Pick how the panel looks for you. This only changes your own view. Everyone else keeps theirs.</p></div></div>

<form method="post" action="/account/theme" class="theme-grid">
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')) ?>">
  <?php foreach ($cards as $c): ?>
    <button class="theme-card<?= $cur === $c['key'] ? ' on' : '' ?>" type="submit" name="theme" value="<?= e($c['key'] === '' ? 'default' : $c['key']) ?>">
      <span class="tc-prev" data-vars="<?= e($c['vars']) ?>">
        <span class="tc-nav"><i></i><i></i><i></i></span>
        <span class="tc-body"><span class="tc-card"><u></u><u></u><span class="tc-btn">Button</span></span></span>
      </span>
      <span class="tc-meta"><b><?= e($c['label']) ?></b><small><?= e($c['blurb']) ?></small></span>
      <?php if ($cur === $c['key']): ?><span class="tc-tag">In use</span><?php endif; ?>
    </button>
  <?php endforeach; ?>
</form>
