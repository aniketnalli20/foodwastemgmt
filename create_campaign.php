<?php
require_once __DIR__ . '/app.php';

$errors = [];
$successId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = create_campaign($_POST, $_FILES['image'] ?? null);
        $successId = $id;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Campaign · No Starve</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body>
    <header class="site-header" role="banner">
        <div class="container header-inner">
            <a href="/index.php#hero" class="brand" aria-label="No Starve home">No Starve</a>
            <nav id="primary-navigation" class="nav-links" role="navigation" aria-label="Primary">
                <a href="/index.php#hero">Home</a>
                <a href="/create_campaign.php">Create Campaign</a>
            </nav>
        </div>
    </header>

    <main class="container" style="max-width: var(--content-max); padding: var(--content-pad);">
        <section class="card-plain" aria-label="Create Campaign">
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
                </div>
            <?php endif; ?>

            <form class="form-grid" method="post" enctype="multipart/form-data">
                <div class="form-field">
                    <label for="contributor_name">Contributor Name</label>
                    <input type="text" id="contributor_name" name="contributor_name" required>
                </div>

                <div class="form-field">
                    <label for="community">Community Suitable For</label>
                    <input type="text" id="community" name="community" required>
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
                    <input type="text" id="location" name="location" required autocomplete="off" aria-autocomplete="list" aria-controls="location-suggestions">
                    <input type="hidden" id="latitude" name="latitude">
                    <input type="hidden" id="longitude" name="longitude">
                    <div id="location-suggestions" class="card-plain" role="listbox" style="position: absolute; z-index: 10; display: none; max-height: 220px; overflow: auto;"></div>
                    <div class="actions" style="margin-top: 8px;">
                        <button class="btn" id="use-my-location" type="button">Use My Location</button>
                    </div>
                    <div id="map" class="card-plain" style="width: 100%; aspect-ratio: 1 / 1; max-width: 640px; margin-top: 12px;"></div>
                    <div class="actions" style="margin-top: 12px; display: flex; justify-content: flex-start;">
                        <button class="btn" type="submit">Create</button>
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
                fetch('/geocode.php?q=' + encodeURIComponent(q))
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
                fetch('/geocode.php?lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon))
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
                if (!navigator.geolocation) { alert('Geolocation not supported on this device/browser'); return; }
                navigator.geolocation.getCurrentPosition(function(pos){
                    setPosition(pos.coords.latitude, pos.coords.longitude);
                }, function(err){
                    alert('Unable to retrieve location');
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

    <footer class="site-footer">
        <div class="container footer-inner">
            <small>&copy; <?= date('Y') ?> No Starve</small>
        </div>
    </footer>
</body>
</html>