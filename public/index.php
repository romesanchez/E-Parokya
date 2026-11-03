<?php
require_once __DIR__ . '/../includes/layout.php';

$masses = [
    ['Sunday', '6:00 AM · 8:00 AM · 10:00 AM · 6:00 PM'],
    ['Monday – Friday', '6:00 AM · 6:00 PM'],
    ['Saturday', '6:00 AM · 6:00 PM (Anticipated)'],
    ['First Friday', 'Whole-day Adoration, 8:00 AM – 5:00 PM'],
];
$services = [
    ['💧', 'Baptism', 'Sundays after the 10:00 AM Mass. Seminar required for parents and godparents.'],
    ['💍', 'Wedding', 'By appointment at least three months in advance. Pre-Cana seminar required.'],
    ['🕯️', 'Funeral', 'Coordination with the parish office for vigil, requiem Mass, and interment.'],
    ['🏠', 'House Blessing', 'Scheduled on weekdays. A priest visits your home for the blessing rite.'],
    ['🍞', 'First Communion', 'For children enrolled in the parish catechism program.'],
    ['🕊️', 'Confirmation', 'Annual schedule with confirmation classes for youth and adults.'],
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
    <div class="hero-actions">
      <a href="request.php" class="btn btn-accent">Request a Service</a>
      <a href="#mass-times" class="btn btn-ghost-light">View Mass Times</a>
    </div>
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

<section class="services-band">
  <div class="container">
    <div class="center">
      <h2 class="section-title">Parish Services</h2>
      <p class="muted small narrow">Request any of the following through the parish office. Submit online and our staff will confirm the schedule with you.</p>
    </div>
    <div class="service-grid">
      <?php foreach ($services as [$icon, $name, $desc]): ?>
        <a href="request.php" class="card service-card">
          <span class="service-icon" aria-hidden="true"><?= $icon ?></span>
          <h3><?= e($name) ?></h3>
          <p class="muted small"><?= e($desc) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="center mt-10"><a href="request.php" class="btn btn-primary">Submit a Service Request</a></div>
  </div>
</section>
<?php page_end();
