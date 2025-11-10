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

// Resolve hero background image from uploads (jpg/png/webp)
$heroUrl = null;
foreach (['hero.jpg','hero.png','hero.webp'] as $name) {
    $candidate = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (is_file($candidate)) { $heroUrl = 'uploads/' . $name; break; }
}

// Handle create listing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_listing') {
    $donor_type = trim($_POST['donor_type'] ?? '');
    $donor_name = trim($_POST['donor_name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $item = trim($_POST['item'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $expires_at = trim($_POST['expires_at'] ?? '');

    if ($donor_type === '') $errors[] = 'Donor type is required.';
    if ($donor_name === '') $errors[] = 'Donor name is required.';
    if ($item === '') $errors[] = 'Item is required.';
    if ($quantity === '') $errors[] = 'Quantity is required.';
    if ($category === '') $errors[] = 'Category is required.';

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
        $stmt = $pdo->prepare('INSERT INTO listings (donor_type, donor_name, contact, item, quantity, category, address, city, pincode, expires_at, image_url, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $donor_type,
            $donor_name,
            $contact,
            $item,
            $quantity,
            $category,
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
$categoryFilter = trim($_GET['category'] ?? '');

global $pdo;
$where = [];
$params = [];
if ($cityFilter !== '') { $where[] = 'city LIKE ?'; $params[] = '%' . $cityFilter . '%'; }
if ($pincodeFilter !== '') { $where[] = 'pincode LIKE ?'; $params[] = '%' . $pincodeFilter . '%'; }
if ($categoryFilter !== '') { $where[] = 'category = ?'; $params[] = $categoryFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where) . ' AND status = "open"') : 'WHERE status = "open"';
$listingsStmt = $pdo->prepare("SELECT id, donor_type, donor_name, contact, item, quantity, category, address, city, pincode, expires_at, image_url, status, created_at, claimed_at FROM listings $whereSql ORDER BY COALESCE(expires_at, '9999-12-31T00:00:00') ASC, id DESC LIMIT 20");
$listingsStmt->execute($params);
$listings = $listingsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Food Waste Management</title>
    <link rel="stylesheet" href="style.css" />
    <!-- Using local Inter font from /fonts; external font links removed -->
    <meta name="description" content="Connect donors with NGOs to rescue surplus food in India." />
</head>
<body>
    <header class="site-header" role="banner">
        <div class="container header-inner">
            <a href="#hero" class="brand" aria-label="Food Waste Management home">FoodWasteMgmt</a>
            <button class="nav-toggle" aria-controls="primary-navigation" aria-expanded="false" aria-label="Toggle navigation">
                <span class="nav-toggle-bar" aria-hidden="true"></span>
                <span class="nav-toggle-bar" aria-hidden="true"></span>
                <span class="nav-toggle-bar" aria-hidden="true"></span>
            </button>
            <nav id="primary-navigation" class="nav-links" role="navigation" aria-label="Primary">
                <a href="#hero">Home</a>
            </nav>
        </div>
    </header>
<section id="hero" class="hero"<?= $heroUrl ? ' style="--hero-img: url(' . h($heroUrl) . ');"' : '' ?> >
        <div class="wrap">
            <h2 class="hero-title">Help us build hope and homes</h2>
            <p class="hero-sub">together Against Food Waste.</p>
        </div>
    </section>

    <main>
        <!-- Inspo cards layout -->
        <section id="cards" class="cards-frame" aria-label="Featured cards">
          <div class="cards-grid">
            <!-- Left feature card -->
            <article class="card-feature" aria-label="Feature">
              <div class="feature-top">
                <span class="badge-num" aria-hidden="true">9</span>
                <button class="chip-arrow" aria-label="Next">
                  <span class="arrow" aria-hidden="true">→</span>
                </button>
              </div>
              <h3 class="headline">
                Bring your impact projects
                <br>to life with this platform.
              </h3>
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

            <!-- Bottom dark theme card -->
            <article class="card-theme" aria-label="Theme options">
              <small class="eyebrow">2 themes</small>
              <div class="theme-title">Colorful, Black & White</div>
              <div class="theme-art" aria-hidden="true"></div>
            </article>
          </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="wrap">
            <small>&copy; <?= date('Y') ?> Food Waste Management</small>
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
    </script>
</body>
</html>