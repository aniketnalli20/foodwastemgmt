<?php
require_once __DIR__ . '/app.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Links · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
    <link rel="icon" type="image/png" href="<?= h($BASE_PATH) ?>uploads/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= h($BASE_PATH) ?>uploads/favicon.png">
    <style>
      .links-center { max-width: 720px; margin: 0 auto; text-align: center; padding: var(--content-pad); }
      .links-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; justify-items: center; }
      .links-card { background: #ffffff; border: 1px solid #e9edf5; border-radius: 12px; padding: 16px; width: 100%; }
      .links-card h3 { margin: 0 0 10px; font-weight: 800; font-size: 18px; }
      .links-card ul { list-style: none; padding: 0; margin: 0; }
      .links-card li { padding: 6px 0; }
      .links-card a { color: var(--inspoTitle); }
      .brand-center { font-weight: 900; font-size: clamp(1.6rem, 1.6vw + 1.2rem, 2.2rem); letter-spacing: -0.01em; margin-bottom: 14px; }
      .careers-demo { margin-top: 22px; }
      .jobs { display: grid; grid-template-columns: 1fr; gap: 10px; }
      .job { background: linear-gradient(180deg, #ffb161, #ff7a2f); color: #1f2023; border-radius: 12px; padding: 14px; text-align: left; }
      .job h4 { margin: 0 0 6px; font-weight: 800; }
      .job small { display:block; opacity: 0.9; }
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

    <main class="links-center" aria-label="Links Hub">
        <div class="brand-center">No Starve</div>
        <div class="links-grid">
            <section class="links-card" aria-label="Resources">
                <h3>Resources</h3>
                <ul>
                    <li><a href="<?= h($BASE_PATH) ?>index.php#hero">Blog</a></li>
                    <li><a href="<?= h($BASE_PATH) ?>index.php#recent-campaigns">Guides</a></li>
                    <li><a href="<?= h($BASE_PATH) ?>index.php#hero">Help Center</a></li>
                </ul>
            </section>
            <section class="links-card" aria-label="Company">
                <h3>Company</h3>
                <ul>
                    <li><a href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>">About</a></li>
                    <li><a href="#careers-demo">Careers</a></li>
                    <li><a href="<?= h($BASE_PATH) ?>index.php#faqs">FAQs</a></li>
                </ul>
            </section>
        </div>

        <section id="careers-demo" class="careers-demo" aria-label="Careers Demo">
            <div class="links-card">
                <h3>Careers (Demo)</h3>
                <div class="jobs">
                    <div class="job">
                        <h4>Community Outreach Coordinator</h4>
                        <small>Remote · Contract</small>
                        <div>Help connect local partners and promote campaigns that reduce food waste.</div>
                    </div>
                    <div class="job">
                        <h4>Frontend Engineer</h4>
                        <small>Remote · Full‑time</small>
                        <div>Build accessible experiences for discovering nearby meals.</div>
                    </div>
                    <div class="job">
                        <h4>Support Specialist</h4>
                        <small>Remote · Part‑time</small>
                        <div>Assist users and contributors with safe, convenient access to meals.</div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <div class="footer-inspo">
                <div>
                    <div class="footer-brand">No Starve</div>
                    <div class="footer-cols">
                        <div class="footer-col">
                            <h4>Resources</h4>
                            <ul class="footer-links list-clean">
                                <li><a href="<?= h($BASE_PATH) ?>index.php#hero">Blog</a></li>
                                <li><a href="<?= h($BASE_PATH) ?>index.php#recent-campaigns">Guides</a></li>
                                <li><a href="<?= h($BASE_PATH) ?>index.php#hero">Help Center</a></li>
                            </ul>
                        </div>
                        <div class="footer-col">
                            <h4>Company</h4>
                            <ul class="footer-links list-clean">
                                <li><a href="<?= h($BASE_PATH) ?>profile.php">About</a></li>
                                <li><a href="#careers-demo">Careers</a></li>
                                <li><a href="<?= h($BASE_PATH) ?>index.php#faqs">FAQs</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="footer-social">
                        <a href="#" aria-label="Twitter">t</a>
                        <a href="#" aria-label="Instagram">i</a>
                        <a href="#" aria-label="LinkedIn">in</a>
                        <a href="#" aria-label="YouTube">yt</a>
                    </div>
                    <div class="footer-desc">No Starve helps people discover available meals nearby and connect safely for convenient access.</div>
                    <div class="footer-legal">&copy; 2025 No Starve</div>
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
    <script>
    window.BASE_PATH = '<?= h($BASE_PATH) ?>';
    </script>
</body>
</html>