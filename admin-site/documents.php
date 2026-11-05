<?php
/**
 * Document & Certificate Management
 * Issues standardized parish documents (baptismal, confirmation, first communion,
 * marriage, death/burial certificates) and digital receipts.
 *
 * - Wording lives in DOC_TYPES below: edit it to match the parish-approved formats.
 * - Creates its own table (parish_documents) on first run; no manual import needed.
 * - Lives in the admin site: only logged-in staff (with 2FA) can open it.
 */
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/db.php';
$me = require_admin();

const PARISH_NAME = 'St. Joseph Parish';
const PARISH_ADDR = '12 Mabini Street, San Roque · (02) 8555-0120 · parishoffice@stjoseph.org';

// Field format: key => [label, type (text|date|money|textarea), required, placeholder]
// Fields not mentioned in 'body' are printed as detail lines under the certificate text.
const DOC_TYPES = [
    'baptism' => [
        'label' => 'Baptismal Certificate', 'title' => 'Certificate of Baptism', 'prefix' => 'BAP', 'icon' => '💧',
        'body' => 'This is to certify that {name}, born on {birth_date} at {birth_place}, child of {father} and {mother}, was solemnly baptized on {bap_date} by {minister}, according to the rite of the Roman Catholic Church.',
        'fields' => [
            'name' => ['Full name of child', 'text', 1, 'Maria Clara Santos'],
            'birth_date' => ['Date of birth', 'date', 1],
            'birth_place' => ['Place of birth', 'text', 1, 'Dagupan City, Pangasinan'],
            'father' => ['Father', 'text', 1],
            'mother' => ['Mother (maiden name)', 'text', 1],
            'bap_date' => ['Date of baptism', 'date', 1],
            'minister' => ['Officiating minister', 'text', 1, 'Rev. Fr. Juan dela Cruz'],
            'sponsors' => ['Sponsors / godparents', 'textarea', 0],
            'register' => ['Register: Book / Page / Entry No.', 'text', 0, 'Book 12 / Page 45 / Entry 133'],
        ],
    ],
    'confirmation' => [
        'label' => 'Confirmation Certificate', 'title' => 'Certificate of Confirmation', 'prefix' => 'CON', 'icon' => '🕊️',
        'body' => 'This is to certify that {name} received the Sacrament of Confirmation on {conf_date} at ' . PARISH_NAME . ', administered by {minister}.',
        'fields' => [
            'name' => ['Full name', 'text', 1],
            'conf_date' => ['Date of confirmation', 'date', 1],
            'minister' => ['Minister of confirmation', 'text', 1, 'Most Rev. Bishop'],
            'sponsor' => ['Sponsor', 'text', 0],
            'register' => ['Register: Book / Page / Entry No.', 'text', 0],
        ],
    ],
    'first_communion' => [
        'label' => 'First Communion Certificate', 'title' => 'Certificate of First Holy Communion', 'prefix' => 'FHC', 'icon' => '🍞',
        'body' => 'This is to certify that {name} received First Holy Communion on {fc_date} at ' . PARISH_NAME . ', with {minister} officiating.',
        'fields' => [
            'name' => ['Full name', 'text', 1],
            'fc_date' => ['Date of First Communion', 'date', 1],
            'minister' => ['Officiating priest', 'text', 1],
            'register' => ['Register: Book / Page / Entry No.', 'text', 0],
        ],
    ],
    'marriage' => [
        'label' => 'Marriage Certificate', 'title' => 'Certificate of Marriage', 'prefix' => 'MAR', 'icon' => '💍',
        'body' => 'This is to certify that {groom} and {bride} were united in Holy Matrimony on {mar_date} at ' . PARISH_NAME . ', the marriage being solemnized by {minister} in the presence of the Church.',
        'fields' => [
            'groom' => ['Groom (full name)', 'text', 1],
            'bride' => ['Bride (full name)', 'text', 1],
            'mar_date' => ['Date of marriage', 'date', 1],
            'minister' => ['Officiating priest', 'text', 1],
            'witnesses' => ['Witnesses / principal sponsors', 'textarea', 0],
            'register' => ['Register: Book / Page / Entry No.', 'text', 0],
        ],
    ],
    'death' => [
        'label' => 'Death / Burial Certificate', 'title' => 'Certificate of Death and Christian Burial', 'prefix' => 'DTH', 'icon' => '🕯️',
        'body' => 'This is to certify that {name}, aged {age}, who died on {death_date}, was given Christian burial on {burial_date} with interment at {interment}, the rites being officiated by {minister}.',
        'fields' => [
            'name' => ['Full name of the deceased', 'text', 1],
            'age' => ['Age at death', 'text', 1, '82 years'],
            'death_date' => ['Date of death', 'date', 1],
            'burial_date' => ['Date of burial', 'date', 1],
            'interment' => ['Place of interment', 'text', 1, 'Holy Cross Cemetery'],
            'minister' => ['Officiating priest', 'text', 1],
            'sacraments' => ['Sacraments received (optional)', 'text', 0, 'Anointing of the Sick, Viaticum'],
            'informant' => ['Informant / next of kin', 'text', 0],
            'register' => ['Register: Book / Page / Entry No.', 'text', 0],
        ],
    ],
    'receipt' => [
        'label' => 'Digital Receipt', 'title' => 'Digital Receipt', 'prefix' => 'OR', 'icon' => '🧾', 'receipt' => true,
        'body' => 'Received from {payer} the amount of {amount} for {purpose}.',
        'fields' => [
            'payer' => ['Received from', 'text', 1],
            'purpose' => ['Purpose', 'text', 1, 'Certificate fee / Mass offering / Donation'],
            'amount' => ['Amount (PHP)', 'money', 1, '300.00'],
            'method' => ['Payment method', 'text', 0, 'Cash / GCash / Bank transfer'],
            'reference' => ['Reference no. (optional)', 'text', 0],
        ],
    ],
];

