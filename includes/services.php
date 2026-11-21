<?php
// Legacy service keys are retained so existing request records keep their meaning.
const SERVICE_LABELS = [
    'baptism'         => 'Baptism',
    'wedding'         => 'Wedding',
    'funeral'         => 'Funeral / Requiem',
    'blessing'        => 'House Blessing',
    'first_communion' => 'First Communion',
    'confirmation'    => 'Confirmation',
    'mass_intention'  => 'Mass Intention',
];

const SERVICE_DESCRIPTIONS = [
    'baptism'         => 'Welcome a new member into the family of God.',
    'wedding'         => 'Begin your journey toward celebrating your marriage in the Church.',
    'funeral'         => 'Request assistance for funeral rites and parish services.',
    'blessing'        => 'Request a priest for a blessing of your home or occasion.',
    'first_communion' => 'Request a First Communion celebration or related parish service.',
    'confirmation'    => 'Request assistance for the Sacrament of Confirmation.',
    'mass_intention'  => 'Submit an intention for a Holy Mass.',
];

const SERVICE_ICONS = [
    'church' => 'Church', 'water' => 'Water', 'ring' => 'Wedding ring',
    'cross' => 'Cross', 'house' => 'House', 'book' => 'Book',
    'dove' => 'Dove', 'calendar' => 'Calendar', 'hands-praying' => 'Praying hands',
    'heart' => 'Heart', 'file-lines' => 'Document',
];

const STATUS_LABELS = [
    'pending'   => 'Pending',
    'approved'  => 'Approved',
    'completed' => 'Completed',
    'declined'  => 'Declined',
];

require_once __DIR__ . '/guides.php';

const FIELD_KEYS = ['serviceType', 'fullName', 'email', 'phone', 'preferredDate', 'notes'];

function service_guide(string $type): array
{
    global $SERVICE_GUIDES;
    return $SERVICE_GUIDES[$type] ?? $SERVICE_GUIDES['baptism'];
}

function field_guide(string $type, string $field): array
{
    global $DEFAULT_FIELD_GUIDES;
    if ($field === 'serviceType') {
        return [
            'title' => 'Service requested',
            'hint' => service_guide($type)['overview'],
            'description' => 'Choose which celebration or request this form is for.',
        ];
    }
    return array_merge($DEFAULT_FIELD_GUIDES[$field] ?? [], service_guide($type)['fields'][$field] ?? []);
}

function field_placeholder(string $type, string $field): ?string
{
    global $DEFAULT_FIELD_GUIDES;
    return field_guide($type, $field)['placeholder'] ?? ($DEFAULT_FIELD_GUIDES[$field]['placeholder'] ?? null);
}

/** Everything the browser-side guide needs, resolved per legacy service. */
function guides_for_js(): array
{
    $out = [];
    foreach (array_keys(SERVICE_LABELS) as $t) {
        $g = service_guide($t);
        $fields = [];
        foreach (FIELD_KEYS as $f) {
            $fields[$f] = field_guide($t, $f);
            $fields[$f]['placeholder'] = $f === 'serviceType' ? null : field_placeholder($t, $f);
        }
        $out[$t] = ['label' => SERVICE_LABELS[$t], 'overview' => $g['overview'], 'tips' => $g['tips'], 'fields' => $fields];
    }
    return $out;
}

/* ---------- Dynamic service catalogue ---------- */

function service_catalog_all(bool $includeArchived = false): array
{
    $sql = 'SELECT * FROM services' . ($includeArchived ? '' : ' WHERE is_archived = 0') . ' ORDER BY sort_order ASC, name ASC';
    return db()->query($sql)->fetchAll();
}

function service_catalog_active(): array
{
    return db()->query('SELECT * FROM services WHERE is_active = 1 AND is_archived = 0 ORDER BY sort_order ASC, name ASC')->fetchAll();
}

function service_catalog_find_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function service_field_find(int $fieldId, int $serviceId): ?array
{
    $st = db()->prepare('SELECT * FROM service_form_fields WHERE id = ? AND service_id = ? LIMIT 1');
    $st->execute([$fieldId, $serviceId]);
    $row = $st->fetch();
    if (!$row) return null;
    $decoded = json_decode((string)($row['options_json'] ?? '[]'), true);
    $row['options'] = is_array($decoded) ? $decoded : [];
    return $row;
}

