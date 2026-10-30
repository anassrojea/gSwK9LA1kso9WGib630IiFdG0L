<section class="cta-band" aria-labelledby="cta-title">
    <div class="wrap">
        <h2 id="cta-title">{{ __('marketing.cta.title') }}</h2>
        <p>{{ __('marketing.cta.text') }}</p>
        <a href="{{ route('create') }}" class="btn btn-light btn-lg" data-track="cta_create" data-track-place="band" rel="nofollow">
            {{ __('marketing.cta.button') }} @include('marketing.partials.icon', ['name' => 'arrow'])
        </a>
    </div>
</section>
