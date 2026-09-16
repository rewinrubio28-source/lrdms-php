<?php
/**
 * Environment variable loader.
 *
 * In production (HostForge, Docker, etc.) the platform injects real
 * environment variables into the PHP process — getenv() sees them
 * directly and no .env file exists there.
 *
 * For local XAMPP development, copy .env.example to .env in the project
 * root and fill in real values. load_env_file() reads that file (if
 * present) and exposes each key via putenv(), without overwriting a
 * variable the real environment already provides.
 */

function load_env_file() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $path = __DIR__ . '/../.env';
    if (!is_file($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

/**
 * Fetch a required env var. Fails loudly and immediately — rather than
 * running with an empty/wrong credential — if it's missing everywhere
 * (no .env locally, not set in HostForge's Environment Variables tab).
 */
function env_required($key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        http_response_code(500);
        die(
            "Missing required environment variable: $key. " .
            "Set it in your local .env file, or in HostForge's " .
            "Environment Variables tab for production."
        );
    }
    return $value;
}

/** Fetch an optional env var, or a sensible default (e.g. for local XAMPP). */
function env_optional($key, $default = null) {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}
