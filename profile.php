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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= h($BASE_PATH) ?>uploads/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= h($BASE_PATH) ?>uploads/favicon.png">
    <style>
      .profile-card { max-width: var(--content-max); padding: var(--content-pad); margin: 0 auto; }
      .profile-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 10px; }
      .profile-grid div { padding: 8px 0; border-bottom: 1px solid var(--border); }
      .profile-actions { margin-top: 16px; display: flex; gap: 10px; }
    </style>
    </head>
<body>
    <header class="site-header" role="banner">
        <div class="container header-inner">
            <a href="<?= h($BASE_PATH) ?>index.php#hero" class="brand" aria-label="No Starve home">No Starve</a>
            <?php $currentPath = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
            <nav id="primary-navigation" class="nav-links" role="navigation" aria-label="Primary">
                <a href="<?= h($BASE_PATH) ?>index.php#hero"<?= $currentPath === 'index.php' ? ' class="active"' : '' ?>>Home</a>
                <a href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>"<?= $currentPath === 'create_campaign.php' ? ' class="active"' : '' ?>>Create Campaign</a>
                <a href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>"<?= $currentPath === 'profile.php' ? ' class="active"' : '' ?>>Profile</a>
                <?php if (is_logged_in()): ?>
                    <a href="<?= h($BASE_PATH) ?>logout.php">Logout</a>
                <?php else: ?>
                    <a href="<?= h($BASE_PATH) ?>login.php"<?= $currentPath === 'login.php' ? ' class="active"' : '' ?>>Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="container profile-card" aria-label="My Profile">
        <h2 class="section-title">My Profile</h2>
        <?php if (!empty($errors)): ?>
            <div class="card-plain" role="alert" style="margin:12px 0; padding:12px; border:1px solid var(--border); border-radius:8px;">
                <strong>Error:</strong>
                <ul class="list-clean">
                    <?php foreach ($errors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="card-plain" role="status" style="margin:12px 0; padding:12px; border:1px solid var(--border); border-radius:8px;">
                <?= h($message) ?>
            </div>
        <?php endif; ?>
        <div class="card-plain">
            <div class="profile-grid">
                <div><strong>Username</strong></div>
                <div><?= h($user['username'] ?? '') ?></div>

                <div><strong>Email</strong></div>
                <div><?= h($user['email'] ?? '') ?></div>

                <div><strong>Phone</strong></div>
                <div><?= h($user['phone'] ?? '') ?></div>

                <div><strong>Address</strong></div>
                <div><?= h($user['address'] ?? '') ?></div>

                <div><strong>Member Since</strong></div>
                <div><?= h($user['created_at'] ?? '') ?></div>
            </div>
            <form method="post" action="<?= h($BASE_PATH) ?>profile.php" class="form" style="margin-top:16px;">
                <input type="hidden" name="action" value="update_profile">
                <label for="phone"><strong>Phone</strong></label>
                <input id="phone" name="phone" type="text" class="input" placeholder="Phone" value="<?= h($user['phone'] ?? '') ?>" pattern="[0-9+\-\s]{7,30}" />

                <label for="address" style="margin-top:10px;"><strong>Address</strong></label>
                <textarea id="address" name="address" class="input" placeholder="Address" rows="3" style="resize: vertical;"><?= h($user['address'] ?? '') ?></textarea>

                <div class="profile-actions">
                    <button type="submit" class="btn accent pill">Save Changes</button>
                    <a class="btn pill" href="<?= h($BASE_PATH) ?>index.php#hero">Cancel</a>
                </div>
            </form>
            <div class="profile-actions">
                <a class="btn pill" href="<?= h($BASE_PATH) ?>index.php#hero">Back to Home</a>
                <a class="btn accent pill" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Create Campaign</a>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <small>&copy; 2025 No Starve</small>
        </div>
    </footer>
</body>
</html>