import unittest
from concurrent.futures import ThreadPoolExecutor
from query_cache import QueryEmbeddingCache


class QueryCacheTests(unittest.TestCase):
    def test_repeated_query_reuses_embedding(self):
        cache = QueryEmbeddingCache(2)
        calls = []
        def encode(text):
            calls.append(text)
            return [len(text)]
        self.assertEqual(cache.get_or_encode('hello', encode), ([5], False))
        self.assertEqual(cache.get_or_encode('hello', encode), ([5], True))
        self.assertEqual(calls, ['hello'])

    def test_lru_evicts_oldest_unused_entry(self):
        cache = QueryEmbeddingCache(2)
        for query in ['a', 'b', 'a', 'c']:
            cache.get_or_encode(query, str)
        self.assertTrue(cache.get_or_encode('a', str)[1])
        self.assertFalse(cache.get_or_encode('b', str)[1])

    def test_disabled_cache_and_failed_encode(self):
        cache = QueryEmbeddingCache(0)
        self.assertFalse(cache.get_or_encode('a', str)[1])
        self.assertFalse(cache.get_or_encode('a', str)[1])
        cache = QueryEmbeddingCache(2)
        def fail(query):
            raise ValueError('encoding failed')
        with self.assertRaises(ValueError):
            cache.get_or_encode('a', fail)
        self.assertFalse(cache.get_or_encode('a', str)[1])

    def test_concurrent_identical_requests_encode_once(self):
        cache = QueryEmbeddingCache(2)
        with ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(lambda _: cache.get_or_encode('a', str), range(12)))
        self.assertEqual(sum(not hit for _, hit in results), 1)


if __name__ == '__main__':
    unittest.main()
