"""Flask contract tests with a fake encoder; no model download or database writes."""
import importlib.util
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import unittest
import numpy as np
from test_document_chunks import Tokenizer


class FakeModel:
    max_seq_length = 16
    tokenizer = Tokenizer()

    def __init__(self, *args):
        pass

    def encode(self, value, **kwargs):
        def vector(text):
            return [1., 0.] if 'elderly' in text else [0., 1.]
        return np.array([vector(text) for text in value] if isinstance(value, list) else vector(value))


class ServicePassageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location('passage_contract_app', Path(__file__).with_name('app.py'))
        cls.service = importlib.util.module_from_spec(spec)
        with patch.dict('sys.modules', {
            'torch': SimpleNamespace(set_num_threads=lambda _: None),
            'sentence_transformers': SimpleNamespace(SentenceTransformer=FakeModel),
            'pymysql': SimpleNamespace(),
        }), patch('threading.Thread.start'), patch.dict('os.environ', {'BERT_API_KEY':'test-only-key'}):
            spec.loader.exec_module(cls.service)
        cls.client = cls.service.app.test_client()

    def test_returns_verified_coordinates_without_document_text(self):
        row = dict(id=7, title='Record', description='', doc_number='ORD-7',
                   body=('intro '*90)+'elderly benefits at the end', ocr_text='')
        with patch.object(self.service, 'fetch_documents', return_value=[row]):
            response = self.client.post('/search', json={'query':'elderly'}, headers={'X-API-Key':'test-only-key'})
        self.assertEqual(response.status_code, 200)
        data = response.get_json()
        self.assertEqual(data['document_ids'], [7])
        span = data['passages']['7']
        text = self.service.build_text(row)
        self.assertIn('elderly', text[span['start']:span['end']])
        self.assertNotIn('elderly', response.get_data(as_text=True))
        self.assertEqual(set(span), {'start','end','text_hash'})

    def test_unauthorized_request_does_not_fetch_documents(self):
        with patch.object(self.service, 'fetch_documents') as fetch:
            response = self.client.post('/search', json={'query':'elderly'})
            self.assertEqual(response.status_code, 401)
            fetch.assert_not_called()


if __name__ == '__main__':
    unittest.main()
