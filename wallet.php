<?php
require_once __DIR__ . '/app.php';
require_wallet_access_or_redirect();

$user = current_user();
if (!$user) { header('Location: ' . $BASE_PATH . 'login.php'); exit; }

$msg = '';
$convFailed = false;
$redeemMsg = '';
$redeemFailed = false;
// Conversion: align wallet with endorsements-based expected earnings
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert') {
    try {
        // Expected coin earnings based on endorsements on user's campaigns, 1 coin per 100 endorsements
        $st = $pdo->prepare('SELECT COALESCE(SUM(endorse_campaign), 0) FROM campaigns WHERE user_id = ?');
        $st->execute([(int)$user['id']]);
        $endorseTotal = (int)($st->fetchColumn() ?: 0);
        $expectedCoins = (int)floor($endorseTotal / 100);
        $currentBalance = get_karma_balance((int)$user['id']);
        $delta = $expectedCoins - $currentBalance;
        if ($delta > 0) {
            award_karma_coins((int)$user['id'], $delta, 'conversion', 'user', (int)$user['id']);
            $msg = 'Converted ' . $delta . ' coins to wallet';
        } else {
            $msg = 'No conversion needed';
        }
    } catch (Throwable $e) {
        $msg = 'Conversion failed';
        $convFailed = true;
    }
}

// Redeem coins to paisa (requires 10 lakh coins)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'redeem') {
    try {
        $res = redeem_karma_to_cash((int)$user['id']);
        if ($res['ok']) {
            $redeemMsg = 'Redeemed ' . (int)$res['coins'] . ' coins → ₹' . number_format(((int)$res['paisa']) / 100, 2);
            $redeemFailed = false;
        } else if ($res['error'] === 'threshold') {
            $redeemMsg = 'Redemption allowed only at 10,00,000 Karma Coins';
            $redeemFailed = true;
        } else {
            $redeemMsg = 'Redemption failed';
            $redeemFailed = true;
        }
    } catch (Throwable $e) {
        $redeemMsg = 'Redemption failed';
        $redeemFailed = true;
    }
}

$balance = get_karma_balance((int)$user['id']);

// Load events
$events = [];
try {
    $st = $pdo->prepare('SELECT amount, reason, ref_type, ref_id, created_at FROM karma_events WHERE user_id = ? ORDER BY created_at DESC LIMIT 200');
    $st->execute([(int)$user['id']]);
    $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wallet · No Starve</title>
  <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0" />
</head>
<body class="page-wallet">
  <header class="site-header" role="banner">
    <div class="container header-inner">
      <nav class="navbar navbar-expand-lg navbar-light bg-light" role="navigation" aria-label="Primary">
        <a class="navbar-brand" href="<?= h($BASE_PATH) ?>index.php#hero">No Starve</a>
        <div class="collapse navbar-collapse" id="primary-navbar">
          <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>index.php#hero">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>">Profile</a></li>
            <li class="nav-item"><a class="nav-link active" href="<?= h($BASE_PATH) ?>wallet.php">Wallet</a></li>
            <li class="nav-item"><a class="nav-link" href="<?= h(is_logged_in() ? ($BASE_PATH . 'kyc.php') : ($BASE_PATH . 'login.php?next=kyc.php')) ?>">KYC</a></li>
            <?php if (is_admin()): ?>
              <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>admin/index.php">Admin Tools</a></li>
            <?php endif; ?>
          </ul>
        </div>
      </nav>
    </div>
  </header>

  <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);">
    <section class="card-plain" aria-label="Wallet Balance">
      <h2 class="section-title">Wallet</h2>
      <div class="stat" style="display:flex; gap:12px; align-items:center;">
        <span class="stat-label"><span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:-4px;">savings</span> Balance</span>
        <span class="stat-num"><?= (int)$balance ?></span>
      </div>
      <form method="post" action="<?= h($BASE_PATH) ?>wallet.php" style="margin-top:10px;">
        <input type="hidden" name="action" value="convert">
        <button type="submit" class="btn pill">Convert endorsements → coins</button>
      </form>
      <?php if ($msg !== ''): ?>
        <?php if ($convFailed): ?><div class="alert error error-wobble" role="alert" style="margin-top:6px;"><?= h($msg) ?></div>
        <?php else: ?><div class="alert success" role="status" style="margin-top:6px;"><?= h($msg) ?></div><?php endif; ?>
      <?php endif; ?>
      <div class="card-plain" style="margin-top:10px;">
        <strong><span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:-4px;">currency_rupee</span> Currency Conversion</strong>
        <div class="muted" style="margin-top:6px;">Conversion: 100 Karma Coins = ₹0.01. Redemption is allowed only at 1,000,000 Karma Coins.</div>
        <form method="post" action="<?= h($BASE_PATH) ?>wallet.php" style="margin-top:10px;">
          <input type="hidden" name="action" value="redeem">
          <button type="submit" class="btn pill">Redeem</button>
        </form>
        <?php if ($redeemMsg !== ''): ?>
          <?php if ($redeemFailed): ?><div class="alert error error-wobble" role="alert" style="margin-top:6px;"><?= h($redeemMsg) ?></div>
          <?php else: ?><div class="alert success" role="status" style="margin-top:6px;"><?= h($redeemMsg) ?></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="card-plain" aria-label="Wallet History">
      <h2 class="section-title">History</h2>
      <?php if (!empty($events)): ?>
        <div class="table" role="table" aria-label="Wallet events">
          <?php foreach ($events as $ev): ?>
            <div class="table-row" role="row" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border);">
              <div><?= h(date('Y-m-d H:i', strtotime($ev['created_at']))) ?></div>
              <div>
                <?php if ((string)($ev['ref_type'] ?? '') === 'redeem'): ?>
                  <span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:-4px;">currency_rupee</span>
                <?php endif; ?>
                <?= h((string)($ev['reason'] ?? '')) ?>
              </div>
              <div><?= (int)$ev['amount'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="muted">No events yet.</div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>