<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $category->name }} · Cuadro para imprimir</title>
    <style>
        @page { size: 297mm 210mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 7pt; color: #111; }
        .page { position: relative; width: 297mm; height: 209.5mm; overflow: hidden; }
        .page + .page { page-break-before: always; }
        .abs { position: absolute; }
        .title { font-size: 13pt; font-weight: bold; }
        .meta { font-size: 8pt; color: #333; }
        .line { position: absolute; background: #222; }
        .card { position: absolute; border: 0.3mm solid #222; background: #fff; padding: 0.4mm 1.2mm; overflow: hidden; }
        .head { font-size: 5pt; color: #555; height: 2.4mm; white-space: nowrap; overflow: hidden; }
        .row { font-size: 6.6pt; height: 3.4mm; white-space: nowrap; overflow: hidden; }
        .sq { display: inline-block; width: 2.4mm; height: 2.4mm; border: 0.25mm solid #222; margin-right: 1mm; }
        .aka { background: #d11a2a; }
        .shiro { background: #fff; }
        .club { color: #555; font-size: 5.6pt; }
        .bib { font-weight: bold; }
        .blank { display: inline-block; width: 13mm; border-bottom: 0.25mm solid #222; height: 2.6mm; }
        .box { position: absolute; border: 0.3mm solid #222; padding: 1.5mm 2.5mm; font-size: 8pt; }
        .tag { color: #555; font-size: 5.2pt; font-style: italic; }
        /* Tarjetas compactas (muchas filas por hoja): cabecera y filas más bajas. */
        .compact .card { padding: 0.2mm 1.2mm; }
        .compact .head { font-size: 4.6pt; height: 2.1mm; }
        .compact .row { font-size: 6pt; height: 3mm; }
        .compact .club, .compact .tag { font-size: 5pt; }
        .foot { font-size: 6pt; color: #555; }
    </style>
</head>
<body>
@foreach ($pages as $i => $page)
    <div class="page{{ $page['compact'] ? ' compact' : '' }}">
        <div class="abs" style="left: {{ \App\Tournament\PaperBracket::MARGIN }}mm; top: {{ \App\Tournament\PaperBracket::MARGIN }}mm; width: 200mm;">
            <div class="title">{{ $event->name }} — {{ $category->name }}</div>
            <div class="meta">
                {{ $event->startsAtLabel() }} · {{ $event->venue }}@if ($category->tatami) · Tatami {{ $category->tatami }}@endif
                · {{ $page['subtitle'] }}
            </div>
        </div>
        <div class="abs meta" style="right: {{ \App\Tournament\PaperBracket::MARGIN }}mm; top: {{ \App\Tournament\PaperBracket::MARGIN }}mm; text-align: right; width: 70mm;">
            Hoja {{ $i + 1 }} de {{ count($pages) }}<br>
            <span class="sq aka"></span>AKA (rojo) &nbsp; <span class="sq shiro"></span>SHIRO (blanco)
        </div>

        @foreach ($page['lines'] as $l)
            <div class="line" style="left: {{ $l['x'] }}mm; top: {{ $l['y'] }}mm; width: {{ $l['w'] }}mm; height: {{ $l['h'] }}mm;"></div>
        @endforeach

        @foreach ($page['cards'] as $card)
            <div class="card" style="left: {{ $card['x'] }}mm; top: {{ $card['y'] }}mm; width: {{ $card['w'] }}mm; height: {{ $card['h'] }}mm;">
                <div class="head">{{ $card['head'] }}</div>
                @foreach ($card['rows'] as $row)
                    <div class="row">
                        <span class="sq {{ $row['side'] }}"></span>
                        @if ($row['name'])
                            @if ($row['label'])<span class="tag">{{ $row['label'] }}</span> @endif{{ $row['name'] }}@if ($row['club']) <span class="club">({{ $row['club'] }})</span>@endif
                        @elseif ($row['label'])
                            <span class="club">{{ $row['label'] }}</span>
                        @endif
                        @if ($row['bib'])
                            <span class="bib">Nº {{ $row['bib'] }}</span>
                        @elseif ($row['blank'])
                            Nº <span class="blank">&nbsp;</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        @if (! empty($page['extras']))
            @php $x = $page['extras']; @endphp
            @if ($x['bronze'])
                <div class="box" style="left: {{ \App\Tournament\PaperBracket::MARGIN }}mm; top: {{ $x['y'] }}mm; width: 120mm;">
                    <strong>Combate por el bronce @if ($x['bronze']['number']) (n.º {{ $x['bronze']['number'] }})@endif:</strong>
                    <span class="sq aka"></span>Nº <span class="blank">&nbsp;</span> &nbsp;vs&nbsp; <span class="sq shiro"></span>Nº <span class="blank">&nbsp;</span>
                </div>
            @endif
            <div class="box" style="right: {{ \App\Tournament\PaperBracket::MARGIN }}mm; top: {{ $x['y'] }}mm; width: 150mm;">
                <strong>Podio:</strong> 1.º Nº <span class="blank">&nbsp;</span> &nbsp; 2.º Nº <span class="blank">&nbsp;</span> &nbsp; 3.º Nº <span class="blank">&nbsp;</span>@if ($x['fourth']) &nbsp; 4.º Nº <span class="blank">&nbsp;</span>@endif
            </div>
        @endif

        <div class="abs foot" style="left: {{ \App\Tournament\PaperBracket::MARGIN }}mm; bottom: 3mm;">KumiteControl · Cuadro para rellenar a mano: en la primera ronda (y los exentos) constan los nombres; en las siguientes, escribe el número de competidor.</div>
    </div>
@endforeach
</body>
</html>
