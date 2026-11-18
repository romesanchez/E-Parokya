<?php
/**
 * Requirements checklist, document upload and staff verification.
 *
 * - Each service has a list of required / optional documents (SERVICE_REQUIREMENTS).
 * - Parishioners (or staff on their behalf) upload a file for each item.
 * - Staff verify or reject each item. "Verified in person" is for walk-ins who showed the paper copy.
 * - A request cannot be moved to Approved / Completed until every REQUIRED item is verified.
 *   Set REQ_ENFORCE to false to turn that block off.
 *
 * Uploaded files are saved in data/uploads/ (blocked from the web by .htaccess) and are only
 * served through an access-checked script.
 */
require_once __DIR__ . '/db.php';

const REQ_ENFORCE   = true;
const REQ_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB per file

const REQ_STATUS_LABELS = [
    'missing'   => 'Not submitted',
    'submitted' => 'Waiting for review',
    'verified'  => 'Verified',
    'rejected'  => 'Rejected',
];

// Badge class per status (these classes exist in both style sheets).
const REQ_STATUS_BADGE = [
    'missing'   => 'badge-pending',
    'submitted' => 'badge-approved',
    'verified'  => 'badge-completed',
    'rejected'  => 'badge-declined',
];

const REQ_LOG_LABELS = [
    'requirement_uploaded' => 'Document uploaded',
    'requirement_verified' => 'Document verified',
    'requirement_rejected' => 'Document rejected',
    'requirement_inperson' => 'Document verified in person',
    'requirement_reset'    => 'Document review reset',
];

const SERVICE_REQUIREMENTS = [
    'baptism' => [
        ['key' => 'valid_id',         'label' => 'Valid ID of a parent',                  'hint' => 'Government-issued ID of the parent making the request.', 'required' => true],
        ['key' => 'birth_cert',       'label' => 'Child’s birth certificate',             'hint' => 'PSA or local civil registrar copy.',                      'required' => true],
        ['key' => 'sponsors_cert',    'label' => 'Godparents’ confirmation certificate',  'hint' => 'Or a letter of eligibility from their parish.',           'required' => true],
        ['key' => 'parents_marriage', 'label' => 'Parents’ marriage certificate',         'hint' => 'If the parents are married.',                             'required' => false],
    ],
    'wedding' => [
        ['key' => 'valid_id',          'label' => 'Valid ID of the bride or groom', 'hint' => 'Government-issued ID.',                                   'required' => true],
        ['key' => 'baptismal_cert',    'label' => 'Baptismal certificate',          'hint' => 'Recent copy marked “for marriage purposes”.',             'required' => true],
        ['key' => 'confirmation_cert', 'label' => 'Confirmation certificate',       'hint' => 'From the parish where it was received.',                  'required' => true],
        ['key' => 'cenomar',           'label' => 'CENOMAR',                        'hint' => 'Certificate of No Marriage Record from PSA.',             'required' => true],
        ['key' => 'marriage_license',  'label' => 'Marriage license',               'hint' => 'Or the civil marriage certificate / exemption document.', 'required' => true],
        ['key' => 'precana',           'label' => 'Pre-Cana seminar certificate',   'hint' => 'Proof that the couple attended the seminar.',             'required' => true],
    ],
    'funeral' => [
        ['key' => 'valid_id',      'label' => 'Valid ID of the family member', 'hint' => 'Government-issued ID of the person making the request.', 'required' => true],
        ['key' => 'death_cert',    'label' => 'Death certificate',             'hint' => 'Or a medical certificate from the hospital.',            'required' => true],
        ['key' => 'burial_permit', 'label' => 'Burial or cemetery permit',     'hint' => 'If already available.',                                  'required' => false],
    ],
    'blessing' => [
        ['key' => 'valid_id',      'label' => 'Valid ID',          'hint' => 'Government-issued ID of the requester.',             'required' => true],
        ['key' => 'proof_address', 'label' => 'Proof of address',  'hint' => 'A utility bill or similar, if outside the parish.', 'required' => false],
    ],
    'first_communion' => [
        ['key' => 'valid_id',       'label' => 'Valid ID of a parent or guardian', 'hint' => 'Government-issued ID.',                          'required' => true],
        ['key' => 'baptismal_cert', 'label' => 'Baptismal certificate',            'hint' => 'Copy of the child’s baptismal certificate.',     'required' => true],
        ['key' => 'catechism_cert', 'label' => 'Catechism attendance certificate', 'hint' => 'From the catechist or parish catechetical office.', 'required' => true],
    ],
    'confirmation' => [
        ['key' => 'valid_id',       'label' => 'Valid ID',                          'hint' => 'Government-issued or school ID.',            'required' => true],
        ['key' => 'baptismal_cert', 'label' => 'Baptismal certificate',             'hint' => 'Recent copy.',                               'required' => true],
        ['key' => 'communion_cert', 'label' => 'First Communion certificate',       'hint' => 'From the parish where it was received.',     'required' => true],
        ['key' => 'sponsor_cert',   'label' => 'Sponsor’s confirmation certificate', 'hint' => 'Proof that the sponsor is confirmed.',      'required' => true],
    ],
    'mass_intention' => [
        ['key' => 'valid_id', 'label' => 'Valid ID', 'hint' => 'Not required for Mass intentions.', 'required' => false],
    ],
];

