"""Read-only service baseline. Does not import app.py or load another model."""
import argparse
import json
import math
import os
from pathlib import Path
import statistics
import threading
import time
import urllib.request
from datetime import datetime, timezone


def summarize(results):
    eligible = [r for r in results if r['state'] != 'skipped']
    ok = [r for r in eligible if r['state'] == 'ok']
    latencies = sorted(r['milliseconds'] for r in ok)
    return {
        'eligible': len(eligible), 'completed': len(ok),
        'errors': len(eligible)-len(ok), 'skipped': len(results)-len(eligible),
        # Errors remain misses, so a partial outage cannot inflate the score.
        'hit_at_1': sum(r.get('rank') == 1 for r in eligible)/len(eligible) if eligible else None,
        'hit_at_5': sum(0 < (r.get('rank') or 0) <= 5 for r in eligible)/len(eligible) if eligible else None,
        'mrr_at_5': sum(1/r['rank'] if 0 < (r.get('rank') or 0) <= 5 else 0 for r in eligible)/len(eligible) if eligible else None,
        'median_ms': statistics.median(latencies) if latencies else None,
        'p95_ms': latencies[math.ceil(.95*len(latencies))-1] if latencies else None,
    }


def memory_bytes():
    for path in ['/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory/memory.usage_in_bytes']:
        try:
            return int(Path(path).read_text().strip())
        except (OSError, ValueError):
            pass
    return None


def request_json(base, route, payload=None):
    headers = {'Content-Type': 'application/json'}
    if os.getenv('BERT_API_KEY'):
        headers['X-API-Key'] = os.environ['BERT_API_KEY']
    req = urllib.request.Request(base+route, data=json.dumps(payload).encode() if payload is not None else None, headers=headers)
    # Do not forward service credentials across redirects.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    with urllib.request.build_opener(NoRedirect).open(req, timeout=35) as response:
        return json.load(response)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default='/tmp/lrdms-search-baseline.json')
    args = parser.parse_args()
    # Run inside the BERT container; secrets are read from its environment.
    base = 'http://127.0.0.1:'+str(int(os.getenv('PORT', '5000')))
    health = request_json(base, '/health')
    if health.get('status') != 'ok':
        raise RuntimeError('Service is not healthy')
    import pymysql
    connection = pymysql.connect(host=os.getenv('DB_HOST', 'localhost'), port=int(os.getenv('DB_PORT', '3306')),
        user=os.getenv('DB_USER', 'root'), password=os.getenv('DB_PASS', ''), database=os.getenv('DB_NAME', 'lrdms_db'), charset='utf8mb4')
    try:
        with connection.cursor() as cursor:
            cursor.execute('SELECT id,doc_number FROM documents WHERE verified_at IS NOT NULL')
            eligible_docs = dict(cursor.fetchall())
    finally:
        connection.close()
    cases = json.loads(Path(__file__).with_name('evaluation_queries.json').read_text(encoding='utf-8'))
    samples = []
    done = threading.Event()
    def sample_memory():
        while not done.is_set():
            value = memory_bytes()
            if value is not None:
                samples.append(value)
            done.wait(.1)
    worker = threading.Thread(target=sample_memory, daemon=True)
    worker.start()
    results = []
    print('Model:', health.get('model'), '| Registered records:', len(eligible_docs), flush=True)
    try:
        for case in cases:
            row = dict(case)
            if not set(case['expected']).issubset(set(eligible_docs.values())):
                row.update(state='skipped', reason='Expected record missing or not registered')
            else:
                started = time.perf_counter()
                try:
                    response = request_json(base, '/search', {'query': case['query']})
                    ids = response.get('document_ids')
                    if not isinstance(ids, list) or len(ids)>1000 or any(type(i) is not int or i<=0 for i in ids):
                        raise ValueError('Invalid search response')
                    numbers = [eligible_docs[i] for i in dict.fromkeys(ids) if i in eligible_docs]
                    rank = next((i+1 for i,n in enumerate(numbers) if n in case['expected']), None)
                    row.update(state='ok', rank=rank, top5=numbers[:5])
                except Exception as exc:
                    row.update(state='error', error_type=type(exc).__name__)
                row['milliseconds'] = round((time.perf_counter()-started)*1000, 2)
            results.append(row)
            print(f"{len(results)}/{len(cases)} {row['state']} | {case['group']} | rank={row.get('rank')}", flush=True)
    finally:
        done.set()
        worker.join()
    report = {
        'evaluated_at_utc': datetime.now(timezone.utc).isoformat(), 'model': health.get('model'),
        'cached_documents_before': health.get('cached_documents'), 'registered_records': len(eligible_docs),
        'scope': 'Service ranking with registered-record filtering; not a per-user UI/RBAC or load test. No keyword fallback.',
        'labels': 'Manually proposed expected matches from the demo catalog; not independently adjudicated relevance judgments.',
        'summary': summarize(results),
        'groups': {g:summarize([r for r in results if r['group']==g]) for g in sorted({r['group'] for r in results})},
        'container_memory_before_mb': round(samples[0]/1048576,2) if samples else None,
        'container_memory_sampled_peak_mb': round(max(samples)/1048576,2) if samples else None,
        'memory_note': '100ms container/cgroup samples include service, runner, and other container processes. Not isolated model RAM or guaranteed peak.',
        'results': results,
    }
    # Do not overwrite an earlier baseline; choose a new filename for reruns.
    with open(args.output, 'x', encoding='utf-8') as output:
        json.dump(report, output, ensure_ascii=False, indent=2)
    print(json.dumps(report['summary'], indent=2))
    print('Report saved:', args.output)
    return 0 if report['summary']['completed']==len(cases) else 2


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as exc:
        # Connection exceptions can contain infrastructure details; keep them local.
        print('Evaluation could not complete:', type(exc).__name__, '(check service/database configuration).')
        raise SystemExit(1)
