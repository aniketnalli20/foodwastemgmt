<?php
// Geolocation provider configuration
// Default provider: LocationIQ (free tier). Create a free key at https://locationiq.com/
// Alternatively, set GEO_PROVIDER to 'nominatim' to use OpenStreetMap without a key.

$GEO_PROVIDER = getenv('GEO_PROVIDER') ?: 'locationiq';
$GEO_API_KEY = getenv('GEO_API_KEY') ?: '';

// You can hardcode the key here if environment variables are not set:
// $GEO_API_KEY = 'YOUR_LOCATIONIQ_KEY';

// Database configuration (supports MySQL and PostgreSQL)
// Set DB_DRIVER to 'pgsql' to use PostgreSQL, or 'mysql' to use MySQL.
$DB_DRIVER = getenv('DB_DRIVER') ?: 'pgsql';
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'foodwastemgmt';
$DB_USER = getenv('DB_USER') ?: 'postgres';
$DB_PASS = getenv('DB_PASS') ?: '';
$DB_PORT = getenv('DB_PORT') ?: '5432'; // Default PostgreSQL port
$DB_CHARSET = getenv('DB_CHARSET') ?: 'utf8mb4';

// Admin tooling port (optional, for pgAdmin or similar). pgAdmin 4 desktop often uses 5050 on Windows.
$PGADMIN_PORT = getenv('PGADMIN_PORT') ?: '5050';

// Hero image path (optional, can be outside web root)
// Set via environment HERO_IMAGE_PATH or hardcode below.
// Using requested local file path by default.
$HERO_IMAGE_PATH = getenv('HERO_IMAGE_PATH') ?: 'C:\\Users\\Aniket\\Downloads\\obi-WWPmiP2Wh0Y-unsplash.jpg';