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

// Change this if the OCR service runs on a different host/port.
define('OCR_SERVICE_URL', 'http://localhost:5001/ocr');

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

    $curlFile = new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', $originalFileName);

    $ch = curl_init(OCR_SERVICE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $curlFile]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return '[OCR unavailable] Could not reach the OCR service for "' . $originalFileName . '" '
             . '(' . $curlError . '). Is ocr_service/app.py running? See ocr_service/README.md.';
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200 || !isset($decoded['text'])) {
        $errorMsg = $decoded['error'] ?? 'Unexpected response from OCR service.';
        return '[OCR failed] "' . $originalFileName . '": ' . $errorMsg;
    }

    $text = trim($decoded['text']);

    return $text !== '' ? $text : '[OCR produced no text] "' . $originalFileName . '" may be blank, '
                                 . 'very low quality, or in a script Tesseract was not trained on.';
}

