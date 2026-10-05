# Semantic search baseline

## Website controls, previews, and recovery

Website searches automatically combine semantic and keyword retrieval. There is
no mode selector. Legacy links and saved searches also use hybrid retrieval even
if they contain `mode=keyword`. Blank-query filtering does not call the model.
The external API retains its existing explicit mode contract.

The service can return winning passage positions and a text hash, never the raw
document passage. PHP first loads authorized registered rows, verifies the hash
against the current source text, and then extracts a preview. New content or
invalid offsets cause a fallback to a keyword-centered excerpt. Old service
versions without passage metadata also use this fallback. Slow tokenizers that
cannot supply offsets omit long-passage coordinates rather than inventing them.
Previews are labeled **Matching passage**, **Document text match**, **OCR text
match**, or a plain preview as appropriate. Highlighted text is HTML-escaped.

Filipino variants now include `matandang`, `nakatatanda`, `nakakatanda`, financial
assistance phrases, and public-hearing terms. These remain curated domain mappings,
not full translation. Typo suggestions use a fixed public vocabulary and require
a unique one-edit match, e.g. `ordinace` → `ordinance`. They appear on no-result
pages and require a click; original queries are never silently corrected.
Document reference tokens are excluded from correction. Suggestions do not imply
that a matching accessible document exists.

No-result actions can preserve the query while clearing optional filters,
try a spelling suggestion, or start a new search. Clearing
filters never clears permission or registration restrictions.

### Evaluate the actual website

Open [website-search-evaluation.html](website-search-evaluation.html) locally in
your browser, sign in to LRDMS, then use its 24 test links. Record actual Top-5 ranks,
misses, errors, skips, and whether the website used hybrid or keyword fallback.
The worksheet calculates overall/per-group Hit@1, Hit@5, and MRR@5 and exports JSON.
Pending entries are unmeasured; errors count as misses; justified skips are
excluded. Export before closing/reloading: entries are not persisted automatically.
No credentials, document contents, or authenticated requests are collected by the
worksheet. It does not measure latency or RAM. Live results still require a human
to run and record the searches; fixture tests do not establish model accuracy.

Deploy both PHP and the BERT service for matching-passage previews. No migration
is needed. Tests: `php tests_search_display.php`, `php tests_search_language.php`,
`php tests_assisted_keywords.php`, `php tests_hybrid_search.php`, and
`python -m unittest discover -s bert_service -p 'test_*.py' -v`.

## Website hybrid search

The website automatically combines semantic service results
with the existing SQL keyword search. Both lists use the caller's visibility and
registration restrictions before merging. Exact document-number matches rank
first; other candidates use reciprocal rank fusion (constant 60, equal weights).
Duplicates are removed, with document ID as the deterministic tie-breaker. Other
selected sort orders override relevance. Service failures retain keyword fallback.
The existing search-log `semantic` category now includes hybrid searches; API
responses additionally expose `strategy: hybrid` when the service succeeds.

The Python runner below still evaluates the semantic service only. Its scores do
not measure the PHP hybrid ranking. Compare website results separately using the
same queries, user account, relevance sort, and filters. In particular, verify that
exact references appear first and private/unregistered records remain excluded.
No improvement percentage is claimed until this comparison is run.

### Filipino vocabulary assistance

For nonempty website queries, PHP maps recognized Filipino words
and phrases to curated English equivalents before sending the single semantic
request. The vocabulary is in `includes/search_language.php`. For example,
`buwanang ayuda para sa matatanda` becomes
`monthly financial assistance para sa senior citizens`.

This is partial domain vocabulary substitution, not a translation model or a claim
of full Tagalog support. Unknown words and proper names are retained. Longest
phrases take precedence, and word boundaries protect embedded terms and document
references. The original query remains in keyword matching, search logs, and exact
reference ranking. Hybrid keyword matching and its outage fallback search both
the original phrase and its curated equivalent, using the same visibility clause
and parameters for each. Thus `matanda` and `matatanda` can retrieve literal
`senior citizens` matches even below the semantic threshold. Duplicates are merged.
Explicit API keyword-only mode still uses the original query. This remains phrase
matching, not arbitrary synonym coverage or general translation.
No additional model, migration, or second semantic request is introduced.

The website indicates when vocabulary assistance was used; API responses include
`language_assisted`. The Python service-only baseline bypasses this PHP feature.
For live verification, repeat all eight Tagalog queries from
`bert_service/evaluation_queries.json` in the website with relevance sorting.
Record the expected document's rank, misses,
and response time with the same user and filters. Compare against the original
3/8 Tagalog Hit@5 cautiously: both hybrid ranking and vocabulary assistance have
changed since that baseline, so this does not isolate either feature's effect.
Also check English and exact-reference queries for regressions.

Local checks (no model or database required):

```sh
php tests_search_language.php
php tests_hybrid_search.php
```

This runner measures the currently deployed service before changing the model or
ranking. It sends 24 sequential queries: 12 English, 8 Tagalog, and 4 exact document
references. Expected matches are proposed manually from the Manila demo metadata;
review these labels before presenting results as evaluation evidence.

## Run on HostForge

### Long document passages

