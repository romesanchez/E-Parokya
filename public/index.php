<?php
require_once __DIR__ . '/../includes/layout.php';

$masses = [
    ['Sunday', '6:00 AM · 8:00 AM · 10:00 AM · 6:00 PM'],
    ['Monday – Friday', '6:00 AM · 6:00 PM'],
    ['Saturday', '6:00 AM · 6:00 PM (Anticipated)'],
    ['First Friday', 'Whole-day Adoration, 8:00 AM – 5:00 PM'],
];

/*
 * One entry per service:
 * key (same as the request form) · icon · name · small tag · headline (bright part) · headline (dim part)
 * · short info line · fallback colours (used when the photo file is missing)
 * Photos go in public/assets/services/<key>.jpg
 */
$services = [
    ['baptism',         '💧',  'Baptism',         'Sacrament',     'Welcome your child',        'into the family of God',        'Sundays after the 10:00 AM Mass · Seminar required', '#3b82a6', '#123247'],
    ['wedding',         '💍',  'Wedding',         'Sacrament',     'Begin your life together',  'before God and community',      'By appointment · At least 3 months ahead',            '#c9a35b', '#4a1d1d'],
    ['funeral',         '🕯️', 'Funeral',         'Pastoral care', 'Comfort and prayer',        'for the family and loved ones', 'Vigil · Requiem Mass · Interment',                    '#d9a441', '#2b1d16'],
    ['blessing',        '🏠',  'House Blessing',  'Blessing',      "Invite God's blessing",     'into your home',                'Weekdays · A priest visits your home',                '#6aa152', '#1d3320'],
    ['first_communion', '🍞',  'First Communion', 'Sacrament',     "A child's first meeting",   'with Jesus in the Eucharist',   'For children in the parish catechism program',        '#e0b36a', '#5a2a1a'],
    ['confirmation',    '🕊️', 'Confirmation',    'Sacrament',     'Be sealed with the Spirit', 'and strengthened in faith',     'Annual schedule · Youth and adults',                  '#c0392b', '#3a0f12'],
];

/** Splits a headline into words so each one can slide up on its own. */
function reel_words(string $text, int &$i): string
{
    $out = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        $out .= '<span class="w" style="--i:' . $i++ . '"><span>' . e($word) . '</span></span> ';
    }
    return $out;
}

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

<section class="services-band">
  <div class="container">
    <div class="center">
      <h2 class="section-title">Parish Services</h2>
      <p class="muted small narrow">Request any of the following through the parish office. Submit online and our staff will confirm the schedule with you.</p>
    </div>

    <div class="reel" id="reel" role="region" aria-roledescription="carousel" aria-label="Parish services">
      <?php foreach ($services as $n => [$key, $icon, $name, $tag, $strong, $dim, $info, $c1, $c2]): ?>
        <?php $w = 0; ?>
        <article class="reel-slide<?= $n === 0 ? ' active' : '' ?>" style="--c1:<?= e($c1) ?>;--c2:<?= e($c2) ?>">
          <img class="reel-photo" src="assets/services/<?= e($key) ?>.jpg" alt="" onerror="this.parentNode.classList.add('no-photo');this.remove()">
          <svg class="reel-parol" viewBox="0 0 120 150" aria-hidden="true"><path d="M60,10 L72,45 L109,46 L80,68 L91,104 L60,83 L29,104 L40,68 L11,46 L48,45 Z" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/><path d="M50,104 L44,146 M60,104 L60,146 M70,104 L76,146" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
          <span class="reel-emoji" aria-hidden="true"><?= $icon ?></span>
          <div class="reel-shade"></div>
          <div class="reel-copy">
            <span class="reel-tag"><?= e($tag) ?></span>
            <h3 class="reel-title"><span class="t-strong"><?= reel_words($strong, $w) ?></span><span class="t-dim"><?= reel_words($dim, $w) ?></span></h3>
            <div class="reel-meta">
              <span class="reel-meta-icon" aria-hidden="true"><?= $icon ?></span>
              <span><strong><?= e($name) ?></strong> · <?= e($info) ?></span>
              <a href="request.php?service=<?= e($key) ?>" class="reel-cta">Request <?= e($name) ?> →</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>

      <div class="reel-thumbs">
        <?php foreach ($services as $n => [$key, $icon, $name, $tag, $strong, $dim, $info, $c1, $c2]): ?>
          <button type="button" class="reel-thumb<?= $n === 0 ? ' active' : '' ?>" style="--c1:<?= e($c1) ?>;--c2:<?= e($c2) ?>" aria-label="Show <?= e($name) ?>"<?= $n === 0 ? ' aria-current="true"' : '' ?>>
            <span aria-hidden="true"><?= $icon ?></span>
            <img src="assets/services/<?= e($key) ?>.jpg" alt="" onerror="this.remove()">
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<script>
(function () {
  var DELAY = 1000; // how long each service stays on screen, in milliseconds (1000 = 1 second)

  var root = document.getElementById('reel');
  if (!root) { return; }
  var slides = [].slice.call(root.querySelectorAll('.reel-slide'));
  var thumbs = [].slice.call(root.querySelectorAll('.reel-thumb'));
  var n = slides.length, cur = 0, timer = null, paused = false, visible = true;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function show(i) {
    var next = (i + n) % n;
    if (next === cur) { return; }
    var prev = slides[cur];
    prev.classList.remove('active');
    prev.classList.add('leaving');          // stays visible underneath while the new one fades in
    setTimeout(function () { prev.classList.remove('leaving'); }, 750);
    cur = next;
    slides[cur].classList.add('active');
    thumbs.forEach(function (t, k) {
      t.classList.toggle('active', k === cur);
      if (k === cur) { t.setAttribute('aria-current', 'true'); } else { t.removeAttribute('aria-current'); }
    });
  }

  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function start() {
    stop();
    if (reduce || paused || !visible || document.hidden) { return; }
    timer = setInterval(function () { show(cur + 1); }, DELAY);
  }

  thumbs.forEach(function (t, k) {
    t.addEventListener('click', function () { show(k); start(); });
  });

  // Pause while the mouse is over it (not on touch screens) or while a keyboard user is focused on it.
  if (window.matchMedia('(hover: hover)').matches) {
    root.addEventListener('mouseenter', function () { paused = true; stop(); });
    root.addEventListener('mouseleave', function () { paused = false; start(); });
  }
  root.addEventListener('focusin', function (e) {
    if (e.target.matches(':focus-visible')) { paused = true; stop(); }
  });
  root.addEventListener('focusout', function () { paused = false; start(); });

  // Do not run while the tab is hidden or the slideshow is scrolled out of view.
  document.addEventListener('visibilitychange', start);
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      start();
    }, { threshold: 0.25 }).observe(root);
  }

  // Swipe on phones
  var x0 = null;
  root.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  root.addEventListener('touchend', function (e) {
    if (x0 === null) { return; }
    var dx = e.changedTouches[0].clientX - x0;
    x0 = null;
    if (Math.abs(dx) > 50) { show(cur + (dx < 0 ? 1 : -1)); start(); }
  });

  start();
})();
</script>
<?php page_end();