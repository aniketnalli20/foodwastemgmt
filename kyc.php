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
      <form method="post" class="form kyc-form" action="<?= h($BASE_PATH) ?>kyc.php">
        <input type="hidden" name="action" value="submit_kyc">
        <div class="group-title">User Details</div>
        <div class="form-grid">
          <div class="field full">
            <label>Full Name</label>
            <input name="full_name" type="text" class="input" placeholder="Full Name" value="<?= h((string)($user['username'] ?? '')) ?>" required>
          </div>
          <div class="field full">
            <label>Email</label>
            <input name="email" type="email" class="input" placeholder="user@example.com" value="<?= h((string)($user['email'] ?? '')) ?>" required disabled>
          </div>
          <div class="field">
            <label>Phone</label>
            <input name="phone" type="text" class="input" placeholder="+91 98765 43210" value="<?= h((string)($user['phone'] ?? '')) ?>" required>
          </div>
          <div class="field full">
            <label>Address</label>
            <textarea name="address" class="input" rows="3" placeholder="Malpur Taluka, Aravalli, Gujarat, 383345, India" required><?= h((string)($user['address'] ?? '')) ?></textarea>
          </div>
        </div>

        <div class="group-title" style="margin-top:12px;">Wallet Details</div>
        <div class="form-grid">
          <div class="field">
            <label>Bank Account Holder Name</label>
            <input name="bank_account_name" type="text" class="input" placeholder="Account holder name" required>
          </div>
          <div class="field">
            <label>Bank Account Number</label>
            <input name="bank_account_number" type="text" class="input" placeholder="Account number" required>
          </div>
          <div class="field">
            <label>IFSC</label>
            <input name="ifsc" type="text" class="input" placeholder="e.g., HDFC0001234" required>
          </div>
          <div class="field">
            <label>Bank Name</label>
            <input name="bank_name" type="text" class="input" placeholder="Bank name" required>
          </div>
          <div class="field full">
            <label>ID Number (PAN/Aadhaar)</label>
            <input name="id_number" type="text" class="input" placeholder="ID number" required>
          </div>
          <div class="field full">
            <label>Notes</label>
            <textarea name="notes" class="input" rows="2" placeholder="Any remarks"></textarea>
          </div>
        </div>
        <div class="actions" style="display:flex; gap:8px; align-items:center;">
          <button type="button" class="btn pill" id="btn-autofill"><span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span> Autofill</button>
          <button type="submit" class="btn pill"><span class="material-symbols-outlined" aria-hidden="true">badge</span> Submit KYC</button>
        </div>
      </form>
    </section>
  </main>
  <script>
  (function(){
    var btn = document.getElementById('btn-autofill');
    if (!btn) return;
    function pick(arr){ return arr[Math.floor(Math.random() * arr.length)]; }
    function randDigits(n){ var s=''; for(var i=0;i<n;i++){ s += String(Math.floor(Math.random()*10)); } return s; }
    function genPAN(){
      var letters='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
      function randL(k){ var r=''; for(var i=0;i<k;i++){ r += letters[Math.floor(Math.random()*letters.length)]; } return r; }
      return randL(5) + randDigits(4) + randL(1);
    }
    var banks = [
      { name: 'State Bank of India', code: 'SBIN' },
      { name: 'HDFC Bank', code: 'HDFC' },
      { name: 'ICICI Bank', code: 'ICIC' },
      { name: 'Axis Bank', code: 'UTIB' },
      { name: 'Kotak Mahindra Bank', code: 'KKBK' },
      { name: 'Punjab National Bank', code: 'PUNB' },
      { name: 'Bank of Baroda', code: 'BARB' },
      { name: 'Canara Bank', code: 'CNRB' }
    ];
    btn.addEventListener('click', function(){
      try {
        var bank = pick(banks);
        var ifsc = bank.code + '0' + randDigits(6);
        var accLen = 12 + Math.floor(Math.random()*5); // 12-16
        var acc = randDigits(accLen);
        var phoneEl = document.querySelector('input[name="phone"]');
        var addrEl = document.querySelector('textarea[name="address"]');
        var nameEl = document.querySelector('input[name="bank_account_name"]');
        var accEl = document.querySelector('input[name="bank_account_number"]');
        var ifscEl = document.querySelector('input[name="ifsc"]');
        var bankEl = document.querySelector('input[name="bank_name"]');
        var idEl = document.querySelector('input[name="id_number"]');
        var notesEl = document.querySelector('textarea[name="notes"]');
        var fullNameEl = document.querySelector('input[name="full_name"]');
        if (nameEl && fullNameEl && (!nameEl.value || nameEl.value.trim() === '')) nameEl.value = fullNameEl.value || 'Account Holder';
        if (accEl) accEl.value = acc;
        if (ifscEl) ifscEl.value = ifsc;
        if (bankEl) bankEl.value = bank.name;
        if (idEl) idEl.value = genPAN();
        if (notesEl && (!notesEl.value || notesEl.value.trim() === '')) notesEl.value = 'Autofilled for review';
        if (phoneEl && (!phoneEl.value || phoneEl.value.trim() === '')) phoneEl.value = '+91 ' + randDigits(5) + ' ' + randDigits(5);
        if (addrEl && (!addrEl.value || addrEl.value.trim() === '')) addrEl.value = 'Malpur Taluka, Aravalli, Gujarat, 383345, India';
      } catch (e) {}
    });
  })();
  </script>
</body>
</html>