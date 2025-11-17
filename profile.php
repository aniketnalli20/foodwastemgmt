<?php
require_once __DIR__ . '/app.php';

// View profile requires authentication
require_login();
$user = current_user();
if (!$user) {
    header('Location: ' . $BASE_PATH . 'login.php?next=profile.php');
    exit;
}
// Profile update state
$errors = [];
$message = '';
$footer_note = '';
global $pdo;
$endorseTotal = 0;
$karmaBalance = 0;
$nextIn = 0;
$walletMsg = '';
$kycStatus = 'pending';
try {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(endorse_campaign),0) AS total FROM campaigns WHERE user_id = ?');
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $endorseTotal = $row ? (int)$row['total'] : 0;
    $expected = intdiv($endorseTotal, 100);
    $karmaBalance = get_karma_balance((int)$user['id']);
    if ($karmaBalance < $expected) {
        $diff = $expected - $karmaBalance;
        $karmaBalance = award_karma_coins((int)$user['id'], $diff, 'endorsements_milestone', 'user', (int)$user['id']);
    }
    $nextIn = 100 - ($endorseTotal % 100);
    if ($nextIn === 100) { $nextIn = 0; }
} catch (Throwable $e) {
}

// Handle profile updates (phone, address)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    global $pdo;
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if ($phone !== '' && !preg_match('/^[0-9+\-\s]{7,30}$/', $phone)) {
        $errors[] = 'Phone must be 7–30 characters using digits, +, -, spaces.';
    }
    if (strlen($address) > 5000) {
        $errors[] = 'Address is too long.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET phone = ?, address = ? WHERE id = ?');
            $stmt->execute([$phone !== '' ? $phone : null, $address !== '' ? $address : null, $user['id']]);
            $message = 'Profile updated successfully.';
            // Refresh current user after update
            $user = current_user();
        } catch (Throwable $e) {
            $errors[] = 'Update failed; please try again later.';
        }
    }
}

