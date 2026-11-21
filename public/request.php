<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/requirements.php';
$me = require_user();

$activeServices = service_catalog_active();
$values = [
    'service_type' => $activeServices[0]['service_key'] ?? '',
    'full_name' => $me['name'],
    'email' => $me['email'],
    'phone' => '',
    'preferred_date' => '',
    'notes' => '',
    'public_schedule_consent' => '0',
];
$dynamicValues = [];
$error = null;
$done = null;

if (isset($_GET['service']) && is_string($_GET['service']) && service_find($_GET['service'], true)) {
    $values['service_type'] = $_GET['service'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $_) {
        if ($key === 'full_name' || $key === 'email') continue;
        $values[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    $values['full_name'] = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $values['email'] = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $values['public_schedule_consent'] = (($_POST['public_schedule_consent'] ?? '') === '1') ? '1' : '0';
    $dynamicValues = is_array($_POST['dynamic_fields'] ?? null) ? $_POST['dynamic_fields'] : [];

    $name = $values['full_name'];
    $mail = strtolower($values['email']);
    $phone = $values['phone'];
    $date = $values['preferred_date'];
    $notes = $values['notes'];
    $selectedService = service_find($values['service_type'], true);

    if (req_post_too_large()) {
        $error = 'That submission is too large. Please reduce the file size; each uploaded file may be up to 5 MB.';
    } elseif (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$selectedService) {
        $error = 'This service is no longer accepting new requests. Please choose another service.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $error = 'Please enter your full name.';
    } elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 255) {
        $error = 'Please enter a valid email address.';
    } elseif (mb_strlen($phone) > 40 || mb_strlen($notes) > 1000) {
        $error = 'Please shorten your contact number or notes.';
    } elseif ($date !== '' && !(($d = DateTime::createFromFormat('Y-m-d', $date)) && $d->format('Y-m-d') === $date)) {
        $error = 'Please enter a valid preferred date.';
    } else {
        $validatedDynamic = service_validate_submission((int)$selectedService['id'], $dynamicValues, $_FILES['dynamic_files'] ?? []);
        if (!empty($validatedDynamic['error'])) {
            $error = $validatedDynamic['error'];
        } else {
            $pdo = db();
            $storedDynamicPaths = [];
            $requestCommitted = false;
            try {
                $pdo->beginTransaction();
                $rid = create_request([
                    'user_id' => $me['id'],
                    'service_type' => $values['service_type'],
                    'full_name' => $name,
                    'email' => $mail,
                    'phone' => $phone !== '' ? $phone : null,
                    'preferred_date' => $date !== '' ? $date : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'method' => 'online',
                    'public_schedule_consent' => $values['public_schedule_consent'],
                ]);
                $storedDynamicPaths = service_save_submission($rid, $validatedDynamic);
                $pdo->commit();
                $requestCommitted = true;
                $ref = get_request($rid)['reference_no'] ?? '';
                log_action('request_created', service_label($values['service_type']) . " request {$ref}");
                $_SESSION['submitted'] = ['name' => $name, 'email' => $mail, 'ref' => $ref, 'service' => $values['service_type']];
                redirect('request.php?done=1');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (!$requestCommitted && $storedDynamicPaths) {
                    service_remove_stored_paths($storedDynamicPaths);
                }
                error_log($ex->getMessage());
                $error = 'Something went wrong while sending your request. Please check your files and try again.';
            }
        }
    }
}

if (isset($_GET['done']) && isset($_SESSION['submitted'])) {
    $done = $_SESSION['submitted'];
    unset($_SESSION['submitted']);
}

if ($done) {
    page_start('Request a Service — St. Joseph Parish', 'Submit a request for a parish service.', 'request');
    $first = explode(' ', $done['name'])[0];
    ?>
<div class="container narrow-page center">
  <div class="card done-card">
    <span class="cross" aria-hidden="true">✝</span>
    <h1>Request received</h1>
    <p class="muted small">Thank you, <?= e($first) ?>. The parish office will review your request and contact you at <strong class="fg"><?= e($done['email']) ?></strong> within 2–3 working days to confirm the schedule.</p>
    <?php if (!empty($done['ref'])): ?>
      <p class="small" style="margin-top:1rem"><span class="muted">Your reference number</span><br><strong style="font:600 1.5rem var(--font-display);letter-spacing:.05em"><?= e($done['ref']) ?></strong></p>
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

$selectedService = $values['service_type'] !== '' ? service_find($values['service_type'], true) : null;
$dynamicFields = $selectedService ? service_fields((int)$selectedService['id'], true) : [];
$customService = $selectedService && !isset(SERVICE_LABELS[$selectedService['service_key']]);
$guide = $customService
    ? ['overview' => ($selectedService['description'] ?: 'Please complete the information requested by the parish office.'), 'tips' => []]
    : service_guide($values['service_type'] ?: 'baptism');

page_start(
    'Request a Service — St. Joseph Parish',
    'Submit a request for an available parish service.',
    'request'
);
?>
<div class="container narrow-page">
  <div class="center">
    <h1 class="page-title">Request a Parish Service</h1>
    <div class="rule"></div>
    <p class="muted small mt-3">Fill out the form and the parish office will contact you to confirm details and requirements.</p>
  </div>

  <?php if (!$activeServices): ?>
    <div class="card panel"><p class="muted">The parish is not currently accepting online service requests. Please contact the parish office.</p></div>
  <?php else: ?>
  <aside id="guide" class="card guide" aria-live="polite">
    <span class="guide-star" aria-hidden="true">✦</span>
    <div class="guide-body" id="guide-body">
      <p class="label-caps" id="guide-kicker"><?= e($selectedService['name'] ?? service_label($values['service_type'])) ?></p>
      <p class="small" id="guide-main"><?= e($guide['overview']) ?></p>
      <?php if (!empty($guide['tips'])): ?><ul class="tips muted small" id="guide-tips"><?php foreach ($guide['tips'] as $tip): ?><li><span class="dot" aria-hidden="true">•</span><span><?= e($tip) ?></span></li><?php endforeach; ?></ul><?php else: ?><ul class="tips muted small" id="guide-tips" hidden></ul><?php endif; ?>
    </div>
  </aside>

  <form method="post" action="request.php" enctype="multipart/form-data" class="card form" id="request-form" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="service_type">Service requested</label>
      <select id="service_type" name="service_type" data-field="serviceType" onchange="window.location.href='request.php?service='+encodeURIComponent(this.value)">
        <?php foreach ($activeServices as $service): ?>
          <option value="<?= e($service['service_key']) ?>" <?= $service['service_key'] === $values['service_type'] ? 'selected' : '' ?>><?= e($service['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="full_name">Full name</label>
      <input id="full_name" name="full_name" data-field="fullName" maxlength="120" required value="<?= e($values['full_name']) ?>" placeholder="Your full name">
    </div>
    <div class="grid-2">
      <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" data-field="email" maxlength="255" required value="<?= e($values['email']) ?>" placeholder="you@example.com"></div>
      <div class="field"><label for="phone">Contact number <span class="muted normal">(optional)</span></label><input id="phone" name="phone" data-field="phone" maxlength="40" value="<?= e($values['phone']) ?>" placeholder="+63 917 000 0000"></div>
    </div>
    <div class="field"><label for="preferred_date">Preferred date <span class="muted normal">(optional)</span></label><input id="preferred_date" name="preferred_date" type="date" data-field="preferredDate" value="<?= e($values['preferred_date']) ?>"></div>
    <div class="field"><label for="notes">Notes <span class="muted normal">(optional)</span></label><textarea id="notes" name="notes" data-field="notes" rows="4" maxlength="1000" placeholder="Anything the parish office should know."><?= e($values['notes']) ?></textarea></div>

    <?php if ($dynamicFields): ?>
      <div class="field full"><h2 class="h2" style="margin-bottom:.25rem">Additional information</h2><p class="muted small">Please provide the details requested for this service.</p></div>
      <?php foreach ($dynamicFields as $field): $key = $field['field_key']; $type = $field['field_type']; $val = $dynamicValues[$key] ?? ''; $required = (int)$field['is_required'] === 1; $fieldId = 'dynamic_' . $key; ?>
        <div class="field">
          <?php if ($type !== 'checkbox'): ?><label for="<?= e($fieldId) ?>"><?= e($field['label']) ?><?= $required ? ' *' : ' (optional)' ?></label><?php endif; ?>
          <?php if ($type === 'textarea'): ?>
            <textarea id="<?= e($fieldId) ?>" name="dynamic_fields[<?= e($key) ?>]" rows="3" maxlength="2000" <?= $required ? 'required' : '' ?> placeholder="<?= e($field['placeholder'] ?? '') ?>"><?= e(is_string($val) ? $val : '') ?></textarea>
          <?php elseif ($type === 'select'): ?>
            <select id="<?= e($fieldId) ?>" name="dynamic_fields[<?= e($key) ?>]" <?= $required ? 'required' : '' ?>><option value="">Choose an option</option><?php foreach ($field['options'] as $option): ?><option value="<?= e($option) ?>" <?= $val === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
          <?php elseif ($type === 'radio'): ?>
            <div style="display:grid;gap:.45rem"><?php foreach ($field['options'] as $i => $option): ?><label style="display:flex;gap:.5rem;align-items:center;font-weight:400"><input type="radio" name="dynamic_fields[<?= e($key) ?>]" value="<?= e($option) ?>" <?= $val === $option ? 'checked' : '' ?> <?= $required && $i === 0 ? 'required' : '' ?>><?= e($option) ?></label><?php endforeach; ?></div>
          <?php elseif ($type === 'checkbox'): ?>
            <label for="<?= e($fieldId) ?>" style="display:flex;gap:.55rem;align-items:flex-start;font-weight:400"><input id="<?= e($fieldId) ?>" type="checkbox" name="dynamic_fields[<?= e($key) ?>]" value="1" style="width:auto;margin-top:.25rem" <?= $val === '1' ? 'checked' : '' ?> <?= $required ? 'required' : '' ?>><span><?= e($field['label']) ?><?= $required ? ' *' : '' ?></span></label>
          <?php elseif ($type === 'file'): ?>
            <input id="<?= e($fieldId) ?>" type="file" name="dynamic_files[<?= e($key) ?>]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?= $required ? 'required' : '' ?>>
            <p class="muted small">PDF, JPG, or PNG; maximum 5 MB.</p>
          <?php else: ?>
            <input id="<?= e($fieldId) ?>" type="<?= e($type === 'tel' ? 'tel' : $type) ?>" name="dynamic_fields[<?= e($key) ?>]" value="<?= e(is_string($val) ? $val : '') ?>" maxlength="2000" placeholder="<?= e($field['placeholder'] ?? '') ?>" <?= $required ? 'required' : '' ?>>
          <?php endif; ?>
          <?php if (!empty($field['help_text'])): ?><p class="muted small"><?= e($field['help_text']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="field full">
      <label for="public_schedule_consent" style="display:flex;align-items:flex-start;gap:.6rem;font-weight:400">
        <input type="checkbox" id="public_schedule_consent" name="public_schedule_consent" value="1" style="width:auto;margin-top:.25rem;flex:0 0 auto" <?= $values['public_schedule_consent'] === '1' ? 'checked' : '' ?>>
        <span>I voluntarily agree that the parish may display my service type, scheduled date, and time on the public parish website, including the Parish Events page.</span>
      </label>
      <p class="muted small">This is optional and will not affect your request. Your name, contact details, venue, officiant, and notes will not be shown publicly.</p>
    </div>
    <?php if ($error): ?><p class="alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <button type="submit" class="btn btn-primary btn-block">Send Request</button>
  </form>
  <?php endif; ?>
</div>
<script>window.PSC_GUIDES = <?= json_encode(guides_for_js(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/app.js"></script>
<?php page_end(); ?>
