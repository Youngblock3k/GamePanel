<?php $cur = App\Theme::userTheme(); ?>
<div class="theme-pick">
  <small>Theme</small>
  <form class="theme-dots" method="post" action="/account/theme">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')) ?>">
    <button class="tdot def<?= $cur === '' ? ' on' : '' ?>" type="submit" name="theme" value="default" title="Follow the panel's look" aria-label="Panel default">A</button>
    <?php foreach (App\Theme::THEMES as $k => $t): ?><button class="tdot<?= $cur === $k ? ' on' : '' ?>" type="submit" name="theme" value="<?= e($k) ?>" title="<?= e($t[0]) ?>" aria-label="<?= e($t[0]) ?>"><i data-c="<?= e($t[4]) ?>"></i></button><?php endforeach; ?>
  </form>
</div>
