import unittest

from layout import layout_text


def data_for(words):
    data = {key: [] for key in ('text', 'conf', 'left', 'top', 'width', 'height')}
    for text, left, top in words:
        for key, value in zip(data, (text, 95, left, top, len(text) * 10, 20)):
            data[key].append(value)
    return data


class LayoutTests(unittest.TestCase):
    def test_centered_heading_and_paragraph_gap(self):
        text = layout_text(data_for([('TITLE', 200, 10), ('Body', 40, 90)]), 600)
        self.assertTrue(text.startswith(' ' * 20 + 'TITLE'))
        self.assertIn('\n\n', text)
        self.assertTrue(text.endswith('    Body'))

    def test_columns_align_despite_ocr_block_order(self):
        words = [('Name', 40, 10), ('Alice', 40, 40),
                 ('Amount', 300, 12), ('100', 300, 41)]
        lines = layout_text(data_for(words), 600).splitlines()
        self.assertEqual(len(lines), 2)
        self.assertEqual(lines[0].index('Amount'), lines[1].index('100'))

    def test_empty_page(self):
        self.assertEqual(layout_text(data_for([]), 600), '')

    def test_text_stays_plain_and_words_do_not_overlap(self):
        text = layout_text(data_for([('<script>', 10, 10), ('next', 40, 10)]), 600)
        self.assertEqual(text.strip(), '<script> next')


if __name__ == '__main__':
    unittest.main()
