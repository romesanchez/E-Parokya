<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

// Retrieve active public Mass and Confession schedules.
$stmt = db()->prepare(
    "SELECT *
     FROM parish_schedules
     WHERE is_active = 1
     ORDER BY sort_order ASC, id ASC"
);
$stmt->execute();

$allSchedules = $stmt->fetchAll();

$masses = array_filter($allSchedules, function ($schedule) {
    return $schedule['schedule_type'] === 'mass';
});

$confessions = array_filter($allSchedules, function ($schedule) {
    return $schedule['schedule_type'] === 'confession';
});

page_start(
    'St. Joseph Parish — Mass Schedule & Parish Services',
    'Mass and confession schedules, sacramental services (baptism, wedding, funeral, blessing), and requests to the parish office of St. Joseph Parish.',
    'home'
);
?>
<section class="hero">
  <img src="assets/hero-church.jpg" alt="Interior of St. Joseph Parish church with morning light through stained glass" width="1600" height="912">
  <div class="hero-overlay"></div>
  <div class="container hero-inner">
    <p class="eyebrow">Est. 1898 · San Roque</p>
    <h1>A house of prayer at the heart of the community</h1>
    <p class="hero-lead">All are welcome. Join us for the Holy Mass, receive the sacraments, and let the parish walk with you through life's milestones.</p>
  </div>
</section>

<section id="mass-times" class="container section">

  <div class="center">
    <h2 class="section-title">Mass Schedule</h2>
    <div class="rule"></div>
  </div>

  <div class="mass-grid">
    <?php if ($masses): ?>

      <?php foreach ($masses as $schedule): ?>
        <div class="mass-row">
          <span class="mass-day">
            <?= e($schedule['day_label']) ?>
          </span>

          <span class="muted small right">
            <?= e($schedule['schedule_details']) ?>
          </span>
        </div>
      <?php endforeach; ?>

    <?php else: ?>

      <p class="center muted small">
        Mass schedule is currently unavailable.
      </p>

    <?php endif; ?>
  </div>

  <?php if ($confessions): ?>

    <div class="center" style="margin-top:2rem">
      <h2 class="section-title">Confession Schedule</h2>
      <div class="rule"></div>
    </div>

    <div class="mass-grid">
      <?php foreach ($confessions as $schedule): ?>
        <div class="mass-row">
          <span class="mass-day">
            <?= e($schedule['day_label']) ?>
          </span>

          <span class="muted small right">
            <?= e($schedule['schedule_details']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</section>

<?php $homeEvents = events_public_upcoming(3); ?>
<?php if ($homeEvents): ?>
<section id="events" class="container section">
  <div class="center"><h2 class="section-title">Upcoming Events</h2><div class="rule"></div></div>
  <div class="ev-list" style="margin-top:1.5rem">
    <?php foreach ($homeEvents as $ev) { render_event_card($ev); } ?>
  </div>
  <p class="ev-more"><a href="events.php" class="btn btn-outline">See all events</a></p>
</section>
<?php endif; ?>
<?php page_end();