The BERT service splits the full combined title, notes, reference, body, and OCR
text into overlapping token windows using the deployed model's tokenizer. Each
window reserves space for special tokens and fits the configured sequence length
(also bounded by the model's supported length). Default overlap is 32 tokens,
reduced for smaller windows. Short documents keep their original text.

All windows are indexed, including the end of a document. The best matching
passage supplies the document score; the response still contains each document
ID at most once. The similarity threshold applies to that best passage. Existing
PHP visibility checks and hybrid merging remain in place. Changed documents
replace their cached passages, and deleted documents are evicted on the next
nonempty-corpus search. No extra model or database migration is required.

More passages increase initial indexing time and embedding-cache RAM. Encoding
uses batches of 8; the whole corpus embedding cache is still in memory and is not
a fixed-size index. Monitor `chunks`, `documents_ms`, and container memory before
claiming the 1 GB deployment can handle a larger corpus. Very large documents or
corpora may need a persisted index or additional resources. First-time indexing
can still exceed request timeouts.

Verify on a test record containing meaningful searchable text near the end, past
the first 256 tokens, then search for that topic. Confirm one result per document,
and recheck after editing or removing the relevant passage. Existing demo metadata
alone does not establish long-document retrieval accuracy. Re-run the baseline to
check relevance changes: max-passage scoring can also introduce false positives.
Local tests check token coverage, overlap, budget, deduplication, and cache refresh
using a synthetic tokenizer/encoder; live MiniLM quality still needs evaluation.

### Performance diagnostics and bounded query cache

The service keeps up to 128 query embeddings in process memory using LRU eviction.
It caches embeddings only, never document IDs or search results. Each search still
reads current documents, refreshes changed document embeddings, and applies the
same cosine ranking and threshold. PHP still applies current access restrictions.
Query text and embeddings remain in memory until eviction or process restart.
Set `BERT_QUERY_CACHE_SIZE=0` to disable; allowed configured capacity is clamped to
0–1024. No query cache is persisted to disk.

`BERT_CPU_THREADS` defaults to 1 for the small CPU container and controls PyTorch
intra-operation threads. Model encoding is serialized to avoid simultaneous
warm-up/request inference competing for resources. This is a tuning starting
point, not a measured optimum; compare 1 versus 2 only if runtime measurements
warrant it. It may affect throughput under concurrent load.

Successful nonempty-corpus searches emit `search_timing` logs with database,
document preparation (including cache lock waits), query encoding/cache lookup,
ranking, and total milliseconds. Logs also show query-cache hits, document count,
and thread count without query contents or document IDs. Query timing includes
any wait for the inference/cache locks.

After deploying the BERT service, run the baseline twice with different report
filenames. Distinguish the first run from the repeated-query cache-warm run, and
compare ranks as well as latency. To isolate CPU tuning from query caching, set
cache capacity to 0 for both compared deployments. Model weights, sequence length,
threshold, and records should stay constant. Timing improvements have not yet
been measured on HostForge. Local unit tests use fake encoders, not the ML model.

Deploy these files to the **BERT service**, then open that service's terminal (not
the PHP application terminal). The service must already be running and configured
to use the same database as LRDMS. Register the expected imported documents first;
missing or unregistered expected records are reported as skipped.

```sh
cd /app
python evaluate_search.py --output /tmp/lrdms-search-baseline-01.json
cat /tmp/lrdms-search-baseline-01.json
```

Choose a new filename for each run; existing reports are never overwritten. Save
the JSON outside the container before a restart or redeployment because `/tmp` is
not persistent storage. The runner reads database credentials and the optional
API key from the environment and does not print them. It queries the database
without modifying records and calls the existing service without loading another
model. Search requests can populate the service's in-memory embedding cache.

## Interpret the report

- **Hit@1:** fraction of eligible queries whose expected record ranks first.
- **Hit@5:** fraction whose expected record appears in the first five results.
- **MRR@5:** average reciprocal expected rank, with zero for misses beyond rank 5.
- **Errors:** failed requests count as misses, not successful searches.
- **Skipped:** expected records absent or unregistered; excluded from scoring.
- **Median/p95 milliseconds:** successful request latency only. First requests
  may include cache warming. These sequential measurements are not a load test.
- **Sampled peak memory:** whole-container cgroup usage sampled every 100 ms,
  including the runner. This is not isolated model memory or a guaranteed peak;
  unavailable readings are `null`.

Scores are fractions: `0.75` means 75%. Report each language group separately and
include completed, error, and skipped counts. Exit code 0 means all cases completed,
not that all expected documents were found; 2 means some cases failed or were
skipped; 1 indicates a setup or report-writing error.

This measures service ranking after filtering to registered documents. It does
not test PHP connectivity, keyword fallback, user-specific permissions, or browser
filters. Verify those separately through the website. Exact-number queries expose
semantic-only weaknesses; the runner does not substitute keyword results.

For before/after comparisons, keep the document contents, registration state,
queries, threshold, model configuration, and cache conditions consistent. Record
deployment/commit and relevant non-secret configuration alongside each report.
The small, curated demo set is a baseline, not proof of accuracy on all documents.

## Local scoring tests

```sh
python -m unittest discover -s bert_service -p test_evaluate_search.py -v
```

These tests check scoring and query structure only; they do not measure model
accuracy and require neither a database nor a loaded model.
