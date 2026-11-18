
<?php
require_once __DIR__ . '/../includes/admin_layout.php';

require_admin();

$self = url('admin/parish-schedules.php');
$error = null;

$form = [
    'id' => 0,
    'schedule_type' => 'mass',
    'day_label' => '',
    'day_pattern' => 'custom',
    'start_day' => 'Monday',
    'end_day' => 'Friday',
    'single_day' => 'Sunday',
    'specific_date' => '',
    'occurrence' => 'First',
    'special_day' => 'Friday',
    'schedule_details' => '',
    'sort_order' => 0,
    'is_active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = db()->prepare(
                'DELETE FROM parish_schedules WHERE id = ?'
            );
            $stmt->execute([$id]);
        }

        redirect($self);
    } elseif ($action === 'save') {
        $form['id'] = (int)($_POST['id'] ?? 0);
        $form['schedule_type'] = trim($_POST['schedule_type'] ?? '');
        $form['day_pattern'] = $_POST['day_pattern'] ?? 'custom';

$weekdays = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday',
    'Friday', 'Saturday', 'Sunday'
];

$occurrences = ['First', 'Second', 'Third', 'Fourth', 'Last'];

$form['start_day'] = $_POST['start_day'] ?? '';
$form['end_day'] = $_POST['end_day'] ?? '';
$form['single_day'] = $_POST['single_day'] ?? '';
$form['specific_date'] = $_POST['specific_date'] ?? '';
$form['occurrence'] = $_POST['occurrence'] ?? '';
$form['special_day'] = $_POST['special_day'] ?? '';

$form['day_label'] = '';

switch ($form['day_pattern']) {

    case 'range':
        if (
            in_array($form['start_day'], $weekdays, true) &&
            in_array($form['end_day'], $weekdays, true)
        ) {
            $form['day_label'] =
                $form['start_day'] . ' – ' . $form['end_day'];
        }
        break;

    case 'single':
        if (in_array($form['single_day'], $weekdays, true)) {
            $form['day_label'] = $form['single_day'];
        }
        break;

    case 'specific':
        $date = DateTime::createFromFormat(
            '!Y-m-d',
            $form['specific_date']
        );

        if (
            $date &&
            $date->format('Y-m-d') === $form['specific_date']
        ) {
            $form['day_label'] = $date->format('F j, Y');
        }
        break;

    case 'special':
        if (
            in_array($form['occurrence'], $occurrences, true) &&
            in_array($form['special_day'], $weekdays, true)
        ) {
            $form['day_label'] =
                $form['occurrence'] . ' ' . $form['special_day'];
        }
        break;

    case 'custom':
        $form['day_label'] = trim($_POST['custom_day'] ?? '');
        break;
}
        $form['schedule_details'] = trim($_POST['schedule_details'] ?? '');
        $form['sort_order'] = (int)($_POST['sort_order'] ?? 0);
        $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($form['schedule_type'], ['mass', 'confession'], true)) {
            $error = 'Invalid schedule type.';
        } elseif (!in_array($form['day_pattern'], [
            'range', 'single', 'specific', 'special', 'custom'
        ], true)) {
            $error = 'Invalid schedule day type.';
        } elseif (
            $form['day_label'] === '' ||
            mb_strlen($form['day_label']) > 80
        ) {
            $error = 'Please provide valid schedule day information.';
        } elseif (
            $form['schedule_details'] === '' ||
            mb_strlen($form['schedule_details']) > 255
        ) {
            $error = 'Please enter valid schedule details.';
        } elseif ($form['sort_order'] < 0) {
            $error = 'Display order cannot be negative.';
        } else {
            $now = date('Y-m-d H:i:s');

            if ($form['id'] > 0) {
                $stmt = db()->prepare(
                    'UPDATE parish_schedules
                     SET schedule_type = ?,
                         day_label = ?,
                         schedule_details = ?,
                         sort_order = ?,
                         is_active = ?,
                         updated_at = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $form['schedule_type'],
                    $form['day_label'],
                    $form['schedule_details'],
                    $form['sort_order'],
                    $form['is_active'],
                    $now,
                    $form['id']
                ]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO parish_schedules
                     (schedule_type, day_label, schedule_details,
                      sort_order, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $form['schedule_type'],
                    $form['day_label'],
                    $form['schedule_details'],
                    $form['sort_order'],
                    $form['is_active'],
                    $now,
                    $now
                ]);
            }

            redirect($self . '?saved=1');
        }
    }
}

