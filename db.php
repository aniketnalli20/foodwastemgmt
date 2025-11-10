<?php
// SQLite database connection and initialization
$dbPath = __DIR__ . '/database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

// Initialize schema if not exists
$pdo->exec('CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_name TEXT NOT NULL,
    contact TEXT,
    item TEXT NOT NULL,
    quantity TEXT NOT NULL,
    category TEXT NOT NULL,
    location TEXT,
    status TEXT NOT NULL DEFAULT "pending",
    created_at TEXT NOT NULL
)');

// Listings posted by donors for NGOs/recipients to claim
$pdo->exec('CREATE TABLE IF NOT EXISTS listings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    donor_type TEXT NOT NULL,            -- Restaurant, Caterer, Individual
    donor_name TEXT NOT NULL,
    contact TEXT,
    item TEXT NOT NULL,
    quantity TEXT NOT NULL,
    category TEXT NOT NULL,
    address TEXT,
    city TEXT,
    pincode TEXT,
    expires_at TEXT,                     -- ISO8601 timestamp
    image_url TEXT,                      -- URL/path to uploaded image
    status TEXT NOT NULL DEFAULT "open", -- open | claimed | expired | closed
    created_at TEXT NOT NULL,
    claimed_at TEXT
)');

// Claims by NGOs/volunteers for a listing
$pdo->exec('CREATE TABLE IF NOT EXISTS claims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    listing_id INTEGER NOT NULL,
    ngo_name TEXT,
    claimer_name TEXT NOT NULL,
    contact TEXT,
    notes TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY(listing_id) REFERENCES listings(id) ON DELETE CASCADE
)');

// Campaigns to coordinate targeted food distribution efforts
$pdo->exec('CREATE TABLE IF NOT EXISTS campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    summary TEXT NOT NULL,
    area TEXT,                     -- e.g., Ghodbunder, Mira Bhayandar
    target_meals INTEGER,          -- numeric goal
    start_date TEXT,               -- ISO8601 date
    end_date TEXT,                 -- ISO8601 date
    status TEXT NOT NULL DEFAULT "draft", -- draft | active | completed | archived
    created_at TEXT NOT NULL
)');

// Extend campaigns with additional fields if they are missing
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN contributor_name TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN location TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN crowd_size INTEGER'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN image_url TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE campaigns ADD COLUMN closing_time TEXT'); } catch (Throwable $e) {}