// Wallet convert: align wallet with endorsements-based expected earnings (1 coin per 100 endorsements)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wallet_convert') {
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(endorse_campaign),0) AS total FROM campaigns WHERE user_id = ?');
        $stmt->execute([(int)$user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $endorseTotalX = $row ? (int)$row['total'] : 0;
        $expectedCoinsX = intdiv($endorseTotalX, 100);
        $currentBalanceX = get_karma_balance((int)$user['id']);
        $deltaX = $expectedCoinsX - $currentBalanceX;
        if ($deltaX > 0) {
            award_karma_coins((int)$user['id'], $deltaX, 'conversion', 'user', (int)$user['id']);
            $walletMsg = 'Converted ' . $deltaX . ' coin(s) to wallet.';
            $karmaBalance = get_karma_balance((int)$user['id']);
        } else {
            $walletMsg = 'No conversion needed.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Conversion failed; please try again later.';
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= h($BASE_PATH) ?>uploads/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= h($BASE_PATH) ?>uploads/favicon.png">
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

    <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);" aria-label="My Profile">
      <section class="card-plain card-fullbleed page-profile" aria-label="Profile">
        <h2 class="section-title">My Profile</h2>
        <?php /* Avatar/cover removed for a cleaner header */ ?>
        <section class="profile-header" aria-label="Profile Header">
          <div class="profile-meta">
            <div class="profile-ident">
              <div>
                <div class="profile-name"><?= h((string)($user['username'] ?? 'User')) ?></div>
                <div class="profile-stats">
                  <div class="stat"><span class="material-symbols-outlined" aria-hidden="true">workspace_premium</span><span><?= h(format_compact_number((int)$karmaBalance)) ?> coins</span></div>
                  <div class="stat"><span class="material-symbols-outlined" aria-hidden="true">favorite</span><span><?= h(format_compact_number((int)$endorseTotal)) ?> endorsements</span></div>
                </div>
              </div>
            </div>
          </div>
          <div class="profile-tabs">
            <button class="tab active" data-tab="overview">Overview</button>
            <button class="tab" data-tab="campaigns">Campaigns</button>
          </div>
        </section>
        <?php if (!empty($errors)): ?>
            <div class="alert error" role="alert">
                <strong>Error:</strong>
                <ul class="list-clean">
                    <?php foreach ($errors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert success" role="status">
                <?= h($message) ?>
            </div>
        <?php endif; ?>
        <?php
          $myCampaigns = [];
          try { $stC = $pdo->prepare('SELECT id, title, crowd_size, status, created_at FROM campaigns WHERE user_id = ? ORDER BY created_at DESC LIMIT 6'); $stC->execute([(int)$user['id']]); $myCampaigns = $stC->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
          $allCampaigns = [];
          try { $stAll = $pdo->prepare('SELECT id, title, area, location, crowd_size, closing_time, endorse_campaign, coins_received, created_at FROM campaigns WHERE user_id = ? ORDER BY created_at DESC LIMIT 30'); $stAll->execute([(int)$user['id']]); $allCampaigns = $stAll->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
        ?>
        <div id="tab-overview" class="tab-panel active">
        <div class="info-grid">
        <div class="info-card" aria-label="Basic info">
            <div class="item"><div class="left"><span class="material-symbols-outlined" aria-hidden="true">badge</span><span><?= h($user['username'] ?? '') ?></span></div></div>
            <div class="item"><div class="left"><span class="material-symbols-outlined" aria-hidden="true">mail</span><span><?= h($user['email'] ?? '') ?></span></div><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></div>
            <div class="item"><div class="left"><span class="material-symbols-outlined" aria-hidden="true">call</span><span><?= h(($user['phone'] ?? '') !== '' ? $user['phone'] : 'Not provided') ?></span></div><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></div>
            <div class="item"><div class="left"><span class="material-symbols-outlined" aria-hidden="true">location_on</span><span><?= h(($user['address'] ?? '') !== '' ? $user['address'] : 'Not provided') ?></span></div><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></div>
            <div class="item"><div class="left"><span class="material-symbols-outlined" aria-hidden="true">event</span><span><?= h($user['created_at'] ?? '') ?></span></div><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></div>
          </div>
          <div class="feed-card" aria-label="Quick update">
            <div class="panel-title">Update Contact</div>
            <div class="form-frame">
              <div class="form-panel">
                <form id="profileForm" method="post" action="<?= h($BASE_PATH) ?>profile.php" class="form" style="margin-top:8px;">
                  <input type="hidden" name="action" value="update_profile">
                  <label for="phone"><strong>Phone</strong></label>
                  <input id="phone" name="phone" type="text" class="input" placeholder="e.g., +91 98765 43210" value="<?= h($user['phone'] ?? '') ?>" pattern="[0-9+\-\s]{7,30}" autocomplete="tel" aria-describedby="phone-hint" list="phone-presets" />
                  <small id="phone-hint" class="input-hint">Use digits, +, -, spaces; 7–30 characters.</small>
                  <datalist id="phone-presets">
                    <option value="+91 98765 43210"></option>
                    <option value="+91 91234 56789"></option>
                    <option value="+1 555-123-4567"></option>
                  </datalist>
                  <div class="preset-group" aria-label="Phone presets">
                    <button type="button" class="chip" data-fill-phone="+91 98765 43210">Use sample</button>
                    <button type="button" class="chip" data-clear="phone">Clear</button>
                  </div>
                  <label for="address" style="margin-top:10px;"><strong>Address</strong></label>
                  <textarea id="address" name="address" class="input" placeholder="Street, City, State, PIN" rows="3" style="resize: vertical;" autocomplete="street-address"><?= h($user['address'] ?? '') ?></textarea>
                  <div class="preset-group" aria-label="Address presets" style="margin-top:6px;">
                    <button type="button" class="chip" id="open-map">Pick on Map</button>
                    <button type="button" class="chip" data-clear="address">Clear</button>
                  </div>
                  <div id="map-container" class="card-plain" style="display:none; margin-top:8px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                      <strong>Select location on map</strong>
                      <button type="button" class="chip" id="close-map">Done</button>
                    </div>
                    <div id="profile-map" style="height:300px; border-radius:8px; overflow:hidden;"></div>
                    <small class="input-hint" id="map-hint">Click the map to auto-fill address.</small>
                  </div>
                </form>
              </div>
              <aside class="summary-card">
                <div class="summary-title">Profile Summary</div>
                <ul class="summary-list">
                  <li><span>Wallet</span><span><?php $balance = 0; try { $stB = $pdo->prepare('SELECT COALESCE(balance,0) FROM karma_wallets WHERE user_id = ?'); $stB->execute([(int)$user['id']]); $balance = (int)($stB->fetchColumn() ?: 0); } catch (Throwable $e) {} echo h(format_compact_number((int)$balance)); ?></span></li>
                  <li><span>Karma Coins</span><span><?= h(format_compact_number((int)$karmaBalance)) ?></span></li>
                  <li><span>Endorsements</span><span><?= h(format_compact_number((int)$endorseTotal)) ?></span></li>
                </ul>
                <div class="summary-cta" style="margin-top:8px;">
                  <button type="submit" class="btn success pill" form="profileForm">Save Changes</button>
                </div>
              </aside>
            </div>
            <div class="panel-title" style="margin-top:10px;">Recent campaigns</div>
            <?php if (!empty($myCampaigns)): ?>
              <div class="profile-grid" style="grid-template-columns: repeat(3, minmax(160px, 1fr)); gap:8px;">
                <?php foreach ($myCampaigns as $mc): ?>
                  <div class="card-plain" style="padding:10px; border-radius:12px;">
                    <div style="font-weight:600;"><?= h((string)($mc['title'] ?? 'Untitled')) ?></div>
                    <div class="muted" style="margin-top:4px;">Crowd <?= h(format_compact_number((int)($mc['crowd_size'] ?? 0))) ?></div>
                    <div class="muted">Status <?= h((string)($mc['status'] ?? '')) ?></div>
                    <a class="chip" style="margin-top:6px; display:inline-block;" href="<?= h($BASE_PATH) ?>campaign.php?id=<?= (int)$mc['id'] ?>">Open</a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="muted">No campaigns yet.</div>
            <?php endif; ?>
          </div>
        </div>
        </div>
        <div id="tab-campaigns" class="tab-panel">
          <?php if (!empty($allCampaigns)): ?>
            <div class="tweet-list">
              <?php foreach ($allCampaigns as $c): ?>
                <?php $name = trim((string)($user['username'] ?? 'Campaign')); $initial = strtoupper(substr($name, 0, 1)); ?>
                <article class="tweet-card" aria-label="Campaign" id="campaign-<?= (int)$c['id'] ?>">
                  <div class="tweet-avatar" aria-hidden="true"><span><?= h($initial) ?></span></div>
                  <div class="tweet-content">
                    <div class="tweet-header" style="display:flex; align-items:center; gap:8px; justify-content:space-between;">
                      <div style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="tweet-name"><?= h((string)($c['title'] ?? 'Campaign')) ?></span>
                      </div>
                      <a class="tweet-btn" href="<?= h($BASE_PATH) ?>campaign.php?id=<?= (int)$c['id'] ?>" title="Open" aria-label="Open"><span class="material-symbols-outlined icon">open_in_new</span></a>
                    </div>
                    <?php
                      $csVal = isset($c['crowd_size']) && $c['crowd_size'] !== '' ? (int)$c['crowd_size'] : null;
                      $locRaw = (($c['location'] ?? '') !== '' ? (string)$c['location'] : (string)($c['area'] ?? '—'));
                      $locText = $locRaw;
                    ?>
                    <div class="tweet-details">
                      <div class="detail"><span class="d-label">Location</span><span class="d-value"><?= $locText ?></span></div>
                      <div class="detail"><span class="d-label">Crowd Size</span><span class="d-value"><?= ($csVal !== null ? h((string)$csVal) : '—') ?></span></div>
                      <div class="detail"><span class="d-label">Closing Time</span><span class="d-value"><?= h($c['closing_time'] ?? '—') ?></span></div>
                      <div class="detail"><span class="d-label"><span class="coin-icon" aria-hidden="true"></span>Karma Coins</span><span class="d-value"><?= (int)($c['coins_received'] ?? 0) ?></span></div>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="muted">You have no campaigns yet.</div>
          <?php endif; ?>
        </div>
        <div class="profile-actions" style="margin-top:12px;">
          <a class="btn pill" href="<?= h($BASE_PATH) ?>index.php#hero">Back to Home</a>
          <a class="btn btn-bhargav" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>"><span>Create Campaign</span></a>
        </div>
        </div>
      </section>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var tabs = Array.prototype.slice.call(document.querySelectorAll('.profile-tabs .tab'));
      tabs.forEach(function(t){
        t.addEventListener('click', function(){
          tabs.forEach(function(x){ x.classList.remove('active'); });
          t.classList.add('active');
          var key = t.getAttribute('data-tab');
          document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
          var panel = document.getElementById('tab-' + key);
          if (panel) panel.classList.add('active');
        });
      });
      document.querySelectorAll('.preset-group .chip').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var phoneInput = document.getElementById('phone');
          var addressInput = document.getElementById('address');
          if (this.dataset.fillPhone && phoneInput) { phoneInput.value = this.dataset.fillPhone; }
          if (this.dataset.clear === 'phone' && phoneInput) { phoneInput.value = ''; }
          if (this.dataset.fillAddress && addressInput) { addressInput.value = this.dataset.fillAddress; }
          if (this.dataset.clear === 'address' && addressInput) { addressInput.value = ''; }
        });
      });

      var openMapBtn = document.getElementById('open-map');
      var closeMapBtn = document.getElementById('close-map');
      var mapContainer = document.getElementById('map-container');
      var mapEl = document.getElementById('profile-map');
      var mapInstance = null;
      var marker = null;
      function initMap() {
        if (mapInstance) return mapInstance;
        // Default center: India; zoom 5
        mapInstance = L.map('profile-map').setView([20.5937, 78.9629], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(mapInstance);
        // Try to center on user location
        if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(function (pos) {
            var lat = pos.coords.latitude, lon = pos.coords.longitude;
            mapInstance.setView([lat, lon], 14);
          }, function () { /* ignore */ });
        }
        // Click to set address
        mapInstance.on('click', function (e) {
          var lat = e.latlng.lat; var lon = e.latlng.lng;
          if (marker) { mapInstance.removeLayer(marker); }
          marker = L.marker([lat, lon]).addTo(mapInstance);
          var addressInput = document.getElementById('address');
          var hint = document.getElementById('map-hint');
          hint.textContent = 'Resolving address…';
          fetch((window.BASE_PATH || '<?= h($BASE_PATH) ?>') + 'geocode.php?lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon))
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (j && j.label && addressInput) {
                addressInput.value = j.label;
                hint.textContent = 'Address filled from map click.';
              } else {
                hint.textContent = 'Unable to resolve address.';
              }
            })
            .catch(function () { hint.textContent = 'Unable to resolve address.'; });
        });
        return mapInstance;
      }
      if (openMapBtn) {
        openMapBtn.addEventListener('click', function () {
          if (mapContainer) { mapContainer.style.display = 'block'; }
          initMap();
          // Ensure map renders after container becomes visible
          setTimeout(function(){ if (mapInstance) { mapInstance.invalidateSize(); } }, 50);
        });
      }
      if (closeMapBtn) {
        closeMapBtn.addEventListener('click', function () {
          if (mapContainer) { mapContainer.style.display = 'none'; }
        });
      }
    });
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

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
                            <a class="btn light pill" href="<?= h($BASE_PATH) ?>login.php?tab=register">Register</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>