function service_find(string $key, bool $activeOnly = false): ?array
{
    if ($key === '') {
        return null;
    }
    $sql = 'SELECT * FROM services WHERE service_key = ? AND is_archived = 0';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $st = db()->prepare($sql . ' LIMIT 1');
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

function service_labels(bool $activeOnly = false, bool $includeArchived = true): array
{
    $rows = $activeOnly
        ? service_catalog_active()
        : service_catalog_all($includeArchived);
    $out = [];
    foreach ($rows as $row) {
        $out[$row['service_key']] = $row['name'];
    }
    return $out;
}

function service_name_exists(string $name, int $exceptId = 0): bool
{
    $st = db()->prepare('SELECT 1 FROM services WHERE LOWER(name) = LOWER(?) AND is_archived = 0 AND id <> ? LIMIT 1');
    $st->execute([$name, $exceptId]);
    return (bool)$st->fetchColumn();
}

function service_label(string $key): string
{
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = db()->prepare('SELECT name FROM services WHERE service_key = ? LIMIT 1');
        $st->execute([$key]);
        $label = $st->fetchColumn();
        if ($label) return $cache[$key] = (string)$label;
    } catch (Throwable $e) {
        // The static labels remain usable while the database is initializing.
    }
    return $cache[$key] = (SERVICE_LABELS[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key)));
}

function service_key_exists(string $key, bool $activeOnly = false): bool
{
    $sql = 'SELECT 1 FROM services WHERE service_key = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1 AND is_archived = 0';
    $st = db()->prepare($sql . ' LIMIT 1');
    $st->execute([$key]);
    return (bool)$st->fetchColumn();
}

function service_fields(int $serviceId, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM service_form_fields WHERE service_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $st = db()->prepare($sql);
    $st->execute([$serviceId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
        $decoded = json_decode((string)($row['options_json'] ?? '[]'), true);
        $row['options'] = is_array($decoded) ? $decoded : [];
    }
    unset($row);
    return $rows;
}

function service_fields_for_key(string $serviceKey, bool $activeOnly = true): array
{
    $service = service_find($serviceKey, false);
    return $service ? service_fields((int)$service['id'], $activeOnly) : [];
}

function service_create(string $name, string $description, string $icon, int $sortOrder = 0): int
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $pdo = db();
    $tmpKey = 'new_' . bin2hex(random_bytes(8));
    $st = $pdo->prepare('INSERT INTO services (service_key, name, description, icon, is_active, is_archived, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?)');
    $st->execute([$tmpKey, $name, $description, $icon, $sortOrder, $now, $now]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE services SET service_key = ? WHERE id = ?')->execute(['svc_' . $id, $id]);
    return $id;
}

function service_update(int $id, string $name, string $description, string $icon, int $sortOrder): void
{
    $st = db()->prepare('UPDATE services SET name = ?, description = ?, icon = ?, sort_order = ?, updated_at = ? WHERE id = ? AND is_archived = 0');
    $st->execute([$name, $description, $icon, $sortOrder, date('Y-m-d H:i:s'), $id]);
}

function service_set_active(int $id, bool $active): void
{
    $st = db()->prepare('UPDATE services SET is_active = ?, updated_at = ? WHERE id = ? AND is_archived = 0');
    $st->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $id]);
}

function service_archive(int $id): void
{
    $st = db()->prepare('UPDATE services SET is_active = 0, is_archived = 1, updated_at = ? WHERE id = ?');
    $st->execute([date('Y-m-d H:i:s'), $id]);
}

function service_restore(int $id): void
{
    // Restored services remain disabled until an administrator explicitly enables them.
    $st = db()->prepare('UPDATE services SET is_active = 0, is_archived = 0, updated_at = ? WHERE id = ?');
    $st->execute([date('Y-m-d H:i:s'), $id]);
}

