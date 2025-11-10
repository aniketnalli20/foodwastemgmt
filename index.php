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
                <a href="<?= h($BASE_PATH) ?>create_campaign.php"<?= $currentPath === 'create_campaign.php' ? ' class="active"' : '' ?>>Create Campaign</a>
                <a href="<?= h($BASE_PATH) ?>communityns.php"<?= $currentPath === 'communityns.php' ? ' class="active"' : '' ?>>Community</a>
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
        <!-- Core content: mission and process -->
        <section id="content" class="content-grid" aria-label="Core content">
        <section id="mission" class="card-plain is-highlight card-horizontal" aria-label="Our Mission">
          <h2 class="section-title">Our Mission</h2>
          <p>At No Starve, our mission is to eliminate hunger and minimize food waste across India by creating a seamless network that connects surplus food donors with verified NGOs and passionate volunteers. We facilitate the efficient, safe, and timely redistribution of food, ensuring that surplus meals reach those who need them most in Ghodbunder, Mira Bhayandar, Maharashtra 401107. Together, we aim to build stronger, healthier communities and foster a culture of responsible food sharing.</p>
        </section>

        <section id="how" class="card-plain is-highlight card-horizontal" aria-label="How No Starve Works">
          <h2 class="section-title">How No Starve Works</h2>
          <div class="steps-grid">
            <div class="step">
              <h3><span class="title-icon" aria-hidden="true">🍱</span> Food Donation Made Easy</h3>
              <p>Donors—restaurants, caterers, event organizers, and individuals—can quickly register and list their surplus food using our user-friendly platform.</p>
            </div>
            <div class="step">
              <h3><span class="title-icon" aria-hidden="true">📍</span> Smart Matching</h3>
              <p>Our system instantly matches available food donations with verified NGOs and volunteers nearby, optimizing routes and timing through location-based technology in Ghodbunder, Mira Bhayandar, Maharashtra 401107.</p>
            </div>
            <div class="step">
              <h3><span class="title-icon" aria-hidden="true">🚚</span> Safe Pickup and Delivery</h3>
              <p>Trained volunteers manage the transportation of food, following strict hygiene and safety protocols to maintain quality.</p>
            </div>
            <div class="step">
              <h3><span class="title-icon" aria-hidden="true">🍽️</span> Feeding Communities</h3>
              <p>The donated food is distributed immediately to local shelters, orphanages, and underprivileged families, ensuring that no food goes to waste and more people gain access to nutritious meals.</p>
            </div>
          </div>
        </section>

        <section id="why" class="card-plain is-highlight card-horizontal" aria-label="Why This Matters">
          <h2 class="section-title">Why This Matters</h2>
          <p>In India, an alarming amount of edible food is wasted every day while millions suffer from hunger and malnutrition. This disconnect not only wastes important resources but also harms the environment through excess food decay and greenhouse gas emissions. No Starve addresses this dual challenge by rescuing surplus food and redirecting it where it’s needed, combating both hunger and environmental harm in a sustainable, community-driven way.</p>
        </section>

        <section id="pillars" class="card-plain is-highlight card-horizontal" aria-label="Our Pillars of Impact">
          <h2 class="section-title">Our Pillars of Impact</h2>
          <div class="pillars-grid">
            <div class="pillar"><h3><span class="title-icon" aria-hidden="true">♻️</span> Food Waste Reduction</h3><p>Minimizing daily food waste through efficient collection and redistribution.</p></div>
            <div class="pillar"><h3><span class="title-icon" aria-hidden="true">🍛</span> Hunger Alleviation</h3><p>Supporting vulnerable populations with reliable food access.</p></div>
            <div class="pillar"><h3><span class="title-icon" aria-hidden="true">👥</span> Community Empowerment</h3><p>Creating a network of dedicated volunteers and NGOs working in unison.</p></div>
            <div class="pillar"><h3><span class="title-icon" aria-hidden="true">📣</span> Awareness & Education</h3><p>Promoting responsible food habits and spreading knowledge about food safety and sustainability.</p></div>
          </div>
        </section>

        <section id="help" class="card-plain is-highlight card-horizontal" aria-label="How You Can Help">
          <h2 class="section-title">How You Can Help</h2>
          <ul class="list-bullets">
            <li><strong>Become a Donor:</strong> Help us by listing your extra food items; your small effort can feed many.</li>
            <li><strong>Volunteer Your Time:</strong> Join our team to collect and deliver food to those in need.</li>
            <li><strong>Partner with Us:</strong> Organizations and businesses can collaborate to scale our outreach and impact.</li>
            <li><strong>Spread the Word:</strong> Share our mission and encourage others to participate.</li>
          </ul>
        </section>

        <section id="stories" class="card-plain is-highlight card-horizontal" aria-label="Success Stories">
          <h2 class="section-title">Success Stories</h2>
          <p>Since our inception, No Starve has redistributed over 250,000 meals across 15 cities and towns, including urban and rural areas in Maharashtra and beyond. Each meal represents a step towards a waste-free and hunger-free India. Stories from our donors, volunteers, and recipients inspire us daily and strengthen our resolve to grow further.</p>
        </section>

        <section id="join" class="card-plain is-highlight card-horizontal" aria-label="Join No Starve Today">
          <h2 class="section-title">Join No Starve Today</h2>
          <p>Take part in transforming the future of food in India. Together, let’s ensure that no meal goes wasted and no one goes hungry.</p>
        </section>
        </section>

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
        <div class="wrap">
            
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