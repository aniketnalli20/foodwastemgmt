<?php
require_once __DIR__ . '/app.php';

$message = '';
$errors = [];

// Ensure uploads folder exists (separate file storage)
$uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
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
                <a href="#publish">Publish</a>
                <a href="#listings">Listings</a>
            </nav>
        </div>
    </header>
<section id="hero" class="hero">
        <div class="wrap">
            <h2 class="hero-title">Rescue surplus food. Feed communities.</h2>
            <p class="hero-sub">A simple platform connecting Indian donors with verified NGOs and volunteers for timely redistribution.</p>
            <div class="hero-actions">
                <a href="#publish" class="btn">Publish a Listing</a>
                <a href="#listings" class="btn secondary">Browse Listings</a>
            </div>
        </div>
    </section>

    <main class="container">
        <section id="publish" class="card">
            <h2>Create Food Listing (Donors)</h2>
            <?php if ($message): ?>
                <div class="alert success"><?= h($message) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert error">
                    <?php foreach ($errors as $err): ?>
                        <div><?= h($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" class="form-grid" autocomplete="off" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_listing" />
                <div class="form-field">
                    <label for="donor_type">Donor Type*</label>
                    <select id="donor_type" name="donor_type" required>
                        <option value="">Select...</option>
                        <option value="Restaurant">Restaurant</option>
                        <option value="Caterer">Caterer</option>
                        <option value="Individual">Individual</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="donor_name">Donor Name*</label>
                    <input type="text" id="donor_name" name="donor_name" required />
                </div>
                <div class="form-field">
                    <label for="contact">Contact (email or phone)</label>
                    <input type="text" id="contact" name="contact" />
                </div>
                <div class="form-field">
                    <label for="item">Item*</label>
                    <input type="text" id="item" name="item" required />
                </div>
                <div class="form-field">
                    <label for="quantity">Quantity*</label>
                    <input type="text" id="quantity" name="quantity" placeholder="e.g., 5 kg, 20 portions" required />
                </div>
                <div class="form-field">
                    <label for="category">Category*</label>
                    <select id="category" name="category" required>
                        <option value="">Select...</option>
                        <option value="Perishable">Perishable</option>
                        <option value="Non-perishable">Non-perishable</option>
                        <option value="Cooked">Cooked</option>
                        <option value="Raw">Raw</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" />
                </div>
                <div class="form-field">
                    <label for="pincode">Pincode</label>
                    <input type="text" id="pincode" name="pincode" />
                </div>
                <div class="form-field full">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" placeholder="Pickup address or area" />
                </div>
                <div class="form-field">
                    <label for="expires_at">Best Before (datetime)</label>
                    <input type="datetime-local" id="expires_at" name="expires_at" />
                </div>
                <div class="form-field">
                    <label for="image">Image (JPG/PNG/WEBP, max 3MB)</label>
                    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" />
                </div>
                <div class="actions">
                    <button type="submit">Publish Listing</button>
                </div>
            </form>
        </section>

        <section id="listings" class="card">
            <h2>Available Listings</h2>
            <form method="get" class="form-grid" style="margin-bottom: 10px;">
                <div class="form-field">
                    <label for="f_city">City</label>
                    <input type="text" id="f_city" name="city" value="<?= h($cityFilter) ?>" />
                </div>
                <div class="form-field">
                    <label for="f_pincode">Pincode</label>
                    <input type="text" id="f_pincode" name="pincode" value="<?= h($pincodeFilter) ?>" />
                </div>
                <div class="form-field">
                    <label for="f_category">Category</label>
                    <select id="f_category" name="category">
                        <option value="">All</option>
                        <option value="Perishable" <?= $categoryFilter==='Perishable'?'selected':'' ?>>Perishable</option>
                        <option value="Non-perishable" <?= $categoryFilter==='Non-perishable'?'selected':'' ?>>Non-perishable</option>
                        <option value="Cooked" <?= $categoryFilter==='Cooked'?'selected':'' ?>>Cooked</option>
                        <option value="Raw" <?= $categoryFilter==='Raw'?'selected':'' ?>>Raw</option>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit">Filter</button>
                </div>
            </form>

            <?php if (!$listings): ?>
                <p class="muted">No listings yet. Publish one above.</p>
            <?php else: ?>
                <ul class="reports">
                    <?php foreach ($listings as $l): 
                        $expiresSoon = $l['expires_at'] && (strtotime($l['expires_at']) - time() < 2*3600) && (strtotime($l['expires_at']) > time());
                        $expired = $l['expires_at'] && (strtotime($l['expires_at']) <= time());
                    ?>
                        <li class="report">
                            <div class="report-main">
                                <?php if ($l['image_url']): ?>
                                    <img src="<?= h($l['image_url']) ?>" alt="<?= h($l['item']) ?>" class="thumb" />
                                <?php endif; ?>
                                <strong><?= h($l['item']) ?></strong>
                                <span class="chip"><?= h($l['category']) ?></span>
                                <span class="muted"><?= h($l['quantity']) ?></span>
                                <?php if ($expired): ?>
                                    <span class="chip danger">Expired</span>
                                <?php elseif ($expiresSoon): ?>
                                    <span class="chip warn">Expiring soon</span>
                                <?php elseif ($l['expires_at']): ?>
                                    <span class="chip">Best before <?= h(date('d M H:i', strtotime($l['expires_at']))) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="report-meta">
                                <span><?= h($l['donor_type']) ?> • <?= h($l['donor_name']) ?></span>
                                <?php if ($l['city']): ?>
                                    <span>• <?= h($l['city']) ?> <?= h($l['pincode']) ?></span>
                                <?php endif; ?>
                                <?php if ($l['address']): ?>
                                    <span>• <?= h($l['address']) ?></span>
                                <?php endif; ?>
                                <span>• Posted <?= h(time_ago($l['created_at'])) ?></span>
                                <span class="status <?= h(strtolower($l['status'])) ?>"><?= h($l['status']) ?></span>
                            </div>
                            <?php if (!$expired && $l['status'] === 'open'): ?>
                            <form method="post" class="form-grid" style="margin-top:8px;">
                                <input type="hidden" name="action" value="claim_listing" />
                                <input type="hidden" name="listing_id" value="<?= h($l['id']) ?>" />
                                <div class="form-field">
                                    <label>NGO Name</label>
                                    <input type="text" name="ngo_name" />
                                </div>
                                <div class="form-field">
                                    <label>Your Name*</label>
                                    <input type="text" name="claimer_name" required />
                                </div>
                                <div class="form-field">
                                    <label>Contact</label>
                                    <input type="text" name="claimer_contact" />
                                </div>
                                <div class="form-field">
                                    <label>Notes</label>
                                    <input type="text" name="notes" />
                                </div>
                                <div class="actions">
                                    <button type="submit">Claim</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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