function service_field_key(string $label, int $serviceId): string
{
    $key = strtolower(trim($label));
    $key = str_replace(["'", '’'], '', $key);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        if ($converted !== false) {
            $key = $converted;
        }
    }
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    if ($key === '') {
        $key = 'field';
    }
    if (preg_match('/^\d/', $key)) {
        $key = 'field_' . $key;
    }
    $key = substr($key, 0, 48);
    $base = $key;
    $n = 2;
    $check = db()->prepare('SELECT id FROM service_form_fields WHERE service_id = ? AND field_key = ? LIMIT 1');
    while (true) {
        $check->execute([$serviceId, $key]);
        if (!$check->fetchColumn()) {
            return $key;
        }
        $suffix = '_' . $n++;
        $key = substr($base, 0, 48 - strlen($suffix)) . $suffix;
    }
}

function service_create_field(int $serviceId, string $label, string $type, bool $required, string $placeholder, string $helpText, array $options, int $sortOrder = 0): void
{
    $count = db()->prepare('SELECT COUNT(*) FROM service_form_fields WHERE service_id = ?');
    $count->execute([$serviceId]);
    if ((int)$count->fetchColumn() >= 100) {
        throw new InvalidArgumentException('A service can have up to 100 form fields. Disable or reuse an existing field instead.');
    }
    $key = service_field_key($label, $serviceId);
    $st = db()->prepare('INSERT INTO service_form_fields (service_id, field_key, label, field_type, is_required, placeholder, help_text, options_json, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $now = date('Y-m-d H:i:s');
    $st->execute([$serviceId, $key, $label, $type, $required ? 1 : 0, $placeholder, $helpText, json_encode(array_values($options), JSON_UNESCAPED_UNICODE), $sortOrder, $now, $now]);
}

function service_update_field(int $fieldId, int $serviceId, string $label, string $type, bool $required, string $placeholder, string $helpText, array $options, int $sortOrder): void
{
    // field_key is intentionally immutable so existing answer variables remain stable.
    $st = db()->prepare('UPDATE service_form_fields SET label = ?, field_type = ?, is_required = ?, placeholder = ?, help_text = ?, options_json = ?, sort_order = ?, updated_at = ? WHERE id = ? AND service_id = ?');
    $st->execute([$label, $type, $required ? 1 : 0, $placeholder, $helpText, json_encode(array_values($options), JSON_UNESCAPED_UNICODE), $sortOrder, date('Y-m-d H:i:s'), $fieldId, $serviceId]);
}

function service_set_field_active(int $fieldId, int $serviceId, bool $active): void
{
    $st = db()->prepare('UPDATE service_form_fields SET is_active = ?, updated_at = ? WHERE id = ? AND service_id = ?');
    $st->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $fieldId, $serviceId]);
}

/** Return a uniform, scalar-only file entry from PHP's nested dynamic_files input. */
function service_file_entry(array $files, string $key): ?array
{
    if (!isset($files['name']) || !is_array($files['name']) || !array_key_exists($key, $files['name'])) {
        return null;
    }

    $name = $files['name'][$key] ?? '';
    $type = $files['type'][$key] ?? '';
    $tmp = $files['tmp_name'][$key] ?? '';
    $error = $files['error'][$key] ?? UPLOAD_ERR_NO_FILE;
    $size = $files['size'][$key] ?? 0;

    // Reject malformed nested arrays instead of casting them to the string "Array".
    if (!is_string($name) || !is_string($type) || !is_string($tmp)
        || !is_numeric($error) || !is_numeric($size)) {
        return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_EXTENSION, 'size' => 0];
    }

    return ['name' => $name, 'type' => $type, 'tmp_name' => $tmp, 'error' => (int)$error, 'size' => (int)$size];
}