/* ---------- Catalogue ---------- */

function requirements_for(string $service): array
{
    return SERVICE_REQUIREMENTS[$service] ?? [];
}

function requirement_def(string $service, string $key): ?array
{
    foreach (requirements_for($service) as $d) {
        if ($d['key'] === $key) {
            return $d;
        }
    }
    return null;
}

/* ---------- Schema ---------- */

function requirements_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $c = require __DIR__ . '/config.php';
    $sqlite = $c['driver'] === 'sqlite';
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $int  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $dt   = $sqlite ? 'TEXT' : 'DATETIME';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    db()->exec("CREATE TABLE IF NOT EXISTS request_requirements (
        id {$pk},
        request_id {$int} NOT NULL,
        req_key VARCHAR(40) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'missing',
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(255) NULL,
        note VARCHAR(300) NULL,
        reviewed_by VARCHAR(120) NULL,
        reviewed_at {$dt} NULL,
        uploaded_at {$dt} NULL,
        UNIQUE (request_id, req_key)
    ){$tail}");
}

/* ---------- Reading ---------- */

/** One checklist item per requirement of the service, merged with whatever has been saved. */
function req_list(int $rid, string $service): array
{
    requirements_schema();
    $st = db()->prepare('SELECT * FROM request_requirements WHERE request_id = ?');
    $st->execute([$rid]);
    $saved = [];
    foreach ($st->fetchAll() as $row) {
        $saved[$row['req_key']] = $row;
    }
    $out = [];
    foreach (requirements_for($service) as $def) {
        $row = $saved[$def['key']] ?? [];
        $out[] = $def + [
            'id'          => (int)($row['id'] ?? 0),
            'status'      => $row['status'] ?? 'missing',
            'file_name'   => $row['file_name'] ?? null,
            'has_file'    => !empty($row['file_path']),
            'note'        => $row['note'] ?? null,
            'reviewed_by' => $row['reviewed_by'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
        ];
    }
    return $out;
}

/** Counts for a list returned by req_list(). */
function req_progress(array $items): array
{
    $required = $verified = $total = $done = 0;
    $outstanding = [];
    foreach ($items as $q) {
        $total++;
        if ($q['status'] === 'verified') {
            $done++;
        }
        if ($q['required']) {
            $required++;
            if ($q['status'] === 'verified') {
                $verified++;
            } else {
                $outstanding[] = $q['label'];
            }
        }
    }
    return [
        'required' => $required, 'verified' => $verified, 'total' => $total, 'done' => $done,
        'outstanding' => $outstanding, 'ready' => $verified >= $required,
    ];
}

/** 'verified' / 'rejected' / 'submitted' / 'missing' for the ID document, or null if the service has none. */
function req_identity_status(array $items): ?string
{
    foreach ($items as $q) {
        if ($q['key'] === 'valid_id') {
            return $q['status'];
        }
    }
    return null;
}

/** [request id => ['required' => n, 'verified' => m]] for a list of request rows, using one query. */
function req_progress_bulk(array $requests): array
{
    requirements_schema();
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $requests)));
    if (!$ids) {
        return [];
    }
    $by = [];
    $sql = 'SELECT request_id, req_key, status FROM request_requirements WHERE request_id IN (' . implode(',', $ids) . ')';
    foreach (db()->query($sql)->fetchAll() as $x) {
        $by[(int)$x['request_id']][$x['req_key']] = $x['status'];
    }
    $out = [];
    foreach ($requests as $r) {
        $id = (int)$r['id'];
        $need = array_filter(requirements_for($r['service_type']), fn($d) => $d['required']);
        $ok = 0;
        foreach ($need as $d) {
            if (($by[$id][$d['key']] ?? '') === 'verified') {
                $ok++;
            }
        }
        $out[$id] = ['required' => count($need), 'verified' => $ok];
    }
    return $out;
}

