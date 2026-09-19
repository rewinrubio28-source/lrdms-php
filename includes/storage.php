<?php
/**
 * File storage for uploaded documents.
 *
 * WHY THIS EXISTS: the app runs in a Docker container on HostForge, and a
 * container's own disk is wiped on every deploy/restart. Anything saved to
 * uploads/ therefore disappears. When the S3_* environment variables below
 * are set, uploads go to an S3-compatible bucket instead (Cloudflare R2,
 * Backblaze B2, Supabase Storage, AWS S3, MinIO ...) and the FULL PUBLIC URL
 * is saved in documents.file_path / document_attachments.file_path. The rest
 * of the app already uses file_path directly as a link/src, so it just works.
 *
 * When the S3_* variables are NOT set (e.g. local XAMPP), it falls back to
 * the old behaviour: files go to uploads/ and file_path is 'uploads/<name>'.
 *
 * Environment variables (HostForge -> Environment Variables):
 *   S3_ENDPOINT    e.g. https://<account-id>.r2.cloudflarestorage.com
 *   S3_BUCKET      bucket name
 *   S3_ACCESS_KEY  access key id
 *   S3_SECRET_KEY  secret access key
 *   S3_PUBLIC_URL  base URL where the bucket's files are publicly readable,
 *                  WITHOUT a trailing slash, e.g. https://pub-xxxx.r2.dev
 *   S3_REGION      optional, default "auto" (R2). B2 example: us-west-004
 *
 * No SDK / Composer package is needed: requests are signed with AWS
 * Signature V4 using plain cURL (PHP's curl extension is already installed
 * in the Dockerfile).
 */

require_once __DIR__ . '/../config/env.php';
load_env_file();

/** True when every S3_* variable needed for remote storage is present. */
function storage_enabled() {
    foreach (['S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY', 'S3_PUBLIC_URL'] as $k) {
        if (env_optional($k, '') === '') return false;
    }
    return true;
}

/** True when a stored file_path is a full URL (remote) rather than 'uploads/x'. */
function storage_is_remote($path) {
    return (bool) preg_match('#^https?://#i', (string) $path);
}

function storage_content_type($name, $localPath = null) {
    static $map = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
    ];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (isset($map[$ext])) return $map[$ext];
    if ($localPath && is_file($localPath)) {
        $m = @mime_content_type($localPath);
        if ($m) return $m;
    }
    return 'application/octet-stream';
}

/**
 * Build AWS Signature V4 headers for a request with no query string.
 * Returns the lower-cased header map to send (includes 'host', which curl
 * sets itself, and 'authorization').
 * Verified against the PUT Object example in AWS's SigV4 documentation.
 */
