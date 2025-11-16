<?php
require_once __DIR__ . '/app.php';

// Restrict to logged-in users (avoid public bulk imports)
require_login();

$message = null;
$details = [];

function slugify($str): string {
    $s = strtolower(trim((string)$str));
    // Replace non-alphanumerics with hyphens
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    $s = trim($s, '-');
    return $s ?: 'user';
}

function gen_email(string $name, int $idx = 0): string {
    $slug = slugify($name);
    $suffix = $idx > 0 ? ('.' . $idx) : '';
    return $slug . $suffix . '@nostrv.com';
}

function gen_phone(int $seed = 0): string {
    // Deterministic 10-digit number starting with 900
    $base = 9000000000;
    $num = $base + ($seed % 999999);
    return (string)$num;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sourcePath = '';
        if (isset($_FILES['csv']) && is_array($_FILES['csv']) && isset($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }
            $destName = 'users_' . uniqid('', true) . '.csv';
            $dest = $uploadsDir . DIRECTORY_SEPARATOR . $destName;
            if (!move_uploaded_file($_FILES['csv']['tmp_name'], $dest)) {
                throw new RuntimeException('Failed to save uploaded file');
            }
            $sourcePath = $dest;
        } else {
            // Allow using a server-local path (e.g., uploads/Indian-Name.csv)
            $provided = trim((string)($_POST['source_path'] ?? ''));
            if ($provided !== '') {
                $sourcePath = $provided;
            }
        }

        if ($sourcePath === '') {
            throw new InvalidArgumentException('Please upload a CSV file or provide a valid server path');
        }

        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException('CSV file not found at: ' . h($sourcePath));
        }

        $fp = fopen($sourcePath, 'r');
        if (!$fp) throw new RuntimeException('Unable to open CSV file');

        // Detect header
        $header = fgetcsv($fp);
        if (!$header) throw new RuntimeException('CSV appears to be empty');
        $map = [];
        foreach ($header as $i => $col) {
            $key = strtolower(trim((string)$col));
            $map[$key] = $i;
        }

        $idxName = $map['name'] ?? ($map['full name'] ?? null);
        $idxFirst = $map['first name'] ?? $map['first'] ?? null;
        $idxLast = $map['last name'] ?? $map['last'] ?? null;
        $idxEmail = $map['email'] ?? null;
        $idxPhone = $map['phone'] ?? ($map['mobile'] ?? ($map['contact'] ?? null));
        $idxAddress = $map['address'] ?? ($map['street'] ?? ($map['location'] ?? null));
        $idxUsername = $map['username'] ?? null;
        $idxAdmin = $map['admin'] ?? ($map['is_admin'] ?? ($map['admin_flag'] ?? ($map['target'] ?? null)));

        $inserted = 0;
        $skipped = 0;
        $seenEmails = [];
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO users (username, email, phone, address, password_hash, created_at, is_admin) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), address = VALUES(address), is_admin = VALUES(is_admin)');

        $rowNum = 1; // account for header
        while (($row = fgetcsv($fp)) !== false) {
            $rowNum++;
            // Extract fields with fallbacks
            $first = ($idxFirst !== null && isset($row[$idxFirst])) ? trim((string)$row[$idxFirst]) : '';
            $last = ($idxLast !== null && isset($row[$idxLast])) ? trim((string)$row[$idxLast]) : '';
            $name = ($idxName !== null && isset($row[$idxName])) ? trim((string)$row[$idxName]) : '';
            if ($name === '') {
                $name = trim(($first . ' ' . $last));
            }
            $username = ($idxUsername !== null && isset($row[$idxUsername])) ? trim((string)$row[$idxUsername]) : '';
            if ($username === '') $username = $name ?: ($first ?: 'User');

            $email = ($idxEmail !== null && isset($row[$idxEmail])) ? strtolower(trim((string)$row[$idxEmail])) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Generate deterministic email to avoid collisions
                $i = 0; $candidate = '';
                do { $candidate = gen_email($username, $i); $i++; } while (isset($seenEmails[$candidate]));
                $email = $candidate;
            }

            $seenEmails[$email] = true;
            $phone = ($idxPhone !== null && isset($row[$idxPhone])) ? preg_replace('/\D+/', '', (string)$row[$idxPhone]) : '';
            if ($phone === '') {
                $phone = gen_phone($rowNum);
            }
            $address = ($idxAddress !== null && isset($row[$idxAddress])) ? trim((string)$row[$idxAddress]) : '';
            $isAdmin = 0;
            if ($idxAdmin !== null && isset($row[$idxAdmin])) {
                $raw = strtolower(trim((string)$row[$idxAdmin]));
                if ($raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'y') { $isAdmin = 1; }
            }
            $passwordHash = password_hash('demo1234', PASSWORD_DEFAULT);

            try {
                $stmt->execute([$username, $email, $phone, $address, $passwordHash, $now, $isAdmin]);
                // Log each imported user entry to a text file
                log_user_entry($username, $email, $phone, $address, 'import');
                $affected = $stmt->rowCount();
                // On duplicate key MySQL reports 2; count as skipped when no insert happened
                if ($affected === 1) { $inserted++; } else { $skipped++; }
            } catch (Throwable $e) {
                $details[] = 'Row ' . $rowNum . ' failed: ' . $e->getMessage();
                $skipped++;
            }
        }
        fclose($fp);

        $message = 'Import complete: inserted ' . $inserted . ', skipped ' . $skipped . ' (duplicates or errors).';
    } catch (Throwable $e) {
        $message = 'Import failed: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Users · No Starve</title>
    <link rel="stylesheet" href="<?= h($BASE_PATH) ?>style.css">
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
                  <li class="nav-item"><a class="nav-link" href="<?= h($BASE_PATH) ?>index.php#hero">Home</a></li>
                  <li class="nav-item"><a class="nav-link<?= $currentPath === 'profile.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>profile.php">Profile</a></li>
                  <li class="nav-item"><a class="nav-link<?= $currentPath === 'create_campaign.php' ? ' active' : '' ?>" href="<?= h($BASE_PATH) ?>create_campaign.php">Create Campaign</a></li>
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
        <h1>Import Users from CSV</h1>
        <p>Upload your CSV or provide a server path (e.g., <code>uploads/Indian-Name.csv</code>). Each row should include <code>Name</code> or <code>First Name</code>/<code>Last Name</code>, and optionally <code>Email</code>, <code>Phone</code>, and <code>Address</code>. Missing emails and phones will be generated.</p>

        <?php if ($message): ?>
            <div class="card-plain" role="alert" style="margin:12px 0; padding:12px; border:1px solid var(--border); border-radius:8px;">
                <?= h($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($details): ?>
            <ul class="list-clean" style="margin:8px 0;">
                <?php foreach ($details as $d): ?>
                    <li><?= h($d) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form" style="margin-top: 16px;">
            <div style="margin-bottom:10px;">
                <label for="csv">CSV file</label><br>
                <input id="csv" name="csv" type="file" accept=".csv,text/csv">
            </div>
            <div style="margin-bottom:10px;">
                <label for="source_path">Or server path</label><br>
                <input id="source_path" name="source_path" type="text" placeholder="uploads/Indian-Name.csv" style="width:100%; max-width:480px;">
            </div>
            <button type="submit" class="btn">Import</button>
        </form>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <small>&copy; 2025 No Starve</small>
        </div>
    </footer>
</body>
</html>