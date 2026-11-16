"""PDF privado: JSON por stdin, PDF por stdout. Invocado por PHP, sin acceso a BD."""
import io
import json
import re
import sys
from xml.sax.saxutils import escape
from reportlab.lib import colors
from reportlab.lib.pagesizes import letter, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, LongTable, Table, TableStyle


def generar(data):
    output = io.BytesIO()
    styles = {
        'body': ParagraphStyle('body', fontName='Helvetica', fontSize=8, leading=11),
        'center': ParagraphStyle('center', fontName='Helvetica', fontSize=10, leading=14, alignment=1),
        'title': ParagraphStyle('title', fontName='Helvetica-Bold', fontSize=17, leading=22, alignment=1, textColor=colors.HexColor('#153750')),
    }
    def p(text, style='body'):
        return Paragraph(escape(str(text)).replace('\n', '<br/>'), styles[style])
    ranged = data.get('tipo') == 'rango'
    margin = 24 if ranged else 32
    doc = SimpleDocTemplate(output, pagesize=landscape(letter) if ranged else letter, rightMargin=margin, leftMargin=margin,
                            topMargin=30, bottomMargin=35, title=data['titulo'], author='TYTOne Báscula')
    story = [p(data['empresa'], 'title'), Spacer(1, 8)]
    story += [p(line, 'center') for line in data['datos_empresa']]
    story += [Spacer(1, 16), p(data['titulo'], 'title'), Spacer(1, 8), p(data['subtitulo'], 'center'), Spacer(1, 20)]
    if data['campos']:
        table = Table([[p(c) for c in row] for row in data['campos']], colWidths=[85,189,85,189], hAlign='LEFT')
        table.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('BOTTOMPADDING',(0,0),(-1,-1),9)]))
        story += [table, Spacer(1, 18)]
    headers = data['cabeceras']
    receipt = len(headers) == 10 and data['firmas']
    widths = [45,45,42,78,65,34,34,34,36,35,34,28,32,28,32,40,41] if ranged else ([79,79,61,143,62,62,62] if len(headers) == 7 else [54,54,54,54,54,54,40,40,54,90])
    if ranged: widths = [w*744/sum(widths) for w in widths]
    def numeric_cell(text, column):
        # Ajustar números largos al ancho de su celda sin partirlos ni alterar el valor.
        value = str(text)
        if re.fullmatch(r'[0-9]+(?:\.[0-9]+)?',value):
            integer,dot,fraction=value.partition('.')
            value=f'{int(integer):,}' + (dot+fraction if dot else '')
        target = 17 if column == 9 else 10.5
        size = min(target, (widths[column] - 10) / max(stringWidth(value, 'Helvetica-Bold', 1), 1))
        style = ParagraphStyle('number', fontName='Helvetica-Bold', fontSize=size, leading=size * 1.3,
                               textColor=colors.HexColor('#7d263c') if column == 9 else colors.HexColor('#16283f'))
        return Paragraph(escape(value), style)
    def ranged_cell(value, column, total=False):
        if column < 5: return p(value,'compact')
        text=str(value);font='Helvetica-Bold' if total or column == 16 else 'Helvetica'
        size=min(8 if column == 16 else 7,(widths[column]-5)/max(stringWidth(text,font,1),1))
        style=ParagraphStyle('range-number',fontName=font,fontSize=size,leading=size*1.35,
                             textColor=colors.HexColor('#7d263c') if column == 16 else colors.HexColor('#16283f'))
        return Paragraph(escape(text),style)
    if ranged:
        styles['compact'] = ParagraphStyle('compact',fontName='Helvetica',fontSize=7,leading=9)
        rows = [[p(c,'compact') for c in headers]]
        for i, row in enumerate(data['filas']):
            rows.append([ranged_cell(c,j) for j,c in enumerate(row)])
            rows.append([p(data['detalles'][i],'compact')]+['']*(len(headers)-1))
    else:
        rows = [[p(c) for c in headers]] + [
            [numeric_cell(c, i) if receipt else p(c) for i, c in enumerate(row)] for row in data['filas']]
    if not data['filas']:
        rows.append([p('Sin boletas cerradas en este rango.')] + [''] * (len(headers)-1))
    if data['totales']:
        rows.append([ranged_cell(c,j,True) if ranged else p(c) for j,c in enumerate(data['totales'])])
    # 548 pt de ancho imprimible tanto para reporte como para boleta.
    table = LongTable(rows, colWidths=widths, repeatRows=1, splitInRow=1 if ranged else 0, hAlign='LEFT')
    rules = [('BACKGROUND',(0,0),(-1,0),colors.HexColor('#eaf0f7')),
             ('LINEBELOW',(0,0),(-1,0),1,colors.HexColor('#153750')),
             ('LINEBELOW',(0,1),(-1,-1),.3,colors.HexColor('#cccccc')),
             ('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),4),
             ('RIGHTPADDING',(0,0),(-1,-1),4),('TOPPADDING',(0,0),(-1,-1),7),('BOTTOMPADDING',(0,0),(-1,-1),7)]
    if ranged:
        for i in range(len(data['filas'])):
            r=2+2*i
            rules += [('SPAN',(0,r),(-1,r)),('BACKGROUND',(0,r),(-1,r),colors.HexColor('#f6f8fb'))]
        rules += [('TOPPADDING',(0,0),(-1,-1),4),('BOTTOMPADDING',(0,0),(-1,-1),4),
                  ('LEFTPADDING',(0,0),(-1,-1),2),('RIGHTPADDING',(0,0),(-1,-1),2)]
    if receipt and data['filas']:
        rules += [('BACKGROUND',(9,1),(9,-1),colors.HexColor('#f9edf0')),
                  ('VALIGN',(0,1),(-1,-1),'MIDDLE'),('TOPPADDING',(0,1),(-1,-1),10),('BOTTOMPADDING',(0,1),(-1,-1),10)]
    if not data['filas']:
        rules.append(('SPAN',(0,1),(-1,1)))
    if data['totales']:
        rules.append(('BACKGROUND',(0,-1),(-1,-1),colors.HexColor('#eaf0f7')))
    table.setStyle(TableStyle(rules))
    story += [table, Spacer(1, 15)]
    for line in data['notas']:
        story += [p(line), Spacer(1, 7)]
    if data['firmas']:
        signatures = Table([[p('Firma operador báscula','center'),'',p('Firma cliente / proveedor','center')]], colWidths=[244,60,244])
        signatures.setStyle(TableStyle([('LINEABOVE',(0,0),(0,0),.5,colors.black),('LINEABOVE',(2,0),(2,0),.5,colors.black),('TOPPADDING',(0,0),(-1,-1),8)]))
        story += [Spacer(1, 60), signatures]
    def footer(canvas, document):
        canvas.saveState();canvas.setFont('Helvetica',8)
        canvas.drawString(32,18,'TYTOne Báscula')
        canvas.drawRightString(document.pagesize[0]-margin,18,f'Página {document.page}')
        canvas.restoreState()
    doc.build(story,onFirstPage=footer,onLaterPages=footer)
    return output.getvalue()

if __name__ == '__main__':
    try:
        sys.stdout.buffer.write(generar(json.load(io.TextIOWrapper(sys.stdin.buffer, encoding='utf-8'))))
    except Exception:
        # No volcar datos del documento ni trazas privadas a registros.
        print('No se pudo generar el documento PDF.',file=sys.stderr)
        raise SystemExit(1)