function req_row_by_id(int $rowId): ?array
{
    requirements_schema();
    $st = db()->prepare('SELECT * FROM request_requirements WHERE id = ?');
    $st->execute([$rowId]);
    return $st->fetch() ?: null;
}

/* ---------- Approval gate ---------- */

/** Returns a message if the request may NOT move to $to yet, otherwise null. */
function requirements_gate(int $rid, string $service, string $to): ?string
{
    if (!REQ_ENFORCE || !in_array($to, ['approved', 'completed'], true)) {
        return null;
    }
    $p = req_progress(req_list($rid, $service));
    if ($p['ready']) {
        return null;
    }
    return 'Cannot move this request to ' . STATUS_LABELS[$to] . ' yet. Still to be verified: '
        . implode(', ', $p['outstanding']) . '.';
}

/* ---------- Uploads ---------- */

function req_upload_dir(): string
{
    $dir = __DIR__ . '/../data/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $guard = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($guard)) {
        @file_put_contents($guard, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

/** Absolute path of a stored file, or null if it is missing or outside the upload folder. */
function req_abs(?string $rel): ?string
{
    if (!$rel) {
        return null;
    }
    $base = realpath(req_upload_dir());
    $abs = $base ? realpath($base . '/' . $rel) : false;
    if (!$abs || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
        return null;
    }
    return $abs;
}

/** True when the browser sent a body bigger than post_max_size (PHP then empties $_POST and $_FILES). */
function req_post_too_large(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/** Real file type from the first bytes (the browser-supplied name and type are not trusted). */
function req_detect_type(string $path): ?string
{
    $h = @file_get_contents($path, false, null, 0, 8);
    if ($h === false || $h === '') {
        return null;
    }
    if (strncmp($h, '%PDF', 4) === 0) {
        return 'pdf';
    }
    if (strncmp($h, "\x89PNG", 4) === 0) {
        return 'png';
    }
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpg';
    }
    return null;
}

/** Saves an uploaded file for one requirement. Returns an error message, or null on success. */
function req_store_upload(int $rid, string $key, $file): ?string
{
    requirements_schema();
    if (!is_array($file) || !isset($file['error'])) {
        return 'Please choose a file to upload.';
    }
    switch ((int)$file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return 'Please choose a file to upload.';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is too large. The maximum size is 5 MB.';
        default:
            return 'The upload failed. Please try again.';
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > REQ_MAX_BYTES) {
        return 'That file is too large. The maximum size is 5 MB.';
    }
    $tmp = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return 'The upload failed. Please try again.';
    }
    $ext = req_detect_type($tmp);
    if (!$ext) {
        return 'Only PDF, JPG or PNG files are accepted.';
    }

    $dir = req_upload_dir() . '/' . $rid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return 'The server could not save the file. Please tell the parish office.';
    }
    $rel = $rid . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($tmp, req_upload_dir() . '/' . $rel)) {
        return 'The server could not save the file. Please tell the parish office.';
    }

    $name = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename((string)$file['name']));
    $name = mb_substr(trim($name) !== '' ? $name : 'document.' . $ext, 0, 120);

    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT id, file_path FROM request_requirements WHERE request_id = ? AND req_key = ?');
    $st->execute([$rid, $key]);
    $old = $st->fetch();
    if ($old) {
        if ($old['file_path'] && ($abs = req_abs($old['file_path']))) {
            @unlink($abs);
        }
        $pdo->prepare("UPDATE request_requirements SET status = 'submitted', file_name = ?, file_path = ?, note = NULL, reviewed_by = NULL, reviewed_at = NULL, uploaded_at = ? WHERE id = ?")
            ->execute([$name, $rel, $now, $old['id']]);
    } else {
        $pdo->prepare("INSERT INTO request_requirements (request_id, req_key, status, file_name, file_path, uploaded_at) VALUES (?, ?, 'submitted', ?, ?, ?)")
            ->execute([$rid, $key, $name, $rel, $now]);
    }
    return null;
}

