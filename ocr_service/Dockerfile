FROM python:3.11-slim

# tesseract-ocr is the actual OCR engine (pytesseract is just a Python
# wrapper around it); poppler-utils is needed for PDF support via
# pdf2image. Neither is a pip package, so it must be installed at the
# OS level here, before pip install even runs.
RUN apt-get update && apt-get install -y --no-install-recommends \
    tesseract-ocr \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 5001

CMD ["python", "app.py"]
