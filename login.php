<?php
require_once __DIR__ . '/app.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        $error = 'Email and password are required';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $error = 'No account found for this email';
            } else if (!password_verify($password, (string)$user['password_hash'])) {
                $error = 'Incorrect password';
            } else {
                $_SESSION['user_id'] = (int)$user['id'];
                header('Location: ' . $BASE_PATH . 'index.php#hero');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Login failed; please try again later';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
</head>
<body>
    <header class="site-header" role="banner">
        <div class="container header-inner">
            <a href="<?= h($BASE_PATH) ?>index.php#hero" class="brand" aria-label="No Starve home">No Starve</a>
            <?php $currentPath = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
            <nav id="primary-navigation" class="nav-links" role="navigation" aria-label="Primary">
                <a href="<?= h($BASE_PATH) ?>index.php#hero"<?= $currentPath === 'index.php' ? ' class="active"' : '' ?>>Home</a>
                <a href="<?= h($BASE_PATH) ?>create_campaign.php"<?= $currentPath === 'create_campaign.php' ? ' class="active"' : '' ?>>Create Campaign</a>
                <a href="<?= h($BASE_PATH) ?>communityns.php"<?= $currentPath === 'communityns.php' ? ' class="active"' : '' ?>>Community</a>
                <?php if (is_logged_in()): ?>
                    <a href="<?= h($BASE_PATH) ?>logout.php">Logout</a>
                <?php else: ?>
                    <a href="<?= h($BASE_PATH) ?>login.php"<?= $currentPath === 'login.php' ? ' class="active"' : '' ?>>Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="container login-page" style="max-width: var(--content-max); padding: var(--content-pad);">
        <div class="container" style="max-width: 350px;">
            <div class="heading">Sign In</div>
            <?php if ($error): ?>
                <div class="card-plain" role="alert" style="margin-top:12px;">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>
            <form class="form" method="post" action="<?= h($BASE_PATH) ?>login.php">
                <input placeholder="E-mail" id="email" name="email" type="email" class="input" required />
                <input placeholder="Password" id="password" name="password" type="password" class="input" required />
                <span class="forgot-password"><a href="#">Forgot Password ?</a></span>
                <button type="submit" class="login-button">Sign In</button>
            </form>
            
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <small>&copy; <?= date('Y') ?> No Starve</small>
        </div>
    </footer>
</body>
</html>