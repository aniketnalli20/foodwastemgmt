<?php
require_once __DIR__ . '/app.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: ' . $BASE_PATH . 'index.php'); exit; }

// Fetch user
try {
  $st = $pdo->prepare('SELECT id, username, email, created_at FROM users WHERE id = ?');
  $st->execute([$id]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) { header('Location: ' . $BASE_PATH . 'index.php'); exit; }
} catch (Throwable $e) { header('Location: ' . $BASE_PATH . 'index.php'); exit; }

// Campaigns by user
$campaigns = [];
try {
  $st2 = $pdo->prepare("SELECT id, title, area, location, crowd_size, closing_time, endorse_campaign, contributor_name, created_at FROM campaigns WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
  $st2->execute([$id]);
  $campaigns = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Follower count
$followers = 0;
$isFollowing = false;
$isVerified = false;
try {
  $stc = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE target_user_id = ?');
  $stc->execute([$id]);
  $followers = (int)($stc->fetchColumn() ?: 0);
} catch (Throwable $e) {}
// Consider a creator verified if contributors table has their username marked verified
try {
  $stV = $pdo->prepare('SELECT verified FROM contributors WHERE name = ?');
  $stV->execute([trim((string)($user['username'] ?? ''))]);
  $isVerified = ((int)($stV->fetchColumn() ?: 0)) === 1;
} catch (Throwable $e) {}
try {
  $st3 = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE follower_user_id = ? AND target_user_id = ?');
  $st3->execute([$_SESSION['user_id'] ?? 0, $id]);
  $isFollowing = ((int)($st3->fetchColumn() ?: 0)) > 0;
} catch (Throwable $e) {}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($user['username']) ?> · Profile</title>
  <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0" />
</head>
<body class="page-profile">
  <header class="site-header" role="banner">
    <div class="container header-inner">
      <?php $currentPath = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
      <nav class="navbar navbar-expand-lg navbar-light bg-light" role="navigation" aria-label="Primary">
        <a class="navbar-brand" href="<?= h($BASE_PATH) ?>index.php#hero">No Starve</a>
      </nav>
    </div>
  </header>

  <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);">
    <section class="card-plain" aria-label="User Profile">
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h2 class="section-title" style="margin:0; display:inline-flex; align-items:center; gap:6px;">
          <span><?= h($user['username']) ?></span>
          <?php if ($isVerified): ?><span class="material-symbols-outlined verified-badge" title="Verified" aria-label="Verified">verified</span><?php endif; ?>
        </h2>
        <?php if (is_logged_in() && (int)$_SESSION['user_id'] !== $id): ?>
          <button type="button" class="btn pill follow-toggle" data-target-user-id="<?= (int)$id ?>"><?= $isFollowing ? 'Following' : 'Follow' ?></button>
        <?php endif; ?>
        <?php if (is_logged_in() && (int)$_SESSION['user_id'] === $id): ?>
          <a class="btn pill" href="<?= h($BASE_PATH) ?>profile.php">Settings</a>
          <a class="btn pill" href="<?= h($BASE_PATH) ?>wallet.php">Wallet</a>
        <?php endif; ?>
      </div>
      <div class="muted">Joined <?= h(date('Y-m-d', strtotime($user['created_at']))) ?> · Followers: <?= (int)$followers ?></div>
    </section>

    <section class="card-plain" aria-label="User Campaigns">
      <h2 class="section-title">Campaigns</h2>
      <?php if (!empty($campaigns)): ?>
        <div class="tweet-list">
          <?php foreach ($campaigns as $c): ?>
            <article class="tweet-card">
              <div class="tweet-avatar" aria-hidden="true"><span><?= h(strtoupper(substr((string)($c['contributor_name'] ?? $user['username']),0,1))) ?></span></div>
              <div class="tweet-content">
                <div class="tweet-header"><span class="tweet-name"><?= h($c['title'] ?? $user['username']) ?></span></div>
                <div class="tweet-details">
                  <div class="detail"><span class="d-label">Location</span><span class="d-value"><?= h((string)($c['location'] ?? $c['area'] ?? '—')) ?></span></div>
                  <div class="detail"><span class="d-label">Crowd Size</span><span class="d-value"><?= isset($c['crowd_size']) ? (int)$c['crowd_size'] : '—' ?></span></div>
                  <div class="detail"><span class="d-label">Closing Time</span><span class="d-value"><?= h((string)($c['closing_time'] ?? '—')) ?></span></div>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="muted">No campaigns yet.</div>
      <?php endif; ?>
    </section>
  </main>

  <script>
  (function(){
    var btn = document.querySelector('.follow-toggle');
    if (!btn) return;
    btn.addEventListener('click', function(){
      var uid = parseInt(btn.getAttribute('data-target-user-id') || '0', 10);
      var body = 'mode=toggle&target_user_id=' + encodeURIComponent(uid);
      fetch('<?= h($BASE_PATH) ?>follow.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function(r){ return r.json(); })
        .then(function(j){ if (j && j.ok) { btn.textContent = j.following ? 'Following' : 'Follow'; } })
        .catch(function(){});
    });
  })();
  </script>
</body>
</html>