{{-- White label (phase 8A, App\Services\Org\WhiteLabel): the organization's logo and name instead of the Daawa badge --}}
<span class="org-brand" data-org-brand>
    @if ($brand['logo_url'])
        <img src="{{ $brand['logo_url'] }}" alt="" width="28" height="28" loading="lazy" decoding="async">
    @endif
    <span><bdi>{{ $brand['name'] }}</bdi></span>
</span>
