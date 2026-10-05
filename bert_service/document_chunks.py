"""Token-budgeted document windows and document-level score aggregation."""


def split_document(text, tokenizer, max_length, overlap=32, with_spans=False):
    budget = max_length - tokenizer.num_special_tokens_to_add(pair=False)
    if budget < 2:
        raise ValueError('Sequence length leaves insufficient room for document text')
    tokens = tokenizer.encode(text, add_special_tokens=False, truncation=False)
    offsets = None
    if with_spans and getattr(tokenizer, 'is_fast', False):
        offsets = tokenizer(text, add_special_tokens=False, truncation=False,
                            return_offsets_mapping=True)['offset_mapping']
    if len(tokens) <= budget:
        return ([text], [(0, len(text))]) if with_spans else [text]
    overlap = min(max(0, overlap), budget // 4)
    chunks = []
    spans = []
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
        spans.append((offsets[start][0], offsets[end-1][1]) if offsets else None)
        if end == len(tokens):
            break
        start = max(start + 1, end - overlap)
    return (chunks, spans) if with_spans else chunks


def rank_documents(chunk_ids, scores, threshold, limit):
    """Best matching passage wins; a document occupies at most one result slot."""
    best = {}
    for doc_id, score in zip(chunk_ids, scores):
        best[doc_id] = max(best.get(doc_id, float('-inf')), float(score))
    ranked = sorted(best.items(), key=lambda pair: (-pair[1], pair[0]))
    return [doc_id for doc_id, score in ranked if score >= threshold][:limit]
