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
$followersOverride = null;
$adminMsg = '';
try {
  $stc = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE target_user_id = ?');
  $stc->execute([$id]);
  $followers = (int)($stc->fetchColumn() ?: 0);
} catch (Throwable $e) {}
try {
  $stOv = $pdo->prepare('SELECT followers_override FROM users WHERE id = ?');
  $stOv->execute([$id]);
  $ov = $stOv->fetchColumn();
  if ($ov !== false && $ov !== null) { $followersOverride = (int)$ov; }
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

// Admin profile preview controls
if (is_admin() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'admin_profile_preview') {
  $followersNew = isset($_POST['followers']) ? (int)$_POST['followers'] : 0;
  $verifiedNew = isset($_POST['verified']) ? 1 : 0;
  $joinedNew = trim((string)($_POST['joined'] ?? ''));
  try {
    if ($joinedNew !== '') {
      $pdo->prepare('UPDATE users SET created_at = ? WHERE id = ?')->execute([$joinedNew . ' 00:00:00', $id]);
      $user['created_at'] = $joinedNew . ' 00:00:00';
    }
    $pdo->prepare('UPDATE users SET followers_override = ? WHERE id = ?')->execute([$followersNew, $id]);
    $followersOverride = $followersNew;
    set_contributor_verified((string)$user['username'], (int)$verifiedNew);
    $isVerified = ($verifiedNew === 1);
    $adminMsg = 'Profile preview updated';
  } catch (Throwable $e) {}
}

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
        <button class="navbar-toggler" type="button" aria-controls="primary-navbar" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon" aria-hidden="true"></span>
        </button>
        <div class="collapse navbar-collapse" id="primary-navbar">
          <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link<?= $currentPath === 'index.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>index.php#hero">Home</a></li>
            <li class="nav-item"><a class="nav-link<?= $currentPath === 'profile.php' ? ' active' : '' ?>" href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>">Profile</a></li>
            <li class="nav-item"><a class="nav-link<?= $currentPath === 'wallet.php' ? ' active' : '' ?>" href="<?= h(is_logged_in() ? ($BASE_PATH . 'wallet.php') : ($BASE_PATH . 'login.php?next=wallet.php')) ?>">Wallet</a></li>
            <li class="nav-item"><a class="nav-link<?= $currentPath === 'kyc.php' ? ' active' : '' ?>" href="<?= h(is_logged_in() ? ($BASE_PATH . 'kyc.php') : ($BASE_PATH . 'login.php?next=kyc.php')) ?>">KYC</a></li>
            <li class="nav-item"><a class="nav-link<?= $currentPath === 'create_campaign.php' ? ' active' : '' ?>" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Create Campaign</a></li>
            <?php if (is_admin()): ?>
              <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>admin/index.php">Admin Tools</a></li>
            <?php endif; ?>
            <?php if (is_logged_in()): ?>
              <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>logout.php">Logout</a></li>
            <?php else: ?>
              <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>login.php">Login</a></li>
            <?php endif; ?>
          </ul>
        </div>
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
          <?php $hasApproved = has_approved_kyc((int)$id); ?>
          <?php if ($hasApproved): ?>
            <a class="btn pill" href="<?= h($BASE_PATH) ?>wallet.php">Wallet</a>
          <?php else: ?>
            <a class="btn pill" href="<?= h($BASE_PATH) ?>kyc.php">KYC</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php $followersEff = (int)($followersOverride !== null ? $followersOverride : $followers); ?>
      <div class="muted">Joined <?= h(date('Y-m-d', strtotime($user['created_at']))) ?> · Followers: <?= h(format_compact_number($followersEff)) ?></div>
    </section>

    <?php if (is_admin()): ?>
    <section class="card-plain ig-card" aria-label="Admin Profile Preview" style="margin-top:10px;">
      <div class="ig-header">
        <div class="ig-avatar" aria-hidden="true"><span><?= h(strtoupper(substr((string)($user['username'] ?? 'U'),0,1))) ?></span></div>
        <div class="ig-meta">
          <div class="ig-name">
            <span class="name-text"><?= h($user['username']) ?></span>
            <?php if ($isVerified): ?><span class="material-symbols-outlined verified-badge" title="Verified" aria-label="Verified">verified</span><?php endif; ?>
            <button type="button" class="btn pill" style="margin-left:8px;"><span class="material-symbols-outlined icon">person_add</span> Follow</button>
          </div>
          <div class="ig-sub muted">Joined <?= h(date('Y-m-d', strtotime($user['created_at']))) ?> · Followers: <?= (int)($followersOverride !== null ? $followersOverride : $followers) ?></div>
        </div>
      </div>
      <form method="post" class="form" style="margin-top:10px;">
        <input type="hidden" name="action" value="admin_profile_preview">
        <label><strong>Followers</strong></label>
        <input name="followers" type="number" class="input" min="0" value="<?= (int)($followersOverride !== null ? $followersOverride : $followers) ?>" style="width:140px; display:inline-block;">
        <label style="margin-left:8px; display:inline-flex; align-items:center; gap:6px;"><input type="checkbox" name="verified" value="1" <?= $isVerified ? 'checked' : '' ?>> Verified</label>
        <label style="margin-left:8px;"><strong>Joined (YYYY-MM-DD)</strong></label>
        <input name="joined" type="text" class="input" value="<?= h(date('Y-m-d', strtotime($user['created_at']))) ?>" style="width:160px; display:inline-block;">
        <div class="actions" style="margin-top:8px;"><button type="submit" class="btn pill">Save</button></div>
      </form>
      <?php if ($adminMsg !== ''): ?><div class="muted" style="margin-top:6px;"><?= h($adminMsg) ?></div><?php endif; ?>
    </section>
    <?php endif; ?>

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
    var header = document.querySelector('.site-header');
    var lastY = window.scrollY || document.documentElement.scrollTop || 0;
    function onScroll(){
      if (!header) return;
      var y = window.scrollY || document.documentElement.scrollTop || 0;
      if (y > 10) header.classList.add('scrolled'); else header.classList.remove('scrolled');
      if (y < lastY) {
        header.classList.add('slim');
        var coll = document.getElementById('primary-navbar');
        if (coll) coll.classList.remove('show');
        var btn = document.querySelector('.navbar-toggler');
        if (btn) btn.setAttribute('aria-expanded','false');
      } else {
        header.classList.remove('slim');
      }
      lastY = y;
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  })();
  </script>
  <script>
  (function(){
    var btn = document.querySelector('.navbar-toggler');
    var coll = document.getElementById('primary-navbar');
    if (btn && coll) {
      btn.addEventListener('click', function(){
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', (!expanded).toString());
        coll.classList.toggle('show');
      });
    }
  })();
  </script>

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