// Service request type -> document type (used to prefill from ?request=ID).
const REQUEST_TO_DOC = ['baptism' => 'baptism', 'wedding' => 'marriage', 'funeral' => 'death', 'first_communion' => 'first_communion', 'confirmation' => 'confirmation'];

/* ------------------------------------------------------------------ data */

function docs_init(): void
{
    $c = require __DIR__ . '/../includes/config.php';
    $pk = $c['driver'] === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    db()->exec("CREATE TABLE IF NOT EXISTS parish_documents (
        id $pk,
        doc_no VARCHAR(32) NOT NULL UNIQUE,
        doc_type VARCHAR(24) NOT NULL,
        recipient VARCHAR(255) NOT NULL,
        data TEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'issued',
        void_reason VARCHAR(255) NULL,
        issued_by VARCHAR(120) NOT NULL,
        issued_at DATETIME NOT NULL
    )");
}

function next_doc_no(string $type): string
{
    $prefix = DOC_TYPES[$type]['prefix'] . '-' . date('Y') . '-';
    $st = db()->prepare('SELECT doc_no FROM parish_documents WHERE doc_no LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();
    return $prefix . str_pad((string)($last ? (int)substr($last, -4) + 1 : 1), 4, '0', STR_PAD_LEFT);
}

function recipient_of(string $type, array $d): string
{
    return match ($type) {
        'marriage' => ($d['groom'] ?? '') . ' & ' . ($d['bride'] ?? ''),
        'receipt' => $d['payer'] ?? '',
        default => $d['name'] ?? '',
    };
}

function verify_code(string $no, string $recipient): string
{
    $secret = getenv('PSC_DOC_SECRET') ?: 'change-this-secret';
    return strtoupper(substr(hash_hmac('sha256', $no . '|' . mb_strtolower($recipient), $secret), 0, 8));
}

function valid_date(string $v): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

function fmt_val(string $ftype, string $v): string
{
    return match ($ftype) {
        'date' => date('F j, Y', strtotime($v)),
        'money' => '₱' . number_format((float)$v, 2),
        default => $v,
    };
}

function get_doc(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM parish_documents WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || !isset(DOC_TYPES[$r['doc_type']])) {
        return null;
    }
    $r['data'] = json_decode($r['data'], true) ?: [];
    return $r;
}

