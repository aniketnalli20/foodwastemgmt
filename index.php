<?php
require_once __DIR__ . '/app.php';

$message = '';
$errors = [];
$footer_note = '';

// Ensure uploads folder exists (separate file storage)
$uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
}

// Upload hero option moved to admin in future. Front page does not handle hero uploads.

// Resolve hero background image: prefer configured path via proxy, fallback to uploads
$heroUrl = null;
if (!empty($HERO_IMAGE_PATH) && is_file($HERO_IMAGE_PATH)) {
    $heroUrl = 'hero_proxy.php';
} else {
    foreach (['hero.jpg','hero.png','hero.webp'] as $name) {
        $candidate = $uploadsDir . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) { $heroUrl = 'uploads/' . $name; break; }
    }
}

// Handle create listing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_listing') {
    $donor_type = trim($_POST['donor_type'] ?? '');
    $donor_name = trim($_POST['donor_name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $item = trim($_POST['item'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $expires_at = trim($_POST['expires_at'] ?? '');

    if ($donor_type === '') $errors[] = 'Donor type is required.';
    if ($donor_name === '') $errors[] = 'Donor name is required.';
    if ($item === '') $errors[] = 'Item is required.';
    if ($quantity === '') $errors[] = 'Quantity is required.';

    // Optional image upload
    $imageUrl = null;
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['image']['tmp_name'];
        $size = (int)($_FILES['image']['size'] ?? 0);
        $mime = @mime_content_type($tmp) ?: '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            $errors[] = 'Only JPG, PNG, or WEBP images are allowed.';
        } elseif ($size > 3 * 1024 * 1024) {
            $errors[] = 'Image must be smaller than 3MB.';
        } else {
            $ext = $allowed[$mime];
            $safeName = 'fw_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $safeName;
            if (move_uploaded_file($tmp, $destPath)) {
                // Store URL/path in DB (relative URL)
                $imageUrl = 'uploads/' . $safeName;
            } else {
                $errors[] = 'Failed to save uploaded image.';
            }
        }
    }

    if (!$errors) {
        global $pdo;
    $stmt = $pdo->prepare('INSERT INTO listings (donor_type, donor_name, contact, item, quantity, address, city, pincode, expires_at, image_url, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $donor_type,
            $donor_name,
            $contact,
            $item,
            $quantity,
            $address,
            $city,
            $pincode,
            $expires_at ?: null,
            $imageUrl,
            'open',
            date('c')
        ]);
        $message = 'Listing created successfully.';
    }
}