// Load an existing entry for editing.
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    isset($_GET['edit'])
) {
    $id = (int)$_GET['edit'];

    $stmt = db()->prepare(
        'SELECT * FROM parish_schedules WHERE id = ?'
    );
    $stmt->execute([$id]);

    $existing = $stmt->fetch();

    if ($existing) {
    $form = array_merge($form, $existing);

    $label = trim($existing['day_label']);

    $weekdays = [
        'Monday', 'Tuesday', 'Wednesday', 'Thursday',
        'Friday', 'Saturday', 'Sunday'
    ];

    // Detect a saved weekday range.
   if (preg_match('/^(.+?)\s*[–-]\s*(.+)$/u', $label, $matches)
        && in_array($matches[1], $weekdays, true)
        && in_array($matches[2], $weekdays, true)) {

        $form['day_pattern'] = 'range';
        $form['start_day'] = $matches[1];
        $form['end_day'] = $matches[2];

    } elseif (in_array($label, $weekdays, true)) {

        $form['day_pattern'] = 'single';
        $form['single_day'] = $label;

    } elseif (
        preg_match('/^(First|Second|Third|Fourth|Last) (.+)$/', $label, $matches)
        && in_array($matches[1], ['First', 'Second', 'Third', 'Fourth', 'Last'], true)
        && in_array($matches[2], $weekdays, true)
    ) {

        $form['day_pattern'] = 'special';
        $form['occurrence'] = $matches[1];
        $form['special_day'] = $matches[2];

    } else {
        $date = DateTime::createFromFormat('!F j, Y', $label);

        if ($date && $date->format('F j, Y') === $label) {
            $form['day_pattern'] = 'specific';
            $form['specific_date'] = $date->format('Y-m-d');
        } else {
            $form['day_pattern'] = 'custom';
        }
    }
}
}

// Get all schedule entries.
$schedules = db()->query(
    'SELECT * FROM parish_schedules
     ORDER BY schedule_type ASC, sort_order ASC, id ASC'
)->fetchAll();

admin_start('Parish Schedules', 'parish-schedules');
?>

<div class="container dash wide">

    <h1 class="page-title left">Parish Schedules</h1>

    <p class="muted small">
        Manage the Mass and Confession schedules displayed on the public website.
    </p>

    <?php if (isset($_GET['saved'])): ?>
        <p class="small" style="color:var(--primary)">
            Schedule saved successfully.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e($self) ?>" class="card form">

        <?= csrf_field() ?>

        <input type="hidden" name="action" value="save">

        <input
            type="hidden"
            name="id"
            value="<?= (int)$form['id'] ?>"
        >

        <h2 class="h2">
            <?= $form['id'] ? 'Edit Schedule' : 'Add Schedule' ?>
        </h2>

        <div class="field">
            <label for="schedule_type">Schedule Type</label>

            <select name="schedule_type" id="schedule_type" required>
                <option value="mass"
                    <?= $form['schedule_type'] === 'mass' ? 'selected' : '' ?>>
                    Mass
                </option>

                <option value="confession"
                    <?= $form['schedule_type'] === 'confession' ? 'selected' : '' ?>>
                    Confession
                </option>
            </select>
        </div>

        <div class="field">

    <label for="day_pattern">Schedule Day Type</label>

    <select name="day_pattern" id="day_pattern" required>
        <option value="range" <?= $form['day_pattern'] === 'range' ? 'selected' : '' ?>>
            Range
        </option>

        <option value="single" <?= $form['day_pattern'] === 'single' ? 'selected' : '' ?>>
            Single Day
        </option>

        <option value="specific" <?= $form['day_pattern'] === 'specific' ? 'selected' : '' ?>>
            Specific Date
        </option>

        <option value="special" <?= $form['day_pattern'] === 'special' ? 'selected' : '' ?>>
            Special Schedule
        </option>

        <option value="custom" <?= $form['day_pattern'] === 'custom' ? 'selected' : '' ?>>
            Custom
        </option>
    </select>

</div>

<!-- RANGE -->
<div class="field day-option" id="range_fields">

    <label>Weekday Range</label>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">

        <div>
            <label for="start_day">Start Day</label>

            <select name="start_day" id="start_day">
                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                    <option value="<?= e($day) ?>" <?= $form['start_day'] === $day ? 'selected' : '' ?>>
                        <?= e($day) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="end_day">End Day</label>

            <select name="end_day" id="end_day">
                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                    <option value="<?= e($day) ?>" <?= $form['end_day'] === $day ? 'selected' : '' ?>>
                        <?= e($day) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

    </div>
</div>

<!-- SINGLE DAY -->
<div class="field day-option" id="single_fields">

    <label for="single_day">Select Weekday</label>

    <select name="single_day" id="single_day">
        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
            <option value="<?= e($day) ?>" <?= $form['single_day'] === $day ? 'selected' : '' ?>>
                <?= e($day) ?>
            </option>
        <?php endforeach; ?>
    </select>

