@php
    $query = isset($s['lat'], $s['lng']) ? $s['lat'].','.$s['lng'] : trim($s['name'].' '.($s['address'] ?? ''));
    $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='.urlencode($query);
@endphp
<section class="blk pad sec-place alt">
    <span class="label rv">{{ $s['label'] ?? __('invite.place.label') }}</span>
    <h2 class="rv">{{ $s['name'] }}</h2>
    @isset($s['address'])<p class="rv note">{{ $s['address'] }}</p>@endisset
    <a class="map rv" href="{{ $mapsUrl }}" target="_blank" rel="noopener" aria-label="{{ __('invite.place.map') }}">
        <svg viewBox="0 0 320 200" aria-hidden="true">
            <rect width="320" height="200" class="m-bg"/>
            <path d="M0 140 L320 95" class="m-road" stroke-width="14"/><path d="M120 0 L170 200" class="m-road" stroke-width="10"/>
            <path d="M0 40 L320 60" class="m-road" stroke-width="6"/><path d="M250 0 L240 200" class="m-road" stroke-width="6"/>
            <rect x="20" y="62" width="70" height="50" rx="3" class="m-block"/><rect x="190" y="120" width="40" height="60" rx="3" class="m-block"/><rect x="260" y="110" width="50" height="70" rx="3" class="m-block"/>
            <circle cx="205" cy="80" r="18" class="m-halo"/>
            <path d="M205 52c-9 0-15 6.5-15 14.5 0 10 15 23.5 15 23.5s15-13.5 15-23.5C220 58.5 214 52 205 52z" class="m-pin"/><circle cx="205" cy="66" r="5" class="m-pin-dot"/>
        </svg>
    </a>
    <div class="btns rv">
        <a class="btn solid" href="{{ $mapsUrl }}" target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/></svg>{{ __('invite.place.directions') }}
        </a>
        @if ($date)
            <a class="btn" href="{{ $date->googleCalendarUrl($title, $s['name'].(isset($s['address']) ? ', '.$s['address'] : '')) }}" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="2"/></svg>{{ __('invite.place.calendar') }}
            </a>
        @endif
    </div>
</section>
