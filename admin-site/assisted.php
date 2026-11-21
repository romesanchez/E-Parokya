<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/requirements.php';
$me = require_admin();
$self = url('admin/assisted.php');
$activeServices = service_catalog_active();
$v = ['service_type' => $activeServices[0]['service_key'] ?? '', 'full_name' => '', 'email' => '', 'phone' => '', 'preferred_date' => '', 'notes' => ''];
$dynamicValues = [];
$error = null;

if (isset($_GET['service']) && is_string($_GET['service']) && service_find($_GET['service'], true)) {
    $v['service_type'] = $_GET['service'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $key => $_) {
        $v[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    $v['email'] = strtolower($v['email']);
    $dynamicValues = is_array($_POST['dynamic_fields'] ?? null) ? $_POST['dynamic_fields'] : [];
    $service = service_find($v['service_type'], true);

    if (req_post_too_large()) {
        $error = 'That file is too large. The maximum size is 5 MB.';
    } elseif (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$service) {
        $error = 'This service is disabled or unavailable for new requests.';
    } elseif (mb_strlen($v['full_name']) < 2 || mb_strlen($v['full_name']) > 120) {
        $error = 'Please enter the requester\'s full name.';
    } elseif ($v['email'] === '' && $v['phone'] === '') {
        $error = 'Please enter at least an email or a contact number.';
    } elseif ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 255)) {
        $error = 'Please enter a valid email address.';
    } elseif (mb_strlen($v['phone']) > 40 || mb_strlen($v['notes']) > 1000) {
        $error = 'Please shorten the contact number or notes.';
    } elseif ($v['preferred_date'] !== '' && !(($d = DateTime::createFromFormat('Y-m-d', $v['preferred_date'])) && $d->format('Y-m-d') === $v['preferred_date'])) {
        $error = 'Please enter a valid preferred date.';
    } else {
        $validatedDynamic = service_validate_submission((int)$service['id'], $dynamicValues, $_FILES['dynamic_files'] ?? []);
        if (!empty($validatedDynamic['error'])) {
            $error = $validatedDynamic['error'];
        } else {
            $pdo = db();
            $storedDynamicPaths = [];
            $requestCommitted = false;
            try {
                $pdo->beginTransaction();
                $acct = $v['email'] !== '' ? find_user_by_email($v['email']) : null;
                $rid = create_request([
                    'user_id' => ($acct && $acct['role'] === 'user') ? (int)$acct['id'] : null,
                    'service_type' => $v['service_type'],
                    'full_name' => $v['full_name'],
                    'email' => $v['email'],
                    'phone' => $v['phone'] !== '' ? $v['phone'] : null,
                    'preferred_date' => $v['preferred_date'] !== '' ? $v['preferred_date'] : null,
                    'notes' => $v['notes'] !== '' ? $v['notes'] : null,
                    'method' => 'assisted',
                    'created_by' => $me['id'],
                ]);
                $storedDynamicPaths = service_save_submission($rid, $validatedDynamic);
                $pdo->commit();
                $requestCommitted = true;
                $ref = get_request($rid)['reference_no'] ?? '';
                log_action('request_assisted', "{$ref} for {$v['full_name']} (" . service_label($v['service_type']) . ')');
                flash_modal('REQUEST RECORDED', "Reference number: {$ref}. Give this number to the parishioner so they can follow up on the request.");
                redirect($self);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (!$requestCommitted && $storedDynamicPaths) {
                    service_remove_stored_paths($storedDynamicPaths);
                }
                error_log($ex->getMessage());
                $error = 'Could not save the request. Please try again.';
            }
        }
    }
}

