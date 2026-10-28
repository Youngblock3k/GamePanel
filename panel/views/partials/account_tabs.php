<?php $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$tabs = ['/account' => 'Overview', '/account/security' => 'Two-factor &amp; sessions', '/account/activity' => 'Activity']; ?>
<nav class="tabs account-tabs"><?php foreach ($tabs as $h => $l): ?><a href="<?= $h ?>" class="<?= $p === $h ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></nav>
