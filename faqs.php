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

    <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);" aria-label="FAQs">
        <section class="card-plain">
            <h2 class="section-title">FAQs</h2>
            <div class="faq">
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
                                <li><a href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Careers</a></li>
                                <li><a href="<?= h($BASE_PATH) ?>faqs.php">FAQs</a></li>
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
</body>
</html>