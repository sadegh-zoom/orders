import fs from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { Workbook, SpreadsheetFile } from '@oai/artifact-tool';

const dir = new URL('.', import.meta.url);
const data = JSON.parse(await fs.readFile(new URL('matched.json', dir), 'utf8'));
const wb = Workbook.create();
const headers = ['ماه', 'ردیف فایل مبدا', 'شماره سفارش فایل', 'مدل / کالای فایل', 'قیمت خرید فایل', 'فروشگاه مبدا', 'قیمت فروش کالا', 'شماره سفارش Order', 'شماره سفارش سایت مبدا', 'نام Agent', 'قیمت خرید سایت', 'نام کالای سایت', 'وضعیت تطبیق', 'منبع فروش'];
for (const month of ['مهر', 'شهریور']) {
  const rows = data.filter(x => x.sheet === month);
  const sheet = wb.worksheets.add(month);
  sheet.showGridLines = false;
  sheet.getRange('A1:N1').values = [headers];
  sheet.getRange(`A2:N${rows.length + 1}`).values = rows.map(x => [x.sheet, x.row, x.reported_id, x.reported_product, x.reported_purchase, x.site, x.sale_price, x.central_id, x.source_id, x.agent, x.site_purchase, x.site_product, x.status, x.source_file]);
  sheet.getRange('A1:N1').format = { fill: '#17365D', font: { name: 'Arial', bold: true, color: '#FFFFFF', size: 11 }, rowHeight: 30, wrapText: true };
  sheet.getRange(`A2:N${rows.length + 1}`).format.font = { name: 'Arial', size: 10 };
  sheet.getRange(`A2:N${rows.length + 1}`).format.rowHeight = 25;
  for (const [col, width] of Object.entries({A:12,B:16,C:18,D:55,E:20,F:18,G:20,H:22,I:24,J:17,K:20,L:55,M:35,N:20})) sheet.getRange(`${col}:${col}`).format.columnWidth = width;
  for (const col of ['E','G','K']) sheet.getRange(`${col}2:${col}${rows.length+1}`).format.numberFormat = '#,##0.##';
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    if (!r.source_id) sheet.getRange(`M${i+2}`).format.fill = r.status.includes('مبهم') ? '#FFF0C2' : '#FDE9E7';
  }
}
wb.recalculate();
for (const month of ['مهر', 'شهریور']) {
  const preview = await wb.render({ sheetName: month, range: 'A1:N8', scale: 1, format: 'png' });
  await fs.writeFile(`/tmp/orders-${month}.png`, new Uint8Array(await preview.arrayBuffer()));
}
const output = await SpreadsheetFile.exportXlsx(wb);
await output.save(fileURLToPath(new URL('orders-matched-mehr-shahrivar.xlsx', dir)));
console.log('exported', data.length);
