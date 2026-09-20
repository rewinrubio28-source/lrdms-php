FROM python:3.11-slim

WORKDIR /app

# CPU-only PyTorch: ~200MB instead of ~2GB+ with CUDA. HostForge has no GPU anyway.
COPY requirements.txt .
RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r requirements.txt

# Download the model at BUILD time so the container doesn't have to fetch
# ~440MB on every cold start.
ARG BERT_MODEL=sentence-transformers/all-MiniLM-L6-v2
ENV BERT_MODEL=${BERT_MODEL}
RUN python -c "import os; from sentence_transformers import SentenceTransformer; SentenceTransformer(os.environ['BERT_MODEL'])"

COPY . .

# HostForge injects PORT at runtime; 5000 is the local default.
ENV PORT=5000
EXPOSE 5000

# ONE worker on purpose: each worker loads its own copy of the model into RAM.
CMD ["sh", "-c", "gunicorn --bind 0.0.0.0:${PORT} --workers 1 --threads 2 --timeout 120 app:app"]
