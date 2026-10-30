{{-- The hall (plan section 7.1) as SVG for the printables. $seated: table id => taken seat indexes (optional). --}}
@php
    $w = $layout['size']['w'] ?? 800;
    $h = $layout['size']['h'] ?? 580;
    $seated = $seated ?? [];
    $sectionNames = ['men' => 'رجال · Men', 'women' => 'نساء · Women', 'mixed' => ''];
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
        <rect x="{{ $e['x'] }}" y="{{ $e['y'] }}" width="{{ $e['w'] }}" height="8" rx="3" class="h-entrance"/>
    @endforeach
    @foreach ($layout['tables'] as $t)
        @php
            $taken = array_flip($seated[$t['id']] ?? []);
            $ring = $t['ring'] ?? 30;
            $chairR = $t['chairR'] ?? 6;
        @endphp
        <g class="h-table @if ($taken) is-used @endif">
            @for ($c = 0; $c < $t['seats']; $c++)
                @php $angle = -M_PI / 2 + $c * 2 * M_PI / $t['seats']; @endphp
                <circle cx="{{ round($t['x'] + $ring * cos($angle), 1) }}" cy="{{ round($t['y'] + $ring * sin($angle), 1) }}" r="{{ $chairR }}" class="h-chair @if (isset($taken[$c])) is-taken @endif"/>
            @endfor
            <circle cx="{{ $t['x'] }}" cy="{{ $t['y'] }}" r="{{ $t['r'] ?? 14 }}" class="h-top"/>
            <text x="{{ $t['x'] }}" y="{{ $t['y'] + min(($t['r'] ?? 14) * 0.38, 7) }}" text-anchor="middle" class="h-num" style="font-size: {{ max(9, min(22, ($t['r'] ?? 14) * 0.95)) }}px">{{ \App\Services\Seating\HallLayoutData::tableNumber($t['id']) }}</text>
        </g>
    @endforeach
</svg>
