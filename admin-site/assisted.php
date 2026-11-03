<?php
require_once __DIR__ . '/../includes/admin_layout.php';
$me = require_admin();

$self = url('admin/assisted.php');
$v = ['service_type' => 'baptism', 'full_name' => '', 'email' => '', 'phone' => '', 'preferred_date' => '', 'notes' => ''];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) {
        $v[$k] = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    }
    $v['email'] = strtolower($v['email']);

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!isset(SERVICE_LABELS[$v['service_type']])) {
        $error = 'Please choose a valid service.';
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
        try {
            // If the requester already has an account, the request also shows in their My Account.
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
            $ref = get_request($rid)['reference_no'] ?? '';
            log_action('request_assisted', "{$ref} for {$v['full_name']} (" . SERVICE_LABELS[$v['service_type']] . ')');
            flash_modal('REQUEST RECORDED', "Reference number: {$ref}. Give this number to the parishioner so they can follow up on the request.");
            redirect($self);
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $error = 'Could not save the request. Please try again.';
        }
    }
}

admin_start('Assisted Request', 'assisted');
?>
<div class="container dash wide">
  <h1 class="page-title left">Assisted Request</h1>
  <p class="muted small">Record a request for a parishioner who came to the office or called. It gets a reference number and is marked as <strong>Assisted</strong>, processed by <?= e($me['name']) ?>.</p>

  <form method="post" action="<?= e($self) ?>" class="card sched-form">
    <?= csrf_field() ?>
    <div class="field full">
      <label for="service_type">Service requested</label>
      <select id="service_type" name="service_type">
        <?php foreach (SERVICE_LABELS as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $k === $v['service_type'] ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field full">
      <label for="full_name">Requester's full name</label>
      <input id="full_name" name="full_name" required maxlength="120" value="<?= e($v['full_name']) ?>">
    </div>
    <div class="field">
      <label for="email">Email <span class="muted normal">(optional if contact number is given)</span></label>
      <input id="email" name="email" type="email" maxlength="255" value="<?= e($v['email']) ?>">
    </div>
    <div class="field">
      <label for="phone">Contact number <span class="muted normal">(optional if email is given)</span></label>
      <input id="phone" name="phone" maxlength="40" value="<?= e($v['phone']) ?>">
    </div>
    <div class="field">
      <label for="preferred_date">Preferred date <span class="muted normal">(optional)</span></label>
      <input id="preferred_date" name="preferred_date" type="date" value="<?= e($v['preferred_date']) ?>">
    </div>
    <div class="field full">
      <label for="notes">Notes <span class="muted normal">(optional)</span></label>
      <textarea id="notes" name="notes" rows="3" maxlength="1000"><?= e($v['notes']) ?></textarea>
    </div>
    <?php if ($error): ?><p class="alert-error full" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="full"><button type="submit" class="btn btn-primary">Record request</button></div>
  </form>
</div>
<?php admin_end();