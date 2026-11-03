<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';
$me = require_user();

$values = ['service_type' => 'baptism', 'full_name' => '', 'email' => '', 'phone' => '', 'preferred_date' => '', 'notes' => ''];
$error = null;

// Prefill from the logged-in account
$values['full_name'] = $me['name'];
$values['email'] = $me['email'];

// Preselect the service when coming from the home page (?service=wedding)
if (isset($_GET['service']) && is_string($_GET['service']) && isset(SERVICE_LABELS[$_GET['service']])) {
    $values['service_type'] = $_GET['service'];
}

// Post/Redirect/Get: show the thank-you screen after a successful submit.
if (isset($_GET['done']) && isset($_SESSION['submitted'])) {
    $done = $_SESSION['submitted'];
    unset($_SESSION['submitted']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $k => $_) {
        $values[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }
    $name = trim($values['full_name']);
    $mail = trim($values['email']);
    $phone = trim($values['phone']);
    $date = trim($values['preferred_date']);
    $notes = trim($values['notes']);

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!isset(SERVICE_LABELS[$values['service_type']])) {
        $error = 'Please choose a valid service.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $error = 'Please enter your full name.';
    } elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 255) {
        $error = 'Please enter a valid email address.';
    } elseif (mb_strlen($phone) > 40 || mb_strlen($notes) > 1000) {
        $error = 'Please shorten your contact number or notes.';
    } elseif ($date !== '' && !(($d = DateTime::createFromFormat('Y-m-d', $date)) && $d->format('Y-m-d') === $date)) {
        $error = 'Please enter a valid preferred date.';
    } else {
        try {
            $rid = create_request([
                'user_id' => $me['id'],
                'service_type' => $values['service_type'],
                'full_name' => $name,
                'email' => $mail,
                'phone' => $phone !== '' ? $phone : null,
                'preferred_date' => $date !== '' ? $date : null,
                'notes' => $notes !== '' ? $notes : null,
                'method' => 'online',
            ]);
            $ref = get_request($rid)['reference_no'] ?? '';
            log_action('request_created', SERVICE_LABELS[$values['service_type']] . " request {$ref}");
            $_SESSION['submitted'] = ['name' => $name, 'email' => $mail, 'ref' => $ref];
            redirect('request.php?done=1');
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $error = 'Something went wrong while sending your request. Please try again.';
        }
    }
}

if (isset($done)) {
    page_start('Request a Service — St. Joseph Parish', 'Submit a request for a parish service.', 'request');
    $first = explode(' ', $done['name'])[0];
    ?>
<div class="container narrow-page center">
  <div class="card done-card">
    <span class="cross" aria-hidden="true">✝</span>
    <h1>Request received</h1>
    <p class="muted small">Thank you, <?= e($first) ?>. The parish office will review your request and contact you at <strong class="fg"><?= e($done['email']) ?></strong> within 2–3 working days to confirm the schedule.</p>
    <?php if (!empty($done['ref'])): ?>
      <p class="small" style="margin-top:1rem"><span class="muted">Your reference number</span><br>
        <strong style="font:600 1.5rem var(--font-display);letter-spacing:.05em"><?= e($done['ref']) ?></strong></p>
      <p class="muted small">Keep this number. You can quote it when you contact the parish office.</p>
    <?php endif; ?>
    <a href="request.php" class="btn btn-outline mt-8">Submit another request</a>
    <a href="my-requests.php" class="btn btn-primary mt-8">View My Requests</a>
  </div>
</div>
<?php
    page_end();
    exit;
}

page_start(
    'Request a Service — St. Joseph Parish',
    'Submit a request for baptism, wedding, funeral, house blessing, first communion, confirmation or mass intention at St. Joseph Parish.',
    'request'
);
$svc = $values['service_type'];
$g = service_guide($svc);
?>
<div class="container narrow-page">
  <div class="center">
    <h1 class="page-title">Request a Parish Service</h1>
    <div class="rule"></div>
    <p class="muted small mt-3">Fill out the form and the parish office will contact you to confirm details and requirements.</p>
  </div>

  <aside id="guide" class="card guide" aria-live="polite">
    <span class="guide-star" aria-hidden="true">✦</span>
    <div class="guide-body" id="guide-body">
      <p class="label-caps" id="guide-kicker"><?= e(SERVICE_LABELS[$svc]) ?></p>
      <p class="small" id="guide-main"><?= e($g['overview']) ?></p>
      <ul class="tips muted small" id="guide-tips">
        <?php foreach ($g['tips'] as $tip): ?><li><span class="dot" aria-hidden="true">•</span><span><?= e($tip) ?></span></li><?php endforeach; ?>
      </ul>
    </div>
  </aside>

  <form method="post" action="request.php" class="card form" id="request-form" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="service_type">Service requested</label>
      <select id="service_type" name="service_type" data-field="serviceType">
        <?php foreach (SERVICE_LABELS as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $k === $svc ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="full_name">Full name</label>
      <input id="full_name" name="full_name" data-field="fullName" maxlength="120" required
             value="<?= e($values['full_name']) ?>" placeholder="<?= e(field_placeholder($svc, 'fullName') ?? 'e.g. Maria Santos') ?>">
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" data-field="email" maxlength="255" required
               value="<?= e($values['email']) ?>" placeholder="you@example.com">
      </div>
      <div class="field">
        <label for="phone">Contact number <span class="muted normal">(optional)</span></label>
        <input id="phone" name="phone" data-field="phone" maxlength="40"
               value="<?= e($values['phone']) ?>" placeholder="<?= e(field_placeholder($svc, 'phone') ?? '+63 917 000 0000') ?>">
      </div>
    </div>

    <div class="field">
      <label for="preferred_date">Preferred date <span class="muted normal">(optional)</span></label>
      <input id="preferred_date" name="preferred_date" type="date" data-field="preferredDate" value="<?= e($values['preferred_date']) ?>">
    </div>

    <div class="field">
      <label for="notes">Notes <span class="muted normal">(optional)</span></label>
      <textarea id="notes" name="notes" data-field="notes" rows="4" maxlength="1000"
        placeholder="<?= e(field_placeholder($svc, 'notes') ?? 'Anything the parish office should know — number of sponsors, location, intentions…') ?>"><?= e($values['notes']) ?></textarea>
    </div>

    <?php if ($error): ?><p class="alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <button type="submit" class="btn btn-primary btn-block">Send Request</button>
  </form>
</div>
<script>window.PSC_GUIDES = <?= json_encode(guides_for_js(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/app.js"></script>
<?php page_end();