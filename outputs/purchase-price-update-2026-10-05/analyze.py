import collections
import json
import re
import unicodedata
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET


SOURCE = Path('/home/sadegh-khan/Documents/Sale Marketing - ZB - SP/new-origin-merge-001.xlsx')
MERGED = Path('/home/sadegh-khan/Documents/Sale Marketing - ZB - SP/new-merged-Orders_Origin-001.xlsx')
OUT = Path(__file__).parent
NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}


def read_rows(path):
    with zipfile.ZipFile(path) as archive:
        strings = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            strings = [''.join(x.itertext()) for x in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall('m:si', NS)]
        root = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
        result = []
        for row in root.findall('.//m:sheetData/m:row', NS):
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


def normalize_title(value):
    value = unicodedata.normalize('NFKC', value or '').casefold()
    value = value.translate(str.maketrans({'ي': 'ی', 'ك': 'ک', '\u200c': ' ', '_': ' ', '-': ' '}))
    return re.sub(r'[^a-z0-9آ-ی]+', '', value)


def title_evidence(source_name, db_name):
    source = normalize_title(source_name)
    db = normalize_title(db_name.replace('...', ''))
    if source == db:
        return 'exact'
    if len(source) >= 4 and source in db:
        return 'source_in_db'
    if len(db) >= 18 and db in source:
        return 'db_prefix_in_source'
    if source == 'pro1700' and 'pro1' in db and '700' in db:
        return 'model_tokens'
    return ''


source = []
for row, values in read_rows(SOURCE):
    if row <= 1 or not values.get('B', '').strip():
        continue
    source.append({
        'row': row, 'number': int(values['B']), 'name': values['C'],
        'purchase': int(float(values['D'])), 'sale': int(float(values['E']))
    })

db_items = []
for row, values in read_rows(MERGED):
    if row <= 1 or not values.get('A', '').strip():
        continue
    db_items.append({
        'row': row, 'number': int(values['A']), 'kind': values['B'],
        'order_id': int(values['C']), 'source_order_id': int(values['D']),
        'site': values['F'], 'status': values['H'],
        'item_id': int(values['M']), 'source_item_id': int(values['N']),
        'name': values['O'], 'quantity': float(values['P']),
        'purchase': values['S'], 'sale_after': float(values['T']),
        'sale_before': float(values['U']),
    })

source_by_order = collections.defaultdict(list)
db_by_order = collections.defaultdict(list)
for item in source:
    source_by_order[item['number']].append(item)
for item in db_items:
    db_by_order[item['number']].append(item)

plans = []
unmatched_db = []
used_source_rows = set()
for number, db_group in sorted(db_by_order.items()):
    source_group = source_by_order[number]
    for db in db_group:
        candidates = [item for item in source_group if title_evidence(item['name'], db['name'])]
        sale_candidates = [
            item for item in candidates
            if item['sale'] in (db['sale_after'], db['sale_before'])
            or item['sale'] * 1000 in (db['sale_after'], db['sale_before'])
        ]
        selected = sale_candidates or candidates
        unique_prices = {item['purchase'] for item in selected}
        if not selected or len(unique_prices) != 1:
            unmatched_db.append({
                'db': db, 'reason': 'no title match' if not selected else 'conflicting purchase prices',
                'candidate_source_rows': [item['row'] for item in selected],
            })
            continue
        for item in selected:
            used_source_rows.add(item['row'])
        sale_match = bool(sale_candidates)
        sale_scale = sorted({
            1 if item['sale'] in (db['sale_after'], db['sale_before']) else 1000
            for item in sale_candidates
        }) if sale_candidates else []
        plans.append({
            'db': db, 'source_rows': [item['row'] for item in selected],
            'source_names': sorted({item['name'] for item in selected}),
            'purchase_raw': next(iter(unique_prices)),
            'sale_match': sale_match, 'sale_scales': sale_scale,
            'title_evidence': sorted({title_evidence(item['name'], db['name']) for item in selected}),
        })

unused_source = [item for item in source if item['row'] not in used_source_rows]
result = {'source_rows': len(source), 'db_items': len(db_items),
          'source_orders': len(source_by_order), 'db_orders': len(db_by_order),
          'plans': plans, 'unmatched_db': unmatched_db, 'unused_source': unused_source}
(OUT / 'analysis.json').write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print('source rows', len(source), 'db items', len(db_items), 'missing purchase',
      sum(x['purchase'] == 'NULL' for x in db_items))
print('matched items', len(plans), 'unmatched db', len(unmatched_db),
      'unused source rows', len(unused_source))
print('plan sale evidence', collections.Counter((x['sale_match'], tuple(x['sale_scales'])) for x in plans))
print('unmatched db', [(x['db']['row'], x['db']['number'], x['db']['item_id'], x['reason'])
                       for x in unmatched_db])
print('unused source', [(x['row'], x['number'], x['name'][:40], x['purchase'])
                        for x in unused_source])
