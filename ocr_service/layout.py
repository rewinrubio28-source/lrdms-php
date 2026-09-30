"""Render Tesseract word coordinates as safe, fixed-width plain text."""

from statistics import median


def layout_text(data, page_width):
    words = []
    for i, text in enumerate(data['text']):
        text = text.strip()
        if not text or float(data['conf'][i]) < 0:
            continue
        words.append({
            'text': text, 'x': int(data['left'][i]),
            'y': int(data['top'][i]), 'w': int(data['width'][i]),
            'h': max(1, int(data['height'][i])),
        })
    if not words:
        return ''

    # A bounded character grid preserves margins without creating huge output
    # for high-resolution scans. Use the whole page, not each block's origin.
    char_width = max(1, page_width / 240, median(w['w'] / len(w['text']) for w in words))
    line_height = max(1, median(w['h'] for w in words) * 1.4)
    rows = []
    for word in sorted(words, key=lambda w: (w['y'] + w['h'] / 2, w['x'])):
        center = word['y'] + word['h'] / 2
        if rows and abs(center - rows[-1]['center']) <= min(word['h'], rows[-1]['height']) * 0.5:
            rows[-1]['words'].append(word)
        else:
            rows.append({'center': center, 'height': word['h'], 'words': [word]})

    result = []
    previous_center = None
    for row in rows:
        if previous_center is not None:
            gap = round((row['center'] - previous_center) / line_height) - 1
            result.extend([''] * max(0, min(gap, 8)))
        line = ''
        for word in sorted(row['words'], key=lambda w: w['x']):
            column = round(word['x'] / char_width)
            spaces = max(1 if line else 0, column - len(line))
            line += ' ' * spaces + word['text']
        result.append(line.rstrip())
        previous_center = row['center']
    return '\n'.join(result)
