<?php
require_once __DIR__ . '/app.php';

$name = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
if ($name === '') { header('Location: ' . $BASE_PATH . 'index.php'); exit; }

// Campaigns by contributor name
$campaigns = [];
try {
  $st = $pdo->prepare("SELECT id, title, area, location, crowd_size, closing_time, endorse_campaign, contributor_name, created_at, user_id FROM campaigns WHERE contributor_name = ? ORDER BY created_at DESC LIMIT 20");
  $st->execute([$name]);
  $campaigns = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Follower count for contributor_name
$followers = 0;
$isFollowing = false;
try {
  $st2 = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE contributor_name = ?');
  $st2->execute([$name]);
  $followers = (int)($st2->fetchColumn() ?: 0);
} catch (Throwable $e) {}
try {
  $st3 = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE follower_user_id = ? AND contributor_name = ?');
  $st3->execute([$_SESSION['user_id'] ?? 0, $name]);
  $isFollowing = ((int)($st3->fetchColumn() ?: 0)) > 0;
} catch (Throwable $e) {}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($name) ?> · Contributor</title>
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
    <section class="card-plain" aria-label="Contributor Profile">
      <h2 class="section-title"><?= h($name) ?></h2>
      <div style="margin-top:8px; display:flex; gap:8px; align-items:center;">
        <span class="chip">Followers: <?= (int)$followers ?></span>
        <?php if (is_logged_in()): ?>
          <button type="button" class="btn pill follow-toggle" data-contributor-name="<?= h($name) ?>"><?= $isFollowing ? 'Following' : 'Follow' ?></button>
        <?php endif; ?>
      </div>
    </section>

    <section class="card-plain" aria-label="Contributor Campaigns">
      <h2 class="section-title">Campaigns</h2>
      <?php if (!empty($campaigns)): ?>
        <div class="tweet-list">
          <?php foreach ($campaigns as $c): ?>
            <article class="tweet-card">
              <div class="tweet-avatar" aria-hidden="true"><span><?= h(strtoupper(substr((string)$name,0,1))) ?></span></div>
              <div class="tweet-content">
                <div class="tweet-header"><span class="tweet-name"><?= h($c['title'] ?? $name) ?></span></div>
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
      var cname = btn.getAttribute('data-contributor-name') || '';
      var body = 'mode=toggle&contributor_name=' + encodeURIComponent(cname);
      fetch('<?= h($BASE_PATH) ?>follow.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function(r){ return r.json(); })
        .then(function(j){ if (j && j.ok) { btn.textContent = j.following ? 'Following' : 'Follow'; } })
        .catch(function(){});
    });
  })();
  </script>
</body>
</html>