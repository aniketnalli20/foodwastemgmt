<?php
require_once __DIR__ . '/app.php';

$next = '';
if (isset($_GET['next'])) {
    $next = trim((string)$_GET['next']);
} elseif (isset($_POST['next'])) {
    $next = trim((string)$_POST['next']);
}

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
                // Persist selected login role (user or contributor)
                $role = strtolower(trim((string)($_POST['role'] ?? 'user')));
                if ($role !== 'contributor') { $role = 'user'; }
                $_SESSION['login_role'] = $role;
                // Auto‑recognize admin
                $st = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
                $st->execute([(int)$user['id']]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                $_SESSION['is_admin'] = ($row && (int)($row['is_admin'] ?? 0) === 1) ? 1 : 0;
                $dest = ($_SESSION['is_admin'] === 1) ? 'admin/index.php' : 'index.php#hero';
                if ($next !== '') {
                    if (preg_match('/^[A-Za-z0-9_\-]+(\.php)?(\?.*)?$/', $next)) {
                        $dest = $next;
                    }
                }
                header('Location: ' . $BASE_PATH . $dest);
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
                  <?php endif; ?>
                </ul>
              </div>
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
                <?php if ($next !== ''): ?>
                    <input type="hidden" name="next" value="<?= h($next) ?>">
                <?php endif; ?>
                <div class="form-field" aria-label="Login Type">
                    <label style="display:block; margin-bottom:8px;">Login As</label>
                    <div class="role-select" style="display:flex; gap:8px; flex-wrap:wrap;">
                        <label class="community-chip" aria-label="No Starve User">
                            <input type="radio" name="role" value="user" checked>
                            <span class="text">No Starve User</span>
                        </label>
                        <label class="community-chip" aria-label="Contributor">
                            <input type="radio" name="role" value="contributor">
                            <span class="text">Contributor</span>
                        </label>
                    </div>
                </div>
                <input placeholder="E-mail" id="email" name="email" type="email" class="input" required />
                <input placeholder="Password" id="password" name="password" type="password" class="input" required />
                <span class="forgot-password"><a href="#">Forgot Password ?</a></span>
                <button type="submit" class="login-button">Sign In</button>
            </form>
            
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <small>&copy; 2025 No Starve</small>
        </div>
    </footer>
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
// Enhance role toggle chips to reflect selection visually
document.addEventListener('DOMContentLoaded', function(){
  var chips = Array.prototype.slice.call(document.querySelectorAll('.role-select .community-chip'));
  chips.forEach(function(chip){
    var input = chip.querySelector('input[type="radio"]');
    if (!input) return;
    function update(){
      chips.forEach(function(c){ c.classList.remove('selected'); });
      if (input.checked) { chip.classList.add('selected'); }
    }
    chip.addEventListener('click', function(){ input.checked = true; update(); });
    input.addEventListener('change', update);
    if (input.checked) { chip.classList.add('selected'); }
  });
});
    </script>
</body>
</html>
</html>