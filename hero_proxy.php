<?php
// Public endpoint to serve the hero image, even if it lives outside the web root.
// Reads path from config and streams bytes with the appropriate content type.

require_once __DIR__ . '/config.php';

// Resolve path: prefer configured path; fallback to uploads/hero.*
$path = null;
if (!empty($HERO_IMAGE_PATH) && is_file($HERO_IMAGE_PATH)) {
    $path = $HERO_IMAGE_PATH;
} else {
    foreach (['hero.jpg', 'hero.png', 'hero.webp'] as $name) {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) { $path = $candidate; break; }
    }
}

if (!$path || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Hero image not found';
    exit;
}

// Detect mime
$mime = @mime_content_type($path) ?: '';
if ($mime === '') {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

// Cache headers
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($path));

// Stream file
$fp = fopen($path, 'rb');
if ($fp) {
    fpassthru($fp);
    fclose($fp);
} else {
    // Fallback to readfile
    readfile($path);
}