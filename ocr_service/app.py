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

Run it with:
    pip install -r requirements.txt
    python app.py
It listens on http://localhost:5001 by default.
"""

import os
import tempfile

from flask import Flask, request, jsonify
from PIL import Image, ImageEnhance, ImageFilter, ImageOps
import pytesseract

app = Flask(__name__)

# On Windows, or if tesseract isn't on PATH, uncomment and set this:
pytesseract.pytesseract.tesseract_cmd = r"C:\Program Files\Tesseract-OCR\tesseract.exe"

ALLOWED_IMAGE_EXT = {"png", "jpg", "jpeg"}
ALLOWED_PDF_EXT = {"pdf"}

# Tesseract config: English + Filipino/Tagalog
TESSERACT_CONFIG = "--psm 6 --oem 3 -l eng+fil"


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
    return pytesseract.image_to_string(processed, config=TESSERACT_CONFIG)


def ocr_pdf_file(path):
    # Imported lazily so the service still starts even if poppler /
    # pdf2image isn't installed, for setups that only need image OCR.
    from pdf2image import convert_from_path

    pages = convert_from_path(path, dpi=300)
    text_parts = []
    for i, page_image in enumerate(pages, start=1):
        processed = preprocess_image(page_image)
        page_text = pytesseract.image_to_string(processed, config=TESSERACT_CONFIG)
        text_parts.append(f"--- Page {i} ---\n{page_text}")
    return "\n\n".join(text_parts)


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

    try:
        if ext in ALLOWED_IMAGE_EXT:
            text = ocr_image_file(tmp_path)
        else:
            text = ocr_pdf_file(tmp_path)
        return jsonify({"text": text.strip()})
    except Exception as exc:  # keep the service alive, report the failure to PHP
        return jsonify({"error": str(exc)}), 500
    finally:
        os.remove(tmp_path)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001)