$selectedService = $v['service_type'] !== '' ? service_find($v['service_type'], true) : null;
$dynamicFields = $selectedService ? service_fields((int)$selectedService['id'], true) : [];
admin_start('Assisted Request', 'assisted');
?>
<div class="container dash wide">
  <h1 class="page-title left">Assisted Request</h1>
  <p class="muted small">Record a request for a parishioner who came to the office or called. It gets a reference number and is marked as <strong>Assisted</strong>, processed by <?= e($me['name']) ?>.</p>
  <?php if (!$activeServices): ?><div class="card panel"><p class="muted">All parish services are currently disabled. Enable a service in Service Management before recording new requests.</p></div><?php else: ?>
  <form method="post" action="<?= e($self) ?>" enctype="multipart/form-data" class="card sched-form">
    <?= csrf_field() ?>
    <div class="field full"><label for="service_type">Service requested</label>
      <select id="service_type" name="service_type" onchange="window.location.href='<?= e($self) ?>?service='+encodeURIComponent(this.value)">
        <?php foreach ($activeServices as $serviceOption): ?><option value="<?= e($serviceOption['service_key']) ?>" <?= $serviceOption['service_key'] === $v['service_type'] ? 'selected' : '' ?>><?= e($serviceOption['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field full"><label for="full_name">Requester's full name</label><input id="full_name" name="full_name" required maxlength="120" value="<?= e($v['full_name']) ?>"></div>
    <div class="field"><label for="email">Email <span class="muted normal">(optional if contact number is given)</span></label><input id="email" name="email" type="email" maxlength="255" value="<?= e($v['email']) ?>"></div>
    <div class="field"><label for="phone">Contact number <span class="muted normal">(optional if email is given)</span></label><input id="phone" name="phone" maxlength="40" value="<?= e($v['phone']) ?>"></div>
    <div class="field"><label for="preferred_date">Preferred date <span class="muted normal">(optional)</span></label><input id="preferred_date" name="preferred_date" type="date" value="<?= e($v['preferred_date']) ?>"></div>
    <div class="field full"><label for="notes">Notes <span class="muted normal">(optional)</span></label><textarea id="notes" name="notes" rows="3" maxlength="1000"><?= e($v['notes']) ?></textarea></div>
    <?php if ($dynamicFields): ?><div class="field full"><h2 class="h2">Additional information</h2></div>
      <?php foreach ($dynamicFields as $field): $key = $field['field_key']; $type = $field['field_type']; $val = $dynamicValues[$key] ?? ''; $required = (int)$field['is_required'] === 1; $fieldId = 'dynamic_' . $key; ?>
        <div class="field">
          <?php if ($type !== 'checkbox'): ?><label for="<?= e($fieldId) ?>"><?= e($field['label']) ?><?= $required ? ' *' : ' (optional)' ?></label><?php endif; ?>
          <?php if ($type === 'textarea'): ?><textarea id="<?= e($fieldId) ?>" name="dynamic_fields[<?= e($key) ?>]" rows="3" maxlength="2000" <?= $required ? 'required' : '' ?> placeholder="<?= e($field['placeholder'] ?? '') ?>"><?= e(is_string($val) ? $val : '') ?></textarea>
          <?php elseif ($type === 'select'): ?><select id="<?= e($fieldId) ?>" name="dynamic_fields[<?= e($key) ?>]" <?= $required ? 'required' : '' ?>><option value="">Choose an option</option><?php foreach ($field['options'] as $option): ?><option value="<?= e($option) ?>" <?= $val === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
          <?php elseif ($type === 'radio'): ?><div style="display:grid;gap:.45rem"><?php foreach ($field['options'] as $i => $option): ?><label style="display:flex;gap:.5rem;align-items:center;font-weight:400"><input type="radio" name="dynamic_fields[<?= e($key) ?>]" value="<?= e($option) ?>" <?= $val === $option ? 'checked' : '' ?> <?= $required && $i === 0 ? 'required' : '' ?>><?= e($option) ?></label><?php endforeach; ?></div>
          <?php elseif ($type === 'checkbox'): ?><label for="<?= e($fieldId) ?>" style="display:flex;gap:.55rem;align-items:flex-start;font-weight:400"><input id="<?= e($fieldId) ?>" type="checkbox" name="dynamic_fields[<?= e($key) ?>]" value="1" style="width:auto;margin-top:.25rem" <?= $val === '1' ? 'checked' : '' ?> <?= $required ? 'required' : '' ?>><span><?= e($field['label']) ?><?= $required ? ' *' : '' ?></span></label>
          <?php elseif ($type === 'file'): ?><input id="<?= e($fieldId) ?>" type="file" name="dynamic_files[<?= e($key) ?>]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?= $required ? 'required' : '' ?>><p class="muted small">PDF, JPG, or PNG; maximum 5 MB.</p>
          <?php else: ?><input id="<?= e($fieldId) ?>" type="<?= e($type === 'tel' ? 'tel' : $type) ?>" name="dynamic_fields[<?= e($key) ?>]" value="<?= e(is_string($val) ? $val : '') ?>" maxlength="2000" placeholder="<?= e($field['placeholder'] ?? '') ?>" <?= $required ? 'required' : '' ?>><?php endif; ?>
          <?php if (!empty($field['help_text'])): ?><p class="muted small"><?= e($field['help_text']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($error): ?><p class="alert-error full" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="full"><button type="submit" class="btn btn-success">Record request</button></div>
  </form>
  <?php endif; ?>
</div>
<?php admin_end(); ?>
