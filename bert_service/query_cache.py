"""Bounded, process-local query embeddings. Never caches document IDs or results."""
from collections import OrderedDict
import threading


class QueryEmbeddingCache:
    def __init__(self, capacity=128):
        self.capacity = max(0, capacity)
        self._entries = OrderedDict()
        self._lock = threading.Lock()

    def get_or_encode(self, query, encode):
        # Serialize cache misses so concurrent identical queries encode only once.
        with self._lock:
            if query in self._entries:
                self._entries.move_to_end(query)
                return self._entries[query], True
            embedding = encode(query)
            if self.capacity:
                self._entries[query] = embedding
                if len(self._entries) > self.capacity:
                    self._entries.popitem(last=False)
            return embedding, False
