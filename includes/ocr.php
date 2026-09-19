<?php
/**
 * OCR extraction.
 *
 * PHP has no built-in OCR engine, so the actual text recognition runs
 * in a small Python/Flask microservice (ocr_service/app.py) built on
 * PyTesseract. This function POSTs the uploaded file to that service
 * and returns the recognized text - the same "call a small Python
 * service over HTTP" pattern documented for the future BERT-backed
 * semantic_search() in includes/semantic_search.php.
 *
 * If the service is unreachable or errors out, this falls back to a
 * labeled placeholder instead of failing the whole upload, so the
 * rest of the pipeline (storage, indexing, keyword search) keeps
 * working even when the OCR service happens to be down.
 *
 * .docx files are NOT sent here. They're already a text-based format
 * rather than a scanned image, so they don't need image OCR - extract
 * their text with a library like PHPWord if that's ever needed.
 *
 * See ocr_service/README.md for how to install and run the service.
 */

require_once __DIR__ . '/../config/env.php';
load_env_file();

// Where the OCR microservice (ocr_service/app.py) lives. In production set the
// OCR_SERVICE_URL environment variable (HostForge -> Environment Variables, or
// your local .env) to the deployed service, e.g. https://ocr.example.com - the
// "/ocr" path is added automatically if it's missing. With no variable set it
// falls back to the docker-compose hostname, so existing setups keep working.
$__ocrUrl = rtrim((string) env_optional('OCR_SERVICE_URL', 'http://ocr_service/ocr'), '/');
if (substr($__ocrUrl, -4) !== '/ocr') {
    $__ocrUrl .= '/ocr';
}
define('OCR_SERVICE_URL', $__ocrUrl);
unset($__ocrUrl);

/**
 * Run real OCR on a file already saved to disk.
 *
 * @param string $filePath         Full filesystem path to the uploaded file.
 * @param string $originalFileName The name of the file as uploaded (used in
 *                                  fallback messages and for the extension).
 * @return string
 */
function ocr_extract($filePath, $originalFileName) {
    $ext = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));

    // Only image/PDF files go through OCR; .docx is text already.
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'pdf'], true)) {
        return '[OCR skipped] "' . $originalFileName . '" is a .' . $ext . ' file - '
             . 'not an image or PDF, so no OCR is needed for it.';
    }

    if (!is_file($filePath)) {
        return '[OCR error] File not found on disk: ' . $originalFileName;
    }

    $noText = '[OCR produced no text] "' . $originalFileName . '" may be blank, '
            . 'very low quality, or in a script Tesseract was not trained on.';

    if ($ext !== 'pdf') {
        $r = ocr_service_request($filePath, $originalFileName);
        if (!$r['ok']) return $r['message'];
        return $r['text'] !== '' ? $r['text'] : $noText;
    }

    // PDFs: ask the service for a few pages at a time (default 1) instead of
    // the whole file in one call. A multi-page scan in a single request can
    // outlive the hosting gateway's timeout and come back as
    // "503 Gateway timeout"; short per-page calls do not. If the service is an
    // older version that ignores the page range, it just answers with the whole
    // document and no total_pages, and the loop ends after one call.
    $chunk = max(1, (int) env_optional('OCR_PAGES_PER_REQUEST', 1));
    $parts = [];
    $first = 1;
    do {
        $last = $first + $chunk - 1;
        $r = ocr_service_request($filePath, $originalFileName, ['first_page' => $first, 'last_page' => $last]);
        if (!$r['ok']) {
            // Never hand back half a document as if it were complete.
            return $r['message'] . ' (stopped at page ' . $first . ')';
        }
        if ($r['text'] !== '') $parts[] = $r['text'];
        $total = $r['total_pages'];
        $first = $last + 1;
    } while ($total !== null && $first <= $total);

    $text = trim(implode("\n\n", $parts));
    return $text !== '' ? $text : $noText;
}

/**
 * One HTTP call to the OCR microservice.
 * Returns ['ok' => true, 'text' => string, 'total_pages' => int|null]
 *      or ['ok' => false, 'message' => '[OCR ...] ...'].
 */
function ocr_service_request($filePath, $originalFileName, array $extraFields = []) {
    $fields = ['file' => new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', $originalFileName)]
            + $extraFields;

    $ch = curl_init(OCR_SERVICE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // fail fast if the service can't be reached
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);       // per request (one page by default for PDFs)
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => '[OCR unavailable] Could not reach the OCR service for "' . $originalFileName . '" '
             . '(' . $curlError . '). Is ocr_service/app.py running? See ocr_service/README.md.'];
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200 || !isset($decoded['text'])) {
        $errorMsg = $decoded['error'] ?? ('Unexpected response from OCR service (HTTP ' . $httpCode . '): '
                  . substr(trim(strip_tags((string) $response)), 0, 200));
        return ['ok' => false, 'message' => '[OCR failed] "' . $originalFileName . '": ' . $errorMsg];
    }

    return [
        'ok'          => true,
        'text'        => trim($decoded['text']),
        'total_pages' => isset($decoded['total_pages']) ? (int) $decoded['total_pages'] : null,
    ];
}

/**
 * True when ocr_extract() handed back one of its "[OCR ...]" placeholder
 * messages (service unreachable, unsupported type, no text found, ...)
 * instead of real recognized text. Callers that STORE OCR output should
 * check this first so an error message never gets saved as document text.
 */
function ocr_result_is_placeholder($text) {
    return strncmp((string) $text, '[OCR', 4) === 0;
}
