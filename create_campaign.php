<?php
require_once __DIR__ . '/app.php';

// Contributions require login
require_login();

$user = current_user();

$errors = [];
$successId = null;
$footer_note = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = create_campaign($_POST, $_FILES['image'] ?? null);
        $successId = $id;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_feedback') {
    $name = trim((string)($_POST['fb_name'] ?? ''));
    $email = trim((string)($_POST['fb_email'] ?? ''));
    $msg = trim((string)($_POST['fb_message'] ?? ''));
    if ($name !== '' && $msg !== '') {
        $line = gmdate('c') . ' | name=' . str_replace(["\r","\n"], '', $name) . ' | email=' . str_replace(["\r","\n"], '', $email) . ' | ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' | msg=' . str_replace(["\r","\n"], ' ', $msg);
        @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'feedback.txt', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        $footer_note = 'Thanks for your feedback.';
    } else {
        $footer_note = 'Please provide your name and message.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_contact') {
    $email = trim((string)($_POST['ct_email'] ?? ''));
    $subject = trim((string)($_POST['ct_subject'] ?? ''));
    $msg = trim((string)($_POST['ct_message'] ?? ''));
    if ($subject !== '' && $msg !== '') {
        $line = gmdate('c') . ' | subject=' . str_replace(["\r","\n"], '', $subject) . ' | email=' . str_replace(["\r","\n"], '', $email) . ' | ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' | msg=' . str_replace(["\r","\n"], ' ', $msg);
        @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'contact_messages.txt', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        $footer_note = 'Message sent.';
    } else {
        $footer_note = 'Please provide a subject and message.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Campaign · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <!-- Favicon -->
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
                <ul class="navbar-nav">
                  <li class="nav-item"><a class="nav-link<?= $currentPath === 'index.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>index.php#hero">Home</a></li>
                  <li class="nav-item"><a class="nav-link<?= $currentPath === 'profile.php' ? ' active' : '' ?>" href="<?= h(is_logged_in() ? ($BASE_PATH . 'profile.php') : ($BASE_PATH . 'login.php?next=profile.php')) ?>">Profile</a></li>
                  <li class="nav-item"><a class="nav-link active" href="<?= h(is_logged_in() ? ($BASE_PATH . 'create_campaign.php') : ($BASE_PATH . 'login.php?next=create_campaign.php')) ?>">Create Campaign</a></li>
                  <?php if (is_admin()): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>admin/index.php">Admin Tools</a></li>
                  <?php endif; ?>
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

    <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);">
        <section class="card-plain card-fullbleed create-page" aria-label="Create Campaign">
            <h2 class="section-title">Create Campaign</h2>

            <?php if (!empty($errors)): ?>
                <div class="card-plain" role="alert">
                    <strong>Error:</strong>
                    <ul class="list-clean">
                        <?php foreach ($errors as $err): ?>
                            <li><?= h($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($successId): ?>
                <div class="card-plain" role="status">
                    Campaign created successfully. ID: <?= h((string)$successId) ?>
                    <div class="actions" style="margin-top:8px;">
                        <a class="btn pill" href="<?= h($BASE_PATH) ?>index.php?created=<?= (int)$successId ?>#campaign-<?= (int)$successId ?>">View on Homepage</a>
                        <a class="btn pill" href="<?= h($BASE_PATH) ?>index.php?created=<?= (int)$successId ?>&endorse=campaign#campaign-<?= (int)$successId ?>">Endorse Now</a>
                        <a class="btn pill" href="<?= h($BASE_PATH) ?>index.php?created=<?= (int)$successId ?>&share=1#campaign-<?= (int)$successId ?>">Share Link</a>
                    </div>
                </div>
            <?php endif; ?>

            <form class="form-grid" method="post" enctype="multipart/form-data">
                <!-- Category field removed -->
                <div class="form-field">
                    <label for="contributor_name">Contributor Name</label>
                    <input type="text" id="contributor_name" name="contributor_name" required value="<?= h($user['username'] ?? '') ?>">
                </div>

                

                <div class="form-field">
                    <label for="crowd_size">Crowd Size</label>
                    <input type="number" id="crowd_size" name="crowd_size" min="0" step="1" required>
                </div>

                <div class="form-field">
                    <label for="closing_time">Closing Time</label>
                    <input type="datetime-local" id="closing_time" name="closing_time" required>
                </div>

                <div class="form-field">
                    <label for="image">Upload Image</label>
                    <input type="file" id="image" name="image" accept="image/*">
                </div>

                <div class="form-field">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" required autocomplete="off" aria-autocomplete="list" aria-controls="location-suggestions" value="<?= h($user['address'] ?? '') ?>">
                    <input type="hidden" id="latitude" name="latitude">
                    <input type="hidden" id="longitude" name="longitude">
                    <div id="location-suggestions" class="card-plain" role="listbox" style="position: absolute; z-index: 10; display: none; max-height: 220px; overflow: auto;"></div>
                    <div class="actions" style="margin-top: 8px; justify-content: flex-start;">
                        <button id="use-my-location" type="button">Use My Location</button>
                    </div>
                    <div id="map" class="card-plain" style="width: 100%; aspect-ratio: 1 / 1; max-width: 360px; margin-top: 12px;"></div>
                </div>

                <!-- Bottom-left submit button as the final form row -->
                <div class="form-field">
                    <div class="actions" style="margin-top: 10px; justify-content: flex-start;">
                        <button class="btn btn-bhargav" type="submit"><span>Create Campaign</span></button>
                    </div>
                </div>

            </form>
        </section>
    </main>

    <script>
    (function(){
        const input = document.getElementById('location');
        const box = document.getElementById('location-suggestions');
        const latEl = document.getElementById('latitude');
        const lonEl = document.getElementById('longitude');
        let timer = null;

        function hideSuggestions(){ box.style.display = 'none'; box.innerHTML=''; }

        function renderSuggestions(items){
            if (!items || items.length === 0) { hideSuggestions(); return; }
            box.innerHTML = '';
            items.forEach(function(it, idx){
                const opt = document.createElement('div');
                opt.setAttribute('role','option');
                opt.textContent = it.label || (it.lat + ',' + it.lon);
                opt.style.padding = '8px 10px';
                opt.style.cursor = 'pointer';
                opt.addEventListener('mousedown', function(e){ e.preventDefault(); });
                opt.addEventListener('click', function(){
                    input.value = it.label;
                    latEl.value = it.lat || '';
                    lonEl.value = it.lon || '';
                    hideSuggestions();
                });
                box.appendChild(opt);
            });
            const rect = input.getBoundingClientRect();
            box.style.width = rect.width + 'px';
            box.style.display = 'block';
        }

        input.addEventListener('input', function(){
            latEl.value = '';
            lonEl.value = '';
            const q = input.value.trim();
            if (timer) clearTimeout(timer);
            if (!q) { hideSuggestions(); return; }
            timer = setTimeout(function(){
                fetch((window.BASE_PATH || '<?= h($BASE_PATH) ?>') + 'geocode.php?q=' + encodeURIComponent(q))
                  .then(function(r){ return r.json(); })
                  .then(function(json){ renderSuggestions(json.results || []); })
                  .catch(function(){ hideSuggestions(); });
            }, 250);
        });

        document.addEventListener('click', function(e){
            if (e.target !== input && !box.contains(e.target)) hideSuggestions();
        });
    })();
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function(){
        // Community chips selection visual state
        var chips = Array.prototype.slice.call(document.querySelectorAll('.community-chip'));
        function refreshSelection(){
            chips.forEach(function(ch){
                var input = ch.querySelector('input[type="radio"]');
                if (input && input.checked) ch.classList.add('selected'); else ch.classList.remove('selected');
            });
        }
        chips.forEach(function(ch){
            var input = ch.querySelector('input[type="radio"]');
            if (input){ input.addEventListener('change', refreshSelection); }
        });
        refreshSelection();

        if (!window.L) return;
        const latEl = document.getElementById('latitude');
        const lonEl = document.getElementById('longitude');
        const locInput = document.getElementById('location');
        const useBtn = document.getElementById('use-my-location');
        const map = L.map('map').setView([20.5937, 78.9629], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        let markerLayer = null;

        function setPosition(lat, lon, label){
            if (markerLayer) { map.removeLayer(markerLayer); }
            markerLayer = L.layerGroup().addTo(map);

            // Outer ring for contrast
            L.circleMarker([lat, lon], {
                radius: 14,
                color: '#ffffff',
                weight: 4,
                opacity: 0.95,
                fillOpacity: 0
            }).addTo(markerLayer);

            // Inner dot: bold, high-contrast
            L.circleMarker([lat, lon], {
                radius: 10,
                color: '#0a62ff',
                weight: 2,
                fillColor: '#1a7aff',
                fillOpacity: 0.95
            }).addTo(markerLayer).bindPopup(label || 'Selected location').openPopup();

            map.setView([lat, lon], 17);
            latEl.value = lat;
            lonEl.value = lon;
            if (label) {
                locInput.value = label;
            } else {
                fetch((window.BASE_PATH || '<?= h($BASE_PATH) ?>') + 'geocode.php?lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon))
                  .then(function(r){ return r.json(); })
                  .then(function(j){ if (j && j.label) locInput.value = j.label; })
                  .catch(function(){});
            }
        }

        map.on('click', function(e){ setPosition(e.latlng.lat, e.latlng.lng); });
        // Ensure map resizes correctly when container size changes
        setTimeout(function(){ map.invalidateSize(); }, 200);
        window.addEventListener('resize', function(){ map.invalidateSize(); });

        if (useBtn) {
            useBtn.addEventListener('click', function(){
                function fallbackIpLocation(){
                    fetch('https://ipapi.co/json/')
                      .then(function(r){ return r.json(); })
                      .then(function(j){
                          if (j && j.latitude && j.longitude) {
                              var label = [j.city, j.region, j.country_name].filter(Boolean).join(', ');
                              setPosition(parseFloat(j.latitude), parseFloat(j.longitude), label || null);
                          } else {
                              alert('Unable to retrieve location. Please allow location access, click on the map, or type your city.');
                          }
                      })
                      .catch(function(){
                          alert('Unable to retrieve location. Please allow location access, click on the map, or type your city.');
                      });
                }

                if (!navigator.geolocation) { fallbackIpLocation(); return; }
                navigator.geolocation.getCurrentPosition(function(pos){
                    setPosition(pos.coords.latitude, pos.coords.longitude);
                }, function(err){
                    try {
                        if (navigator.permissions && navigator.permissions.query) {
                            navigator.permissions.query({ name: 'geolocation' }).then(function(result){
                                if (result.state === 'denied') {
                                    alert('Location permission denied. Please allow access, click on the map, or type your city.');
                                } else {
                                    fallbackIpLocation();
                                }
                            }).catch(function(){ fallbackIpLocation(); });
                        } else {
                            fallbackIpLocation();
                        }
                    } catch (e) {
                        fallbackIpLocation();
                    }
                }, { enableHighAccuracy: true, timeout: 8000 });
            });
        }

        // If autocomplete has already set lat/lon, center the map there
        var initLat = parseFloat(latEl.value);
        var initLon = parseFloat(lonEl.value);
        if (!isNaN(initLat) && !isNaN(initLon)) {
            setPosition(initLat, initLon, locInput.value || null);
        }
    })();
    </script>
    <script>
      // Expose BASE_PATH to JS for building internal requests correctly under subfolder or vhost
      window.BASE_PATH = '<?= h($BASE_PATH) ?>';
    </script>

    <footer class="site-footer">
        <div class="container footer-inner">
            <div class="footer-inspo">
                <div style="margin:0; text-align:left; width:100%;">
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
                            <a class="btn light pill" href="<?= h($BASE_PATH) ?>login.php?tab=register">Register</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>