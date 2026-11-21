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
$activeServiceCatalog = service_catalog_active();

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
        <?php if ($activeServiceCatalog): ?>
            <?php foreach ($activeServiceCatalog as $service): ?>
                <?php $icon = isset(SERVICE_ICONS[$service['icon']]) ? $service['icon'] : 'church'; ?>
                <a href="request.php?service=<?= e(rawurlencode($service['service_key'])) ?>" class="service-card">
                    <div class="service-icon">
                        <i class="fa-solid fa-<?= e($icon) ?>" aria-hidden="true"></i>
                    </div>
                    <h3><?= e($service['name']) ?></h3>
                    <p><?= e($service['description'] ?: 'Contact the parish office for more information about this service.') ?></p>
                    <span class="service-link">Request service &rarr;</span>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="center muted small">The parish is not currently accepting service requests online.</p>
        <?php endif; ?>
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
                        <?= e(service_label($serviceSchedule['service_type'])) ?>
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
