<?php
require_once __DIR__ . '/../includes/layout.php';

$masses = [
    ['Sunday', '6:00 AM · 8:00 AM · 10:00 AM · 6:00 PM'],
    ['Monday – Friday', '6:00 AM · 6:00 PM'],
    ['Saturday', '6:00 AM · 6:00 PM (Anticipated)'],
    ['First Friday', 'Whole-day Adoration, 8:00 AM – 5:00 PM'],
];
// [service key (same as the request form), icon, name, description]
$services = [
    ['baptism',         '💧',  'Baptism',         'Sundays after the 10:00 AM Mass. Seminar required for parents and godparents.'],
    ['wedding',         '💍',  'Wedding',         'By appointment at least three months in advance. Pre-Cana seminar required.'],
    ['funeral',         '🕯️', 'Funeral',         'Coordination with the parish office for vigil, requiem Mass, and interment.'],
    ['blessing',        '🏠',  'House Blessing',  'Scheduled on weekdays. A priest visits your home for the blessing rite.'],
    ['first_communion', '🍞',  'First Communion', 'For children enrolled in the parish catechism program.'],
    ['confirmation',    '🕊️', 'Confirmation',    'Annual schedule with confirmation classes for youth and adults.'],
];
$total = count($services);

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

    <div class="showcase" id="showcase" role="region" aria-roledescription="carousel" aria-label="Parish services">
      <div class="stage">
        <?php foreach ($services as $i => [$key, $icon, $name, $desc]): ?>
          <article class="slide<?= $i === 0 ? ' active' : '' ?>" id="svc-panel-<?= $i ?>" role="tabpanel" aria-labelledby="svc-tab-<?= $i ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
            <div class="slide-copy">
              <p class="slide-count"><?= sprintf('%02d / %02d', $i + 1, $total) ?></p>
              <h3><?= e($name) ?></h3>
              <p class="slide-desc"><?= e($desc) ?></p>
              <a href="request.php?service=<?= e($key) ?>" class="btn btn-accent">Request <?= e($name) ?></a>
            </div>
            <div class="slide-art" aria-hidden="true"><span class="slide-icon"><?= $icon ?></span></div>
          </article>
        <?php endforeach; ?>
        <div class="progress" aria-hidden="true"><span></span></div>
      </div>

      <div class="svc-tabs" role="tablist" aria-label="Choose a service">
        <?php foreach ($services as $i => [$key, $icon, $name, $desc]): ?>
          <button type="button" class="svc-tab<?= $i === 0 ? ' active' : '' ?>" id="svc-tab-<?= $i ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="svc-panel-<?= $i ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>">
            <span class="svc-tab-icon" aria-hidden="true"><?= $icon ?></span><span><?= e($name) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="center mt-10"><a href="request.php" class="btn btn-primary">Submit a Service Request</a></div>
  </div>
</section>

<script>
(function () {
  var root = document.getElementById('showcase');
  if (!root) { return; }
  var slides = root.querySelectorAll('.slide');
  var tabs = root.querySelectorAll('.svc-tab');
  var bar = root.querySelector('.progress span');
  var n = slides.length, cur = 0;

  function restart() {
    bar.classList.remove('run');
    void bar.offsetWidth; // restart the CSS animation
    bar.classList.add('run');
  }

  function go(i) {
    cur = (i + n) % n;
    slides.forEach(function (s, k) {
      var on = k === cur;
      s.classList.toggle('active', on);
      s.setAttribute('aria-hidden', on ? 'false' : 'true');
    });
    tabs.forEach(function (t, k) {
      var on = k === cur;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
    });
    restart();
  }

  // The progress bar IS the timer: when it fills up, show the next service.
  // Hovering (or keyboard focus) pauses it; "reduce motion" turns autoplay off.
  bar.addEventListener('animationend', function () { go(cur + 1); });

  tabs.forEach(function (t, k) {
    t.addEventListener('click', function () { go(k); });
    t.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); go(cur + 1); tabs[cur].focus(); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(cur - 1); tabs[cur].focus(); }
    });
  });

  var x0 = null;
  root.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  root.addEventListener('touchend', function (e) {
    if (x0 === null) { return; }
    var dx = e.changedTouches[0].clientX - x0;
    x0 = null;
    if (Math.abs(dx) > 50) { go(cur + (dx < 0 ? 1 : -1)); }
  });

  go(0);
})();
</script>
<?php page_end();