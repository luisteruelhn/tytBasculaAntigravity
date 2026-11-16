"""Rellena la plantilla XLSX de Artifact Tool con datos privados por stdin.

Solo biblioteca estándar: no instala Excel, Node ni dependencias en producción.
"""
import copy
from datetime import datetime
from decimal import Decimal
import io
import json
import math
from pathlib import Path
import re
import sys
import zipfile
import xml.etree.ElementTree as ET

NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
M = '{' + NS + '}'
R = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'
ET.register_namespace('', NS)
ET.register_namespace('r', R[1:-1])
NUMBERS = {5,6,7,8,9,10,12,14,15,16,23}
PERCENT = {11,13,38,39}
DATES = {0,1,25,26,27,28}
TOTALS = {5,6,7,8,9,10,12,14,15,16}


def column(index):
    text = ''
    index += 1
    while index:
        index, rem = divmod(index - 1, 26)
        text = chr(65 + rem) + text
    return text


def clean(value):
    return re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f]', '\ufffd', str(value))


def typed(value, index):
    if value is None or value in ('', '—'):
        return None
    if index in DATES:
        moment = datetime.fromisoformat(value)
        delta = moment - datetime(1899,12,30)
        return Decimal(delta.days) + Decimal(delta.seconds) / Decimal(86400)
    if index in NUMBERS:
        return Decimal(str(value))
    if index in PERCENT:
        return Decimal(str(value)) / Decimal(100)
    return clean(value)


def cell(ref, value, style=None, formula=None):
    attrs = {'r':ref}
    if style is not None: attrs['s'] = style
    c = ET.Element(M+'c', attrs)
    if value is None: return c
    if formula:
        if not isinstance(value, Decimal): c.set('t','str')
        ET.SubElement(c,M+'f').text = formula
        ET.SubElement(c,M+'v').text = str(value)
    elif isinstance(value, Decimal):
        ET.SubElement(c,M+'v').text = format(value,'f')
    else:
        c.set('t','inlineStr')
        t = ET.SubElement(ET.SubElement(c,M+'is'),M+'t')
        t.set('{http://www.w3.org/XML/1998/namespace}space','preserve')
        t.text = clean(value)
    return c