function render_body(string $type, array $d): string
{
    $fields = DOC_TYPES[$type]['fields'];
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($fields, $d) {
        $k = $m[1];
        return '<strong>' . e(isset($d[$k], $fields[$k]) ? fmt_val($fields[$k][1], $d[$k]) : '—') . '</strong>';
    }, e(DOC_TYPES[$type]['body'])) ?? '';
}

/* ------------------------------------------------------- printable view */

function render_document(array $doc): never
{
    $def = DOC_TYPES[$doc['doc_type']];
    $d = $doc['data'];
    $isReceipt = !empty($def['receipt']);
    $code = verify_code($doc['doc_no'], $doc['recipient']);
    $voided = $doc['status'] === 'voided';
    $extras = [];
    foreach ($def['fields'] as $k => $f) {
        if (!str_contains($def['body'], '{' . $k . '}') && isset($d[$k])) {
            $extras[$f[0]] = fmt_val($f[1], $d[$k]);
        }
    }
    $label = fn(string $s) => preg_replace('/ \(optional\)$/', '', $s);
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($def['title'] . ' ' . $doc['doc_no']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,400&family=Work+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --wine: #6b2a22; --gold: #b8923a; --ink: #2e2218; --paper: #fffdf7; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #ece6d8; color: var(--ink); font-family: "Work Sans", system-ui, sans-serif; }
  .bar { display: flex; gap: .5rem; justify-content: center; padding: 1rem; }
  .bar a, .bar button { border: 1px solid var(--wine); background: var(--wine); color: #fff; padding: .55rem 1.2rem; border-radius: 4px; font: 600 .85rem "Work Sans", sans-serif; cursor: pointer; text-decoration: none; }
  .bar a.alt { background: transparent; color: var(--wine); }
  .sheet { position: relative; width: min(100%, <?= $isReceipt ? '560px' : '980px' ?>); margin: 0 auto 2rem; background: var(--paper); padding: 14px; box-shadow: 0 6px 24px rgb(0 0 0 / .15); }
  .frame { border: 3px double var(--gold); padding: <?= $isReceipt ? '1.5rem' : '2.5rem 3rem' ?>; text-align: center; min-height: <?= $isReceipt ? '0' : '560px' ?>; display: flex; flex-direction: column; }
  .cross { font: 2rem "Cormorant Garamond", serif; color: var(--wine); }
  .parish { font: 700 1.6rem "Cormorant Garamond", serif; letter-spacing: .08em; text-transform: uppercase; color: var(--wine); }
  .addr, .small { font-size: .78rem; color: #7a6a58; }
  .title { margin: 1.4rem 0 .4rem; font: 600 <?= $isReceipt ? '1.7rem' : '2.3rem' ?> "Cormorant Garamond", serif; }
  .rule { width: 6rem; height: 2px; background: var(--gold); margin: 0 auto 1.4rem; }
  .body { font: 500 1.35rem/1.8 "Cormorant Garamond", serif; max-width: 46rem; margin: 0 auto; }
  .body strong { font-weight: 700; color: var(--wine); border-bottom: 1px solid #d9cdb4; }
  .extras { margin: 1.2rem auto 0; font-size: .85rem; text-align: left; display: grid; gap: .2rem; }
  .extras b { color: #7a6a58; font-weight: 500; }
  .amount { margin: 1rem auto 0; border: 2px solid var(--wine); padding: .5rem 1.6rem; font: 700 1.8rem "Cormorant Garamond", serif; color: var(--wine); }
  .spacer { flex: 1; }
  .sign { display: flex; justify-content: space-around; gap: 2rem; margin-top: 2.6rem; }
  .sign div { min-width: 11rem; border-top: 1px solid var(--ink); padding-top: .3rem; font-size: .8rem; }
  .sign span { display: block; font-weight: 600; }
  .foot { margin-top: 1.6rem; display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; font-size: .72rem; color: #7a6a58; text-align: left; }
  .void { position: absolute; inset: 0; display: grid; place-items: center; pointer-events: none; font: 700 7rem "Cormorant Garamond", serif; color: rgb(180 30 30 / .22); transform: rotate(-24deg); letter-spacing: .2em; }
  @page { size: A4 <?= $isReceipt ? 'portrait' : 'landscape' ?>; margin: 8mm; }
  @media print { body { background: none; } .bar { display: none; } .sheet { box-shadow: none; margin: 0 auto; width: 100%; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">Print / Save as PDF</button><a class="alt" href="documents.php">← Back to documents</a></div>
<div class="sheet"><div class="frame">
  <div class="cross">✝</div>
  <div class="parish"><?= e(PARISH_NAME) ?></div>
  <div class="addr"><?= e(PARISH_ADDR) ?></div>
  <h1 class="title"><?= e($def['title']) ?></h1><div class="rule"></div>
  <p class="body"><?= render_body($doc['doc_type'], $d) ?></p>
  <?php if ($isReceipt): ?><div><span class="amount"><?= e(fmt_val('money', $d['amount'])) ?></span></div><?php endif; ?>
  <?php if ($extras): ?><div class="extras"><?php foreach ($extras as $k => $v): ?><div><b><?= e($label($k)) ?>:</b> <?= e($v) ?></div><?php endforeach; ?></div><?php endif; ?>
  <div class="spacer"></div>
  <div class="sign">
    <?php if ($isReceipt): ?>
      <div><span><?= e($doc['issued_by']) ?></span>Received by / Parish Office</div>
    <?php else: ?>
      <div><span>&nbsp;</span>Parish Secretary</div><div><span>&nbsp;</span>Parish Priest</div>
    <?php endif; ?>
  </div>
  <div class="foot">
    <span>No. <b><?= e($doc['doc_no']) ?></b> · Issued <?= e(date('F j, Y', strtotime($doc['issued_at']))) ?><?= $isReceipt ? '' : ' by ' . e($doc['issued_by']) ?></span>
    <?php if (!empty($d['_fee']) || !empty($d['_or'])): ?><span>Fee paid: <?= e(fmt_val('money', $d['_fee'] ?? '0')) ?><?= !empty($d['_or']) ? ' · OR No. ' . e($d['_or']) : '' ?></span><?php endif; ?>
    <span>Verification code: <b><?= e($code) ?></b> (verify at documents.php)</span>
  </div>
  <?php if (!$isReceipt): ?><p class="small" style="margin-top:.6rem">Not valid without the parish dry seal and the signature of the parish priest.</p><?php endif; ?>
</div>
<?php if ($voided): ?><div class="void">VOID</div><?php endif; ?>
</div></body></html>
<?php
    exit;
}

/* ---------------------------------------------------------------- actions */

docs_init();

if (isset($_GET['view'])) {
    $doc = get_doc((int)$_GET['view']);
    if ($doc) {
        render_document($doc);
    }
    http_response_code(404);
}

$newType = isset(DOC_TYPES[$_GET['new'] ?? '']) ? $_GET['new'] : null;
$old = [];
$errors = [];
$flash = null;

// Prefill from a service request: documents.php?request=ID
if (!$newType && isset($_GET['request'])) {
    $st = db()->prepare('SELECT * FROM service_requests WHERE id = ?');
    $st->execute([(int)$_GET['request']]);
    if ($req = $st->fetch()) {
        $newType = REQUEST_TO_DOC[$req['service_type']] ?? null;
        if ($newType) {
            $old[$newType === 'marriage' ? 'groom' : 'name'] = $req['full_name'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'void') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        $id = (int)($_POST['id'] ?? 0);
        if ($id && mb_strlen($reason) >= 3) {
            db()->prepare("UPDATE parish_documents SET status = 'voided', void_reason = ? WHERE id = ?")->execute([mb_substr($reason, 0, 255), $id]);
            redirect('documents.php?voided=1');
        }
        $errors[] = 'Please give a reason for voiding the document.';
    } elseif ($action === 'issue' && isset(DOC_TYPES[$_POST['type'] ?? ''])) {
        $newType = $_POST['type'];
        $def = DOC_TYPES[$newType];
        $data = [];
        foreach ($def['fields'] as $k => $f) {
            $v = trim((string)($_POST[$k] ?? ''));
            $old[$k] = $v;
            if ($v === '') {
                if ($f[2]) {
                    $errors[] = $f[0] . ' is required.';
                }
                continue;
            }
            if ($f[1] === 'date' && !valid_date($v)) {
                $errors[] = $f[0] . ' is not a valid date.';
            } elseif ($f[1] === 'money' && (!is_numeric($v) || (float)$v <= 0 || (float)$v > 10000000)) {
                $errors[] = $f[0] . ' must be a positive amount.';
            } else {
                $data[$k] = mb_substr($v, 0, 500);
            }
        }
        $issuedBy = trim((string)($_POST['issued_by'] ?? ''));
        $old['issued_by'] = $issuedBy;
        if (mb_strlen($issuedBy) < 2) {
            $errors[] = 'Please enter the name of the staff issuing this document.';
        }
        if (empty($def['receipt'])) {
            $fee = trim((string)($_POST['_fee'] ?? ''));
            $or = trim((string)($_POST['_or'] ?? ''));
            $old['_fee'] = $fee;
            $old['_or'] = $or;
            if ($fee !== '') {
                if (is_numeric($fee) && (float)$fee >= 0) {
                    $data['_fee'] = $fee;
                } else {
                    $errors[] = 'Fee paid must be a number.';
                }
            }
            if ($or !== '') {
                $data['_or'] = mb_substr($or, 0, 40);
            }
        }
        if (!$errors) {
            $recipient = mb_substr(recipient_of($newType, $data), 0, 255);
            for ($try = 0; $try < 3; $try++) {
                try {
                    $no = next_doc_no($newType);
                    db()->prepare('INSERT INTO parish_documents (doc_no, doc_type, recipient, data, status, issued_by, issued_at) VALUES (?, ?, ?, ?, \'issued\', ?, ?)')
                        ->execute([$no, $newType, $recipient, json_encode($data, JSON_UNESCAPED_UNICODE), mb_substr($issuedBy, 0, 120), date('Y-m-d H:i:s')]);
                    log_action('document_issued', $no . ' — ' . $recipient);
                    redirect('documents.php?view=' . (int)db()->lastInsertId());
                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                }
            }
            $errors[] = 'Could not save the document. Please try again.';
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $st = db()->prepare('SELECT * FROM parish_documents WHERE recipient LIKE ? OR doc_no LIKE ? ORDER BY id DESC LIMIT 100');
    $st->execute(['%' . $q . '%', '%' . $q . '%']);
} else {
    $st = db()->query('SELECT * FROM parish_documents ORDER BY id DESC LIMIT 100');
}
$docs = $st->fetchAll();
$total = (int)db()->query("SELECT COUNT(*) FROM parish_documents WHERE status = 'issued'")->fetchColumn();
$voidedN = (int)db()->query("SELECT COUNT(*) FROM parish_documents WHERE status = 'voided'")->fetchColumn();
$month = (int)db()->query("SELECT COUNT(*) FROM parish_documents WHERE issued_at >= '" . date('Y-m-01') . " 00:00:00'")->fetchColumn();

$verifyMsg = null;
if (isset($_GET['verify_no'])) {
    $vs = db()->prepare('SELECT * FROM parish_documents WHERE doc_no = ?');
    $vs->execute([trim((string)$_GET['verify_no'])]);
    $v = $vs->fetch();
    $ok = $v && hash_equals(verify_code($v['doc_no'], $v['recipient']), strtoupper(trim((string)($_GET['verify_code'] ?? ''))));
    $verifyMsg = $ok
        ? [$v['status'] === 'issued', ($v['status'] === 'issued' ? '✓ Authentic. ' : '⚠ This document was VOIDED. ') . DOC_TYPES[$v['doc_type']]['label'] . ' issued ' . date('F j, Y', strtotime($v['issued_at'])) . '.']
        : [false, 'No document matches that number and code.'];
}

admin_start('Documents & Certificates', 'documents');
?>
<style>
  .docs { padding-top: 1.75rem; padding-bottom: 2.5rem; max-width: 78rem; }
  .type-grid { margin-top: 1.5rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr)); gap: .75rem; }
  .type-card { padding: 1rem; text-align: center; font-size: .875rem; font-weight: 500; transition: box-shadow .15s, border-color .15s; }
  .type-card:hover, .type-card.on { border-color: var(--primary); box-shadow: 0 2px 6px rgb(0 0 0 / .1); }
  .type-card span { display: block; font-size: 1.5rem; }
  .doc-form { margin-top: 1.5rem; padding: 1.5rem; display: grid; gap: 1rem; }
  .doc-form h2, .docs h2 { font: 600 1.4rem var(--font-display); }
  .doc-form .row { display: grid; gap: 1rem; }
  .doc-row { padding: 1rem; display: flex; gap: 1rem; align-items: center; justify-content: space-between; flex-wrap: wrap; }
  .doc-row h3 { font: 600 1.1rem var(--font-display); }
  .doc-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
  .doc-actions .btn { padding: .4rem .9rem; }
  .doc-actions form { display: flex; gap: .4rem; }
  .doc-actions input { width: 9rem; padding: .35rem .6rem; }
  .search { margin-top: 2rem; display: flex; gap: .5rem; }
  .ok { background: color-mix(in oklab, var(--primary) 10%, transparent); color: var(--primary); border-radius: var(--radius); padding: .5rem .75rem; font-size: .875rem; margin-top: 1rem; }
  @media (min-width: 640px) { .doc-form .row { grid-template-columns: 1fr 1fr; } }
</style>
<div class="container wide docs">
  <h1 class="page-title left">Documents &amp; Certificates</h1>
  <p class="muted small">Issue sacramental certificates and digital receipts in the standard parish formats. Each document gets a unique number and verification code.</p>

  <div class="stats" style="grid-template-columns: repeat(3, 1fr)">
    <?php foreach ([['Issued documents', $total], ['Issued this month', $month], ['Voided', $voidedN]] as [$l, $n]): ?>
      <div class="card stat"><p class="label-caps"><?= e($l) ?></p><p class="stat-n"><?= (int)$n ?></p></div>
    <?php endforeach; ?>
  </div>

  <div class="type-grid">
    <?php foreach (DOC_TYPES as $key => $t): ?>
      <a href="documents.php?new=<?= e($key) ?>#form" class="card type-card<?= $newType === $key ? ' on' : '' ?>"><span aria-hidden="true"><?= $t['icon'] ?></span><?= e($t['label']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (isset($_GET['voided'])): ?><p class="ok">Document voided. The printed copy now shows a VOID mark and will fail verification.</p><?php endif; ?>

  <?php if ($newType): $def = DOC_TYPES[$newType]; ?>
    <form id="form" method="post" action="documents.php?new=<?= e($newType) ?>" class="card doc-form">
      <h2><?= e($def['icon'] . ' ' . $def['label']) ?></h2>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="issue">
      <input type="hidden" name="type" value="<?= e($newType) ?>">
      <?php foreach ($errors as $err): ?><p class="alert-error"><?= e($err) ?></p><?php endforeach; ?>
      <div class="row">
        <?php foreach ($def['fields'] as $k => $f): ?>
          <div class="field"<?= $f[1] === 'textarea' ? ' style="grid-column: 1 / -1"' : '' ?>>
            <label for="f_<?= e($k) ?>"><?= e($f[0]) ?><?= $f[2] ? ' *' : '' ?></label>
            <?php if ($f[1] === 'textarea'): ?>
              <textarea id="f_<?= e($k) ?>" name="<?= e($k) ?>" rows="2" maxlength="500"><?= e($old[$k] ?? '') ?></textarea>
            <?php else: ?>
              <input id="f_<?= e($k) ?>" name="<?= e($k) ?>" type="<?= $f[1] === 'date' ? 'date' : ($f[1] === 'money' ? 'number' : 'text') ?>"
                     <?= $f[1] === 'money' ? 'step="0.01" min="0.01"' : 'maxlength="200"' ?> value="<?= e($old[$k] ?? '') ?>"
                     placeholder="<?= e($f[3] ?? '') ?>" <?= $f[2] ? 'required' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (empty($def['receipt'])): ?>
          <div class="field"><label for="f_fee">Fee paid (₱, optional)</label><input id="f_fee" name="_fee" type="number" step="0.01" min="0" value="<?= e($old['_fee'] ?? '') ?>"></div>
          <div class="field"><label for="f_or">OR number (optional)</label><input id="f_or" name="_or" maxlength="40" value="<?= e($old['_or'] ?? '') ?>"></div>
        <?php endif; ?>
        <div class="field"><label for="f_by">Issued by (staff name) *</label><input id="f_by" name="issued_by" maxlength="120" required value="<?= e($old['issued_by'] ?? $me['name']) ?>"></div>
      </div>
      <div><button class="btn btn-success">Generate <?= e($def['label']) ?></button></div>
    </form>
  <?php elseif ($errors): foreach ($errors as $err): ?><p class="alert-error"><?= e($err) ?></p><?php endforeach; endif; ?>

  <form method="get" action="documents.php" class="search">
    <input name="q" value="<?= e($q) ?>" placeholder="Search by name or document number" aria-label="Search documents">
    <button class="btn btn-info">Search</button>
  </form>

  <h2 style="margin-top:2rem">Issued documents</h2>
  <div class="req-list" style="margin-top:1rem; display:grid; gap:.75rem">
    <?php if (!$docs): ?><div class="empty">No documents yet.</div><?php endif; ?>
    <?php foreach ($docs as $r): $t = DOC_TYPES[$r['doc_type']] ?? null; if (!$t) { continue; } ?>
      <div class="card doc-row">
        <div>
          <h3><?= e($r['recipient']) ?> <span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></h3>
          <p class="muted small"><?= e($t['label']) ?> · <?= e($r['doc_no']) ?> · <?= e(fmt_date($r['issued_at'])) ?> · by <?= e($r['issued_by']) ?><?= $r['void_reason'] ? ' · Void reason: ' . e($r['void_reason']) : '' ?></p>
        </div>
        <div class="doc-actions">
          <a class="btn btn-outline" href="documents.php?view=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">View / Print</a>
          <?php if ($r['status'] === 'issued'): ?>
            <form method="post" action="documents.php" onsubmit="return confirm('Void this document? This cannot be undone.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input name="reason" placeholder="Reason to void" required minlength="3" aria-label="Reason to void">
              <button class="btn btn-danger">Void</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card doc-form" style="margin-top:2rem">
    <h2>Verify a document</h2>
    <p class="muted small">Enter the document number and verification code printed at the bottom of the certificate.</p>
    <form method="get" action="documents.php#verify" id="verify" class="search" style="margin-top:0">
      <input name="verify_no" placeholder="e.g. BAP-2026-0001" value="<?= e($_GET['verify_no'] ?? '') ?>" required aria-label="Document number">
      <input name="verify_code" placeholder="Code" value="<?= e($_GET['verify_code'] ?? '') ?>" required aria-label="Verification code" style="max-width:10rem">
      <button class="btn btn-info">Verify</button>
    </form>
    <?php if ($verifyMsg): ?><p class="<?= $verifyMsg[0] ? 'ok' : 'alert-error' ?>" style="margin-top:0"><?= e($verifyMsg[1]) ?></p><?php endif; ?>
  </div>
</div>
<?php admin_end();