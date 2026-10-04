"""Token-budgeted document windows and document-level score aggregation."""


def split_document(text, tokenizer, max_length, overlap=32):
    budget = max_length - tokenizer.num_special_tokens_to_add(pair=False)
    if budget < 2:
        raise ValueError('Sequence length leaves insufficient room for document text')
    tokens = tokenizer.encode(text, add_special_tokens=False, truncation=False)
    if len(tokens) <= budget:
        return [text]
    overlap = min(max(0, overlap), budget // 4)
    chunks = []
    start = 0
    while start < len(tokens):
        end = min(start + budget, len(tokens))
        chunk = tokenizer.decode(tokens[start:end], skip_special_tokens=True)
        # A window beginning in a subword can re-tokenize differently. Verify its
        # actual encoded length instead of relying on whitespace word counts.
        while len(tokenizer.encode(chunk, add_special_tokens=False, truncation=False)) > budget:
            end -= 1
            if end <= start:
                raise ValueError('Unable to fit document token into a chunk')
            chunk = tokenizer.decode(tokens[start:end], skip_special_tokens=True)
        chunks.append(chunk)
        if end == len(tokens):
            break
        start = max(start + 1, end - overlap)
    return chunks


def rank_documents(chunk_ids, scores, threshold, limit):
    """Best matching passage wins; a document occupies at most one result slot."""
    best = {}
    for doc_id, score in zip(chunk_ids, scores):
        best[doc_id] = max(best.get(doc_id, float('-inf')), float(score))
    ranked = sorted(best.items(), key=lambda pair: (-pair[1], pair[0]))
    return [doc_id for doc_id, score in ranked if score >= threshold][:limit]