def generar(data):
    template = Path(__file__).parent / 'plantillas/reporte_pesadas.xlsx'
    original = zipfile.ZipFile(template)
    records = data['excel_filas']
    count = len(data['excel_cabeceras'])
    data_last = 5 + max(1,len(records))
    data_total = data_last + 1
    report_total = 6 + 2 * len(records)
    replacement = {}
    for sheet_no, name in [(1,'Reporte'),(2,'Datos')]:
        printing = sheet_no == 1
        source = ET.fromstring(original.read(f'xl/worksheets/sheet{sheet_no}.xml'))
        rows = {int(r.get('r')):r for r in source.find(M+'sheetData')}
        count_sheet = 17 if printing else count
        last = report_total if printing else data_total
        end = column(count_sheet-1)
        root = ET.Element(M+'worksheet')
        props = ET.SubElement(root,M+'sheetPr')
        ET.SubElement(props,M+'pageSetUpPr',{'fitToPage':'1'})
        ET.SubElement(root,M+'dimension',{'ref':f'A1:{end}{last}'})
        view = ET.SubElement(ET.SubElement(root,M+'sheetViews'),M+'sheetView',{'workbookViewId':'0','showGridLines':'0'})
        ET.SubElement(view,M+'pane',{'xSplit':'3','ySplit':'5','topLeftCell':'D6','activePane':'bottomRight','state':'frozen'})
        ET.SubElement(root,M+'sheetFormatPr',{'defaultRowHeight':'17'})
        cols = source.find(M+'cols')
        if cols is not None: root.append(copy.deepcopy(cols))
        grid = ET.SubElement(root,M+'sheetData')
        for number in range(1,6):
            row = copy.deepcopy(rows.get(number,ET.Element(M+'row',{'r':str(number)})))
            if number in (2,3):
                first = row.find(M+'c')
                style = first.get('s') if first is not None else None
                for c in list(row): row.remove(c)
                text = data['titulo'] if number == 2 else data['empresa'] + ' · ' + data['subtitulo']
                row.append(cell('A'+str(number),text,style))
            grid.append(row)
        styles = {re.sub(r'[0-9]','',c.get('r')):c.get('s') for c in rows[6]}
        for i, values in enumerate(records if records else ([] if printing else [[None]*count])):
            r = 6 + (2*i if printing else i)
            heights=[27]
            for j in range(count_sheet):
                value=values[j]
                if value is None or j in NUMBERS or j in PERCENT or j in DATES: continue
                width=next((float(c.get('width','13')) for c in cols if int(c.get('min'))<=j+1<=int(c.get('max'))),13) if cols is not None else 13
                lines=sum(max(1,math.ceil(len(line)/max(1,width))) for line in str(value).split('\n'))
                heights.append(lines*14)
            row = ET.SubElement(grid,M+'row',{'r':str(r),'ht':str(min(409,max(heights))),'customHeight':'1'})
            for j in range(count_sheet):
                value = typed(values[j],j)
                if printing and value is None and values[j] == '—': value = '—'
                formula = f"'Datos'!{column(j)}{6+i}" if printing and value is not None and value != '—' else None
                row.append(cell(f'{column(j)}{r}',value,styles.get(column(j)),formula))
            if printing:
                detail = data['detalles'][i]
                height = min(405, max(40, 13*(len(detail)//170+detail.count('\n')+1)))
                row = ET.SubElement(grid,M+'row',{'r':str(r+1),'ht':str(height),'customHeight':'1'})
                row.append(cell('A'+str(r+1),detail,rows[7][0].get('s')))
        total_template = rows[8 if printing else 7]
        total_styles = {re.sub(r'[0-9]','',c.get('r')):c.get('s') for c in total_template}
        row = ET.SubElement(grid,M+'row',{'r':str(last),'ht':'23','customHeight':'1'})
        for j in range(count_sheet):
            value = 'TOTALES' if j == 3 else None
            formula = None
            if j in TOTALS:
                value = typed(data['totales'][j],j)
                if value is None and data['totales'][j] == '—': value = '—'
                if isinstance(value,Decimal): formula = f"'Datos'!{column(j)}{data_total}" if printing else f'SUM({column(j)}6:{column(j)}{data_last})'
            row.append(cell(f'{column(j)}{last}',value,total_styles.get(column(j)),formula))
        if printing and records:
            merges = ET.SubElement(root,M+'mergeCells',{'count':str(len(records))})
            for i in range(len(records)): ET.SubElement(merges,M+'mergeCell',{'ref':f'A{7+2*i}:Q{7+2*i}'})
        ET.SubElement(root,M+'printOptions',{'horizontalCentered':'1'})
        ET.SubElement(root,M+'pageMargins',{'left':'0.25','right':'0.25','top':'0.3','bottom':'0.3','header':'0.1','footer':'0.1'})
        ET.SubElement(root,M+'pageSetup',{'paperSize':'1','orientation':'landscape','fitToWidth':'1' if printing else '0','fitToHeight':'0'})
        if not printing:
            parts = source.find(M+'tableParts')
            if parts is not None: root.append(copy.deepcopy(parts))
        replacement[f'xl/worksheets/sheet{sheet_no}.xml'] = ET.tostring(root,encoding='utf-8',xml_declaration=True)
    for name in original.namelist():
        if name.startswith('xl/tables/') and name.endswith('.xml'):
            table = ET.fromstring(original.read(name))
            table.set('ref',f'A5:{column(count-1)}{data_last}')
            filt = table.find(M+'autoFilter')
            if filt is not None: filt.set('ref',table.get('ref'))
            replacement[name] = ET.tostring(table,encoding='utf-8',xml_declaration=True)
    book = ET.fromstring(original.read('xl/workbook.xml'))
    defined = book.find(M+'definedNames')
    if defined is None: defined = ET.SubElement(book,M+'definedNames')
    for child in list(defined):
        if child.get('name') in ('_xlnm.Print_Area','_xlnm.Print_Titles'): defined.remove(child)
    for i,name,last,end in [(0,'Reporte',report_total,'Q'),(1,'Datos',data_total,column(count-1))]:
        ET.SubElement(defined,M+'definedName',{'name':'_xlnm.Print_Area','localSheetId':str(i)}).text = f"'{name}'!$A$1:${end}${last}"
        ET.SubElement(defined,M+'definedName',{'name':'_xlnm.Print_Titles','localSheetId':str(i)}).text = f"'{name}'!$1:$5"
    replacement['xl/workbook.xml'] = ET.tostring(book,encoding='utf-8',xml_declaration=True)
    output = io.BytesIO()
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as result:
        for item in original.infolist(): result.writestr(item.filename,replacement.get(item.filename,original.read(item.filename)))
    return output.getvalue()


if __name__ == '__main__':
    try:
        sys.stdout.buffer.write(generar(json.load(io.TextIOWrapper(sys.stdin.buffer,encoding='utf-8'))))
    except Exception:
        print('No se pudo generar el documento Excel.',file=sys.stderr)
        raise SystemExit(1)
