<?php
require_once __DIR__ . '/app.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FAQs · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
    <link rel="icon" type="image/png" href="<?= h($BASE_PATH) ?>uploads/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= h($BASE_PATH) ?>uploads/favicon.png">
</head>
<body>
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

    <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);" aria-label="FAQs">
        <section class="card-plain">
            <h2 class="section-title">FAQs</h2>
            <div class="faq" id="faqs">
                <details open>
                    <summary><strong>What is No Starve?</strong></summary>
                    <div>No Starve helps users discover nearby available meals and connect safely for convenient access, reducing waste.</div>
                </details>
                <details>
                    <summary><strong>What are Karma Coins?</strong></summary>
                    <div>Karma Coins are rewards earned by contributors based on community support for their campaigns.</div>
                </details>
                <details>
                    <summary><strong>How do I earn Karma Coins?</strong></summary>
                    <div>You receive 1 Karma Coin for every 100 endorsements across your campaigns.</div>
                </details>
                <details>
                    <summary><strong>Where can I see my coins?</strong></summary>
                    <div>Open your <a href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>">Profile</a> to view your current Karma Coins and endorsement totals.</div>
                </details>
                <details>
                    <summary><strong>When are coins updated?</strong></summary>
                    <div>Coins update automatically when you open your Profile based on your latest endorsements.</div>
                </details>
                <details>
                    <summary><strong>How do I create a campaign?</strong></summary>
                    <div>Go to <a href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Create Campaign</a> and publish details such as location, crowd size, and closing time.</div>
                </details>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <div class="footer-inspo">
                <div style="max-width:640px; margin:0 auto; text-align:center;">
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
                            <a class="btn light pill" href="<?= h($BASE_PATH) ?>register.php">Register</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>