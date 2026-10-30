{{-- The web pass (phase 7B, plan section 10): the wallet pass as a page for phones without a wallet,
     installable from the browser ("Add to home screen"). App\Http\Controllers\Invite\WalletController@pass. --}}
@php($t = fn ($key) => __('wallet.pass.'.$key))
<!doctype html>
<html lang="{{ $data->locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('wallet.web.title') }} · {{ $data->couple }}</title>
    <meta name="theme-color" content="{{ $data->colors['background'] }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $data->couple }}">
    <link rel="manifest" href="{{ route('invite.pass.manifest', $token, false) }}">
    @if ($fontsUrl)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif
    <style>
        :root{ {!! $themeVars !!}; --p-bg:{{ $data->colors['background'] }}; --p-fg:{{ $data->colors['foreground'] }}; --p-label:{{ $data->colors['label'] }} }
        *{box-sizing:border-box;margin:0}
        body{min-height:100svh;background:color-mix(in srgb,var(--p-bg) 18%,#f4f1ec);font:400 16px/1.6 var(--f-body);color:#1d1d1f;display:grid;place-items:start center;padding:24px 16px calc(24px + env(safe-area-inset-bottom,0px))}
        .pass{width:100%;max-width:380px;background:var(--p-bg);color:var(--p-fg);border-radius:22px;overflow:hidden;box-shadow:0 20px 50px rgb(0 0 0 / .18)}
        .top{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;font:700 14px var(--f-label);color:var(--p-label)}
        .strip{padding:18px 18px 22px;text-align:center;background:radial-gradient(ellipse 70% 80% at 50% 50%,color-mix(in srgb,var(--c-gold) 25%,transparent),transparent 70%)}
        .strip h1{font:700 32px/1.35 var(--f-display)}
        .fields{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 12px;padding:16px 18px}
        .fields .wide{grid-column:1/-1}
        .lbl{display:block;margin-bottom:4px;font:700 11px var(--f-label);color:var(--p-label);letter-spacing:.04em;text-transform:uppercase}
        .val{display:block;font:600 17px/1.4 var(--f-body)}
        .val.big{font:700 24px/1.6 var(--f-display)}
        .qr{background:#fff;margin:6px 18px 18px;border-radius:14px;padding:14px;display:grid;justify-items:center;gap:6px;color:#333}
        .qr svg{width:190px;height:190px}
        .qr small{font:500 12px var(--f-label)}
        .help{max-width:380px;width:100%;margin-top:18px;display:grid;gap:10px;font-size:14px;color:#444;text-align:center}
        .btn{display:inline-flex;justify-content:center;align-items:center;min-height:46px;border-radius:12px;padding:10px 16px;font:600 15px var(--f-label);text-decoration:none;color:#fff;background:#000}
        .btn.google{background:#fff;color:#1f1f1f;border:1px solid #c4c7c5}
        .back{color:var(--p-bg);font-weight:600}
    </style>
</head>
<body>
<main>
    <article class="pass" aria-label="{{ __('wallet.web.title') }}">
        <div class="top"><span>{{ $data->occasion }}</span><span>{{ config('app.name') }}</span></div>
        <div class="strip"><h1>{{ $data->couple }}</h1></div>
        <div class="fields">
            <div class="wide"><span class="lbl">{{ $t('guest') }}</span><span class="val big">{{ $data->guest }}</span></div>
            @if ($data->date)
                <div class="wide"><span class="lbl">{{ $t('date') }}</span><span class="val">{{ $data->date }}</span></div>
            @endif
            @if ($data->time)
                <div><span class="lbl">{{ $t('time') }}</span><span class="val">{{ $data->time }}</span></div>
            @endif
            <div><span class="lbl">{{ $t('seats') }}</span><span class="val">{{ $data->seatsLabel() }}</span></div>
            @if ($data->hall)
                <div class="{{ $data->table ? '' : 'wide' }}"><span class="lbl">{{ $t('hall') }}</span><span class="val">{{ $data->hall }}</span></div>
            @endif
            @if ($data->table)
                <div><span class="lbl">{{ $t('table') }}</span><span class="val big">{{ $data->table }}</span></div>
            @endif
        </div>
        <div class="qr">{!! $qr !!}<small>{{ $t('show_at_door') }}</small></div>
    </article>

    <div class="help">
        @if ($platforms['apple'])
            <a class="btn" href="{{ route('invite.wallet', [$token, 'apple'], false) }}">{{ __('invite.wallet.apple') }}</a>
        @endif
        @if ($platforms['google'])
            <a class="btn google" href="{{ route('invite.wallet', [$token, 'google'], false) }}">{{ __('invite.wallet.google') }}</a>
        @endif
        <strong>{{ __('wallet.web.add_home') }}</strong>
        <span>{{ __('wallet.web.add_home_ios') }}</span>
        <span>{{ __('wallet.web.add_home_android') }}</span>
        <a class="back" href="{{ route('invite.show', $token, false) }}">{{ __('wallet.web.back') }}</a>
    </div>
</main>
</body>
</html>
