<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/events.php';

$masses = [
    ['Sunday', '6:00 AM · 8:00 AM · 10:00 AM · 6:00 PM'],
    ['Monday – Friday', '6:00 AM · 6:00 PM'],
    ['Saturday', '6:00 AM · 6:00 PM (Anticipated)'],
    ['First Friday', 'Whole-day Adoration, 8:00 AM – 5:00 PM'],
];

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
    <?php foreach ($masses as [$day, $times]): ?>
      <div class="mass-row"><span class="mass-day"><?= e($day) ?></span><span class="muted small right"><?= e($times) ?></span></div>
    <?php endforeach; ?>
  </div>
  <p class="center muted small mt-6">Confessions: Saturdays 4:30 – 5:30 PM, or by appointment at the parish office.</p>
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