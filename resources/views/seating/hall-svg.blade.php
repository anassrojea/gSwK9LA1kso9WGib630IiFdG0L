{{-- The hall (plan section 7.1) as SVG for the printables and the venue directory. $seated: table id => taken seat indexes (optional).
     Every shape of App\Services\Seating\HallLayoutData: round tables, banquet tables, sofas, rows of chairs, plus obstacles and labels. --}}
@php
    use App\Services\Seating\HallLayoutData;

    $w = $layout['size']['w'] ?? 800;
    $h = $layout['size']['h'] ?? 580;
    $seated = $seated ?? [];
    $sectionNames = ['men' => 'رجال · Men', 'women' => 'نساء · Women', 'vip' => 'كبار الضيوف · VIP', 'mixed' => ''];
    $obstacleNames = ['buffet' => 'بوفيه · Buffet', 'column' => '', 'obstacle' => ''];
@endphp
<svg class="hall" viewBox="0 0 {{ $w }} {{ $h }}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="{{ $layout['name'] ?? '' }}">
    <rect x="0.5" y="0.5" width="{{ $w - 1 }}" height="{{ $h - 1 }}" rx="10" class="h-floor"/>
    @foreach ($layout['sections'] ?? [] as $section)
        @php $a = $section['area']; @endphp
        @if (($section['id'] ?? '') !== 'mixed')
            <rect x="{{ $a['x'] }}" y="{{ $a['y'] }}" width="{{ $a['w'] }}" height="{{ $a['h'] }}" rx="8" class="h-section h-{{ $section['id'] }}"/>
            <text x="{{ $a['x'] + $a['w'] / 2 }}" y="{{ $a['y'] - 6 }}" class="h-section-label" text-anchor="middle">{{ $sectionNames[$section['id']] ?? $section['label'] }}</text>
        @endif
    @endforeach
    @isset($layout['stage'])
        @php $s = $layout['stage']; @endphp
        <rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ $s['w'] }}" height="{{ $s['h'] }}" rx="8" class="h-stage"/>
        <text x="{{ $s['x'] + $s['w'] / 2 }}" y="{{ $s['y'] + $s['h'] / 2 + 5 }}" text-anchor="middle" class="h-stage-label">الكوشة · {{ $s['label'] ?? 'Kosha' }}</text>
    @endisset
    @isset($layout['danceFloor'])
        @php $d = $layout['danceFloor']; @endphp
        <rect x="{{ $d['x'] }}" y="{{ $d['y'] }}" width="{{ $d['w'] }}" height="{{ $d['h'] }}" rx="6" class="h-dance"/>
    @endisset
    @foreach ($layout['entrances'] ?? [] as $e)
        @if (($e['rotation'] ?? 0) === 90)
            <rect x="{{ $e['x'] }}" y="{{ $e['y'] }}" width="8" height="{{ $e['w'] }}" rx="3" class="h-entrance"/>
        @else
            <rect x="{{ $e['x'] }}" y="{{ $e['y'] }}" width="{{ $e['w'] }}" height="8" rx="3" class="h-entrance"/>
        @endif
    @endforeach
    @foreach ($layout['obstacles'] ?? [] as $o)
        @if ($o['kind'] === 'column')
            <ellipse cx="{{ $o['x'] + $o['w'] / 2 }}" cy="{{ $o['y'] + $o['h'] / 2 }}" rx="{{ $o['w'] / 2 }}" ry="{{ $o['h'] / 2 }}" class="h-obstacle h-column"/>
        @else
            <rect x="{{ $o['x'] }}" y="{{ $o['y'] }}" width="{{ $o['w'] }}" height="{{ $o['h'] }}" rx="4" class="h-obstacle h-{{ $o['kind'] }}"/>
        @endif
        @if (($o['label'] ?? '') !== '' || $obstacleNames[$o['kind']] !== '')
            <text x="{{ $o['x'] + $o['w'] / 2 }}" y="{{ $o['y'] + $o['h'] / 2 + 4 }}" text-anchor="middle" class="h-obstacle-label">{{ $o['label'] ?? $obstacleNames[$o['kind']] }}</text>
        @endif
    @endforeach
    @foreach ($layout['tables'] as $t)
        @php
            $taken = array_flip($seated[$t['id']] ?? []);
            $ring = $t['ring'] ?? 30;
            $chairR = $t['chairR'] ?? 6;
            $shape = $t['shape'] ?? 'round';
        @endphp
        @if ($shape === 'round')
        <g class="h-table @if ($taken) is-used @endif @if (! empty($t['kids'])) is-kids @endif">
            @for ($c = 0; $c < $t['seats']; $c++)
                @php $angle = -M_PI / 2 + $c * 2 * M_PI / $t['seats']; @endphp
                <circle cx="{{ round($t['x'] + $ring * cos($angle), 1) }}" cy="{{ round($t['y'] + $ring * sin($angle), 1) }}" r="{{ $chairR }}" class="h-chair @if (isset($taken[$c])) is-taken @endif"/>
            @endfor
            <circle cx="{{ $t['x'] }}" cy="{{ $t['y'] }}" r="{{ $t['r'] ?? 14 }}" class="h-top"/>
            <text x="{{ $t['x'] }}" y="{{ $t['y'] + min(($t['r'] ?? 14) * 0.38, 7) }}" text-anchor="middle" class="h-num" style="font-size: {{ max(9, min(22, ($t['r'] ?? 14) * 0.95)) }}px">{{ HallLayoutData::tableNumber($t['id']) }}</text>
        </g>
        @else
            @php
                [$bw, $bh] = HallLayoutData::bodySize($t);
                $chairs = HallLayoutData::chairPositions($t);
                $font = max(9, min(20, min($bw, $bh) * 0.7));
            @endphp
            <g class="h-table h-{{ $shape }} @if ($taken) is-used @endif @if (! empty($t['kids'])) is-kids @endif">
                @if ($shape === 'sofa')
                    {{-- the back of the sofa is away from its seats --}}
                    @php $turned = ($t['rotation'] ?? 0) === 90; @endphp
                    <rect x="{{ $t['x'] - $bw / 2 }}" y="{{ $t['y'] - $bh / 2 }}" width="{{ $bw }}" height="{{ $bh }}" rx="{{ min(8, $bh / 3) }}" class="h-top"/>
                    @if ($turned)
                        <rect x="{{ $t['x'] + $bw / 2 - $bw * 0.28 }}" y="{{ $t['y'] - $bh / 2 }}" width="{{ $bw * 0.28 }}" height="{{ $bh }}" rx="3" class="h-sofa-back"/>
                    @else
                        <rect x="{{ $t['x'] - $bw / 2 }}" y="{{ $t['y'] - $bh / 2 }}" width="{{ $bw }}" height="{{ $bh * 0.28 }}" rx="3" class="h-sofa-back"/>
                    @endif
                @elseif ($shape === 'rect')
                    <rect x="{{ $t['x'] - $bw / 2 }}" y="{{ $t['y'] - $bh / 2 }}" width="{{ $bw }}" height="{{ $bh }}" rx="3" class="h-top"/>
                @endif
                @foreach ($chairs as $c => $p)
                    <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="{{ $chairR }}" class="h-chair @if (isset($taken[$c])) is-taken @endif"/>
                @endforeach
                @if ($shape === 'row')
                    @php $first = $chairs[0] ?? ['x' => $t['x'], 'y' => $t['y']]; $turned = ($t['rotation'] ?? 0) === 90; @endphp
                    <text x="{{ $turned ? $t['x'] : $first['x'] - $chairR - 9 }}" y="{{ $turned ? $first['y'] - $chairR - 5 : $t['y'] + 4 }}" text-anchor="middle" class="h-num" style="font-size: 11px">{{ HallLayoutData::tableNumber($t['id']) }}</text>
                @else
                    <text x="{{ $t['x'] }}" y="{{ $t['y'] + $font * 0.36 }}" text-anchor="middle" class="h-num" style="font-size: {{ $font }}px">{{ HallLayoutData::tableNumber($t['id']) }}</text>
                @endif
            </g>
        @endif
    @endforeach
    @foreach ($layout['labels'] ?? [] as $l)
        <text x="{{ $l['x'] }}" y="{{ $l['y'] }}" text-anchor="middle" class="h-label" style="font-size: {{ $l['size'] }}px">{{ $l['text'] }}</text>
    @endforeach
</svg>