function service_uploaded_file_info(?array $file): array
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => null, 'ext' => null, 'mime' => null, 'size' => 0];
    }
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'A file is too large. The maximum size is 5 MB.'
            : 'The file upload failed.';
        return ['error' => $message, 'ext' => null, 'mime' => null, 'size' => 0];
    }

    $tmp = $file['tmp_name'] ?? null;
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Choose a valid file up to 5 MB.', 'ext' => null, 'mime' => null, 'size' => 0];
    }
    $actualSize = @filesize($tmp);
    if ($actualSize === false || $actualSize <= 0 || $actualSize > 5 * 1024 * 1024) {
        return ['error' => 'Choose a valid file up to 5 MB.', 'ext' => null, 'mime' => null, 'size' => 0];
    }
    if (!class_exists('finfo')) {
        return ['error' => 'File verification is unavailable. Please contact the parish office.', 'ext' => null, 'mime' => null, 'size' => 0];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $header = @file_get_contents($tmp, false, null, 0, 8);
    $ext = null;
    if ($mime === 'application/pdf' && is_string($header) && strncmp($header, '%PDF', 4) === 0) {
        $ext = 'pdf';
    } elseif ($mime === 'image/png' && is_string($header) && strncmp($header, "\x89PNG\r\n\x1a\n", 8) === 0) {
        $img = @getimagesize($tmp);
        if ($img && ($img['mime'] ?? '') === 'image/png') $ext = 'png';
    } elseif ($mime === 'image/jpeg' && is_string($header) && strncmp($header, "\xFF\xD8\xFF", 3) === 0) {
        $img = @getimagesize($tmp);
        if ($img && ($img['mime'] ?? '') === 'image/jpeg') $ext = 'jpg';
    }
    return $ext ? ['error' => null, 'ext' => $ext, 'mime' => $mime, 'size' => (int)$actualSize] : ['error' => 'Only valid PDF, JPG, or PNG files are accepted.', 'ext' => null, 'mime' => null, 'size' => 0];
}

/** Validate all submitted dynamic fields against the saved, server-side definitions. */
function service_validate_submission(int $serviceId, array $input, array $files): array
{
    $answers = [];
    $uploads = [];
    $totalUploadBytes = 0;
    foreach (service_fields($serviceId, true) as $field) {
        $key = (string)$field['field_key'];
        $type = (string)$field['field_type'];
        if ($type === 'file') {
            $file = service_file_entry($files, $key);
            $info = service_uploaded_file_info($file);
            if ($info['error']) {
                return ['error' => $field['label'] . ': ' . $info['error']];
            }
            $hasFile = $file && (int)$file['error'] === UPLOAD_ERR_OK;
            if (!$hasFile && (int)$field['is_required'] === 1) {
                return ['error' => 'Please upload ' . $field['label'] . '.'];
            }
            if ($hasFile) {
                $totalUploadBytes += (int)$info['size'];
                if ($totalUploadBytes > 20 * 1024 * 1024) {
                    return ['error' => 'The combined uploaded files exceed 20 MB. Please reduce the file sizes and try again.'];
                }
                $uploads[$key] = ['field' => $field, 'file' => $file, 'info' => $info];
            }
            continue;
        }

        $raw = $input[$key] ?? '';
        if ($type === 'checkbox') {
            $value = ($raw === '1' || $raw === 1 || $raw === true) ? 'Yes' : 'No';
            if ((int)$field['is_required'] === 1 && $value !== 'Yes') {
                return ['error' => 'Please confirm ' . $field['label'] . '.'];
            }
            $answers[$key] = ['label' => $field['label'], 'type' => $type, 'value' => $value];
            continue;
        }
        $value = is_string($raw) || is_numeric($raw) ? trim((string)$raw) : '';
        if ((int)$field['is_required'] === 1 && $value === '') {
            return ['error' => 'Please complete ' . $field['label'] . '.'];
        }
        if (mb_strlen($value) > 2000) {
            return ['error' => $field['label'] . ' is too long.'];
        }
        if ($value !== '' && $type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Please enter a valid email address for ' . $field['label'] . '.'];
        }
        if ($value !== '' && $type === 'date') {
            $date = DateTime::createFromFormat('Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                return ['error' => 'Please enter a valid date for ' . $field['label'] . '.'];
            }
        }
        if ($value !== '' && $type === 'number' && !is_numeric($value)) {
            return ['error' => $field['label'] . ' must be a number.'];
        }
        if (in_array($type, ['select', 'radio'], true) && $value !== '' && !in_array($value, $field['options'], true)) {
            return ['error' => 'Please choose a valid option for ' . $field['label'] . '.'];
        }
        $answers[$key] = ['label' => $field['label'], 'type' => $type, 'value' => $value];
    }
    return ['error' => null, 'answers' => $answers, 'uploads' => $uploads];
}

