<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

$u = current_user();
$loggedIn = $u && $u['role'] === 'user';
$events = events_public_upcoming();
$weddings = weddings_upcoming();
$altar = $loggedIn ? altar_servers_public_map() : [];

// Group events by month.
$byMonth = [];
foreach ($events as $ev) {
    $ev['altar_servers'] = $altar['event:' . $ev['id']] ?? [];
    $byMonth[date('F Y', strtotime($ev['event_date']))][] = $ev;
}

page_start('Parish Events — St. Joseph Parish', 'Upcoming activities, feasts and weddings at St. Joseph Parish.', 'events');
?>
<div class="container dash">
  <h1 class="page-title left">Parish Events</h1>
  <p class="muted small">Upcoming Masses, feasts, devotions and other activities at St. Joseph Parish.</p>
  <?php if (!$loggedIn): ?><p class="muted small"><a href="login.php" style="text-decoration:underline">Log in</a> to see which altar servers are assigned.</p><?php endif; ?>

  <?php if (!$events): ?>
    <div class="empty" style="margin-top:1.5rem">No upcoming events have been posted yet. Please check back soon.</div>
  <?php endif; ?>

  <?php foreach ($byMonth as $month => $list): ?>
    <h2 class="ev-month"><?= e($month) ?></h2>
    <div class="ev-list">
      <?php foreach ($list as $ev) { render_event_card($ev); } ?>
    </div>
  <?php endforeach; ?>

  <h2 class="h2">Upcoming weddings</h2>
  <?php if (!$loggedIn): ?>
    <div class="empty"><a href="login.php" style="text-decoration:underline">Log in</a> to see the parish wedding schedule.</div>
  <?php elseif (!$weddings): ?>
    <div class="empty">No weddings are scheduled yet.</div>
  <?php else: ?>
    <div class="ev-list">
      <?php foreach ($weddings as $w): $ts = strtotime($w['event_date']); ?>
        <article class="card ev">
          <div class="ev-date" aria-hidden="true"><span class="ev-day"><?= e(date('j', $ts)) ?></span><span class="ev-mon"><?= e(date('M', $ts)) ?></span></div>
          <div class="ev-body">
            <div class="ev-head">
                <h3>Wedding</h3>
                <span class="badge badge-approved">Wedding</span>
            </div>
            <p class="small"><strong><?= e(date('l, F j, Y', $ts)) ?></strong> · <?= e(fmt_time($w['event_time'])) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php page_end();