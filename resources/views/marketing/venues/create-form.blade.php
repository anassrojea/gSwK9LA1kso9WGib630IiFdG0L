{{-- POST /create with the venue's hall preselected (phase 8B). $hallId may be null (a venue without public halls). --}}
<form method="post" action="{{ route('create.store') }}" class="venue-create" data-venue-create>
    @csrf
    @if ($hallId)
        <input type="hidden" name="hall_layout_id" value="{{ $hallId }}">
    @endif
    <label class="sr-only" for="occ-{{ $place }}-{{ $hallId }}">{{ __('marketing.nav.occasions') }}</label>
    <select name="occasion" id="occ-{{ $place }}-{{ $hallId }}" class="venue-input venue-occasion">
        @foreach ($occasions as $occasion)
            <option value="{{ $occasion }}">{{ __("marketing.occasions.items.{$occasion}.name") }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn btn-primary {{ $big ? 'btn-lg' : '' }}" data-track="cta_create" data-track-place="{{ $place }}" rel="nofollow">
        {{ $label }} @include('marketing.partials.icon', ['name' => 'arrow'])
    </button>
</form>
