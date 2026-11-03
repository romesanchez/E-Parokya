<?php
require_once __DIR__ . '/../includes/layout.php';

$pages = [
    'contact' => ['Contact Us', [
        'St. Joseph Parish Office, 12 Mabini Street, San Roque.',
        'Telephone: (02) 8555-0120',
        'Email: parishoffice@stjoseph.org',
        'Office hours: Monday to Saturday, 8:00 AM – 5:00 PM.',
    ]],
    'privacy' => ['Privacy Policy', [
        'We collect only what the parish office needs to process your request: your name, email, contact number, preferred date and any notes you write.',
        'Your information is used to review your request, contact you, and schedule the service. We do not sell or share it with outside companies.',
        'Passwords are stored in hashed form and cannot be read by parish staff. Staff actions in the parish office are recorded in an activity log.',
        'To correct or remove your information, contact the parish office.',
    ]],
    'agreement' => ['Service Agreement', [
        'By creating an account you agree to provide accurate information and to use the website only for genuine parish service requests.',
        'Submitting a request does not guarantee a date or time. A request is confirmed only once the parish office approves it and sets a schedule.',
        'The parish may decline, reschedule or cancel a request when requirements are not met or the venue or priest is unavailable.',
        'You are responsible for keeping your login details private.',
    ]],
    'security' => ['Learn About Security', [
        'Never share your password with anyone. The parish office will never ask for it by phone, text or email.',
        'Use a password that is at least 8 characters and not used on other websites.',
        'Always log out when using a shared or public computer.',
        'Repeated failed log-ins temporarily lock the account for 15 minutes. If you notice anything unusual, tell the parish office right away.',
    ]],
];

$p = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'contact';
if (!isset($pages[$p])) {
    $p = 'contact';
}
[$title, $paras] = $pages[$p];

page_start($title . ' — St. Joseph Parish', $title, '');
?>
<div class="container narrow-page">
  <div class="center">
    <h1 class="page-title"><?= e($title) ?></h1>
    <div class="rule"></div>
  </div>

  <div class="tabs" style="justify-content:center;margin-top:1.5rem">
    <?php foreach ($pages as $key => [$label]): ?>
      <a href="info.php?p=<?= e($key) ?>" class="tab<?= $p === $key ? ' active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="card" style="padding:1.5rem;margin-top:1.5rem">
    <?php foreach ($paras as $para): ?>
      <p class="small" style="margin-bottom:.75rem"><?= e($para) ?></p>
    <?php endforeach; ?>
  </div>
</div>
<?php page_end();