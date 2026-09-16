# LRDMS OCR microservice (PyTesseract)

PHP has no OCR engine, so real text recognition runs here, in a small
Python/Flask service. `includes/ocr.php` calls this over HTTP on
every upload — same pattern the codebase already documents for the
future BERT-based `semantic_search.php` service.

## 1. Install system dependencies

**Tesseract itself** (the actual OCR engine; pytesseract is just a
Python wrapper around it):

- Ubuntu/Debian: `sudo apt-get install tesseract-ocr`
- macOS: `brew install tesseract`
- Windows: install from
  https://github.com/UB-Mannheim/tesseract/wiki, then point
  `pytesseract.pytesseract.tesseract_cmd` in `app.py` to the
  installed `tesseract.exe`.

**Poppler** (only needed for PDF support, via pdf2image):

- Ubuntu/Debian: `sudo apt-get install poppler-utils`
- macOS: `brew install poppler`
- Windows: download poppler binaries and add the `bin/` folder to PATH.

## 2. Install Python dependencies

```bash
cd ocr_service
python -m venv venv
source venv/bin/activate   # Windows: venv\Scripts\activate
pip install -r requirements.txt
```

## 3. Run the service

```bash
python app.py
```

It listens on `http://localhost:5001`. Check it's alive:

```bash
curl http://localhost:5001/health
```

## 4. How PHP talks to it

`includes/ocr.php`'s `ocr_extract()` posts the uploaded file to
`POST /ocr` as multipart form data (field name `file`) and reads back
`{"text": "..."}`. If the service is down or returns an error, PHP
falls back to a labeled placeholder instead of crashing the upload —
see the try/catch-equivalent logic in `ocr_extract()`.

## 5. Deployment note

In production, run this as a background service (systemd unit,
supervisor, or a small Docker container) instead of a manual
`python app.py`, and update `OCR_SERVICE_URL` in
`includes/ocr.php` if it's not on `localhost:5001`.
