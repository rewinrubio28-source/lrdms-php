import unittest
import ast
import hashlib
import re
from pathlib import Path
import threading
from types import SimpleNamespace

import numpy as np
from document_chunks import split_document, rank_documents


class Tokenizer:
    is_fast = True

    def __call__(self, text, **kwargs):
        return {'offset_mapping': [match.span() for match in re.finditer(r'\S+', text)]}
    def num_special_tokens_to_add(self, pair=False):
        return 2

    def encode(self, text, **kwargs):
        return text.split()

    def decode(self, tokens, **kwargs):
        return ' '.join(tokens)


class ChunkTests(unittest.TestCase):
    def test_short_and_empty_text_are_preserved(self):
        for text in ['', 'short  document']:
            self.assertEqual(split_document(text, Tokenizer(), 16), [text])

    def test_full_document_coverage_with_overlap_and_budget(self):
        tokens = ['word'+str(i) for i in range(70)]
        chunks = split_document(' '.join(tokens), Tokenizer(), 16, 3)
        self.assertEqual(set(' '.join(chunks).split()), set(tokens))
        self.assertIn('word69', chunks[-1])
        for chunk in chunks:
            self.assertLessEqual(len(chunk.split()) + 2, 16)
        for left, right in zip(chunks, chunks[1:]):
            self.assertEqual(left.split()[-3:], right.split()[:3])

    def test_late_passage_can_win_without_duplicate_results(self):
        self.assertEqual(rank_documents([1,1,1,2,3], [.1,.2,.9,.8,.1], .3, 25), [1,2])

    def test_threshold_limit_and_tie_break(self):
        self.assertEqual(rank_documents([3,2,1], [.3,.3,.2], .3, 1), [2])
        self.assertEqual(rank_documents([], [], .3, 25), [])

    def test_invalid_budget_is_rejected(self):
        with self.assertRaises(ValueError):
            split_document('text', Tokenizer(), 2)

    def test_passage_offsets_recover_original_unicode_text(self):
        text = ' '.join(['ordinance']*40) + '  tulong sa mamamayang Pilipino ñ'
        chunks, spans = split_document(text, Tokenizer(), 16, with_spans=True)
        self.assertEqual(len(chunks), len(spans))
        self.assertEqual(text[spans[-1][0]:spans[-1][1]].split(), chunks[-1].split())
        self.assertEqual(spans[-1][1], len(text))

    def test_service_cache_refresh_and_deletion(self):
        # Execute the real service helpers without importing app.py, which would
        # start a model and database warm-up. The encoder is deterministic here.
        source = ast.parse(Path(__file__).with_name('app.py').read_text(encoding='utf-8'))
        functions = [node for node in source.body if isinstance(node, ast.FunctionDef)
                     and node.name in {'build_text', 'get_doc_matrix'}]
        calls = []
        def encode(chunks, **kwargs):
            calls.append(list(chunks))
            return np.array([[len(chunk), 1] for chunk in chunks], dtype=np.float32)
        scope = dict(hashlib=hashlib, np=np, _cache={}, _lock=threading.Lock(),
                     split_document=split_document, MAX_SEQ_LENGTH=16,
                     model=SimpleNamespace(tokenizer=Tokenizer()), encode_text=encode)
        exec(compile(ast.Module(body=functions, type_ignores=[]), 'app.py', 'exec'), scope)
        row = dict(id=1, title='Record', description='', doc_number='ORD-1',
                   body=' '.join('word'+str(i) for i in range(60)), ocr_text='')
        other = dict(row, id=2, body='short')
        ids, matrix = scope['get_doc_matrix']([row, other])
        self.assertEqual(len(ids), matrix.shape[0])
        self.assertGreater(ids.count(1), 1)
        self.assertEqual(ids.count(2), 1)
        scope['get_doc_matrix']([row, other])
        self.assertEqual(len(calls), 2, 'unchanged records must not re-encode')
        row['body'] = 'changed content'
        ids, matrix = scope['get_doc_matrix']([row])
        self.assertEqual(len(calls), 3)
        self.assertEqual(ids, [1])
        self.assertEqual(set(scope['_cache']), {1}, 'deleted records must be evicted')
        ids, matrix, passages = scope['get_doc_matrix']([row], with_passages=True)
        self.assertEqual(len(passages), len(ids))
        self.assertEqual(passages[0]['text_hash'], hashlib.md5(scope['build_text'](row).encode('utf-8')).hexdigest())


if __name__ == '__main__':
    unittest.main()
