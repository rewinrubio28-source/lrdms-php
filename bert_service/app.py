"""
LRDMS BERT semantic search microservice.

Uses a real BERT-base model (Sentence-BERT: BERT fine-tuned so that
sentence/document vectors can be compared with cosine similarity).
Plain, un-fine-tuned BERT is NOT good at similarity search - its raw
vectors are not trained for it - which is why the SBERT-style
fine-tuned BERT below is the correct "real BERT" for this job.

Flow:
  1. PHP POSTs {"query": "..."} to /search.
  2. This service reads id/title/body/ocr_text/description/doc_number
     from the same MySQL database PHP uses.
  3. Document embeddings are cached in memory (re-computed only when a
     document's text changes), so only the query is embedded per request.
  4. Ranks by cosine similarity and returns matching document_ids.
  5. PHP re-fetches those ids applying RBAC - this service never makes
     access-control decisions.

Environment variables (all optional, defaults suit local XAMPP):
  DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
  BERT_MODEL            HuggingFace model id (default: bert-base-nli-mean-tokens)
  BERT_MAX_SEQ_LENGTH   tokens per document, max 512 (default 256)
  SIMILARITY_THRESHOLD  minimum cosine score to count as a match (default 0.30)
  BERT_API_KEY          if set, requests must send a matching X-API-Key header
  PORT                  listen port (default 5000)
"""

import hashlib
import os
import threading

import numpy as np
import pymysql
from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer

app = Flask(__name__)

DB_HOST = os.environ.get("DB_HOST", "localhost")
DB_NAME = os.environ.get("DB_NAME", "lrdms_db")
DB_USER = os.environ.get("DB_USER", "root")
DB_PASS = os.environ.get("DB_PASS", "")
DB_PORT = int(os.environ.get("DB_PORT", "3306"))

BERT_API_KEY = os.environ.get("BERT_API_KEY", "")

# A genuine BERT-base (110M params) Sentence-BERT model.
MODEL_NAME = os.environ.get("BERT_MODEL", "sentence-transformers/bert-base-nli-mean-tokens")
MAX_SEQ_LENGTH = min(int(os.environ.get("BERT_MAX_SEQ_LENGTH", "256")), 512)
SIMILARITY_THRESHOLD = float(os.environ.get("SIMILARITY_THRESHOLD", "0.30"))
TOP_N = 25

print(f"Loading BERT model '{MODEL_NAME}'... (first run downloads it, please wait)")
model = SentenceTransformer(MODEL_NAME)
model.max_seq_length = MAX_SEQ_LENGTH
print("Model loaded.")

# doc_id -> (text hash, embedding). Re-embed a document only if its text changed.
_cache = {}
_lock = threading.Lock()


def get_db_connection():
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def fetch_documents():
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "SELECT id, title, body, ocr_text, description, doc_number FROM documents"
            )
            return cursor.fetchall()
    finally:
        conn.close()


def build_text(row):
    return (
        f"{row['title']}. {row['description'] or ''} {row['doc_number'] or ''} "
        f"{row['body'] or ''} {row['ocr_text'] or ''}"
    ).strip()


def get_doc_matrix(rows):
    """Return (ids, embedding matrix), embedding only new/changed documents."""
    ids = [r["id"] for r in rows]
    texts = [build_text(r) for r in rows]
    hashes = [hashlib.md5(t.encode("utf-8")).hexdigest() for t in texts]

    with _lock:
        missing = [
            i for i, (doc_id, h) in enumerate(zip(ids, hashes))
            if doc_id not in _cache or _cache[doc_id][0] != h
        ]
        if missing:
            embeddings = model.encode([texts[i] for i in missing], batch_size=16)
            for i, emb in zip(missing, embeddings):
                _cache[ids[i]] = (hashes[i], emb)

        live = set(ids)
        for stale in [k for k in _cache if k not in live]:
            del _cache[stale]

        matrix = np.array([_cache[doc_id][1] for doc_id in ids])
    return ids, matrix


def cosine_similarity(query_vec, doc_matrix):
    query_norm = query_vec / np.linalg.norm(query_vec)
    doc_norms = doc_matrix / np.linalg.norm(doc_matrix, axis=1, keepdims=True)
    return doc_norms @ query_norm


def warm_cache():
    """Pre-embed all documents at startup so the first search isn't slow."""
    try:
        rows = fetch_documents()
        if rows:
            get_doc_matrix(rows)
            print(f"Warmed embedding cache for {len(rows)} documents.")
    except Exception as exc:
        print(f"Cache warm-up skipped: {exc}")


threading.Thread(target=warm_cache, daemon=True).start()


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "model": MODEL_NAME, "cached_documents": len(_cache)})


@app.route("/search", methods=["POST"])
def search():
    if BERT_API_KEY and request.headers.get("X-API-Key", "") != BERT_API_KEY:
        return jsonify({"error": "Unauthorized."}), 401

    payload = request.get_json(silent=True) or {}
    query = (payload.get("query") or "").strip()
    if not query:
        return jsonify({"error": "Missing 'query' field."}), 400

    try:
        rows = fetch_documents()
    except Exception as exc:
        return jsonify({"error": f"Could not read documents table: {exc}"}), 500

    if not rows:
        return jsonify({"document_ids": []})

    ids, doc_matrix = get_doc_matrix(rows)

    # No stemming: BERT's WordPiece tokenizer already handles word forms,
    # and chopped-off stems ("ordinanc") actually hurt its accuracy.
    query_embedding = model.encode(query)
    scores = cosine_similarity(query_embedding, doc_matrix)

    ranked = sorted(zip(ids, scores), key=lambda p: p[1], reverse=True)
    matched_ids = [d for d, s in ranked if s >= SIMILARITY_THRESHOLD][:TOP_N]

    return jsonify({"document_ids": matched_ids})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", "5000")))
