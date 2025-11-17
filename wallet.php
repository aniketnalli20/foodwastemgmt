<?php
require_once __DIR__ . '/app.php';
require_login();

$user = current_user();
if (!$user) { header('Location: ' . $BASE_PATH . 'login.php'); exit; }

$msg = '';
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
      </nav>
    </div>
  </header>

  <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);">
    <section class="card-plain" aria-label="Wallet Balance">
      <h2 class="section-title">Wallet</h2>
      <div class="stat" style="display:flex; gap:12px; align-items:center;">
        <span class="stat-label">Balance</span>
        <span class="stat-num"><?= (int)$balance ?></span>
      </div>
      <form method="post" action="<?= h($BASE_PATH) ?>wallet.php" style="margin-top:10px;">
        <input type="hidden" name="action" value="convert">
        <button type="submit" class="btn pill">Convert karma to wallet</button>
      </form>
      <?php if ($msg !== ''): ?><div class="muted" style="margin-top:6px;"><?= h($msg) ?></div><?php endif; ?>
    </section>

    <section class="card-plain" aria-label="Wallet History">
      <h2 class="section-title">History</h2>
      <?php if (!empty($events)): ?>
        <div class="table" role="table" aria-label="Wallet events">
          <?php foreach ($events as $ev): ?>
            <div class="table-row" role="row" style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border);">
              <div><?= h(date('Y-m-d H:i', strtotime($ev['created_at']))) ?></div>
              <div><?= h((string)($ev['reason'] ?? '')) ?></div>
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