/** Save answer snapshots and private uploads. Call inside the request-creation DB transaction. */
function service_save_submission(int $requestId, array $validated): array
{
    $pdo = db();
    $storedPaths = [];
    try {
        $answerSt = $pdo->prepare('INSERT INTO service_request_answers (request_id, field_key, field_label, field_type, answer_text, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        foreach (($validated['answers'] ?? []) as $key => $answer) {
            $answerSt->execute([$requestId, $key, $answer['label'], $answer['type'], $answer['value'], $now]);
        }
        foreach (($validated['uploads'] ?? []) as $key => $item) {
            $base = __DIR__ . '/../data/uploads';
            if (!is_dir($base) && !@mkdir($base, 0750, true)) {
                throw new RuntimeException('Could not create the private upload folder.');
            }
            $guard = $base . '/.htaccess';
            if (!is_file($guard)) {
                $guardRules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
                if (@file_put_contents($guard, $guardRules, LOCK_EX) === false) {
                    throw new RuntimeException('Could not protect the private upload folder.');
                }
            }
            $requestDir = $base . '/' . $requestId;
            if (!is_dir($requestDir) && !@mkdir($requestDir, 0750, true)) {
                throw new RuntimeException('Could not create the private request upload folder.');
            }
            $ext = $item['info']['ext'];
            $stored = $requestId . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $absolute = $base . '/' . $stored;
            if (!move_uploaded_file((string)$item['file']['tmp_name'], $absolute)) {
                throw new RuntimeException('Could not store an uploaded form file.');
            }
            $storedPaths[] = $absolute;
            $original = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename((string)$item['file']['name']));
            $original = mb_substr(trim((string)$original) !== '' ? $original : 'upload.' . $ext, 0, 120);
            $up = $pdo->prepare('INSERT INTO service_request_uploads (request_id, field_key, field_label, original_name, stored_path, mime_type, file_size, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $up->execute([$requestId, $key, $item['field']['label'], $original, $stored, $item['info']['mime'], (int)$item['info']['size'], $now]);
        }
    } catch (Throwable $e) {
        foreach ($storedPaths as $path) {
            if (is_file($path)) @unlink($path);
        }
        throw $e;
    }
    return $storedPaths;
}

/** Remove files written before a request transaction failed to commit. */
function service_remove_stored_paths(array $paths): void
{
    $base = realpath(__DIR__ . '/../data/uploads');
    if (!$base) return;
    foreach ($paths as $candidate) {
        if (!is_string($candidate)) continue;
        $path = realpath($candidate);
        if ($path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
            @unlink($path);
        }
    }
}

function service_answers_for_request(int $requestId): array
{
    $st = db()->prepare('SELECT field_key, field_label, field_type, answer_text FROM service_request_answers WHERE request_id = ? ORDER BY id ASC');
    $st->execute([$requestId]);
    return $st->fetchAll();
}

function service_uploads_for_request(int $requestId): array
{
    $st = db()->prepare('SELECT * FROM service_request_uploads WHERE request_id = ? ORDER BY id ASC');
    $st->execute([$requestId]);
    return $st->fetchAll();
}

function service_upload_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM service_request_uploads WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function service_send_upload(array $row): never
{
    $base = realpath(__DIR__ . '/../data/uploads');
    $path = $base ? realpath($base . '/' . (string)$row['stored_path']) : false;
    if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
        not_found();
    }
    $mime = in_array($row['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true) ? $row['mime_type'] : 'application/octet-stream';
    $name = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', (string)$row['original_name']);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function service_remove_upload_files(array $uploads): void
{
    $base = realpath(__DIR__ . '/../data/uploads');
    foreach ($uploads as $upload) {
        $path = $base ? realpath($base . '/' . (string)$upload['stored_path']) : false;
        if ($base && $path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
            @unlink($path);
        }
    }
}

function service_purge_request_data(int $requestId): void
{
    $uploads = service_uploads_for_request($requestId);
    db()->prepare('DELETE FROM service_request_uploads WHERE request_id = ?')->execute([$requestId]);
    db()->prepare('DELETE FROM service_request_answers WHERE request_id = ?')->execute([$requestId]);
    service_remove_upload_files($uploads);
}
