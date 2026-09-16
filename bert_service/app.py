"""
LRDMS BERT semantic search microservice.

PHP cannot run a transformer model itself, so this small Flask app
does the actual "meaning, not just words" search with
sentence-transformers and hands the result back to PHP as a list of
document_ids. includes/semantic_search.php calls this service over
HTTP the same way includes/ocr.php calls ocr_service/app.py - same
integration pattern, different endpoint.

How it works:
  1. On each /search request, this service connects directly to the
     same MySQL database PHP uses (config below) and pulls
     id, title, ocr_text for every document.
  2. It embeds the query and every document's text with a small
     pretrained BERT-family model (all-MiniLM-L6-v2).
  3. It ranks documents by cosine similarity to the query and returns
     the ids above a similarity threshold, best first.
  4. PHP then re-fetches those ids from MySQL itself, applying the
     RBAC visibility WHERE clause - this service never makes access
     control decisions, it only ranks by meaning.

Re-embedding on every request is deliberately simple (no cache, no
vector DB) - fine for a thesis-scale document set (dozens/hundreds of
rows). If the corpus grows large, the first thing to add is an
in-memory cache keyed by a "last updated" timestamp, or a proper
vector index (e.g. FAISS) - not needed here.

Run it with:
    pip install -r requirements.txt
    python app.py
It listens on http://localhost:5000 by default.
"""

import re
import pymysql
import numpy as np
from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer

app = Flask(__name__)

# Same defaults as config/database.php (stock XAMPP: localhost / root / no password).
DB_HOST = "localhost"
DB_NAME = "lrdms_db"
DB_USER = "root"
DB_PASS = ""

# Small, fast, good-enough-for-a-thesis sentence embedding model.
# First run downloads it (~80MB) and caches it locally.
MODEL_NAME = "all-MiniLM-L6-v2"
SIMILARITY_THRESHOLD = 0.10  # low threshold to catch related concepts + singular/plural
TOP_N = 25  # mirrors the LIMIT 25 in keyword_search()


def simple_stem(word):
    """Basic English stemmer — removes common suffixes for better matching."""
    word = word.lower().strip()
    for suffix in ['tion', 'ness', 'ment', 'able', 'ible', 'ful', 'less', 'ous', 'ive', 'ing', 'ed', 'er', 'est', 'ly', 'es', 's']:
        if word.endswith(suffix) and len(word) - len(suffix) >= 3:
            return word[:-len(suffix)]
    return word


def stem_query(query):
    """Stem each word in the search query."""
    return ' '.join(simple_stem(w) for w in query.split())

print(f"Loading model '{MODEL_NAME}'... (first run downloads it, please wait)")
model = SentenceTransformer(MODEL_NAME)
print("Model loaded. Service ready.")


def get_db_connection():
    return pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def fetch_documents():
    """Pull id + searchable text for every document currently in the DB."""
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("SELECT id, title, body, ocr_text, description, doc_number FROM documents")
            return cursor.fetchall()
    finally:
        conn.close()


def cosine_similarity(query_vec, doc_matrix):
    query_norm = query_vec / np.linalg.norm(query_vec)
    doc_norms = doc_matrix / np.linalg.norm(doc_matrix, axis=1, keepdims=True)
    return doc_norms @ query_norm


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "model": MODEL_NAME})


@app.route("/search", methods=["POST"])
def search():
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

    texts = [
        f"{row['title']} {row['body'] or ''} {row['ocr_text'] or ''} {row['description'] or ''} {row['doc_number'] or ''}".strip()
        for row in rows
    ]
    ids = [row["id"] for row in rows]

    # Stem the query so "farms" matches "farm" and vice versa
    stemmed = stem_query(query)
    query_embedding = model.encode(stemmed)
    doc_embeddings = model.encode(texts)

    scores = cosine_similarity(query_embedding, np.array(doc_embeddings))

    ranked = sorted(zip(ids, scores), key=lambda pair: pair[1], reverse=True)
    matched_ids = [doc_id for doc_id, score in ranked if score >= SIMILARITY_THRESHOLD][:TOP_N]

    return jsonify({"document_ids": matched_ids})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
