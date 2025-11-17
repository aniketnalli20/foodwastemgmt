<?php
require_once __DIR__ . '/app.php';

$next = '';
if (isset($_GET['next'])) {
    $next = trim((string)$_GET['next']);
} elseif (isset($_POST['next'])) {
    $next = trim((string)$_POST['next']);
}

$error = null; $resetInfo = null; $resetTokenQS = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'login'));
    if ($action === 'register') {
        try {
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['confirm'] ?? '');
            $phone = trim((string)($_POST['phone'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            if ($password !== $confirm) {
                throw new InvalidArgumentException('Password and confirm must match');
            }
            $newId = register_user($username, $email, $password, ($phone !== '' ? $phone : null), ($address !== '' ? $address : null));
            $_SESSION['user_id'] = (int)$newId;
            $_SESSION['login_role'] = 'user';
            $_SESSION['is_admin'] = 0;
            $dest = $next !== '' ? $next : 'index.php#hero';
            // Append registered flag for toast on destination
            if (strpos($dest, '?') === false) { $dest .= '?registered=1'; } else { $dest .= '&registered=1'; }
            header('Location: ' . $BASE_PATH . $dest);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage() ?: 'Registration failed';
        }
    } else if ($action === 'forgot') {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '') { $error = 'Email is required'; }
        else {
            try {
                $st = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
                $st->execute([$email]);
                $u = $st->fetch(PDO::FETCH_ASSOC);
                if (!$u) { $error = 'No account found for this email'; }
                else {
                    $token = create_password_reset_token((int)$u['id'], (string)$u['email']);
                    $link = $BASE_PATH . 'login.php?tab=reset&token=' . urlencode($token);
                    $sent = send_password_reset_email((string)$u['email'], $link);
                    if ($sent) {
                        $resetInfo = 'Reset link sent to your email.';
                    } else {
                        $resetInfo = 'Email sending failed. Use this link to reset: ' . $link;
                    }
                    $resetTokenQS = $token;
                }
            } catch (Throwable $e) { $error = 'Request failed; try again later'; }
        }
    } else if ($action === 'reset') {
        $token = trim((string)($_POST['token'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if ($token === '' || $password === '' || $confirm === '') { $error = 'All fields are required'; }
        else if ($password !== $confirm) { $error = 'Password and confirm must match'; }
        else {
            if (complete_password_reset($token, $password)) {
                $resetInfo = 'Password updated. Please log in.';
            } else {
                $error = 'Reset failed or token expired';
                $resetTokenQS = $token;
            }
        }
    } else {
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
                    $role = strtolower(trim((string)($_POST['role'] ?? 'user')));
                    if ($role !== 'contributor') { $role = 'user'; }
                    $_SESSION['login_role'] = $role;
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
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0" />
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
                    <li class="nav-item"><a class="nav-link active" href="<?= h($BASE_PATH) ?>login.php">Login</a></li>
                  <?php endif; ?>
                </ul>
              </div>
            </nav>
        </div>
    </header>

    <main class="container login-page" style="max-width: var(--content-max); padding: var(--content-pad);">
        <div class="container" style="max-width: 460px;">
            <div class="heading" style="margin: 6px 0 12px; text-align:center; font-weight:700;">Access your account</div>
            <div class="login-tabs" style="display:flex; gap:8px; justify-content:center; margin-bottom:10px;">
              <button type="button" class="tab-btn" data-tab="login">Log In</button>
              <button type="button" class="tab-btn" data-tab="register">Register</button>
            </div>
            <?php if ($error): ?>
                <div class="card-plain" role="alert" style="margin-top:12px;">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>
            <?php $tab = isset($_GET['tab']) ? (string)$_GET['tab'] : ''; $showResetTab = ($tab === 'reset'); ?>
            <form class="form" id="form-login" method="post" action="<?= h($BASE_PATH) ?>login.php" style="<?= $showResetTab ? 'display:none;' : '' ?>">
                <?php if ($next !== ''): ?>
                    <input type="hidden" name="next" value="<?= h($next) ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="login">
                <div class="social-login" style="display:flex; flex-direction:column; gap:10px; margin-top:6px;">
                  <a class="btn-social google" href="<?= h($BASE_PATH) ?>google_login.php<?= $next ? ('?next=' . urlencode($next)) : '' ?>" aria-label="Log in with Google">
                    <span class="material-symbols-outlined" aria-hidden="true">account_circle</span>
                    <span>Log in with Google</span>
                  </a>
                  <a class="btn-social github" href="<?= h($BASE_PATH) ?>github_login.php<?= $next ? ('?next=' . urlencode($next)) : '' ?>" aria-label="Log in with GitHub">
                    <span class="material-symbols-outlined" aria-hidden="true">terminal</span>
                    <span>Log in with GitHub</span>
                  </a>
                </div>
                <div style="text-align:center; margin:10px 0; color:#888;">or</div>
                <div class="form-field" aria-label="Login Type">
                    <label style="display:block; margin-bottom:8px; text-align:center;">Login As</label>
                    <div class="role-select" style="display:flex; gap:8px; flex-wrap:wrap; justify-content:center;">
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
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">mail</span>
                  <input placeholder="Email" id="email" name="email" type="email" class="input" required />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                  <input placeholder="Password" id="password" name="password" type="password" class="input" required />
                </div>
                <span class="forgot-password"><a href="#" id="link-forgot">Forgot Password ?</a></span>
                <button type="submit" class="login-button">Log In</button>
            </form>

            <form class="form" id="form-forgot" method="post" action="<?= h($BASE_PATH) ?>login.php" style="display:none;">
                <input type="hidden" name="action" value="forgot">
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">mail</span>
                  <input placeholder="Email" name="email" type="email" class="input" required />
                </div>
                <div class="muted" style="margin:8px 0;">We will generate a one-time reset link.</div>
                <?php if ($resetInfo): ?><div class="card-plain" style="margin-top:8px;"><?= h($resetInfo) ?></div><?php endif; ?>
                <button type="submit" class="login-button">Send Reset Link</button>
            </form>

            <form class="form" id="form-reset" method="post" action="<?= h($BASE_PATH) ?>login.php" style="display:<?= $showResetTab ? 'block' : 'none' ?>; margin-top:10px;">
                <input type="hidden" name="action" value="reset">
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">key</span>
                  <input placeholder="Token" name="token" type="text" class="input" required value="<?= h($resetTokenQS) ?>" />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                  <input placeholder="New Password" name="password" type="password" class="input" required minlength="6" />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                  <input placeholder="Confirm Password" name="confirm" type="password" class="input" required minlength="6" />
                </div>
                <button type="submit" class="login-button">Update Password</button>
            </form>

            <form class="form" id="form-register" method="post" action="<?= h($BASE_PATH) ?>login.php" style="display:none;">
                <?php if ($next !== ''): ?>
                    <input type="hidden" name="next" value="<?= h($next) ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="register">
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">person</span>
                  <input placeholder="Username" id="r_username" name="username" type="text" class="input" required />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">mail</span>
                  <input placeholder="Email" id="r_email" name="email" type="email" class="input" required />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                  <input placeholder="Password" id="r_password" name="password" type="password" class="input" required minlength="6" />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                  <input placeholder="Confirm Password" id="r_confirm" name="confirm" type="password" class="input" required minlength="6" />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">call</span>
                  <input placeholder="Phone (optional)" id="r_phone" name="phone" type="text" class="input" pattern="[0-9+\-\s]{7,30}" />
                </div>
                <div class="input-with-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">home</span>
                  <textarea placeholder="Address (optional)" id="r_address" name="address" class="input" rows="3" style="padding-left:42px;"></textarea>
                </div>
                <button type="submit" class="login-button">Register</button>
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
      var linkForgot = document.getElementById('link-forgot');
      var formLogin = document.getElementById('form-login');
      var formForgot = document.getElementById('form-forgot');
      var formReset = document.getElementById('form-reset');
      if (linkForgot) { linkForgot.addEventListener('click', function(ev){ ev.preventDefault(); if (formLogin) formLogin.style.display='none'; if (formForgot) formForgot.style.display='block'; if (formReset) formReset.style.display='none'; }); }
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
    <script>
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
      // Tabs
      var tabs = Array.prototype.slice.call(document.querySelectorAll('.login-tabs .tab-btn'));
      var formLogin = document.getElementById('form-login');
      var formRegister = document.getElementById('form-register');
      function show(which){
        if (!formLogin || !formRegister) return;
        if (which === 'register') { formRegister.style.display = 'block'; formLogin.style.display = 'none'; }
        else { formRegister.style.display = 'none'; formLogin.style.display = 'block'; }
        tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-tab') === which); });
      }
      tabs.forEach(function(t){ t.addEventListener('click', function(){ show(t.getAttribute('data-tab')); }); });
      var params = new URLSearchParams(window.location.search || '');
      var tab = params.get('tab') || (window.location.hash ? window.location.hash.replace('#','') : '');
      if (tab !== 'register' && tab !== 'login') tab = 'login';
      show(tab);
    });
    </script>
</body>
</html>