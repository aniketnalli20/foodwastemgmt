<?php
require_once __DIR__ . '/app.php';
require_login();

$user = current_user();
if (!$user) { header('Location: ' . $BASE_PATH . 'login.php'); exit; }

$errors = [];
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'submit_kyc') {
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $bank_account_name = trim((string)($_POST['bank_account_name'] ?? ''));
    $bank_account_number = trim((string)($_POST['bank_account_number'] ?? ''));
    $ifsc = trim((string)($_POST['ifsc'] ?? ''));
    $bank_name = trim((string)($_POST['bank_name'] ?? ''));
    $id_number = trim((string)($_POST['id_number'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($full_name === '' || $phone === '' || $address === '' || $bank_account_name === '' || $bank_account_number === '' || $ifsc === '' || $bank_name === '' || $id_number === '') {
        $errors[] = 'All fields are required';
    } else if (!preg_match('/^[0-9A-Za-z\-\s]{6,20}$/', $ifsc)) {
        $errors[] = 'Invalid IFSC';
    }

    if (empty($errors)) {
        try {
            $now = gmdate('Y-m-d H:i:s');
            $st = $pdo->prepare('INSERT INTO kyc_requests (user_id, full_name, phone, address, bank_account_name, bank_account_number, ifsc, bank_name, id_number, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([(int)$user['id'], $full_name, $phone, $address, $bank_account_name, $bank_account_number, $ifsc, $bank_name, $id_number, 'pending', ($notes !== '' ? $notes : null), $now, $now]);
            $message = 'KYC submitted. We will verify your details manually.';
        } catch (Throwable $e) {
            $errors[] = 'Submission failed; please try again later';
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KYC · No Starve</title>
  <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0" />
</head>
<body class="page-profile">
  <header class="site-header" role="banner">
    <div class="container header-inner">
      <nav class="navbar navbar-expand-lg navbar-light bg-light" role="navigation" aria-label="Primary">
        <a class="navbar-brand" href="<?= h($BASE_PATH) ?>index.php#hero">No Starve</a>
      </nav>
    </div>
  </header>

  <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);">
    <section class="card-plain" aria-label="KYC Requirements">
      <h2 class="section-title">KYC</h2>
      <div class="card-plain">
        <strong>Wallet access requirements</strong>
        <ul class="list-clean" style="margin-top:6px;">
          <li>Verified user (blue tick)</li>
          <li>10k+ followers</li>
          <li>100k+ Karma Coins</li>
        </ul>
      </div>
      <?php if (!empty($errors)): ?>
        <div class="alert error" role="alert">
          <ul class="list-clean">
            <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($message): ?>
        <div class="alert success" role="status"><?= h($message) ?></div>
      <?php endif; ?>
      <form method="post" class="form" action="<?= h($BASE_PATH) ?>kyc.php">
        <input type="hidden" name="action" value="submit_kyc">
        <label><strong>Full Name</strong></label>
        <input name="full_name" type="text" class="input" value="<?= h((string)($user['username'] ?? '')) ?>" required>
        <label style="margin-top:8px;"><strong>Phone</strong></label>
        <input name="phone" type="text" class="input" value="<?= h((string)($user['phone'] ?? '')) ?>" required>
        <label style="margin-top:8px;"><strong>Address</strong></label>
        <textarea name="address" class="input" rows="3" required><?= h((string)($user['address'] ?? '')) ?></textarea>
        <label style="margin-top:8px;"><strong>Bank Account Holder Name</strong></label>
        <input name="bank_account_name" type="text" class="input" required>
        <label style="margin-top:8px;"><strong>Bank Account Number</strong></label>
        <input name="bank_account_number" type="text" class="input" required>
        <label style="margin-top:8px;"><strong>IFSC</strong></label>
        <input name="ifsc" type="text" class="input" placeholder="e.g., HDFC0001234" required>
        <label style="margin-top:8px;"><strong>Bank Name</strong></label>
        <input name="bank_name" type="text" class="input" required>
        <label style="margin-top:8px;"><strong>ID Number (PAN/Aadhaar)</strong></label>
        <input name="id_number" type="text" class="input" required>
        <label style="margin-top:8px;"><strong>Notes</strong></label>
        <textarea name="notes" class="input" rows="2" placeholder="Any remarks"></textarea>
        <div class="actions" style="margin-top:10px;">
          <button type="submit" class="btn pill"><span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:-4px;">badge</span> Submit KYC</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>