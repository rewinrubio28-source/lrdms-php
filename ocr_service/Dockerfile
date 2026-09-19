FROM python:3.11-slim

# tesseract-ocr is the actual OCR engine (pytesseract is just a Python
# wrapper around it); tesseract-ocr-fil adds the Filipino language data
# needed by "-l eng+fil"; poppler-utils is needed for PDF support via
# pdf2image. None of these are pip packages, so they are installed at
# the OS level here, before pip install runs.
RUN apt-get update && apt-get install -y --no-install-recommends \
    tesseract-ocr \
    tesseract-ocr-fil \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# The hosting platform injects PORT at runtime; 5001 is the local default.
ENV PORT=5001
# Tesseract hangs in containers with a small CPU limit when it spawns many
# OpenMP threads. One thread is enough and finishes in seconds.


# Long timeout because 300-DPI PDF OCR can take a while per request.
CMD ["sh", "-c", "gunicorn --bind 0.0.0.0:${PORT} --workers 2 --timeout 180 app:app"]
