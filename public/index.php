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
// Use Philippine local time for upcoming appointment filtering.
$manilaNow = new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Manila')
);

$today = $manilaNow->format('Y-m-d');
$currentTime = $manilaNow->format('H:i');

// Retrieve only appointments explicitly approved for public display.
$serviceScheduleStmt = db()->prepare(
    "SELECT r.service_type, s.event_date, s.event_time
     FROM schedules AS s
     INNER JOIN service_requests AS r ON r.id = s.request_id
     WHERE r.status = 'approved'
       AND r.archived_at IS NULL
        AND r.public_schedule_consent = 1
        AND r.public_schedule_consent_at IS NOT NULL
        AND s.is_public = 1
       AND (
            s.event_date > ?
            OR (
                s.event_date = ?
                AND s.event_time >= ?
            )
       )
     ORDER BY s.event_date ASC, s.event_time ASC
     LIMIT 6"
);

$serviceScheduleStmt->execute([
    $today,
    $today,
    $currentTime,
]);

$upcomingServiceSchedules = $serviceScheduleStmt->fetchAll();

page_start(
    'St. Joseph Parish — Mass Schedule & Parish Services',
    'Mass and confession schedules, sacramental services (baptism, wedding, funeral, blessing), and requests to the parish office of St. Joseph Parish.',
    'home'
);
?>

<section class="hero">
    <img
        src="assets/hero-church.jpg"
        alt="Interior of St. Joseph Parish church with morning light through stained glass"
        width="1600"
        height="912"
    >
    <div class="hero-overlay"></div>

    <div class="container hero-inner">
        <p class="eyebrow">Est. 1898 · San Roque</p>
        <h1>A house of prayer at the heart of the community</h1>
        <p class="hero-lead">
            All are welcome. Join us for the Holy Mass, receive the sacraments,
            and let the parish walk with you through life's milestones.
        </p>

        <a href="#services" class="btn btn-primary hero-cta">
            Request a Parish Service
        </a>
    </div>
</section>

<section id="mass-times" class="container section">
    <div class="center">
        <h2 class="section-title">Parish Schedule</h2>
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
        <div class="center" style="margin-top: 2rem;">
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

<section id="services" class="container section services-section">
    <div class="center">
        <p class="eyebrow">How can we help you?</p>
        <h2 class="section-title">Our Parish Services</h2>
        <p class="section-lead">
            Choose a service below to begin your request or learn more.
        </p>
        <div class="rule"></div>
    </div>

    <div class="service-grid">
        <a href="request.php?service=baptism" class="service-card">
            <div class="service-icon icon-baptism">
                <i class="fa-solid fa-water" aria-hidden="true"></i>
            </div>
            <h3>Baptism</h3>
            <p>Welcome a new member into the family of God.</p>
            <span class="service-link">Request service &rarr;</span>
        </a>

        <a href="request.php?service=wedding" class="service-card">
            <div class="service-icon icon-wedding">
                <i class="fa-solid fa-ring" aria-hidden="true"></i>
            </div>
            <h3>Wedding</h3>
            <p>Begin your journey toward celebrating your marriage in the Church.</p>
            <span class="service-link">Request service &rarr;</span>
        </a>

        <a href="request.php?service=funeral" class="service-card">
            <div class="service-icon icon-funeral">
                <i class="fa-solid fa-cross" aria-hidden="true"></i>
            </div>
            <h3>Funeral</h3>
            <p>Request assistance for funeral rites and parish services.</p>
            <span class="service-link">Request service &rarr;</span>
        </a>

        <a href="request.php?service=blessing" class="service-card">
            <div class="service-icon icon-blessing">
                <i class="fa-solid fa-house" aria-hidden="true"></i>
            </div>
            <h3>Blessing</h3>
            <p>Request a priest for a blessing of your home or occasion.</p>
            <span class="service-link">Request service &rarr;</span>
        </a>

        <a href="request.php?service=first_communion" class="service-card">
            <div class="service-icon icon-communion">
                <i class="fa-solid fa-church" aria-hidden="true"></i>
            </div>
            <h3>First Communion</h3>
            <p>Prepare for a child's first reception of the Holy Eucharist.</p>
            <span class="service-link">Request service &rarr;</span>
        </a>

        <a href="request.php?service=confirmation" class="service-card">
            <div class="service-icon icon-confirmation">
                <i class="fa-solid fa-dove" aria-hidden="true"></i>
            </div>
            <h3>Confirmation</h3>
            <p>Learn about and request assistance for Confirmation.</p>
            <span class="service-link">Request service &rarr;</span>
        </a>

        <a href="request.php?service=mass_intention" class="service-card">
            <div class="service-icon icon-intention">
                <i class="fa-solid fa-hands-praying" aria-hidden="true"></i>
            </div>
            <h3>Mass Intention</h3>
            <p>Offer a Mass intention for a loved one or special occasion.</p>
            <span class="service-link">Request service &rarr;</span>
        </a>
    </div>
</section>

<?php if ($upcomingServiceSchedules): ?>
    <section id="upcoming-services" class="container section">
        <div class="center">
            <h2 class="section-title">Upcoming Parish Services</h2>
            <p class="section-lead">
                View upcoming services scheduled by the parish.
            </p>
            <div class="rule"></div>
        </div>

        <div class="mass-grid">
            <?php foreach ($upcomingServiceSchedules as $serviceSchedule): ?>
                <div class="mass-row">
                    <span class="mass-day">
                        <?= e(SERVICE_LABELS[$serviceSchedule['service_type']] ?? ucfirst($serviceSchedule['service_type'])) ?>
                    </span>

                    <span class="muted small right">
                        <?= e(date('M j, Y', strtotime($serviceSchedule['event_date']))) ?>
                        &middot;
                        <?= e(date('g:i A', strtotime($serviceSchedule['event_time']))) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php $homeEvents = events_public_upcoming(3); ?>
<?php if ($homeEvents): ?>
    <section id="events" class="container section">
        <div class="center">
            <h2 class="section-title">Upcoming Events</h2>
            <div class="rule"></div>
        </div>

        <div class="ev-list" style="margin-top: 1.5rem;">
            <?php foreach ($homeEvents as $ev) { render_event_card($ev); } ?>
        </div>

        <p class="ev-more">
            <a href="events.php" class="btn btn-outline">See all events</a>
        </p>
    </section>
<?php endif; ?>

<?php page_end(); ?>
