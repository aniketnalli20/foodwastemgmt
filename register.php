<?php
require_once __DIR__ . '/app.php';

$next = '';
if (isset($_GET['next'])) {
    $next = trim((string)$_GET['next']);
} elseif (isset($_POST['next'])) {
    $next = trim((string)$_POST['next']);
}

$errors = [];
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match';
    }

    if (!$errors) {
        try {
            $userId = register_user($username, $email, $password, $phone !== '' ? $phone : null, $address !== '' ? $address : null);
            $_SESSION['user_id'] = $userId;
            // Redirect after successful registration
            $dest = 'profile.php';
            if ($next !== '' && preg_match('/^[A-Za-z0-9_\-]+(\.php)?(\?.*)?$/', $next)) {
                $dest = $next;
            }
            header('Location: ' . $BASE_PATH . $dest);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
</head>
<body class="page-login">
    <header class="site-header" role="banner">
        <div class="container header-inner">
            <?php $currentPath = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
            <nav class="navbar navbar-expand-lg navbar-light bg-light" role="navigation" aria-label="Primary">
              <a class="navbar-brand" href="<?= h($BASE_PATH) ?>index.php#hero">No Starve</a>
              <button class="navbar-toggler" type="button" aria-controls="primary-navbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon" aria-hidden="true"></span>
              </button>
              <div class="collapse navbar-collapse" id="primary-navbar">
                <ul class="navbar-nav mr-auto">
                  <li class="nav-item"><a class="nav-link<?= $currentPath === 'index.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>index.php#hero">Home</a></li>
                  <li class="nav-item"><a class="nav-link<?= $currentPath === 'profile.php' ? ' active' : '' ?>" href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>">Profile</a></li>
                  <?php if (is_logged_in() && is_admin()): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>admin/index.php">Admin</a></li>
                  <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                  <li class="nav-item"><a class="nav-link<?= $currentPath === 'create_campaign.php' ? ' active' : '' ?>" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Create Campaign</a></li>
                  <?php if (is_logged_in()): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>logout.php">Logout</a></li>
                  <?php else: ?>
                    <li class="nav-item"><a class="nav-link<?= $currentPath === 'login.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>login.php">Login</a></li>
                    <li class="nav-item"><a class="nav-link<?= $currentPath === 'register.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>register.php">Register</a></li>
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

    <main class="container login-page" style="max-width: var(--content-max); padding: var(--content-pad);">
        <div class="container" style="max-width: 420px;">
            <div class="heading">Create Your Account</div>
            <?php if (!empty($errors)): ?>
                <div class="alert error" role="alert" style="margin-top:12px;">
                    <strong>Error:</strong>
                    <ul class="list-clean">
                        <?php foreach ($errors as $err): ?>
                            <li><?= h($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form class="form" method="post" action="<?= h($BASE_PATH) ?>register.php">
                <?php if ($next !== ''): ?>
                    <input type="hidden" name="next" value="<?= h($next) ?>">
                <?php endif; ?>
                <input placeholder="Username" id="username" name="username" type="text" class="input" required />
                <input placeholder="E-mail" id="email" name="email" type="email" class="input" required />
                <input placeholder="Password" id="password" name="password" type="password" class="input" required minlength="6" />
                <input placeholder="Confirm Password" id="confirm" name="confirm" type="password" class="input" required minlength="6" />
                <input placeholder="Phone (optional)" id="phone" name="phone" type="text" class="input" pattern="[0-9+\-\s]{7,30}" />
                <textarea placeholder="Address (optional)" id="address" name="address" class="input" rows="3"></textarea>
                <button type="submit" class="login-button">Register</button>
            </form>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <small>&copy; 2025 No Starve</small>
        </div>
    </footer>
</body>
</html>