function storage_sigv4_headers($method, $host, $uri, array $headers, $payloadHash, $region, $accessKey, $secretKey, $amzDate) {
    $headers['host'] = $host;
    $headers['x-amz-content-sha256'] = $payloadHash;
    $headers['x-amz-date'] = $amzDate;

    $normalized = [];
    foreach ($headers as $name => $value) {
        $normalized[strtolower($name)] = trim(preg_replace('/\s+/', ' ', (string) $value));
    }
    ksort($normalized);

    $canonicalHeaders = '';
    foreach ($normalized as $name => $value) {
        $canonicalHeaders .= $name . ':' . $value . "\n";
    }
    $signedHeaders = implode(';', array_keys($normalized));

    $canonicalRequest = $method . "\n" . $uri . "\n" . "" . "\n"
        . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

    $date  = substr($amzDate, 0, 8);
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

    $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $normalized['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
    return $normalized;
}

/**
 * Upload a local file to the bucket under $key.
 * Returns the public URL on success, or null on failure (and logs why).
 */
function storage_put($localPath, $key, $contentType) {
    if (!is_file($localPath)) return null;

    $endpoint = rtrim((string) env_optional('S3_ENDPOINT'), '/');
    if (!preg_match('#^https?://#i', $endpoint)) $endpoint = 'https://' . $endpoint;

    $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $host   = parse_url($endpoint, PHP_URL_HOST);
    $port   = parse_url($endpoint, PHP_URL_PORT);
    $base   = rtrim((string) parse_url($endpoint, PHP_URL_PATH), '/');   // e.g. /storage/v1/s3 (Supabase)
    if (!$host) {
        error_log('[storage] S3_ENDPOINT is not a valid URL.');
        return null;
    }
    $hostHeader = $host . ($port ? ':' . $port : '');

    $region    = env_optional('S3_REGION', 'auto');
    $bucket    = env_optional('S3_BUCKET');
    $accessKey = env_optional('S3_ACCESS_KEY');
    $secretKey = env_optional('S3_SECRET_KEY');

    // Path-style addressing: /<bucket>/<key>, each segment URI-encoded once.
    $uri = $base . '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));

    $payloadHash = hash_file('sha256', $localPath);
    $signed = storage_sigv4_headers(
        'PUT', $hostHeader, $uri, ['content-type' => $contentType],
        $payloadHash, $region, $accessKey, $secretKey, gmdate('Ymd\THis\Z')
    );

    $curlHeaders = [];
    foreach ($signed as $name => $value) {
        if ($name === 'host') continue;          // curl derives Host from the URL
        $curlHeaders[] = $name . ': ' . $value;
    }
    $curlHeaders[] = 'Expect:';                  // don't wait for 100-continue

    $fh = fopen($localPath, 'rb');
    if (!$fh) return null;

    $ch = curl_init($scheme . '://' . $hostHeader . $uri);
    curl_setopt_array($ch, [
        CURLOPT_UPLOAD         => true,          // = HTTP PUT with the file body
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => filesize($localPath),
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $response = curl_exec($ch);
    $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($response === false || $http < 200 || $http >= 300) {
        error_log('[storage] Upload of "' . $key . '" failed (HTTP ' . $http . '): '
            . ($err !== '' ? $err : substr(trim(strip_tags((string) $response)), 0, 300)));
        return null;
    }

    return rtrim((string) env_optional('S3_PUBLIC_URL'), '/') . '/' . $key;
}

/**
 * Store one uploaded file and return the value to save in file_path
 * ('uploads/<name>' locally, or a full URL when remote storage is on).
 * Returns null if it could not be stored.
 *
 * $localReadable is set to a local path the file can be read from right now
 * (e.g. to run OCR on it): the moved file locally, or the PHP temp upload
 * when remote (that temp file is deleted by PHP at the end of the request).
 */
function storage_store_upload($tmpName, $safeName, &$localReadable = null) {
    if (storage_enabled()) {
        $url = storage_put($tmpName, 'uploads/' . $safeName, storage_content_type($safeName, $tmpName));
        if ($url === null) return null;
        $localReadable = $tmpName;
        return $url;
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    if (!move_uploaded_file($tmpName, $uploadDir . $safeName)) return null;
    $localReadable = $uploadDir . $safeName;
    return 'uploads/' . $safeName;
}

/**
 * Run OCR on an already-stored file, whether it lives on local disk
 * ('uploads/x.pdf') or in the bucket (full URL). Remote files are downloaded
 * to a temp file first. Returns OCR text or an "[OCR ...]" placeholder message,
 * exactly like ocr_extract().
 */
function storage_run_ocr($path) {
    require_once __DIR__ . '/ocr.php';

    if (!storage_is_remote($path)) {
        return ocr_extract(__DIR__ . '/../' . $path, basename($path));
    }

    $name = basename((string) (parse_url($path, PHP_URL_PATH) ?: $path));
    $tmp  = tempnam(sys_get_temp_dir(), 'lrdms_ocr_');
    $fh   = fopen($tmp, 'wb');
    if (!$fh) return '[OCR failed] Could not create a temp file for "' . $name . '".';

    $ch = curl_init($path);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 100,
    ]);
    $ok   = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($ok === false || $http !== 200) {
        @unlink($tmp);
        return '[OCR failed] Could not download "' . $name . '" from storage (HTTP ' . $http . ').';
    }

    $text = ocr_extract($tmp, $name);
    @unlink($tmp);
    return $text;
}
