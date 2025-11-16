<?php
require_once __DIR__ . '/../app.php';
require_admin();

// Handle actions
$message = '';
$errors = [];
$section = isset($_GET['section']) ? strtolower(trim((string)$_GET['section'])) : 'users';
if (!in_array($section, ['users','campaigns','rewards'], true)) { $section = 'users'; }
$awardUsers = [];

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'add_user') {
      $username = trim((string)($_POST['username'] ?? ''));
      $email = trim((string)($_POST['email'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
      if ($username === '' || $email === '' || $password === '') {
        $errors[] = 'Username, email, and password are required';
      } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, created_at, is_admin) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $email, $hash, $now, $isAdmin]);
        $message = 'User added: ' . htmlspecialchars($username);
      }
    } else if ($action === 'delete_user') {
      $uid = (int)($_POST['user_id'] ?? 0);
      if ($uid > 0) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        $message = 'Deleted user #' . $uid;
      }
    } else if ($action === 'bulk_delete_fake_users') {
      $st = $pdo->prepare('DELETE FROM users WHERE is_admin = 0 AND username LIKE ? AND email LIKE ?');
      $st->execute(['user%', '%@example.com']);
      $message = 'Removed ' . (int)$st->rowCount() . ' fake users.';
    } else if ($action === 'award_coins') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $amount = (int)($_POST['amount'] ?? 0);
      if ($uid > 0 && $amount > 0) {
        award_karma_coins($uid, $amount, 'admin_award', 'admin', (int)($_SESSION['user_id'] ?? 0));
        $message = 'Awarded ' . $amount . ' coins to user #' . $uid;
      }
    } else if ($action === 'search_award') {
      $q = trim((string)($_POST['user_query'] ?? ''));
      if ($q === '') {
        $errors[] = 'Enter a name or email to search';
      } else {
        $st = $pdo->prepare('SELECT id, username, email FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 20');
        $st->execute(['%' . $q . '%', '%' . $q . '%']);
        $awardUsers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($awardUsers)) { $message = 'No users found for query: ' . htmlspecialchars($q); }
      }
    } else if ($action === 'create_campaign') {
      $title = trim((string)($_POST['title'] ?? ''));
      $summary = trim((string)($_POST['summary'] ?? ''));
      $area = trim((string)($_POST['area'] ?? ''));
      $status = trim((string)($_POST['status'] ?? 'open'));
      if ($title === '' || $summary === '') {
        $errors[] = 'Title and summary are required';
      } else {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO campaigns (title, summary, area, status, created_at, user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$title, $summary, ($area !== '' ? $area : null), $status !== '' ? $status : 'open', $now, (int)($_SESSION['user_id'] ?? null)]);
        $message = 'Campaign created: ' . htmlspecialchars($title);
      }
    } else if ($action === 'update_campaign_status') {
      $cid = (int)($_POST['campaign_id'] ?? 0);
      $status = trim((string)($_POST['status'] ?? 'open'));
      if ($cid > 0) {
        $pdo->prepare('UPDATE campaigns SET status = ? WHERE id = ?')->execute([$status, $cid]);
        $message = 'Updated campaign #' . $cid . ' to ' . htmlspecialchars($status);
      }
  } else if ($action === 'delete_campaign') {
      $cid = (int)($_POST['campaign_id'] ?? 0);
      if ($cid > 0) {
        $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$cid]);
        $message = 'Deleted campaign #' . $cid;
      }
    } else if ($action === 'award_campaign_bonus') {
      $cid = (int)($_POST['campaign_id'] ?? 0);
      $amountReq = (int)($_POST['amount'] ?? 0);
      if ($cid > 0) {
        $st = $pdo->prepare('SELECT user_id, crowd_size FROM campaigns WHERE id = ?');
        $st->execute([$cid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $uid = (int)($row['user_id'] ?? 0);
        $crowd = (int)($row['crowd_size'] ?? 0);
        if ($uid > 0) {
          $amount = $amountReq > 0 ? $amountReq : max(1, intdiv($crowd > 0 ? $crowd : 100, 100));
          award_karma_coins($uid, $amount, 'campaign_bonus', 'campaign', $cid);
          $message = 'Awarded ' . $amount . ' Karma Coin(s) (Campaign Bonus) to user #' . $uid . ' for campaign #' . $cid . '.';
        } else {
          $errors[] = 'Campaign has no associated user; cannot award bonus.';
        }
      }
    } else if ($action === 'add_endorsements') {
      $cid = (int)($_POST['campaign_id'] ?? 0);
      $kind = strtolower(trim((string)($_POST['kind'] ?? 'campaign')));
      if ($kind !== 'campaign' && $kind !== 'contributor') { $kind = 'campaign'; }
      $count = (int)($_POST['count'] ?? 0);
      if ($cid > 0 && $count > 0) {
        $st = $pdo->prepare('SELECT contributor_name FROM campaigns WHERE id = ?');
        $st->execute([$cid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cname = (string)($row['contributor_name'] ?? '');
        $toInsert = min($count, 200);
        $now = gmdate('Y-m-d H:i:s');
        $ins = $pdo->prepare('INSERT INTO endorsements (campaign_id, kind, contributor_name, ip, user_agent, created_at, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < $toInsert; $i++) {
          $ins->execute([$cid, $kind, $cname, ($_SERVER['REMOTE_ADDR'] ?? null), ($_SERVER['HTTP_USER_AGENT'] ?? null), $now, null]);
        }
        $col = ($kind === 'contributor') ? 'endorse_contributor' : 'endorse_campaign';
        $upd = $pdo->prepare("UPDATE campaigns SET $col = COALESCE($col, 0) + ? WHERE id = ?");
        $upd->execute([$count, $cid]);
        $message = 'Added ' . $count . ' endorsement(s) (' . $kind . ') to campaign #' . $cid . '.';
      } else {
        $errors[] = 'Provide a valid campaign and positive endorsement count.';
      }
    } else if ($action === 'autogen_users') {
      $n = (int)($_POST['count'] ?? 0);
      if ($n > 0 && $n <= 500) {
        $now = gmdate('Y-m-d H:i:s');
        $ins = $pdo->prepare('INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < $n; $i++) {
          $name = 'user' . mt_rand(10000, 99999);
          $email = $name . '@example.com';
          $pass = password_hash('password', PASSWORD_DEFAULT);
          $ins->execute([$name, $email, $pass, $now]);
        }
        $message = 'Generated ' . $n . ' users.';
      } else {
        $errors[] = 'Count must be between 1 and 500';
      }
    } else if ($action === 'autogen_campaigns') {
      $n = (int)($_POST['count'] ?? 20);
      if ($n > 0 && $n <= 1000) {
        $areas = [
          'Mumbai','Delhi','Bengaluru','Hyderabad','Ahmedabad','Chennai','Kolkata','Pune','Jaipur','Surat',
          'Lucknow','Kanpur','Nagpur','Indore','Thane','Bhopal','Visakhapatnam','Patna','Vadodara','Ghaziabad',
          'Coimbatore','Kochi','Mysuru','Noida','Gurugram','Chandigarh','Madurai','Nashik','Rajkot','Vijayawada'
        ];
        $now = gmdate('Y-m-d H:i:s');
        $users = $pdo->query('SELECT id, username FROM users ORDER BY id DESC LIMIT ' . (int)max(1, $n))->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ins = $pdo->prepare('INSERT INTO campaigns (title, summary, area, status, created_at, user_id, crowd_size, contributor_name, closing_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < $n; $i++) {
          $area = $areas[$i % count($areas)];
          $crowd = 50 + ($i * 13) % 700; // 50–749
          $title = 'Meal Support in ' . $area;
          $summary = 'Coordinating safe access to surplus meals for local communities.';
          $uid = null; $cName = null;
          if (!empty($users)) { $u = $users[$i % count($users)]; $uid = (int)($u['id'] ?? null); $cName = (string)($u['username'] ?? ''); }
          $hour = 18 + ($i % 5); // 18:00–22:00
          $closing = (string)($hour < 10 ? ('0' . $hour) : (string)$hour) . ':00';
          $ins->execute([$title, $summary, $area, 'open', $now, $uid, $crowd, $cName, $closing]);
        }
        $message = 'Generated ' . $n . ' campaigns.';
      } else {
        $errors[] = 'Count must be between 1 and 1000';
      }
    }
  }
} catch (Throwable $e) {
  $errors[] = 'Error: ' . $e->getMessage();
}

// Fetch lists
$users = [];
$campaigns = [];
$wallets = [];
$limitRows = 15;
$usersFull = isset($_GET['users_full']);
$campaignsFull = isset($_GET['campaigns_full']);
$walletsFull = isset($_GET['wallets_full']);
$tablesFull = isset($_GET['tables_full']);
try {
  $users = $pdo->query('SELECT id, username, email, created_at, is_admin FROM users ORDER BY id DESC' . ($usersFull ? '' : ' LIMIT ' . (int)$limitRows))->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $campaigns = $pdo->query('SELECT id, title, status, area, created_at, user_id, crowd_size, endorse_campaign, contributor_name, location, latitude, longitude FROM campaigns ORDER BY id DESC' . ($campaignsFull ? '' : ' LIMIT ' . (int)$limitRows))->fetchAll(PDO::FETCH_ASSOC) ?: [];
  // Endorsements section: show only actual posts (eligible/open campaigns)
  $endorseableCampaigns = $pdo->query("SELECT id, title, area, endorse_campaign, contributor_name FROM campaigns\n    WHERE status = 'open'\n      AND ((location IS NOT NULL AND location <> '') OR (area IS NOT NULL AND area <> ''))\n    ORDER BY id DESC" . ($campaignsFull ? '' : ' LIMIT ' . (int)$limitRows))->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $wallets = $pdo->query('SELECT w.user_id, u.username, w.balance, w.updated_at FROM karma_wallets w JOIN users u ON u.id = w.user_id ORDER BY w.updated_at DESC' . ($walletsFull ? '' : ' LIMIT ' . (int)$limitRows))->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Database Tools · No Starve</title>
  <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
</head>
<body class="admin-scroll">
  <header class="site-header" role="banner">
    <div class="container header-inner">
      <a href="<?= h($BASE_PATH) ?>index.php#hero" class="brand" aria-label="No Starve home">No Starve</a>
      <?php $currentPath = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
      <nav id="primary-navigation" class="nav-links" role="navigation" aria-label="Primary">
        <a href="<?= h($BASE_PATH) ?>index.php#hero"<?= $currentPath === 'index.php' ? ' class="active"' : '' ?>>Home</a>
        <a href="<?= h($BASE_PATH) ?>create_campaign.php"<?= $currentPath === 'create_campaign.php' ? ' class="active"' : '' ?>>Create Campaign</a>
        <a href="<?= h($BASE_PATH) ?>profile.php"<?= $currentPath === 'profile.php' ? ' class="active"' : '' ?>>Profile</a>
        <a href="<?= h($BASE_PATH) ?>admin/index.php" class="active">Admin</a>
        <a href="<?= h($BASE_PATH) ?>logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="container">
    <h1>Database Tools</h1>
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a class="home" href="<?= h($BASE_PATH) ?>index.php#hero">Home</a>
      <span>›</span>
      <a class="admin" href="<?= h($BASE_PATH) ?>admin/index.php">Admin</a>
      <span>›</span>
      <a class="users" href="<?= h($BASE_PATH) ?>admin/index.php#users">Users</a>
      <a class="campaigns" href="<?= h($BASE_PATH) ?>admin/index.php#campaigns">Campaigns</a>
      <a class="endorsements" href="<?= h($BASE_PATH) ?>admin/index.php#endorsements">Endorsements</a>
      <a class="rewards" href="<?= h($BASE_PATH) ?>admin/index.php#rewards">Rewards</a>
    </nav>
    <div class="actions" style="margin: 8px 0 0;">
      <a class="btn btn-sm pill" href="<?= h($BASE_PATH) ?>admin/index.php#users">Users</a>
      <a class="btn btn-sm pill" href="<?= h($BASE_PATH) ?>admin/index.php#campaigns">Campaigns</a>
      <a class="btn btn-sm pill" href="<?= h($BASE_PATH) ?>admin/index.php#endorsements">Endorsements</a>
      <a class="btn btn-sm pill" href="<?= h($BASE_PATH) ?>admin/index.php#rewards">Rewards</a>
    </div>
    <div class="admin-grid stack-container">
    <?php if (!empty($errors)): ?>
      <div class="card-plain is-highlight" role="alert">
        <ul class="list-clean">
          <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($message): ?>
      <div class="alert success" role="status"><?= h($message) ?></div>
    <?php endif; ?>
    
    <section id="users" class="card-plain card-horizontal card-fullbleed stack-card" aria-label="Users">
      <h2 class="section-title">Users</h2>
      <div class="actions">
        <?php if ($usersFull): ?>
          <a class="btn btn-sm secondary" href="<?= h($BASE_PATH) ?>admin/index.php#users">Show 15</a>
        <?php else: ?>
          <a class="btn btn-sm secondary" href="<?= h($BASE_PATH) ?>admin/index.php?users_full=1#users">View more</a>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Remove non-admin users named like userXXXXX with example.com emails?');" style="display:inline-block; margin-left:8px;">
          <input type="hidden" name="action" value="bulk_delete_fake_users">
          <button type="submit" class="btn btn-sm pill">Remove fake users</button>
        </form>
      </div>

      <div class="card-plain">
        <strong>Users (latest)</strong>
        <table class="table" aria-label="Users table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Email</th>
              <th>Admin</th>
              <th>Map</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td>#<?= (int)$u['id'] ?></td>
              <td><?= h($u['username']) ?><?= ((int)($u['is_admin'] ?? 0) === 1 ? '<span class="star-admin" aria-label="Admin">★</span>' : '') ?></td>
              <td><?= h($u['email']) ?></td>
              <td><?= ((int)($u['is_admin'] ?? 0) === 1 ? 'yes' : 'no') ?></td>
              <td>
                <div class="actions">
                  <form method="post">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-sm pill">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="endorsements" class="card-plain card-horizontal card-fullbleed stack-card" aria-label="Endorsements">
      <h2 class="section-title">Add Endorsements</h2>
      <form method="post" class="form">
        <input type="hidden" name="action" value="add_endorsements">
        <label for="endorse-campaign"><strong>Campaign</strong></label>
        <select id="endorse-campaign" name="campaign_id" class="input" required style="display:inline-block; max-width: 480px;">
          <?php foreach ($endorseableCampaigns as $c): ?>
            <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> · <?= h($c['title']) ?><?= !empty($c['area']) ? (' · ' . h($c['area'])) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <label for="endorse-kind" style="margin-left:8px;"><strong>Type</strong></label>
        <select id="endorse-kind" name="kind" class="input" style="display:inline-block; width:auto;">
          <option value="campaign">campaign</option>
          <option value="contributor">contributor</option>
        </select>
        <label for="endorse-count" style="margin-left:8px;"><strong>Count</strong></label>
        <input id="endorse-count" name="count" type="number" class="input" placeholder="e.g., 10" required min="1" style="display:inline-block; width:120px;">
        <div class="actions" style="margin-top:8px;"><button type="submit" class="btn pill">Add Endorsements</button></div>
      </form>
      <div class="card-plain">
        <strong>Campaigns (current endorsements)</strong>
        <table class="table" aria-label="Campaign endorsements table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Title</th>
              <th>Area</th>
              <th>Endorsements</th>
              <th>Contributor</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($endorseableCampaigns as $c): ?>
            <tr>
              <td>#<?= (int)$c['id'] ?></td>
              <td><?= h($c['title']) ?></td>
              <td><?= h($c['area'] ?? '') ?></td>
              <td><?= (int)($c['endorse_campaign'] ?? 0) ?></td>
              <td><?= h(($c["contributor_name"] ?? '') !== '' ? (string)$c["contributor_name"] : '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="campaigns" class="card-plain card-horizontal card-fullbleed stack-card" aria-label="Campaigns">
      <h2 class="section-title">Campaigns</h2>
      <div class="actions">
        <?php if ($campaignsFull): ?>
          <a class="btn btn-sm secondary" href="<?= h($BASE_PATH) ?>admin/index.php">Show 15</a>
        <?php else: ?>
          <a class="btn btn-sm secondary" href="<?= h($BASE_PATH) ?>admin/index.php?campaigns_full=1">View full table</a>
        <?php endif; ?>
        <form method="post" style="display:inline-block; margin-left:8px;">
          <input type="hidden" name="action" value="autogen_campaigns">
          <input type="hidden" name="count" value="20">
          <button type="submit" class="btn btn-sm pill">Generate 20</button>
        </form>
      </div>
      <form method="post" class="form">
        <input type="hidden" name="action" value="create_campaign">
        <input name="title" type="text" class="input" placeholder="Title" required>
        <textarea name="summary" class="input" placeholder="Summary" required></textarea>
        <input name="area" type="text" class="input" placeholder="Area (optional)">
        <select name="status" class="input">
          <option value="open">open</option>
          <option value="draft">draft</option>
          <option value="closed">closed</option>
        </select>
        <div class="actions"><button type="submit" class="btn pill">Create Campaign</button></div>
      </form>
      <div class="card-plain">
        <strong>Campaigns (latest)</strong>
        <table class="table" aria-label="Campaigns table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Title</th>
              <th>Status</th>
              <th>Area</th>
              <th>Contributor</th>
              <th>Crowd</th>
              <th>Bonus</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($campaigns as $c): ?>
            <tr>
              <td>#<?= (int)$c['id'] ?></td>
              <td><?= h($c['title']) ?></td>
              <td><?= h($c['status']) ?></td>
              <td><?= h($c['area'] ?? '') ?></td>
              <td><?= h(($c['contributor_name'] ?? '') !== '' ? (string)$c['contributor_name'] : '—') ?></td>
              <td><?= (int)($c['crowd_size'] ?? 0) ?></td>
              <td>
                <?php $crowd = (int)($c['crowd_size'] ?? 0); $suggest = max(1, intdiv($crowd > 0 ? $crowd : 100, 100)); ?>
                <form method="post" style="display:inline-block;">
                  <input type="hidden" name="action" value="award_campaign_bonus">
                  <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                  <input name="amount" type="number" class="input" min="1" value="<?= (int)$suggest ?>" style="display:inline-block; width:110px;">
                  <button type="submit" class="btn btn-sm pill" title="Award bonus Karma Coin based on contribution">Award</button>
                </form>
              </td>
              <td>
                <?php
                  $lat = isset($c['latitude']) && $c['latitude'] !== '' ? (float)$c['latitude'] : null;
                  $lon = isset($c['longitude']) && $c['longitude'] !== '' ? (float)$c['longitude'] : null;
                  $q = trim((string)(($c['location'] ?? '') !== '' ? $c['location'] : ($c['area'] ?? '')));
                  $mapUrl = null;
                  if ($lat !== null && $lon !== null) {
                    $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lon);
                  } else if ($q !== '') {
                    $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
                  }
                ?>
                <?php if (!empty($mapUrl)): ?><a class="btn btn-sm pill" href="<?= h($mapUrl) ?>" target="_blank" rel="noopener">View Map</a><?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <div class="actions">
                  <form method="post">
                    <input type="hidden" name="action" value="update_campaign_status">
                    <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                    <select name="status" class="input" style="display:inline-block; width:auto;">
                      <option value="open">open</option>
                      <option value="draft">draft</option>
                      <option value="closed">closed</option>
                    </select>
                    <button type="submit" class="btn btn-sm pill">Update</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="delete_campaign">
                    <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn btn-sm pill">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    
    <section id="rewards" class="card-plain card-horizontal card-fullbleed stack-card" aria-label="Rewards">
      <h2 class="section-title">Rewards</h2>
      <form method="post" class="form">
        <input type="hidden" name="action" value="search_award">
        <input name="user_query" type="text" class="input" placeholder="Search by name or email" required>
        <div class="actions">
          <button type="submit" class="btn pill">Find User</button>
          <button type="submit" class="btn pill">Proceed</button>
        </div>
      </form>
      <?php if (!empty($awardUsers)): ?>
      <div class="card-plain">
        <strong>Search Results</strong>
        <table class="table" aria-label="Award search results">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Email</th>
              <th>Amount</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($awardUsers as $au): ?>
            <tr>
              <td>#<?= (int)$au['id'] ?></td>
              <td><?= h($au['username']) ?></td>
              <td><?= h($au['email']) ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="award_coins">
                  <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                  <input name="amount" type="number" class="input" placeholder="Amount" required min="1" style="display:inline-block; width:120px;">
                  <button type="submit" class="btn btn-sm pill">Award</button>
                </form>
              </td>
              <td></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <div class="card-plain">
        <strong>Wallets (latest)</strong>
        <table class="table" aria-label="Wallets table">
          <thead>
            <tr>
              <th>User ID</th>
              <th>Username</th>
              <th>Balance</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($wallets as $w): ?>
            <tr>
              <td>#<?= (int)$w['user_id'] ?></td>
              <td><?= h($w['username']) ?></td>
              <td><?= (int)$w['balance'] ?></td>
              <td><?= h($w['updated_at']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    </div>
  </main>

  <footer class="site-footer">
    <div class="container footer-inner">
      <small>&copy; 2025 No Starve</small>
    </div>
  </footer>
  <script>
    (function() {
      if (!window.location.hash) {
        window.scrollTo(0, 0);
      }
      var hash = (window.location.hash || '#users').toLowerCase();
      var sel = null;
      if (hash.indexOf('#campaigns') === 0) sel = document.querySelector('.breadcrumb .campaigns');
      else if (hash.indexOf('#endorsements') === 0) sel = document.querySelector('.breadcrumb .endorsements');
      else if (hash.indexOf('#rewards') === 0) sel = document.querySelector('.breadcrumb .rewards');
      else sel = document.querySelector('.breadcrumb .users');
      if (sel) { sel.classList.add('active'); sel.setAttribute('aria-current', 'page'); }
    })();
  </script>
  <script>
    (function(){
      var header = document.querySelector('.site-header');
      function onScroll(){
        if (!header) return;
        var y = window.scrollY || document.documentElement.scrollTop || 0;
        if (y > 10) header.classList.add('scrolled'); else header.classList.remove('scrolled');
      }
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
    })();
  </script>
</body>
</html>