<?php
require_once __DIR__ . '/../app.php';
require_admin();

// Handle actions
$message = '';
$errors = [];
$section = isset($_GET['section']) ? strtolower(trim((string)$_GET['section'])) : 'users';
if (!in_array($section, ['users','campaigns','rewards','contributors','kyc'], true)) { $section = 'users'; }
$awardUsers = [];
$previewUser = null;

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
    } else if ($action === 'export_users') {
      try {
        $uploadsDir = __DIR__ . '/../uploads';
        if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0777, true); }
        $path = $uploadsDir . '/users_export.txt';
        $stmt = $pdo->query('SELECT id, username, email, phone, address, is_admin, created_at FROM users ORDER BY id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        $out[] = 'No Starve Users Export (' . gmdate('c') . ')';
        foreach ($rows as $u) {
          $line = 'id=' . (int)$u['id']
            . ', username=' . (string)$u['username']
            . ', email=' . (string)$u['email']
            . ', phone=' . (string)($u['phone'] ?? '')
            . ', address=' . (string)($u['address'] ?? '')
            . ', admin=' . (((int)($u['is_admin'] ?? 0) === 1) ? 'yes' : 'no')
            . ', created_at=' . (string)$u['created_at'];
          $out[] = $line;
        }
        file_put_contents($path, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
        $message = 'Exported ' . count($rows) . ' users to uploads/users_export.txt';
      } catch (Throwable $e) {
        $errors[] = 'Export failed: ' . $e->getMessage();
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
    } else if ($action === 'set_contributor_verified') {
      $name = trim((string)($_POST['name'] ?? ''));
      $verified = isset($_POST['verified']) ? 1 : 0;
      if ($name === '') { $errors[] = 'Contributor name is required'; }
      else {
        $now = gmdate('Y-m-d H:i:s');
        try {
          if ($DB_DRIVER === 'pgsql') {
            $pdo->prepare('INSERT INTO contributors (name, verified, created_at, updated_at) VALUES (?, ?, ?, ?)
                           ON CONFLICT (name) DO UPDATE SET verified = EXCLUDED.verified, updated_at = EXCLUDED.updated_at')
                ->execute([$name, $verified, $now, $now]);
          } else {
            $pdo->prepare('INSERT INTO contributors (name, verified, created_at, updated_at) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE verified = VALUES(verified), updated_at = VALUES(updated_at)')
                ->execute([$name, $verified, $now, $now]);
          }
          $message = 'Contributor ' . htmlspecialchars($name) . ' set to ' . ($verified ? 'verified' : 'unverified');
        } catch (Throwable $e) { $errors[] = 'Failed to set contributor: ' . $e->getMessage(); }
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
  $contributorsList = [];
  try {
    $sql = "SELECT c.contributor_name AS name, COALESCE(cc.verified, 0) AS verified\n            FROM (SELECT DISTINCT contributor_name FROM campaigns WHERE contributor_name IS NOT NULL AND contributor_name <> '') c\n            LEFT JOIN contributors cc ON cc.name = c.contributor_name\n            ORDER BY c.contributor_name ASC";
    $contributorsList = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
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
<body class="admin-scroll page-admin">
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
            <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>index.php#hero">Home</a></li>
            <li class="nav-item"><a class="nav-link<?= $currentPath === 'profile.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>profile.php">Profile</a></li>
            <li class="nav-item"><a class="nav-link<?= $currentPath === 'create_campaign.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>create_campaign.php">Create Campaign</a></li>
            <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>logout.php">Logout</a></li>
          </ul>
        </div>
      </nav>
    </div>
  </header>

  <main class="container">
    
    <div class="admin-layout">
      <?php
        // Sidebar counts (lightweight queries for quick badges)
        $usersCountSidebar = 0; $campaignsCountSidebar = 0; $kycCountSidebar = 0;
        try { $usersCountSidebar = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(); } catch (Throwable $e) {}
        try { $campaignsCountSidebar = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn(); } catch (Throwable $e) {}
        try { $kycCountSidebar = (int)$pdo->query('SELECT COUNT(*) FROM kyc_requests')->fetchColumn(); } catch (Throwable $e) {}
      ?>
      <aside class="admin-sidebar" aria-label="Admin Navigation">
        <div class="sidebar-group">
          <div class="sidebar-title">Sections</div>
          <a href="#dashboard" class="side-link">Dashboard</a>
          <a href="#users" class="side-link">Users <span class="side-count"><?= (int)$usersCountSidebar ?></span></a>
          <a href="#campaigns" class="side-link">Campaigns <span class="side-count"><?= (int)$campaignsCountSidebar ?></span></a>
          <a href="#endorsements" class="side-link">Endorsements</a>
          <a href="#rewards" class="side-link">Rewards</a>
          <a href="#contributors" class="side-link">Contributors</a>
          <a href="#kyc" class="side-link">KYC <span class="side-count"><?= (int)$kycCountSidebar ?></span></a>
        </div>
        <div class="sidebar-group" style="margin-top:10px;">
          <a href="#dbtools" class="side-link">Database Tools</a>
        </div>
      </aside>
      <section class="admin-main">
    <?php
      // Chart filters via query params
      $start = isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['start']) ? (string)$_GET['start'] : '';
      $end = isset($_GET['end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['end']) ? (string)$_GET['end'] : '';
      $areaFilter = trim((string)($_GET['area'] ?? ''));
      $startDT = $start !== '' ? ($start . ' 00:00:00') : null;
      $endDT = $end !== '' ? ($end . ' 23:59:59') : null;
      // Dashboard aggregates
      $totalUsers = 0; $totalCampaigns = 0; $openCampaigns = 0; $closedCampaigns = 0; $endorseTotal = 0; $kycApproved = 0; $kycPending = 0; $walletsTotal = 0;
      try {
        $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
      } catch (Throwable $e) {}
      try {
        $totalCampaigns = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
        $openCampaigns = (int)$pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'open'")->fetchColumn();
        $closedCampaigns = (int)$pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'closed'")->fetchColumn();
        $endorseTotal = (int)($pdo->query('SELECT SUM(COALESCE(endorse_campaign,0)) FROM campaigns')->fetchColumn() ?: 0);
      } catch (Throwable $e) {}
      try {
        $kycApproved = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests WHERE status = 'approved'")->fetchColumn();
        $kycPending = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests WHERE status = 'pending'")->fetchColumn();
      } catch (Throwable $e) {}
      try {
        $walletsTotal = (int)$pdo->query('SELECT COUNT(*) FROM karma_wallets')->fetchColumn();
      } catch (Throwable $e) {}
      // Bar: endorsements by area (top 5) with optional filters
      $endorseByArea = [];
      try {
        $where = ["area IS NOT NULL AND area <> ''"];
        $binds = [];
        if ($areaFilter !== '') { $where[] = 'area LIKE ?'; $binds[] = '%' . $areaFilter . '%'; }
        if ($startDT !== null) { $where[] = 'created_at >= ?'; $binds[] = $startDT; }
        if ($endDT !== null) { $where[] = 'created_at <= ?'; $binds[] = $endDT; }
        $sqlArea = 'SELECT area, SUM(COALESCE(endorse_campaign,0)) AS total FROM campaigns WHERE ' . implode(' AND ', $where) . ' GROUP BY area ORDER BY total DESC LIMIT 5';
        $stArea = $pdo->prepare($sqlArea);
        $stArea->execute($binds);
        $endorseByArea = $stArea->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) {}
      // Donut: KYC completion distribution
      $kycRejected = 0;
      try {
        $whereK = [];
        $bindK = [];
        if ($startDT !== null) { $whereK[] = 'created_at >= ?'; $bindK[] = $startDT; }
        if ($endDT !== null) { $whereK[] = 'created_at <= ?'; $bindK[] = $endDT; }
        $cond = empty($whereK) ? '' : (' WHERE ' . implode(' AND ', $whereK));
        $whereStatus = empty($cond) ? ' WHERE ' : ' AND ';
        $stA = $pdo->prepare("SELECT COUNT(*) FROM kyc_requests" . $cond . $whereStatus . "status = 'approved'");
        $stP = $pdo->prepare("SELECT COUNT(*) FROM kyc_requests" . $cond . $whereStatus . "status = 'pending'");
        $stR = $pdo->prepare("SELECT COUNT(*) FROM kyc_requests" . $cond . $whereStatus . "status = 'rejected'");
        $stA->execute($bindK); $kycApproved = (int)$stA->fetchColumn();
        $stP->execute($bindK); $kycPending = (int)$stP->fetchColumn();
        $stR->execute($bindK); $kycRejected = (int)$stR->fetchColumn();
      } catch (Throwable $e) { try { $kycRejected = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests WHERE status = 'rejected'")->fetchColumn(); } catch (Throwable $e2) {} }
      $kycTotal = max(1, $kycApproved + $kycPending + $kycRejected);
      $pApproved = ($kycApproved / $kycTotal);
      $pPending = ($kycPending / $kycTotal);
      $pRejected = ($kycRejected / $kycTotal);
      // Donut SVG arc helpers
      function arcLen($pct, $radius = 50) { return 2 * pi() * $radius * $pct; }
      $circ = 2 * pi() * 50; // r=50
      $lenApproved = arcLen($pApproved);
      $lenPending = arcLen($pPending);
      $lenRejected = arcLen($pRejected);
    ?>
    <section id="dashboard" class="card-plain card-horizontal card-fullbleed" aria-label="Dashboard">
      <h2 class="section-title">Dashboard</h2>
      <div class="dash-tabs" role="tablist">
        <a href="#dashboard" class="tab-btn active" data-tab="overview">Overview</a>
        <a href="#dashboard" class="tab-btn" data-tab="calendar">Calendar</a>
        <a href="#dashboard" class="tab-btn" data-tab="tasks">Tasks</a>
        <a href="#dashboard" class="tab-btn" data-tab="activity">Activity</a>
      </div>
      <div class="tab-pane active" id="tab-overview">
        <div class="dash-cards">
          <div class="metric-card"><div class="metric-value"><?= (int)$totalUsers ?></div><div class="metric-label">Total Users</div></div>
          <div class="metric-card"><div class="metric-value"><?= (int)$totalCampaigns ?></div><div class="metric-label">Campaigns</div></div>
          <div class="metric-card"><div class="metric-value"><?= (int)$openCampaigns ?></div><div class="metric-label">Open Campaigns</div></div>
          <div class="metric-card"><div class="metric-value"><?= (int)$closedCampaigns ?></div><div class="metric-label">Closed Campaigns</div></div>
          <div class="metric-card"><div class="metric-value"><?= (int)$kycApproved ?></div><div class="metric-label">KYC Approved</div></div>
          <div class="metric-card"><div class="metric-value"><?= (int)$kycPending ?></div><div class="metric-label">KYC Pending</div></div>
          <div class="metric-card"><div class="metric-value"><?= (int)$walletsTotal ?></div><div class="metric-label">Wallets</div></div>
          <div class="metric-card"><div class="metric-value"><?= (int)$endorseTotal ?></div><div class="metric-label">Endorsements</div></div>
        </div>
        <div class="chart-card" style="margin-top:12px;">
          <div class="section-title" style="margin:0 0 8px;">Engagement</div>
          <div class="chart" style="height:160px; border:1px dashed var(--border); border-radius:10px; display:flex; align-items:center; justify-content:center; color:var(--muted);">Line chart coming soon</div>
        </div>
      </div>
      <div class="tab-pane" id="tab-calendar">
        <div class="card-plain" style="margin-top:6px;">
          <div class="muted">Calendar view coming soon</div>
        </div>
      </div>
      <div class="tab-pane" id="tab-tasks">
        <div class="card-plain" style="margin-top:6px;">
          <div class="muted">Tasks view coming soon</div>
        </div>
      </div>
      <div class="tab-pane" id="tab-activity">
        <div class="card-plain" style="margin-top:6px;">
          <div class="muted">Activity view coming soon</div>
        </div>
      </div>
    </section>
    <h2 class="section-title" id="dbtools" style="margin-left: var(--content-pad);">Database Tools</h2>
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
        <form method="post" style="display:inline-block; margin-left:8px;">
          <input type="hidden" name="action" value="export_users">
          <button type="submit" class="btn btn-sm pill">Export to text</button>
          <a href="<?= h($BASE_PATH) ?>uploads/users_export.txt" class="btn btn-sm pill" style="margin-left:6px;">Download</a>
        </form>
      </div>

      <div class="card-plain">
        <strong>Users (latest)</strong>
        <div class="table-wrap">
        <table class="table" aria-label="Users table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Email</th>
              <th>Admin</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td>#<?= (int)$u['id'] ?></td>
              <td><?= h($u['username']) ?><?= ((int)($u['is_admin'] ?? 0) === 1 ? '<span class="chip status" aria-label="Admin">admin</span>' : '') ?></td>
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
        <div class="table-wrap">
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
        <div class="table-wrap">
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
        <div class="table-wrap">
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
      </div>
      <?php endif; ?>
      <div class="card-plain">
        <strong>Wallets (latest)</strong>
        <div class="table-wrap">
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
      </div>
    </section>

    <section id="contributors" class="card-plain card-horizontal card-fullbleed stack-card" aria-label="Contributors">
      <h2 class="section-title">Contributors</h2>
      <div class="card-plain">
        <form method="post" class="form" style="margin-bottom:10px;">
          <input type="hidden" name="action" value="set_contributor_verified">
          <input name="name" type="text" class="input" placeholder="Contributor name" required style="max-width:320px;">
          <label style="margin-left:8px; display:inline-flex; align-items:center; gap:6px;"><input type="checkbox" name="verified" value="1"> Verified</label>
          <div class="actions" style="margin-top:8px;"><button type="submit" class="btn pill">Save</button></div>
        </form>
      </div>
      <div class="card-plain">
        <strong>Known Contributors</strong>
        <div class="table-wrap">
        <table class="table" aria-label="Contributors table">
          <thead>
            <tr><th>Name</th><th>Verified</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($contributorsList as $c): ?>
              <tr>
                <td><?= h($c['name']) ?></td>
                <td><?= ((int)$c['verified'] === 1 ? 'yes' : 'no') ?></td>
                <td>
                  <form method="post" style="display:inline-block;">
                    <input type="hidden" name="action" value="set_contributor_verified">
                    <input type="hidden" name="name" value="<?= h($c['name']) ?>">
                    <input type="hidden" name="verified" value="<?= ((int)$c['verified'] === 1 ? '0' : '1') ?>">
                    <button type="submit" class="btn btn-sm pill"><?= ((int)$c['verified'] === 1 ? 'Unverify' : 'Verify') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </section>
    <section id="kyc" class="card-plain card-horizontal card-fullbleed stack-card" aria-label="KYC">
      <h2 class="section-title">KYC</h2>
      <?php
        $kycList = [];
        $kycTotalRows = 0;
        $kycPages = 1;
        $kycPage = max(1, (int)($_GET['kyc_page'] ?? 1));
        $kycPerPage = 20;
        $kycStatus = strtolower(trim((string)($_GET['kyc_status'] ?? '')));
        if (!in_array($kycStatus, ['pending','approved','rejected'], true)) { $kycStatus = ''; }
        $kycStart = isset($_GET['kyc_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['kyc_start']) ? (string)$_GET['kyc_start'] : '';
        $kycEnd = isset($_GET['kyc_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['kyc_end']) ? (string)$_GET['kyc_end'] : '';
        $kycSort = strtolower(trim((string)($_GET['kyc_sort'] ?? 'created_at')));
        if (!in_array($kycSort, ['id','status','created_at'], true)) { $kycSort = 'created_at'; }
        $kycDir = strtolower(trim((string)($_GET['kyc_dir'] ?? 'desc')));
        $kycDir = ($kycDir === 'asc') ? 'asc' : 'desc';
        try {
          $where = [];
          $binds = [];
          if ($kycStatus !== '') { $where[] = 'k.status = ?'; $binds[] = $kycStatus; }
          if ($kycStart !== '') { $where[] = 'k.created_at >= ?'; $binds[] = $kycStart . ' 00:00:00'; }
          if ($kycEnd !== '') { $where[] = 'k.created_at <= ?'; $binds[] = $kycEnd . ' 23:59:59'; }
          $cond = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));
          $stCount = $pdo->prepare('SELECT COUNT(*) FROM kyc_requests k' . $cond);
          $stCount->execute($binds);
          $kycTotalRows = (int)($stCount->fetchColumn() ?: 0);
          $kycPages = max(1, (int)ceil($kycTotalRows / $kycPerPage));
          $kycPage = min(max(1, $kycPage), $kycPages);
          $offset = ($kycPage - 1) * $kycPerPage;
          $sql = 'SELECT k.id, k.user_id, u.username, u.email, k.full_name, k.phone, k.bank_name, k.bank_account_number, k.ifsc, k.id_number, k.status, k.created_at
                  FROM kyc_requests k LEFT JOIN users u ON u.id = k.user_id' . $cond . ' ORDER BY k.' . $kycSort . ' ' . strtoupper($kycDir) . ' LIMIT ' . (int)$kycPerPage . ' OFFSET ' . (int)$offset;
          $st = $pdo->prepare($sql);
          $st->execute($binds);
          $kycList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'set_kyc_status') {
          $kid = isset($_POST['kyc_id']) ? (int)$_POST['kyc_id'] : 0;
          $stt = trim((string)($_POST['status'] ?? 'pending'));
          $note = trim((string)($_POST['note'] ?? ''));
          if ($kid > 0 && in_array($stt, ['pending','approved','rejected'], true)) {
            try {
              $pdo->prepare('UPDATE kyc_requests SET status = ?, notes = ?, updated_at = ? WHERE id = ?')
                  ->execute([$stt, ($note !== '' ? $note : null), gmdate('Y-m-d H:i:s'), $kid]);
              $message = 'KYC #' . $kid . ' updated';
            } catch (Throwable $e) { $errors[] = 'Failed to update KYC'; }
          }
        }
      ?>
      <?php
        $qs = ['view' => 'tools'];
        if ($kycStatus !== '') $qs['kyc_status'] = $kycStatus;
        if ($kycStart !== '') $qs['kyc_start'] = $kycStart;
        if ($kycEnd !== '') $qs['kyc_end'] = $kycEnd;
        if ($kycSort !== '') $qs['kyc_sort'] = $kycSort;
        if ($kycDir !== '') $qs['kyc_dir'] = $kycDir;
        $baseQS = http_build_query($qs);
        $prevQS = $baseQS . '&kyc_page=' . max(1, $kycPage - 1);
        $nextQS = $baseQS . '&kyc_page=' . min($kycPages, $kycPage + 1);
      ?>
      <div class="actions" style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
        <form method="get" action="<?= h($BASE_PATH) ?>admin/index.php#kyc" style="display:flex; gap:6px; align-items:center;">
          <input type="hidden" name="view" value="tools">
          <select name="kyc_status" class="input" style="width:140px;">
            <option value="" <?= ($kycStatus === '' ? 'selected' : '') ?>>all</option>
            <option value="pending" <?= ($kycStatus === 'pending' ? 'selected' : '') ?>>pending</option>
            <option value="approved" <?= ($kycStatus === 'approved' ? 'selected' : '') ?>>approved</option>
            <option value="rejected" <?= ($kycStatus === 'rejected' ? 'selected' : '') ?>>rejected</option>
          </select>
          <input name="kyc_start" type="date" class="input" value="<?= h($kycStart) ?>" style="width:140px;">
          <input name="kyc_end" type="date" class="input" value="<?= h($kycEnd) ?>" style="width:140px;">
          <button type="submit" class="btn btn-sm pill">Apply</button>
          <a class="btn btn-sm pill" href="<?= h($BASE_PATH) ?>admin/index.php?view=tools#kyc">Reset</a>
        </form>
        <div class="pagination" aria-label="Pagination" style="margin-left:auto; display:flex; align-items:center; gap:6px;">
          <a class="btn btn-sm pill" href="<?= h($BASE_PATH) ?>admin/index.php?<?= h($prevQS) ?>#kyc" aria-label="Previous">Prev</a>
          <span class="muted">Page <?= (int)$kycPage ?> / <?= (int)$kycPages ?></span>
          <a class="btn btn-sm pill" href="<?= h($BASE_PATH) ?>admin/index.php?<?= h($nextQS) ?>#kyc" aria-label="Next">Next</a>
        </div>
      </div>
      <div class="card-plain card-compact kyc-card">
        <div class="table-wrap">
        <table class="table table-compact" aria-label="KYC table">
          <thead>
            <tr>
              <?php
                $dirToggleId = ($kycSort === 'id' && $kycDir === 'asc') ? 'desc' : 'asc';
                $qsId = $baseQS . '&kyc_sort=id&kyc_dir=' . $dirToggleId . '&kyc_page=1';
                $dirToggleSt = ($kycSort === 'status' && $kycDir === 'asc') ? 'desc' : 'asc';
                $qsSt = $baseQS . '&kyc_sort=status&kyc_dir=' . $dirToggleSt . '&kyc_page=1';
              ?>
              <th><a href="<?= h($BASE_PATH) ?>admin/index.php?<?= h($qsId) ?>#kyc">ID</a></th>
              <th>User</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Bank</th>
              <th>IFSC</th>
              <th>ID Number</th>
              <th><a href="<?= h($BASE_PATH) ?>admin/index.php?<?= h($qsSt) ?>#kyc">Status</a></th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($kycList as $k): ?>
              <tr>
                <td><?= (int)$k['id'] ?></td>
                <td><?= h((string)($k['username'] ?? $k['full_name'] ?? '')) ?></td>
                <td><?= h((string)($k['email'] ?? '')) ?></td>
                <td><?= h((string)($k['phone'] ?? '')) ?></td>
                <td><?= h((string)($k['bank_name'] ?? '')) ?> / <?= h((string)($k['bank_account_number'] ?? '')) ?></td>
                <td><?= h((string)($k['ifsc'] ?? '')) ?></td>
                <td><?= h((string)($k['id_number'] ?? '')) ?></td>
                <td><?= h((string)($k['status'] ?? 'pending')) ?></td>
                <td>
                  <form method="post" style="display:inline-flex; gap:6px; align-items:center;">
                    <input type="hidden" name="action" value="set_kyc_status">
                    <input type="hidden" name="kyc_id" value="<?= (int)$k['id'] ?>">
                    <select name="status" class="input" style="width:120px;">
                      <option value="pending" <?= ((string)$k['status'] === 'pending' ? 'selected' : '') ?>>pending</option>
                      <option value="approved" <?= ((string)$k['status'] === 'approved' ? 'selected' : '') ?>>approved</option>
                      <option value="rejected" <?= ((string)$k['status'] === 'rejected' ? 'selected' : '') ?>>rejected</option>
                    </select>
                    <input name="note" type="text" class="input" placeholder="Note" style="width:160px;">
                    <button type="submit" class="btn btn-sm pill">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </section>

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
    (function(){
      var add = document.getElementById('btn-add-chart');
      if (!add) return;
      add.addEventListener('click', function(){
        if (window.showToast) {
          window.showToast('Chart added','success');
        } else {
          try {
            var t = document.createElement('div');
            t.className = 'toast show';
            t.textContent = 'Chart added';
            document.body.appendChild(t);
            setTimeout(function(){ t.classList.remove('show'); t.remove(); }, 1600);
          } catch(e) {}
        }
      });
    })();
  </script>
  <script>
    (function(){
      try {
        var wrap = document.querySelector('.dash-tabs');
        if (!wrap) return;
        var btns = wrap.querySelectorAll('.tab-btn');
        function activate(tab){
          btns.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-tab') === tab); });
          ['overview','calendar','tasks','activity'].forEach(function(t){
            var pane = document.getElementById('tab-' + t);
            if (pane) pane.classList.toggle('active', t === tab);
          });
        }
        btns.forEach(function(b){
          b.addEventListener('click', function(ev){
            ev.preventDefault();
            var tab = b.getAttribute('data-tab');
            activate(tab);
          });
        });
      } catch(e) {}
    })();
  </script>
  <script>
    (function(){
      // Highlight active sidebar link based on hash and visible section
      function setActiveByHash(){
        try {
          var hash = (window.location.hash || '#dashboard').toLowerCase();
          document.querySelectorAll('.admin-sidebar .side-link').forEach(function(a){ a.classList.remove('active'); });
          var link = document.querySelector('.admin-sidebar .side-link[href="' + hash + '"]');
          if (link) link.classList.add('active');
        } catch(e) {}
      }
      setActiveByHash();
      window.addEventListener('hashchange', setActiveByHash);
      // IntersectionObserver to update active section on scroll
      try {
        var obs = new IntersectionObserver(function(entries){
          entries.forEach(function(en){
            if (en.isIntersecting) {
              var id = '#' + en.target.id;
              var link = document.querySelector('.admin-sidebar .side-link[href="' + id + '"]');
              if (link) {
                document.querySelectorAll('.admin-sidebar .side-link').forEach(function(a){ a.classList.remove('active'); });
                link.classList.add('active');
              }
            }
          });
        }, { rootMargin: '-30% 0px -60% 0px', threshold: 0.1 });
        ['dashboard','users','campaigns','endorsements','rewards','contributors','kyc','dbtools'].forEach(function(id){
          var el = document.getElementById(id); if (el) obs.observe(el);
        });
      } catch(e) {}
    })();
  </script>
  <script>
    (function(){
      try {
        var params = new URLSearchParams(window.location.search || '');
        if (params.get('test') !== '1') return;
        var checks = [];
        var tabsWrap = document.querySelector('.admin-tabs');
        if (tabsWrap) {
          var tabs = document.querySelectorAll('.admin-tabs .tab-btn');
          checks.push({ name: 'Tabs present (>=3)', ok: (tabs && tabs.length >= 3) });
          checks.push({ name: 'Active tab set', ok: !!document.querySelector('.admin-tabs .tab-btn.active') });
        }
        var sidebar = document.querySelector('.admin-sidebar');
        var sideLinks = document.querySelectorAll('.admin-sidebar .side-link');
        checks.push({ name: 'Sidebar exists', ok: !!sidebar });
        checks.push({ name: 'Sidebar links (>=7)', ok: (sideLinks && sideLinks.length >= 7) });
        var metrics = document.querySelectorAll('.dash-cards .metric-card');
        checks.push({ name: 'Metric cards (>=5)', ok: (metrics && metrics.length >= 5) });
        var kycForm = document.querySelector('form[action*="#kyc"]');
        var kycStatusSel = document.querySelector('select[name="kyc_status"]');
        var kycStart = document.querySelector('input[name="kyc_start"]');
        var kycEnd = document.querySelector('input[name="kyc_end"]');
        checks.push({ name: 'KYC filter form', ok: !!kycForm });
        checks.push({ name: 'KYC status filter', ok: !!kycStatusSel });
        checks.push({ name: 'KYC date filters', ok: (!!kycStart && !!kycEnd) });
        var kycTable = document.querySelector('.kyc-card .table');
        checks.push({ name: 'KYC table', ok: !!kycTable });
        var sections = ['users','campaigns','endorsements','rewards','contributors','kyc'];
        sections.forEach(function(id){
          var el = document.getElementById(id);
          checks.push({ name: 'Section #' + id + ' visible', ok: !!el && el.offsetHeight > 0 });
        });
        checks.push({ name: 'Toast helper', ok: !!window.showToast });
        var panel = document.createElement('div');
        panel.className = 'test-panel';
        var title = document.createElement('div');
        title.className = 'test-title';
        title.textContent = 'Admin Self-Test';
        panel.appendChild(title);
        checks.forEach(function(c){
          var row = document.createElement('div');
          row.className = 'test-item';
          var chip = document.createElement('span');
          chip.className = c.ok ? 'chip chip-pass' : 'chip chip-fail';
          chip.textContent = c.ok ? 'pass' : 'fail';
          var txt = document.createElement('span');
          txt.textContent = ' ' + c.name;
          row.appendChild(chip);
          row.appendChild(txt);
          panel.appendChild(row);
        });
        document.body.appendChild(panel);
      } catch (e) {}
    })();
  </script>
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
</body>
</html>