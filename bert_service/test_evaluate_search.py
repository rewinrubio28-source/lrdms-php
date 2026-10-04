import json
from pathlib import Path
import unittest

from evaluate_search import summarize


class BaselineScoringTests(unittest.TestCase):
    def test_errors_count_as_misses_and_missing_documents_are_excluded(self):
        result = summarize([
            {'state': 'ok', 'rank': 1, 'milliseconds': 10},
            {'state': 'ok', 'rank': 5, 'milliseconds': 20},
            {'state': 'ok', 'rank': 6, 'milliseconds': 30},
            {'state': 'ok', 'rank': None, 'milliseconds': 40},
            {'state': 'error', 'milliseconds': 35000},
            {'state': 'skipped'},
        ])
        self.assertEqual(result['eligible'], 5)
        self.assertEqual(result['completed'], 4)
        self.assertEqual(result['errors'], 1)
        self.assertEqual(result['skipped'], 1)
        self.assertEqual(result['hit_at_1'], .2)
        self.assertEqual(result['hit_at_5'], .4)
        self.assertAlmostEqual(result['mrr_at_5'], .24)
        self.assertEqual(result['median_ms'], 25)
        self.assertEqual(result['p95_ms'], 40)

    def test_no_eligible_queries_has_no_score(self):
        result = summarize([{'state': 'skipped'}])
        for field in ('hit_at_1', 'hit_at_5', 'mrr_at_5', 'median_ms', 'p95_ms'):
            self.assertIsNone(result[field])

    def test_outage_is_zero_score_without_success_latency(self):
        result = summarize([{'state': 'error', 'milliseconds': 35000}])
        self.assertEqual(result['hit_at_5'], 0)
        self.assertIsNone(result['p95_ms'])

    def test_query_set_has_unique_queries_and_expected_references(self):
        cases = json.loads(Path(__file__).with_name('evaluation_queries.json').read_text(encoding='utf-8'))
        self.assertEqual(len(cases), 24)
        self.assertEqual(len({case['query'] for case in cases}), 24)
        self.assertEqual({g: sum(c['group'] == g for c in cases) for g in {c['group'] for c in cases}},
                         {'English': 12, 'Tagalog': 8, 'Exact reference': 4})
        for case in cases:
            self.assertTrue(case['expected'])
            for reference in case['expected']:
                self.assertRegex(reference, r'^(ORD-\d+|RES-\d+-S\d{4})$')


if __name__ == '__main__':
    unittest.main()
