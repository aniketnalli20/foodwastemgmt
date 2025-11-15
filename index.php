<?php
require_once __DIR__ . '/app.php';

$message = '';
$errors = [];

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
            $pdo->prepare('UPDATE listings SET status = "claimed", claimed_at = ? WHERE id = ? AND status = "open"')
                ->execute([date('c'), $listing_id]);
            $pdo->commit();
            $message = 'Listing claimed successfully.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to claim: ' . $e->getMessage();
        }
    }
}

// Filters
$cityFilter = trim($_GET['city'] ?? '');
$pincodeFilter = trim($_GET['pincode'] ?? '');

global $pdo;
$where = [];
$params = [];
if ($cityFilter !== '') { $where[] = 'city LIKE ?'; $params[] = '%' . $cityFilter . '%'; }
if ($pincodeFilter !== '') { $where[] = 'pincode LIKE ?'; $params[] = '%' . $pincodeFilter . '%'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where) . ' AND status = "open"') : 'WHERE status = "open"';
$listingsStmt = $pdo->prepare("SELECT id, donor_type, donor_name, contact, item, quantity, address, city, pincode, expires_at, image_url, status, created_at, claimed_at FROM listings $whereSql ORDER BY COALESCE(expires_at, '9999-12-31T00:00:00') ASC, id DESC LIMIT 20");
$listingsStmt->execute($params);
$listings = $listingsStmt->fetchAll();
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
            <h1 class="hero-title break-100">We strive to make a real difference by helping people find available meals nearby, reducing food waste, and supporting those in need.</h1>
            <p class="hero-sub break-100">Together Against Food Waste</p>
            <div class="hero-actions">
              <a class="btn accent pill" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Get Started</a>
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
              <div class="stat"><span class="stat-num">250k+</span><span class="stat-label">Meals Saved</span></div>
              <div class="stat"><span class="stat-num">1.5k+</span><span class="stat-label">Donors</span></div>
              <div class="stat"><span class="stat-num">800+</span><span class="stat-label">Partners</span></div>
            </div>
        </div>
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
            <small>&copy; 2025 No Starve</small>
        </div>
    </footer>
    <script>
  (function() {
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.getElementById('primary-navigation');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', function() {
      const expanded = this.getAttribute('aria-expanded') === 'true';
      this.setAttribute('aria-expanded', String(!expanded));
      nav.classList.toggle('open');
    });
  })();
  // Break text into lines of 8 words for elements with .break-8
  (function() {
    // Break text into lines of ~60 characters (range 45–75) for elements with .break-fit
    const TARGET = 60;
    const MIN = 45;
    const MAX = 75;
    const targets = document.querySelectorAll('.break-fit');
    targets.forEach(el => {
      const hasChildren = Array.from(el.childNodes).some(n => n.nodeType === Node.ELEMENT_NODE);
      if (hasChildren) return;
      const text = (el.textContent || '').trim();
      if (!text) return;
      const words = text.split(/\s+/);
      const lines = [];
      let current = '';
      for (let i = 0; i < words.length; i++) {
        const word = words[i];
        const candidate = current ? current + ' ' + word : word;
        if (candidate.length <= TARGET || current.length < MIN) {
          current = candidate;
        } else {
          lines.push(current);
          current = word;
        }
      }
      if (current) lines.push(current);
      el.innerHTML = lines.map(l => '<span class="line">' + l + '</span>').join('<br/>');
    });
  })();
  (function() {
    // Break text into lines of exactly ~100 characters for elements with .break-100
    const EXACT = 100;
    const targets = document.querySelectorAll('.break-100');
    targets.forEach(el => {
      const hasChildren = Array.from(el.childNodes).some(n => n.nodeType === Node.ELEMENT_NODE);
      if (hasChildren) return;
      const text = (el.textContent || '').trim();
      if (!text) return;
      const words = text.split(/\s+/);
      const lines = [];
      let current = '';
      for (let i = 0; i < words.length; i++) {
        const word = words[i];
        const candidate = current ? current + ' ' + word : word;
        if (candidate.length <= EXACT) {
          current = candidate;
        } else {
          if (current.length === 0) {
            // Very long single word: hard wrap
            lines.push(candidate.slice(0, EXACT));
            current = candidate.slice(EXACT);
          } else {
            lines.push(current);
            current = word;
          }
        }
      }
      if (current) lines.push(current);
      el.innerHTML = lines.map(l => '<span class="line">' + l + '</span>').join('<br/>');
    });
  })();
    </script>
</body>
</html>