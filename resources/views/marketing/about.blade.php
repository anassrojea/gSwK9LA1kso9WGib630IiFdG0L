@extends('marketing.layout')

@section('content')
<section class="page-hero">
    <div class="wrap narrow">
        <h1>{{ __('marketing.about.title') }}</h1>
        <p class="lead">{{ __('marketing.about.lead') }}</p>
        @foreach (__('marketing.about.body') as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </div>
</section>

<section class="section pt-0" aria-labelledby="contact-title">
    <div class="wrap narrow">
        <div class="contact-card">
            <h2 id="contact-title">{{ __('marketing.about.contact') }}</h2>
            <p>{{ __('marketing.about.contact_text') }}</p>
            <div class="actions">
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" class="btn btn-whatsapp btn-lg" target="_blank" rel="noopener" data-track="contact_whatsapp">
                        @include('marketing.partials.icon', ['name' => 'whatsapp']) {{ __('marketing.about.whatsapp') }}
                    </a>
                @endif
                @if ($email)
                    <a href="mailto:{{ $email }}" class="btn btn-ghost btn-lg" dir="ltr">{{ $email }}</a>
                @endif
            </div>
            @if (! $whatsapp && ! $email)
                <p class="muted">{{ __('marketing.about.no_contact') }}</p>
            @endif
        </div>
    </div>
</section>

@include('marketing.partials.cta')
@endsection
