"""
LRDMS OCR microservice.

PHP has no OCR engine of its own, so this small Flask app does the
actual text recognition with PyTesseract and hands the result back to
PHP as JSON. includes/ocr.php calls this service over HTTP the same
way semantic_search.php is documented to call a future BERT service —
same integration pattern, different endpoint.

Supported inputs: PNG, JPG/JPEG (read directly with Pillow) and PDF
(each page is rasterized with pdf2image/poppler, then OCR'd and the
text is joined together). .docx is NOT sent here — it's already a
text-based format, not a scanned image, so PHP should keep it out of
scope for this service (see includes/ocr.php).

Run it locally with:
    pip install -r requirements.txt
    python app.py
It listens on http://localhost:5001 by default. In production (Docker /
hosting platform) it reads the PORT environment variable and is served
by gunicorn (see Dockerfile).
"""

import os
import tempfile

# Tesseract hangs in containers that expose many CPUs (this host reports 12)
# when it spawns one OpenMP thread per CPU. Force a single thread. This must be
# set BEFORE any tesseract subprocess starts; child processes inherit it.
os.environ["OMP_THREAD_LIMIT"] = "1"

from flask import Flask, request, jsonify
from PIL import Image, ImageEnhance, ImageFilter, ImageOps
import pytesseract

app = Flask(__name__)

# Only needed on Windows (local dev). On Linux/Docker, tesseract is on PATH.
if os.name == "nt":
    pytesseract.pytesseract.tesseract_cmd = r"C:\Program Files\Tesseract-OCR\tesseract.exe"

ALLOWED_IMAGE_EXT = {"png", "jpg", "jpeg"}
ALLOWED_PDF_EXT = {"pdf"}

# Tesseract config: English + Filipino/Tagalog
TESSERACT_CONFIG = "--psm 6 --oem 3 -l eng+fil"

# Max seconds Tesseract may spend on ONE image/page. If it hangs, pytesseract
# kills it and raises, so the request fails fast with a clear error instead of
# blocking the worker until the hosting proxy returns a 504.
TESSERACT_TIMEOUT = 50

# PDF rasterizing resolution. 300 is the most accurate but slowest; if a single
# page still takes too long behind the hosting gateway, set OCR_DPI=200 in this
# app's Environment Variables (roughly 2x faster, slightly less accurate).
OCR_DPI = int(os.environ.get("OCR_DPI", "300"))


def preprocess_image(image):
    """Enhance image for better OCR accuracy."""
    # Convert to RGB if needed (handles RGBA, palette, etc.)
    if image.mode not in ("L", "RGB"):
        image = image.convert("RGB")

    # Convert to grayscale
    gray = image.convert("L")

    # Upscale small images (Tesseract works best at 300+ DPI)
    w, h = gray.size
    if w < 1000:
        scale = 1000 / w
        gray = gray.resize((int(w * scale), int(h * scale)), Image.LANCZOS)

    # Increase contrast
    enhancer = ImageEnhance.Contrast(gray)
    gray = enhancer.enhance(1.5)

    # Sharpen
    gray = gray.filter(ImageFilter.SHARPEN)

    # NOTE: a median-filter denoise + hard 128 threshold used to run here.
    # Both are tuned for noisy/uneven scanned paper, but on clean digital
    # images (screenshots, exported PDFs) they erase fine anti-aliased
    # text strokes and drop whole lines instead of helping - confirmed by
    # testing against a real ordinance screenshot. Tesseract's own
    # (Leptonica) internal binarization already handles this better than
    # a flat global threshold does. If real noisy paper scans need extra
    # help later, prefer a milder/adaptive approach over these two.

    return gray


def ocr_image_file(path):
    image = Image.open(path)
    processed = preprocess_image(image)
    return pytesseract.image_to_string(processed, config=TESSERACT_CONFIG, timeout=TESSERACT_TIMEOUT)


def ocr_pdf_file(path, first_page=None, last_page=None):
    """OCR a PDF, optionally only pages first_page..last_page (1-based).

    Returns (text, total_pages). PHP asks for one page (or a few) per request
    so that no single call outlives the hosting gateway's timeout (the 503
    "Gateway timeout" a whole multi-page scan used to hit). With no range it
    behaves as before and reads every page.
    """
    # Imported lazily so the service still starts even if poppler /
    # pdf2image isn't installed, for setups that only need image OCR.
    from pdf2image import convert_from_path, pdfinfo_from_path

    total = int(pdfinfo_from_path(path)["Pages"])
    first = max(1, first_page or 1)
    last = min(total, last_page or total)
    if first > total or first > last:
        return "", total

    # Only the requested pages are rasterized (also keeps RAM use low).
    pages = convert_from_path(path, dpi=OCR_DPI, first_page=first, last_page=last)
    text_parts = []
    for offset, page_image in enumerate(pages):
        processed = preprocess_image(page_image)
        page_text = pytesseract.image_to_string(processed, config=TESSERACT_CONFIG, timeout=TESSERACT_TIMEOUT)
        text_parts.append(f"--- Page {first + offset} ---\n{page_text}")
    return "\n\n".join(text_parts), total


@app.route("/", methods=["GET"])
def index():
    return jsonify({"service": "lrdms-ocr", "status": "ok"})


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"})


@app.route("/ocr", methods=["POST"])
def ocr():
    if "file" not in request.files:
        return jsonify({"error": "No file field in the request."}), 400

    uploaded = request.files["file"]
    original_name = uploaded.filename or ""
    ext = original_name.rsplit(".", 1)[-1].lower() if "." in original_name else ""

    if ext not in ALLOWED_IMAGE_EXT and ext not in ALLOWED_PDF_EXT:
        return jsonify({"error": f"Unsupported file type: .{ext}"}), 422

    with tempfile.NamedTemporaryFile(suffix="." + ext, delete=False) as tmp:
        tmp_path = tmp.name
        uploaded.save(tmp_path)

    def _int_field(name):
        try:
            value = int(request.form.get(name, ""))
            return value if value > 0 else None
        except (TypeError, ValueError):
            return None

    try:
        if ext in ALLOWED_IMAGE_EXT:
            text = ocr_image_file(tmp_path)
            return jsonify({"text": text.strip()})
        text, total_pages = ocr_pdf_file(tmp_path, _int_field("first_page"), _int_field("last_page"))
        return jsonify({"text": text.strip(), "total_pages": total_pages})
    except Exception as exc:  # keep the service alive, report the failure to PHP
        return jsonify({"error": str(exc)}), 500
    finally:
        os.remove(tmp_path)


if __name__ == "__main__":
    # Local dev only. In production, gunicorn serves `app` (see Dockerfile).
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 5001)))