// Handle claim listing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_listing') {
    $listing_id = (int)($_POST['listing_id'] ?? 0);
    $ngo_name = trim($_POST['ngo_name'] ?? '');
    $claimer_name = trim($_POST['claimer_name'] ?? '');
    $contact = trim($_POST['claimer_contact'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($listing_id <= 0) $errors[] = 'Invalid listing.';
    if ($claimer_name === '') $errors[] = 'Claimer name is required.';

    if (!$errors) {
        global $pdo;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO claims (listing_id, ngo_name, claimer_name, contact, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$listing_id, $ngo_name ?: null, $claimer_name, $contact ?: null, $notes ?: null, date('c')]);
            $pdo->prepare("UPDATE listings SET status = 'claimed', claimed_at = ? WHERE id = ? AND status = 'open'")
                ->execute([date('c'), $listing_id]);
            $pdo->commit();
            $message = 'Listing claimed successfully.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to claim: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_feedback') {
    $name = trim((string)($_POST['fb_name'] ?? ''));
    $email = trim((string)($_POST['fb_email'] ?? ''));
    $msg = trim((string)($_POST['fb_message'] ?? ''));
    if ($name !== '' && $msg !== '') {
        $line = gmdate('c') . ' | name=' . str_replace(["\r","\n"], '', $name) . ' | email=' . str_replace(["\r","\n"], '', $email) . ' | ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' | msg=' . str_replace(["\r","\n"], ' ', $msg);
        @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'feedback.txt', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        $footer_note = 'Thanks for your feedback.';
    } else {
        $footer_note = 'Please provide your name and message.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_contact') {
    $email = trim((string)($_POST['ct_email'] ?? ''));
    $subject = trim((string)($_POST['ct_subject'] ?? ''));
    $msg = trim((string)($_POST['ct_message'] ?? ''));
    if ($subject !== '' && $msg !== '') {
        $line = gmdate('c') . ' | subject=' . str_replace(["\r","\n"], '', $subject) . ' | email=' . str_replace(["\r","\n"], '', $email) . ' | ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' | msg=' . str_replace(["\r","\n"], ' ', $msg);
        @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'contact_messages.txt', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        $footer_note = 'Message sent.';
    } else {
        $footer_note = 'Please provide a subject and message.';
    }
}

// Filters
$cityFilter = trim($_GET['city'] ?? '');
$pincodeFilter = trim($_GET['pincode'] ?? '');

global $pdo;
global $DB_DRIVER;
$where = [];
$params = [];
if ($cityFilter !== '') { $where[] = 'city LIKE ?'; $params[] = '%' . $cityFilter . '%'; }
if ($pincodeFilter !== '') { $where[] = 'pincode LIKE ?'; $params[] = '%' . $pincodeFilter . '%'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where) . " AND status = 'open'") : "WHERE status = 'open'";
// Order by soonest expiry, handling NULLs; cast needed for PostgreSQL
$orderExpr = ($DB_DRIVER === 'pgsql') ? "COALESCE(expires_at, TIMESTAMP '9999-12-31 00:00:00')" : "COALESCE(expires_at, '9999-12-31T00:00:00')";
$listingsStmt = $pdo->prepare("SELECT id, donor_type, donor_name, contact, item, quantity, address, city, pincode, expires_at, image_url, status, created_at, claimed_at FROM listings $whereSql ORDER BY $orderExpr ASC, id DESC LIMIT 20");
$listingsStmt->execute($params);
$listings = $listingsStmt->fetchAll();

// Fetch recent campaigns
$campaigns = [];
try {
    // Include contributor_name and endorse counts; show only actively open campaigns
    // Community filter removed to allow campaigns without community selection to appear
$campaignsStmt = $pdo->prepare("SELECT id, title, summary, area, target_meals, status, created_at, contributor_name, endorse_campaign, location, crowd_size, closing_time\n  FROM campaigns\n  WHERE status = 'open'\n    AND ((location IS NOT NULL AND location <> '') OR (area IS NOT NULL AND area <> ''))\n    AND crowd_size IS NOT NULL\n    AND closing_time IS NOT NULL AND closing_time <> ''\n  ORDER BY created_at DESC\n  LIMIT 6");
    $campaignsStmt->execute();
    $campaigns = $campaignsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Silent: if table not available yet, skip section
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Food Waste Management</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css" />
    <!-- Using local Inter font from /fonts; external font links removed -->
    <meta name="description" content="Connect donors with NGOs to rescue surplus food in India." />
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= h($BASE_PATH) ?>uploads/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= h($BASE_PATH) ?>uploads/favicon.png">
</head>
<body>
    <header class="site-header" role="banner">
        <div class="container header-inner">
            <a href="#hero" class="brand" aria-label="Food Waste Management home">No Starve</a>
            <button class="nav-toggle" aria-controls="primary-navigation" aria-expanded="false" aria-label="Toggle navigation">
                <span class="nav-toggle-bar" aria-hidden="true"></span>
                <span class="nav-toggle-bar" aria-hidden="true"></span>
                <span class="nav-toggle-bar" aria-hidden="true"></span>
            </button>
            <?php $currentPath = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
            <nav id="primary-navigation" class="nav-links" role="navigation" aria-label="Primary">
                <a href="#hero"<?= $currentPath === 'index.php' ? ' class="active"' : '' ?>>Home</a>
                <a href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>"<?= $currentPath === 'create_campaign.php' ? ' class="active"' : '' ?>>Create Campaign</a>
                <a href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>"<?= $currentPath === 'profile.php' ? ' class="active"' : '' ?>>Profile</a>
                <?php if (is_logged_in() && is_admin()): ?>
                  <a href="<?= h($BASE_PATH) ?>admin/index.php"<?= $currentPath === 'index.php' ? '' : '' ?>>Admin</a>
                <?php endif; ?>
                <?php if (is_logged_in()): ?>
                  <a href="<?= h($BASE_PATH) ?>logout.php">Logout</a>
                <?php else: ?>
                  <a href="<?= h($BASE_PATH) ?>login.php"<?= $currentPath === 'login.php' ? ' class="active"' : '' ?>>Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <section id="hero" class="hero"<?= $heroUrl ? ' style="--hero-img: url(' . h($heroUrl) . ');"' : '' ?> >
        <div class="wrap">
            <h1 class="hero-title break-100">We strive to make a real difference by helping people find available meals nearby</h1>
            <p class="hero-sub break-100">Together Against Food Waste</p>
            <div class="hero-actions">
              <a class="btn accent pill" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Get Started</a>
              <?php if (is_logged_in()): ?>
                <a class="btn pill" href="<?= h($BASE_PATH) ?>profile.php">View Profile</a>
              <?php else: ?>
                <a class="btn pill" href="<?= h($BASE_PATH) ?>login.php?next=profile.php">View Profile</a>
              <?php endif; ?>
            </div>
            <form class="search-bar" role="search" method="get" action="<?= h($BASE_PATH) ?>index.php">
              <div class="search-fields">
                <input type="text" name="city" placeholder="Search by city" value="<?= h($cityFilter) ?>" aria-label="City" />
                <input type="text" name="pincode" placeholder="Pincode" value="<?= h($pincodeFilter) ?>" aria-label="Pincode" />
                <!-- Category filter removed -->
                <button class="btn accent pill" type="submit">Search</button>
              </div>
              <?php if ($cityFilter !== '' || $pincodeFilter !== ''): ?>
                <div class="search-meta" aria-live="polite">Showing results for 
                  <?= $cityFilter !== '' ? '<span class="chip">' . h($cityFilter) . '</span>' : '' ?>
                  <?= $pincodeFilter !== '' ? '<span class="chip">' . h($pincodeFilter) . '</span>' : '' ?>
                </div>
              <?php endif; ?>
            </form>
            <div class="stats">
              <div class="stat"><span id="meals-count" class="stat-num">0</span><span class="stat-label">Meals Made</span></div>
              <div class="stat"><span id="donors-count" class="stat-num">0</span><span class="stat-label">Contributors</span></div>
              <div class="stat"><span id="partners-count" class="stat-num">0</span><span class="stat-label">Partners</span></div>
            </div>
        </div>
        <div id="campaigns-refresh" class="card-plain" style="display:none; margin-top:12px; text-align:center;">
            <div class="section-title" style="border-bottom:none;">End of campaigns</div>
            <p>Refresh to see the latest campaigns.</p>
            <div class="actions" style="justify-content:center; margin-top:6px;">
                <button type="button" class="btn pill" onclick="location.reload()">Refresh</button>
            </div>
        </div>
    </section>

    <!-- Recent Campaigns section placed directly under hero -->
    <section id="recent-campaigns" class="container fullbleed" aria-label="Recent Campaigns" style="padding: var(--content-pad);">
        <div class="heading" style="margin-bottom: 12px; display:flex; align-items:center; justify-content:space-between;">
            <span>Recent Campaigns</span>
            <a class="btn pill" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Create Campaign</a>
        </div>
        <?php if (!empty($campaigns)): ?>
            <div class="tweet-list">
                <?php foreach ($campaigns as $c): ?>
                    <?php $name = trim((string)($c['contributor_name'] ?? $c['title'] ?? 'Campaign')); $initial = strtoupper(substr($name, 0, 1)); ?>
                    <article class="tweet-card" aria-label="Campaign" id="campaign-<?= (int)$c['id'] ?>">
                        <div class="tweet-avatar" aria-hidden="true"><span><?= h($initial) ?></span></div>
                        <div class="tweet-content">
                            <div class="tweet-header">
                                <span class="tweet-name"><?= h($name) ?></span>
                            </div>
                            <?php
                              $csVal = isset($c['crowd_size']) && $c['crowd_size'] !== '' ? (int)$c['crowd_size'] : null;
                              $csLabel = null; $csClass = '';
                              if ($csVal !== null) {
                                if ($csVal >= 200) { $csLabel = 'High'; $csClass = 'high'; }
                                else if ($csVal >= 50) { $csLabel = 'Medium'; $csClass = 'medium'; }
                                else { $csLabel = 'Low'; $csClass = 'low'; }
                              }
                            ?>
                            <div class="tweet-details">
                                <div class="detail"><span class="d-label">Location</span><span class="d-value"><?= h(($c['location'] ?? '') !== '' ? $c['location'] : ($c['area'] ?? '—')) ?></span></div>
                                <div class="detail"><span class="d-label">Crowd Size</span><span class="d-value">
                                  <?= ($csVal !== null ? h((string)$csVal) : '—') ?>
                                  <?php if ($csLabel): ?><span class="chip <?= h($csClass) ?>" style="margin-left:6px;"><?= h($csLabel) ?></span><?php endif; ?>
                                </span></div>
                                <div class="detail"><span class="d-label">Closing Time</span><span class="d-value"><?= h($c['closing_time'] ?? '—') ?></span></div>
                                <div class="detail"><span class="d-label">Target Meals</span><span class="d-value"><?= h(isset($c['target_meals']) && $c['target_meals'] !== '' ? (string)$c['target_meals'] : '—') ?></span></div>
                            </div>
                            <div class="tweet-meta">
                                <?php if (!empty($c['area'])): ?><span class="chip">Area: <?= h($c['area']) ?></span><?php endif; ?>
                            </div>
                            <div class="tweet-actions">
                                <button class="tweet-btn endorse-btn" type="button" data-campaign-id="<?= (int)$c['id'] ?>">Endorse <span class="endorse-count" data-campaign-id="<?= (int)$c['id'] ?>"><?= h((string)($c['endorse_campaign'] ?? 0)) ?></span></button>
                                <button class="tweet-btn share-btn" type="button" data-campaign-id="<?= (int)$c['id'] ?>">Share</button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card-plain" role="note" style="padding:12px; border:1px solid var(--border); border-radius:8px;">No campaigns yet. Be the first to <a href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">create one</a>.</div>
        <?php endif; ?>
    </section>

    <main>
        <!-- Trending donations grid removed per request -->
        <!-- Core content removed per request -->

        <!-- Inspo cards layout -->
        <section id="cards" class="cards-frame" aria-label="Featured cards">
          <div class="cards-grid">
            <!-- Left feature card (text removed per request) -->
            <article class="card-feature" aria-label="Feature">
            </article>

            <!-- Right media card -->
            <article class="card-media" aria-label="Illustration">
              <div class="phone-illustration" aria-hidden="true">
                <div class="phone"></div>
                <div class="hand"></div>
                <div class="decor a"></div>
                <div class="decor b"></div>
              </div>
            </article>

            <!-- Bottom dark theme card (text removed per request) -->
            <article class="card-theme" aria-label="Theme options">
              <div class="theme-art" aria-hidden="true"></div>
            </article>
          </div>
    </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <div class="footer-inspo">
                <div style="margin:0; text-align:left; width:100%;">
                    <div class="footer-social" style="justify-content:center;">
                        <a href="#" aria-label="Twitter">t</a>
                        <a href="#" aria-label="Instagram">i</a>
                        <a href="#" aria-label="LinkedIn">in</a>
                        <a href="#" aria-label="YouTube">yt</a>
                    </div>
                    <div class="footer-desc" style="text-align:center;">No Starve helps people discover available meals nearby and connect safely for convenient access.</div>
                    <div class="footer-legal" style="text-align:center;">&copy; 2025 No Starve</div>
                </div>
                <div>
                    <div class="cta-card" aria-label="Call to action">
                        <h3>Make Access To Meals Easy</h3>
                        <ul class="list-bullets checklist">
                            <li>Discover nearby meal availability</li>
                            <li>Save time coordinating campaigns</li>
                        </ul>
                        <div class="actions">
                            <a class="btn dark pill" href="<?= h($BASE_PATH) ?>login.php">Get access</a>
                            <a class="btn light pill" href="<?= h($BASE_PATH) ?>register.php">Register</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    <script>
    // Expose BASE_PATH to JS for building internal requests correctly under subfolder or vhost
    window.BASE_PATH = '<?= h($BASE_PATH) ?>';
    </script>
    <script>
    (function(){
      var sec = document.getElementById('recent-campaigns');
      var msg = document.getElementById('campaigns-refresh');
      function check(){
        if (!sec || !msg) return;
        var atBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 10);
        var rect = sec.getBoundingClientRect();
        var endVisible = rect.bottom <= (window.innerHeight + 20);
        msg.style.display = (atBottom && endVisible) ? 'block' : 'none';
      }
      window.addEventListener('scroll', check, { passive: true });
      window.addEventListener('resize', check);
      document.addEventListener('DOMContentLoaded', check);
      check();
    })();
    </script>
    <script>
    (function(){
      try {
        var params = new URLSearchParams(window.location.search || '');
        var created = parseInt(params.get('created') || '', 10);
        if (created && created > 0) {
          var el = document.getElementById('campaign-' + created);
          if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var oldBox = el.style.boxShadow;
            var oldBg = el.style.backgroundColor;
            el.style.boxShadow = '0 0 0 3px #1a7aff66';
            el.style.backgroundColor = 'rgba(26,122,255,0.06)';
            setTimeout(function(){ el.style.boxShadow = oldBox; el.style.backgroundColor = oldBg; }, 2500);
            var endorseParam = (params.get('endorse') || '').toLowerCase();
            var shareParam = params.get('share');
            if (endorseParam) {
              var btn = document.querySelector('.endorse-btn[data-campaign-id="' + created + '"]');
              if (btn) {
                setTimeout(function(){ btn.click(); }, 300);
              }
            }
            if (shareParam) {
              var sbtn = document.querySelector('.share-btn[data-campaign-id="' + created + '"]');
              if (sbtn) {
                setTimeout(function(){ sbtn.click(); }, 600);
              }
            }
          }
        }
      } catch (e) {}
    })();
    </script>
    <script>
    // Live counters: fetch from server and update periodically
    (function(){
      function updateCounters(){
        fetch((window.BASE_PATH || '<?= h($BASE_PATH) ?>') + 'stats.php')
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j) return;
            var mealsEl = document.getElementById('meals-count');
            var donorsEl = document.getElementById('donors-count');
            var partnersEl = document.getElementById('partners-count');
            if (mealsEl && typeof j.mealsSaved === 'number') mealsEl.textContent = j.mealsSaved.toString();
            if (donorsEl && typeof j.donorsCount === 'number') donorsEl.textContent = j.donorsCount.toString();
            if (partnersEl && typeof j.partnersCount === 'number') partnersEl.textContent = j.partnersCount.toString();
          })
          .catch(function(){ /* silent */ });
      }
      updateCounters();
      setInterval(updateCounters, 10000);
    })();
    // Endorse and Share handlers
    (function(){
      function qsAll(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }

      // Endorse
      qsAll('.endorse-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = parseInt(btn.getAttribute('data-campaign-id'), 10);
          if (!id || btn.disabled) return;
          btn.disabled = true;
          var body = 'campaign_id=' + encodeURIComponent(id) + '&kind=campaign';
          fetch((window.BASE_PATH || '<?= h($BASE_PATH) ?>') + 'endorse.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
          }).then(function(r){ return r.json(); })
            .then(function(j){
              var countEl = document.querySelector('.endorse-count[data-campaign-id="' + id + '"]');
              if (countEl && j && typeof j.count === 'number') { countEl.textContent = j.count.toString(); }
              btn.disabled = false;
            }).catch(function(){ btn.disabled = false; });
        });
      });

      // Share
      function shareCampaign(id){
        var url = (window.location.origin || '') + (window.BASE_PATH || '<?= h($BASE_PATH) ?>') + 'index.php#campaign-' + id;
        var title = 'No Starve Campaign';
        if (navigator.share) {
          navigator.share({ title: title, url: url }).catch(function(){});
        } else if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function(){ alert('Link copied to clipboard'); }).catch(function(){ alert(url); });
        } else {
          alert(url);
        }
      }
      qsAll('.share-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = parseInt(btn.getAttribute('data-campaign-id'), 10);
          if (!id) return;
          shareCampaign(id);
        });
      });
    })();
    </script>
</body>
</html>