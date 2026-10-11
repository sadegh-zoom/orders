import collections
import itertools
import json
import re
import unicodedata
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET


ROOT = Path('/home/sadegh-khan/Documents/Sale Marketing - ZB - SP')
OUT = Path(__file__).parent
NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}


def rows(path):
    with zipfile.ZipFile(path) as archive:
        strings = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            root = ET.fromstring(archive.read('xl/sharedStrings.xml'))
            strings = [''.join(x.itertext()) for x in root.findall('m:si', NS)]
        sheet = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
        result = []
        for row in sheet.findall('.//m:sheetData/m:row', NS):
            values = {}
            for cell in row.findall('m:c', NS):
                col = re.match(r'[A-Z]+', cell.attrib['r']).group()
                if cell.attrib.get('t') == 's':
                    value = strings[int(cell.findtext('m:v', default='0', namespaces=NS))]
                elif cell.find('m:is', NS) is not None:
                    value = ''.join(cell.find('m:is', NS).itertext())
                else:
                    value = cell.findtext('m:v', default='', namespaces=NS)
                values[col] = value
            result.append((int(row.attrib['r']), values))
        return result


def norm(value):
    value = unicodedata.normalize('NFKC', value or '').casefold()
    value = value.translate(str.maketrans({'ي': 'ی', 'ك': 'ک', '\u200c': ' ', '_': ' ', '-': ' '}))
    return re.sub(r'\s+', ' ', value).strip()


source = [
    {'row': row, 'number': int(v['A']), 'name': v.get('B', ''), 'price': int(float(v['C']))}
    for row, v in rows(ROOT / 'merge - one page.xlsx')
    if row > 1 and v.get('A', '').strip()
]
central = [
    {'row': row, 'number': int(v['A']), 'type': v['B'], 'central_order_id': int(v['C']),
     'source_order_id': int(v['D']), 'site': v['F'], 'status': v['H'],
     'central_item_id': int(v['M']), 'source_item_id': int(v['N']),
     'name': v['O'], 'quantity': float(v['P']), 'purchase': v['S'],
     'sales_unit': float(v['T']) if v.get('T') not in ('', 'NULL') else None}
    for row, v in rows(ROOT / 'new-merge-with-orders-2.xlsx')
    if row > 16 and v.get('A', '').strip()
]

by_number = collections.defaultdict(list)
for record in central:
    by_number[record['number']].append(record)

matches = []
unmatched_source = []
unmatched_central = []
for number, source_group in itertools.groupby(sorted(source, key=lambda x: x['number']), key=lambda x: x['number']):
    source_group = list(source_group)
    central_group = by_number[number]
    remaining = central_group.copy()
    for sr in source_group:
        candidates = [cr for cr in remaining if norm(sr['name']) == norm(cr['name'])]
        if len(candidates) == 1:
            cr = candidates[0]
            matches.append({'source': sr, 'central': cr, 'method': 'exact_normalized'})
            remaining.remove(cr)
        elif len(candidates) > 1:
            cr = candidates[0]
            matches.append({'source': sr, 'central': cr, 'method': 'repeated_exact_title'})
            remaining.remove(cr)
        else:
            unmatched_source.append(sr)
    unmatched_central.extend(remaining)

result = {
    'source_rows': len(source), 'central_rows': len(central),
    'source_order_count': len({x['number'] for x in source}),
    'central_order_count': len({x['number'] for x in central}),
    'central_missing_purchase': sum(x['purchase'] in ('', 'NULL') for x in central),
    'matches': matches, 'unmatched_source': unmatched_source,
    'unmatched_central': unmatched_central,
}
(OUT / 'comparison.json').write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps({k: (len(v) if isinstance(v, list) else v) for k, v in result.items()}, ensure_ascii=False))


def compact(value):
    return re.sub(r'[^a-z0-9آ-ی]+', '', norm(value))


def title_evidence(src_name, db_name):
    a, b = compact(src_name), compact(db_name)
    if a == b:
        return 'exact'
    if len(a) >= 4 and a in b:
        return 'contained'
    if a == 'pro1700' and 'pro1' in b and '700' in b:
        return 'model_tokens'
    return ''


proposals = []
review = []
for number in sorted(by_number):
    source_group = [x for x in source if x['number'] == number]
    central_group = by_number[number]
    used = set()
    for db in central_group:
        choices = [x for x in source_group if title_evidence(x['name'], db['name'])]
        distinct_prices = {x['price'] for x in choices}
        if not choices:
            review.append({'central': db, 'reason': 'no matching source product title',
                           'source_rows': source_group})
            continue
        if len(distinct_prices) != 1:
            review.append({'central': db, 'reason': 'conflicting source purchase prices',
                           'source_rows': choices})
            continue
        for choice in choices:
            used.add(choice['row'])
        proposals.append({'central': db, 'source_rows': [x['row'] for x in choices],
                          'price_raw': next(iter(distinct_prices)),
                          'evidence': sorted({title_evidence(x['name'], db['name']) for x in choices})})
    for sr in source_group:
        if sr['row'] not in used:
            review.append({'source': sr, 'reason': 'source row not assigned to an Orders item'})

result['proposals'] = proposals
result['review'] = review
(OUT / 'comparison.json').write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print('proposed item assignments:', len(proposals))
print('review central items:', [(x['central']['number'], x['central']['central_item_id'], x['reason'])
                                for x in review if 'central' in x])
print('review source rows:', [(x['source']['row'], x['source']['number'], x['reason'])
                              for x in review if 'source' in x])
