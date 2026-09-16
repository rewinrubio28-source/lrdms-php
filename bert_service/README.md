# LRDMS BERT semantic search microservice

This is the real implementation of the "BERT-backed semantic search"
described in `includes/semantic_search.php` and the thesis brief. It's
a small Flask app that:

1. Reads `id`, `title`, `ocr_text` straight from the `documents` table
   in `lrdms_db` (same DB PHP uses).
2. Embeds the search query and every document with a pretrained
   sentence-embedding model (`all-MiniLM-L6-v2` — a distilled
   BERT-family model, ~80MB, runs fine on CPU).
3. Ranks documents by cosine similarity and returns the matching
   `document_ids` to PHP.

PHP (`includes/semantic_search.php`) then re-fetches those specific
rows from MySQL itself, applying the RBAC visibility clause — this
service never decides who can see what, it only ranks by meaning.

## Setup

1. Make sure Python 3.9+ is installed.
2. From this folder:
   ```bash
   pip install -r requirements.txt
   ```
   (First install pulls in PyTorch via sentence-transformers — it's a
   few hundred MB, this is normal and only happens once.)
3. Run it:
   ```bash
   python app.py
   ```
   The first run also downloads the `all-MiniLM-L6-v2` model
   (~80MB) and caches it — subsequent runs start instantly.
4. Leave this running in its own terminal window/tab. It listens on
   `http://localhost:5000`.
5. Confirm it's alive:
   ```bash
   curl http://localhost:5000/health
   ```
   should return `{"status": "ok", "model": "all-MiniLM-L6-v2"}`.

## Wiring it into PHP

Already done in `includes/semantic_search.php` — `semantic_search()`
now calls this service over `cURL` and falls back to
`keyword_search()` automatically if the service is down or errors
out, so the rest of the app never breaks because of this.

## Notes for the thesis defense

- This is a genuine transformer-based semantic search, not a keyword
  trick — it will match a query like "traffic fine increase" against
  a document titled "An Ordinance Adjusting Penalties for Moving
  Violations" even though they share almost no words in common,
  because the model compares meaning, not exact terms.
- It re-embeds the whole corpus on every request instead of caching a
  vector index. That's a deliberate simplicity trade-off appropriate
  for a thesis-scale document set (dozens/hundreds of rows) — if this
  were a production system with thousands of documents, the next
  step would be a persisted vector index (e.g. FAISS, or a vector
  column in Postgres/MySQL) rebuilt incrementally instead of per
  request. Good to mention this trade-off if asked.
- `SIMILARITY_THRESHOLD` (0.25 by default) controls how loose the
  "meaning match" is — lower it if the panel's demo queries return
  too few results, raise it if they return too many loosely-related
  ones.
