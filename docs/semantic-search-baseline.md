# Semantic search baseline

This runner measures the currently deployed service before changing the model or
ranking. It sends 24 sequential queries: 12 English, 8 Tagalog, and 4 exact document
references. Expected matches are proposed manually from the Manila demo metadata;
review these labels before presenting results as evaluation evidence.

## Run on HostForge

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
