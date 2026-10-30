{{-- Small inline icons (no icon font, no requests). Usage: @include('marketing.partials.icon', ['name' => 'link']) --}}
<svg class="icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
    @case('link')<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>@break
    @case('check')<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16 10"/>@break
    @case('whatsapp')<path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.1L4 20z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.4-2-1-1 .9a4 4 0 0 1-2-2l.9-1-1-2z"/>@break
    @case('seat')<path d="M6 11V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v5"/><path d="M4 11h16v4H4z"/><path d="M6 15v5M18 15v5"/>@break
    @case('qr')<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2"/>@break
    @case('photo')<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/>@break
    @case('moon')<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>@break
    @case('bolt')<path d="M13 3 5 14h6l-1 7 8-11h-6z"/>@break
    @case('arrow')<path d="M5 12h14M13 6l6 6-6 6"/>@break
    @case('play')<circle cx="12" cy="12" r="9"/><path d="m10 8.5 5 3.5-5 3.5z" fill="currentColor"/>@break
    @case('yes')<path d="m5 12.5 4.5 4.5L19 7.5"/>@break
    @case('no')<path d="M7 12h10"/>@break
@endswitch
</svg>
