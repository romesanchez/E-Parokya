<?php
require_once __DIR__ . '/../includes/admin_layout.php';
$me = require_admin();
$self = url('admin/services.php');
$error = null;
$success = null;
$selectedId = (int)(filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
$fieldEditId = (int)(filter_var($_GET['field'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
$allowedTypes = ['text', 'textarea', 'email', 'tel', 'number', 'date', 'select', 'radio', 'checkbox', 'file'];

function service_admin_options(string $raw, string $type): array
{
    if (!in_array($type, ['select', 'radio'], true)) {
        return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $options = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $options[] = mb_substr($line, 0, 100);
        }
    }
    $options = array_values(array_unique($options));
    if (count($options) < 2 || count($options) > 20) {
        throw new InvalidArgumentException('Dropdown and radio fields need 2 to 20 unique options, one per line.');
    }
    return $options;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $postedServiceId = (int)(filter_var($_POST['service_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
    $selectedId = $postedServiceId ?: $selectedId;
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            if ($action === 'create_service' || $action === 'update_service') {
                $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
                $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
                $icon = is_string($_POST['icon'] ?? null) ? $_POST['icon'] : 'church';
                $sortOrder = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
                $sortOrder = $sortOrder === false ? -1 : (int)$sortOrder;
                if ($sortOrder < 0 || $sortOrder > 100000) {
                    throw new InvalidArgumentException('Display order must be between 0 and 100000.');
                }
                if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                    throw new InvalidArgumentException('Enter a service name between 2 and 120 characters.');
                }
                $exceptId = $action === 'update_service' ? $postedServiceId : 0;
                if (service_name_exists($name, $exceptId)) {
                    throw new InvalidArgumentException('A service with that name already exists. Choose a different name.');
                }
                if (mb_strlen($description) > 500 || !isset(SERVICE_ICONS[$icon])) {
                    throw new InvalidArgumentException('Check the description and service icon.');
                }
                if ($action === 'create_service') {
                    $selectedId = service_create($name, $description, $icon, $sortOrder);
                    log_action('service_created', "Created service {$name}");
                    admin_flash('ok', 'Service created. Add its custom form fields below.');
                } else {
                    $current = service_catalog_find_by_id($postedServiceId);
                    if (!$current || (int)$current['is_archived'] === 1) {
                        throw new InvalidArgumentException('That service cannot be edited.');
                    }
                    service_update($postedServiceId, $name, $description, $icon, $sortOrder);
                    log_action('service_updated', "Updated service {$name}");
                    admin_flash('ok', 'Service details saved.');
                }
                redirect($self . '?id=' . $selectedId);
            }

            if (in_array($action, ['toggle_service', 'archive_service', 'restore_service'], true)) {
                $service = service_catalog_find_by_id($postedServiceId);
                if (!$service) {
                    throw new InvalidArgumentException('Service not found.');
                }
                if ($action === 'toggle_service') {
                    if ((int)$service['is_archived'] === 1) {
                        throw new InvalidArgumentException('Restore this service before changing its enabled status.');
                    }
                    $active = (int)($service['is_active'] ?? 0) !== 1;
                    service_set_active($postedServiceId, $active);
                    log_action($active ? 'service_enabled' : 'service_disabled', ($active ? 'Enabled ' : 'Disabled ') . $service['name']);
                    admin_flash('ok', $active ? 'Service enabled for new requests.' : 'Service disabled. Existing requests are preserved.');
                } elseif ($action === 'archive_service') {
                    if ((int)$service['is_archived'] === 1) {
                        throw new InvalidArgumentException('This service is already archived.');
                    }
                    service_archive($postedServiceId);
                    log_action('service_archived', 'Archived ' . $service['name']);
                    admin_flash('ok', 'Service archived. Existing requests and answers were preserved.');
                } else {
                    if ((int)$service['is_archived'] !== 1) {
                        throw new InvalidArgumentException('Only archived services can be restored.');
                    }
                    if (service_name_exists((string)$service['name'], $postedServiceId)) {
                        throw new InvalidArgumentException('Another active or unarchived service already uses this name. Rename or archive that service before restoring this one.');
                    }
                    service_restore($postedServiceId);
                    log_action('service_restored', 'Restored ' . $service['name']);
                    admin_flash('ok', 'Service restored as disabled. Enable it when ready.');
                }
                redirect($self . ($action === 'restore_service' ? '' : '?id=' . $postedServiceId));
            }

            if (in_array($action, ['create_field', 'update_field'], true)) {
                $service = service_catalog_find_by_id($postedServiceId);
                if (!$service || (int)$service['is_archived'] === 1) {
                    throw new InvalidArgumentException('Choose an active, non-archived service before editing its form.');
                }
                $label = trim(is_string($_POST['label'] ?? null) ? $_POST['label'] : '');
                $type = is_string($_POST['field_type'] ?? null) ? $_POST['field_type'] : 'text';
                $placeholder = trim(is_string($_POST['placeholder'] ?? null) ? $_POST['placeholder'] : '');
                $helpText = trim(is_string($_POST['help_text'] ?? null) ? $_POST['help_text'] : '');
                $sortOrder = filter_var($_POST['field_sort_order'] ?? 0, FILTER_VALIDATE_INT);
                $sortOrder = $sortOrder === false ? -1 : (int)$sortOrder;
                if ($sortOrder < 0 || $sortOrder > 100000) {
                    throw new InvalidArgumentException('Field display order must be between 0 and 100000.');
                }
                $required = ($_POST['is_required'] ?? '') === '1';
                $options = service_admin_options(is_string($_POST['options'] ?? null) ? $_POST['options'] : '', $type);
                if (mb_strlen($label) < 2 || mb_strlen($label) > 120 || !in_array($type, $allowedTypes, true)) {
                    throw new InvalidArgumentException('Enter a valid field label and field type.');
                }
                if (mb_strlen($placeholder) > 160 || mb_strlen($helpText) > 255) {
                    throw new InvalidArgumentException('Placeholder or help text is too long.');
                }
                if ($action === 'create_field') {
                    service_create_field($postedServiceId, $label, $type, $required, $placeholder, $helpText, $options, $sortOrder);
                    log_action('service_field_added', $service['name'] . ': ' . $label);
                    admin_flash('ok', 'Form field added. Its database variable was generated automatically.');
                } else {
                    $fieldId = (int)(filter_var($_POST['field_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
                    $exists = service_field_find($fieldId, $postedServiceId);
                    if (!$exists) {
                        throw new InvalidArgumentException('Form field not found.');
                    }
                    service_update_field($fieldId, $postedServiceId, $label, $type, $required, $placeholder, $helpText, $options, $sortOrder);
                    log_action('service_field_updated', $service['name'] . ': ' . $label);
                    admin_flash('ok', 'Form field updated. Existing submitted answers retain their original label and key.');
                }
                redirect($self . '?id=' . $postedServiceId);
            }

            if ($action === 'toggle_field') {
                $parentService = service_catalog_find_by_id($postedServiceId);
                if (!$parentService || (int)$parentService['is_archived'] === 1) {
                    throw new InvalidArgumentException('Restore the service before changing its form fields.');
                }
                $fieldId = (int)(filter_var($_POST['field_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
                $field = service_field_find($fieldId, $postedServiceId);
                if (!$field) {
                    throw new InvalidArgumentException('Form field not found.');
                }
                $active = (int)$field['is_active'] !== 1;
                service_set_field_active($fieldId, $postedServiceId, $active);
                log_action($active ? 'service_field_enabled' : 'service_field_disabled', $field['label']);
                admin_flash('ok', $active ? 'Field enabled.' : 'Field disabled. Previously submitted answers are preserved.');
                redirect($self . '?id=' . $postedServiceId);
            }

            throw new InvalidArgumentException('Unknown service-management action.');
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $error = $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'The change could not be saved. Please try again.';
        }
    }
}

$services = service_catalog_all(true);
$selected = $selectedId ? service_catalog_find_by_id($selectedId) : null;
$fields = $selected ? service_fields((int)$selected['id'], false) : [];
$editingField = $fieldEditId && $selected ? service_field_find($fieldEditId, (int)$selected['id']) : null;
if ($fieldEditId && !$editingField) {
    $error = $error ?: 'The selected form field could not be found.';
}
admin_start('Service Management', 'services');
?>
<style>
.service-admin-grid{display:grid;grid-template-columns:minmax(260px,.85fr) minmax(0,1.5fr);gap:1rem;align-items:start}
.service-row{display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;padding:.8rem 0;border-top:1px solid var(--border)}
.service-row:first-child{border-top:0}.service-actions{display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end}
.service-actions form{display:inline}.service-field{padding:.8rem 0;border-top:1px solid var(--border)}
.service-field:first-child{border-top:0}.service-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem}
.service-form-grid .full{grid-column:1/-1}.service-chip{font:500 .72rem var(--font-body);padding:.2rem .5rem;border-radius:99px;background:var(--secondary);color:var(--muted-foreground)}
@media(max-width:900px){.service-admin-grid{grid-template-columns:1fr}}@media(max-width:600px){.service-form-grid{grid-template-columns:1fr}.service-form-grid .full{grid-column:auto}.service-row{flex-direction:column}.service-actions{justify-content:flex-start}}
</style>
<div class="container dash wide">
  <div class="page-head">
    <div><h1 class="page-title left">Service Management</h1><p class="muted small">Manage the services parishioners can request and configure each request form.</p></div>
    <a class="btn btn-outline" href="<?= e($self) ?>">New service</a>
  </div>
  <?php if ($error): ?><p class="flash flash-err" role="alert"><?= e($error) ?></p><?php endif; ?>

  <div class="service-admin-grid">
    <section class="card panel">
      <h2>Services</h2>
      <p class="muted small">Disabling a service stops new requests. It does not remove existing requests or submitted answers.</p>
      <?php foreach ($services as $service): ?>
        <div class="service-row">
          <div>
            <strong><?= e($service['name']) ?></strong><br>
            <span class="muted small"><?= e($service['service_key']) ?></span><br>
            <?php if ((int)$service['is_archived'] === 1): ?><span class="badge badge-declined">Archived</span>
            <?php elseif ((int)$service['is_active'] === 1): ?><span class="badge badge-approved">Enabled</span>
            <?php else: ?><span class="badge badge-pending">Disabled</span><?php endif; ?>
          </div>
          <div class="service-actions">
            <a class="act act-edit" href="<?= e($self . '?id=' . (int)$service['id']) ?>">Manage</a>
            <?php if ((int)$service['is_archived'] === 1): ?>
              <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="restore_service"><input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>"><button class="act" type="submit">Restore</button></form>
            <?php else: ?>
              <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_service"><input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>"><button class="act" type="submit"><?= (int)$service['is_active'] === 1 ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" action="<?= e($self) ?>" onsubmit="return confirm('Archive this service? Existing requests and answers will be kept.')"><?= csrf_field() ?><input type="hidden" name="action" value="archive_service"><input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>"><button class="act act-delete" type="submit">Archive</button></form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>

    <div style="display:grid;gap:1rem">
      <section class="card panel">
        <?php if ($selected && (int)$selected['is_archived'] === 1): ?>
          <h2>Archived service</h2>
          <p class="muted small">This service is archived and cannot be edited or used for new requests. Restore it first; restored services remain disabled until enabled.</p>
          <p><strong><?= e($selected['name']) ?></strong></p>
          <p class="muted small">Stable service key: <code><?= e($selected['service_key']) ?></code></p>
        <?php else: ?>
        <h2><?= $selected ? 'Edit service' : 'Add a service' ?></h2>
        <form method="post" action="<?= e($self . ($selected ? '?id=' . (int)$selected['id'] : '')) ?>" class="service-form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $selected ? 'update_service' : 'create_service' ?>">
          <?php if ($selected): ?><input type="hidden" name="service_id" value="<?= (int)$selected['id'] ?>"><?php endif; ?>
          <div class="field full"><label for="name">Service name</label><input id="name" name="name" maxlength="120" required value="<?= e($selected['name'] ?? '') ?>" placeholder="e.g. Baptismal Certificate"></div>
          <div class="field full"><label for="description">Description</label><textarea id="description" name="description" rows="3" maxlength="500" placeholder="Explain what this service is for."><?= e($selected['description'] ?? '') ?></textarea></div>
          <div class="field"><label for="icon">Icon</label><select id="icon" name="icon"><?php foreach (SERVICE_ICONS as $key => $label): ?><option value="<?= e($key) ?>" <?= ($selected['icon'] ?? 'church') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="sort_order">Display order</label><input id="sort_order" name="sort_order" type="number" min="0" max="100000" value="<?= e((string)($selected['sort_order'] ?? 0)) ?>"></div>
          <div class="full"><button class="btn btn-success" type="submit"><?= $selected ? 'Save service details' : 'Create service' ?></button></div>
        </form>
        <?php if ($selected): ?><p class="muted small mt-8">Stable service key: <code><?= e($selected['service_key']) ?></code>. Renaming the service does not change this key or existing request history.</p><?php endif; ?>
        <?php endif; ?>
      </section>

      <?php if ($selected): ?>
      <section class="card panel">
        <div class="page-head"><div><h2>Request form fields</h2><p class="muted small">Add fields such as Father's Name. E-Parokya generates the internal key automatically.</p></div></div>
        <?php if ($fields): ?>
          <?php foreach ($fields as $field): ?>
            <div class="service-field">
              <div class="service-row" style="padding:0;border:0">
                <div>
                  <strong><?= e($field['label']) ?></strong> <span class="service-chip"><?= e($field['field_type']) ?></span>
                  <div class="muted small">Variable: <code><?= e($field['field_key']) ?></code> · <?= (int)$field['is_required'] === 1 ? 'Required' : 'Optional' ?><?= (int)$field['is_active'] !== 1 ? ' · Disabled' : '' ?></div>
                </div>
                <?php if ((int)$selected['is_archived'] === 0): ?>
                <div class="service-actions">
                  <a class="act act-edit" href="<?= e($self . '?id=' . (int)$selected['id'] . '&field=' . (int)$field['id']) ?>">Edit</a>
                  <form method="post" action="<?= e($self . '?id=' . (int)$selected['id']) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_field"><input type="hidden" name="service_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="field_id" value="<?= (int)$field['id'] ?>"><button class="act" type="submit"><?= (int)$field['is_active'] === 1 ? 'Disable' : 'Enable' ?></button></form>
                </div>
                <?php endif; ?>
              </div>
              <?php if ($field['help_text']): ?><p class="muted small"><?= e($field['help_text']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?><p class="muted small">No custom fields yet. The standard name, contact, preferred date, and notes fields remain on every request form.</p><?php endif; ?>
      </section>

      <details class="card panel">
        <summary style="cursor:pointer;font-weight:600">Preview parishioner form</summary>
        <p class="muted small">Preview only. This does not create a request or save any information.</p>
        <div class="service-form-grid" style="margin-top:.75rem">
          <div class="field full"><label>Service</label><input value="<?= e($selected['name']) ?>" disabled></div>
          <div class="field full"><label>Full name</label><input placeholder="Your full name" disabled></div>
          <div class="field"><label>Email</label><input type="email" placeholder="you@example.com" disabled></div>
          <div class="field"><label>Contact number</label><input placeholder="Optional" disabled></div>
          <div class="field"><label>Preferred date</label><input type="date" disabled></div>
          <div class="field full"><label>Notes</label><textarea rows="2" disabled></textarea></div>
          <?php foreach ($fields as $previewField): if ((int)$previewField['is_active'] !== 1) continue; $previewType = $previewField['field_type']; ?>
            <div class="field">
              <?php if ($previewType !== 'checkbox'): ?><label><?= e($previewField['label']) ?><?= (int)$previewField['is_required'] === 1 ? ' *' : '' ?></label><?php endif; ?>
              <?php if ($previewType === 'textarea'): ?><textarea rows="2" placeholder="<?= e($previewField['placeholder'] ?? '') ?>" disabled></textarea>
              <?php elseif ($previewType === 'select'): ?><select disabled><option>Choose an option</option><?php foreach ($previewField['options'] as $option): ?><option><?= e($option) ?></option><?php endforeach; ?></select>
              <?php elseif ($previewType === 'radio'): ?><div style="display:grid;gap:.35rem"><?php foreach ($previewField['options'] as $option): ?><label style="font-weight:400"><input type="radio" disabled> <?= e($option) ?></label><?php endforeach; ?></div>
              <?php elseif ($previewType === 'checkbox'): ?><label style="font-weight:400"><input type="checkbox" disabled> <?= e($previewField['label']) ?></label>
              <?php elseif ($previewType === 'file'): ?><input type="file" disabled><small class="muted">PDF, JPG, or PNG; maximum 5 MB.</small>
              <?php else: ?><input type="<?= e($previewType === 'tel' ? 'tel' : $previewType) ?>" placeholder="<?= e($previewField['placeholder'] ?? '') ?>" disabled><?php endif; ?>
              <?php if (!empty($previewField['help_text'])): ?><small class="muted"><?= e($previewField['help_text']) ?></small><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </details>

      <?php if ((int)$selected['is_archived'] === 0): ?>
      <section class="card panel">
        <h2><?= $editingField ? 'Edit form field' : 'Add form field' ?></h2>
        <form method="post" action="<?= e($self . '?id=' . (int)$selected['id']) ?>" class="service-form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $editingField ? 'update_field' : 'create_field' ?>">
          <input type="hidden" name="service_id" value="<?= (int)$selected['id'] ?>">
          <?php if ($editingField): ?><input type="hidden" name="field_id" value="<?= (int)$editingField['id'] ?>"><?php endif; ?>
          <div class="field"><label for="field_label">Field label</label><input id="field_label" name="label" required maxlength="120" value="<?= e($editingField['label'] ?? '') ?>" placeholder="Father's Name"></div>
          <div class="field"><label for="field_type">Field type</label><select id="field_type" name="field_type"><?php foreach ($allowedTypes as $type): ?><option value="<?= e($type) ?>" <?= ($editingField['field_type'] ?? 'text') === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="placeholder">Placeholder</label><input id="placeholder" name="placeholder" maxlength="160" value="<?= e($editingField['placeholder'] ?? '') ?>" placeholder="Enter father's full name"></div>
          <div class="field"><label for="field_sort_order">Display order</label><input id="field_sort_order" name="field_sort_order" type="number" min="0" max="100000" value="<?= e((string)($editingField['sort_order'] ?? 0)) ?>"></div>
          <div class="field full"><label for="help_text">Help text (optional)</label><input id="help_text" name="help_text" maxlength="255" value="<?= e($editingField['help_text'] ?? '') ?>"></div>
          <div class="field full"><label for="options">Options (one per line; select/radio only)</label><textarea id="options" name="options" rows="4" placeholder="First option&#10;Second option"><?= e(implode("\n", $editingField['options'] ?? [])) ?></textarea></div>
          <div class="field full"><label><input type="checkbox" name="is_required" value="1" <?= !$editingField || (int)$editingField['is_required'] === 1 ? 'checked' : '' ?>> Required field</label></div>
          <div class="full"><button class="btn btn-success" type="submit"><?= $editingField ? 'Save field' : 'Add field' ?></button><?php if ($editingField): ?> <a class="btn btn-outline" href="<?= e($self . '?id=' . (int)$selected['id']) ?>">Cancel</a><?php endif; ?></div>
        </form>
        <p class="muted small mt-8">The internal variable is generated from the label (for example, <strong>Father's Name</strong> becomes <code>fathers_name</code>). It stays unchanged after the field is used, preserving previous answers.</p>
      </section>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php admin_end(); ?>