/**
 * Staff review. $decision: verify | reject | inperson | reset.
 * Returns an error message, or null on success.
 */
function req_review(int $rid, string $key, string $decision, string $note, string $by): ?string
{
    requirements_schema();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM request_requirements WHERE request_id = ? AND req_key = ?');
    $st->execute([$rid, $key]);
    $row = $st->fetch() ?: null;
    $hasFile = $row && !empty($row['file_path']);
    $note = mb_substr(trim($note), 0, 300);
    $now = date('Y-m-d H:i:s');

    if ($decision === 'verify') {
        if (!$hasFile) {
            return 'There is no uploaded file to verify. Use “Verified in person” if the paper copy was checked at the office.';
        }
        $pdo->prepare("UPDATE request_requirements SET status = 'verified', note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?")
            ->execute([$note !== '' ? $note : null, $by, $now, $row['id']]);
        return null;
    }
    if ($decision === 'reject') {
        if (!$hasFile) {
            return 'There is no uploaded file to reject.';
        }
        if ($note === '') {
            return 'Please give a short reason so the parishioner knows what to fix.';
        }
        $pdo->prepare("UPDATE request_requirements SET status = 'rejected', note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?")
            ->execute([$note, $by, $now, $row['id']]);
        return null;
    }
    if ($decision === 'inperson') {
        $note = $note !== '' ? $note : 'Checked in person at the parish office.';
        if ($row) {
            $pdo->prepare("UPDATE request_requirements SET status = 'verified', note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?")
                ->execute([$note, $by, $now, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO request_requirements (request_id, req_key, status, note, reviewed_by, reviewed_at) VALUES (?, ?, 'verified', ?, ?, ?)")
                ->execute([$rid, $key, $note, $by, $now]);
        }
        return null;
    }
    if ($decision === 'reset') {
        if (!$row) {
            return null;
        }
        $pdo->prepare('UPDATE request_requirements SET status = ?, note = NULL, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?')
            ->execute([$hasFile ? 'submitted' : 'missing', $row['id']]);
        return null;
    }
    return 'Unknown action.';
}

/** Sends a stored file to the browser. Call only after checking that the visitor may see it. */
function req_send_file(array $row): never
{
    $abs = req_abs($row['file_path'] ?? null);
    if (!$abs) {
        not_found();
    }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'][$ext] ?? 'application/octet-stream';
    $name = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', (string)($row['file_name'] ?? 'document.' . $ext));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

/** Deletes rows and files whose request no longer exists (after a permanent delete). */
function req_purge_orphans(): void
{
    requirements_schema();
    $pdo = db();
    $orphans = $pdo->query('SELECT id, file_path FROM request_requirements WHERE request_id NOT IN (SELECT id FROM service_requests)')->fetchAll();
    if (!$orphans) {
        return;
    }
    foreach ($orphans as $o) {
        if ($abs = req_abs($o['file_path'])) {
            @unlink($abs);
        }
    }
    $pdo->exec('DELETE FROM request_requirements WHERE request_id NOT IN (SELECT id FROM service_requests)');
}