</div>

<!-- SPECIFIC DATE -->
<div class="field day-option" id="specific_fields">

    <label for="specific_date">Select Date</label>

    <input
        type="date"
        name="specific_date"
        id="specific_date"
        value="<?= e($form['specific_date']) ?>"
    >

</div>

<!-- SPECIAL SCHEDULE -->
<div class="field day-option" id="special_fields">

    <label>Special Schedule</label>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">

        <div>
            <label for="occurrence">Occurrence</label>

            <select name="occurrence" id="occurrence">
                <?php foreach (['First','Second','Third','Fourth','Last'] as $occurrence): ?>
                    <option value="<?= e($occurrence) ?>" <?= $form['occurrence'] === $occurrence ? 'selected' : '' ?>>
                        <?= e($occurrence) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="special_day">Weekday</label>

            <select name="special_day" id="special_day">
                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                    <option value="<?= e($day) ?>" <?= $form['special_day'] === $day ? 'selected' : '' ?>>
                        <?= e($day) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

    </div>
</div>

<!-- CUSTOM -->
<div class="field day-option" id="custom_fields">

    <label for="custom_day">Custom Schedule Label</label>

    <input
        type="text"
        name="custom_day"
        id="custom_day"
        maxlength="80"
        value="<?= $form['day_pattern'] === 'custom' ? e($form['day_label']) : '' ?>"
        placeholder="Example: Christmas Eve"
    >
</div>

        <div class="field">
            <label for="schedule_details">Schedule Details</label>

            <input
                type="text"
                name="schedule_details"
                id="schedule_details"
                maxlength="255"
                required
                value="<?= e($form['schedule_details']) ?>"
                placeholder="Example: 6:00 AM · 8:00 AM · 10:00 AM"
            >
        </div>

        <div class="field">
            <label for="sort_order">Display Order</label>

            <input
                type="number"
                name="sort_order"
                id="sort_order"
                min="0"
                value="<?= (int)$form['sort_order'] ?>"
            >
        </div>

        <div class="field">
            <label>
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    <?= $form['is_active'] ? 'checked' : '' ?>
                >
                Active (display on public website)
            </label>
        </div>

        <?php if ($error): ?>
            <p class="alert-error" role="alert">
                <?= e($error) ?>
            </p>
        <?php endif; ?>

        <div class="actions">
            <button type="submit" class="btn btn-success">
                Save Schedule
            </button>

            <?php if ($form['id']): ?>
                <a href="<?= e($self) ?>" class="btn btn-outline">
                    Cancel Editing
                </a>
            <?php endif; ?>
        </div>

    </form>

    <h2 class="h2">Existing Schedules</h2>

    <div class="table-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Day / Label</th>
                    <th>Details</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$schedules): ?>
                    <tr>
                        <td colspan="6" class="muted">
                            No schedules added yet.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($schedules as $s): ?>
                    <tr>
                        <td><?= e(ucfirst($s['schedule_type'])) ?></td>
                        <td><?= e($s['day_label']) ?></td>
                        <td><?= e($s['schedule_details']) ?></td>
                        <td><?= (int)$s['sort_order'] ?></td>
                        <td>
                            <?= $s['is_active'] ? 'Active' : 'Hidden' ?>
                        </td>
                        <td style="white-space:nowrap">

                            <a
                                class="act act-edit"
                                href="<?= e($self . '?edit=' . (int)$s['id']) ?>"
                            >
                                Edit
                            </a>

                            <form
                                method="post"
                                action="<?= e($self) ?>"
                                style="display:inline"
                                onsubmit="return confirm('Delete this schedule?')"
                            >
                                <?= csrf_field() ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$s['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="act act-delete"
                                >
                                    Delete
                                </button>
                            </form>

                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const patternSelect = document.getElementById('day_pattern');

    const sections = {
        range: document.getElementById('range_fields'),
        single: document.getElementById('single_fields'),
        specific: document.getElementById('specific_fields'),
        special: document.getElementById('special_fields'),
        custom: document.getElementById('custom_fields')
    };

    function updateDayFields() {

        const selected = patternSelect.value;

        Object.keys(sections).forEach(function (key) {

            const section = sections[key];
            const visible = key === selected;

            section.style.display = visible ? 'block' : 'none';

            section.querySelectorAll('input, select').forEach(function (input) {
                input.disabled = !visible;
                input.required = visible;
            });

        });
    }

    patternSelect.addEventListener('change', updateDayFields);

    updateDayFields();

});
</script>

<?php admin_end(); ?>
