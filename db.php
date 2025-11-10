<?php
require_once __DIR__ . '/config.php';

// MySQL database connection and initialization
try {
    // Ensure database exists
    $bootstrap = new PDO("mysql:host=$DB_HOST;charset=$DB_CHARSET", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $bootstrap->exec("CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET $DB_CHARSET COLLATE utf8mb4_unicode_ci");
    $bootstrap = null;

    // Connect to the app database
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHARSET", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

// Initialize schema if not exists
$pdo->exec('CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=' . $DB_CHARSET);
// Seed demo users if none exist
try {
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)');
        $demoPassword = 'demo1234';
        $stmt->execute(['Donor', 'donor@nostrv.com', password_hash($demoPassword, PASSWORD_DEFAULT), $now]);
        $stmt->execute(['NGO Lead', 'ngo@nostrv.com', password_hash($demoPassword, PASSWORD_DEFAULT), $now]);
        $stmt->execute(['Volunteer', 'volunteer@nostrv.com', password_hash($demoPassword, PASSWORD_DEFAULT), $now]);
    }
} catch (Throwable $e) {
    // Silently ignore seeding errors to avoid breaking page loads
}
$pdo->exec('CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_name VARCHAR(255) NOT NULL,
    contact VARCHAR(255),
    item VARCHAR(255) NOT NULL,
    quantity VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    location TEXT,
    status VARCHAR(50) NOT NULL DEFAULT "pending",
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=' . $DB_CHARSET);

// Listings posted by donors for NGOs/recipients to claim
$pdo->exec('CREATE TABLE IF NOT EXISTS listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donor_type VARCHAR(50) NOT NULL,
    donor_name VARCHAR(255) NOT NULL,
    contact VARCHAR(255),
    item VARCHAR(255) NOT NULL,
    quantity VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    address TEXT,
    city VARCHAR(100),
    pincode VARCHAR(20),
    expires_at DATETIME,
    image_url TEXT,
    status VARCHAR(50) NOT NULL DEFAULT "open",
    created_at DATETIME NOT NULL,
    claimed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=' . $DB_CHARSET);

// Claims by NGOs/volunteers for a listing
$pdo->exec('CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    ngo_name VARCHAR(255),
    claimer_name VARCHAR(255) NOT NULL,
    contact VARCHAR(255),
    notes TEXT,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_claims_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=' . $DB_CHARSET);

// Campaigns to coordinate targeted food distribution efforts
$pdo->exec('CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    summary TEXT NOT NULL,
    area VARCHAR(255),
    target_meals INT,
    start_date DATE,
    end_date DATE,
    status VARCHAR(50) NOT NULL DEFAULT "draft",
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=' . $DB_CHARSET);

// Extend campaigns with additional fields if they are missing
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN contributor_name VARCHAR(255)'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN location TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN crowd_size INT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN image_url TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN closing_time TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN latitude DOUBLE'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN longitude DOUBLE'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN community VARCHAR(255)'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN endorse_campaign INT DEFAULT 0'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN endorse_contributor INT DEFAULT 0'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN category VARCHAR(100)'); } catch (Throwable $e) {}

// Track individual endorsement events for auditing/analytics
$pdo->exec('CREATE TABLE IF NOT EXISTS endorsements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    kind VARCHAR(32) NOT NULL, -- "campaign" or "contributor"
    contributor_name VARCHAR(255) NULL,
    ip VARCHAR(64) NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_endorsements_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=' . $DB_CHARSET);