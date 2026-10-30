{{-- "Find your table" (phase 8B): the page behind the entrance QR. No guest data in the HTML; resources/js/find-table.js asks POST /e/{slug}/find. --}}
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('venues.find.title') }} · {{ $title }}</title>
    @if ($fontsUrl)
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif
    <style>
        :root{ {!! $themeVars !!} }
        *{box-sizing:border-box}
        body{margin:0;min-height:100svh;background:linear-gradient(180deg,var(--c-surface),var(--c-bg) 60%);color:var(--c-ink, #2a1219);font:400 17px/1.7 var(--f-body);-webkit-text-size-adjust:100%}
        main{width:min(100% - 32px,28rem);margin-inline:auto;padding-block:28px 40px;display:grid;gap:18px}
        .couple{font:700 clamp(26px,8vw,36px)/1.35 var(--f-display);color:var(--c-accent);text-align:center;margin:0}
        h1{font:700 22px/1.4 var(--f-label);margin:0;text-align:center;color:var(--c-accent)}
        .sub{margin:0;text-align:center;color:var(--c-soft)}
        form{display:grid;gap:12px;background:#fff;border-radius:calc(var(--radius) + 10px);padding:18px;box-shadow:0 18px 40px -30px rgb(0 0 0 / .45)}
        label{display:grid;gap:4px;font:600 14px/1.4 var(--f-label)}
        label small{font-weight:400;color:var(--c-soft)}
        input{font:inherit;font-size:17px;min-height:48px;padding:10px 14px;border-radius:12px;border:1px solid color-mix(in srgb,var(--c-soft) 35%,transparent);background:#fff;color:inherit;width:100%}
        input:focus{outline:2px solid var(--c-accent);outline-offset:1px}
        input[inputmode=numeric]{direction:ltr;text-align:start}
        [dir=rtl] input[inputmode=numeric]{text-align:end}
        .or{display:flex;align-items:center;gap:10px;color:var(--c-soft);font-size:14px}
        .or::before,.or::after{content:"";flex:1;border-top:1px solid color-mix(in srgb,var(--c-soft) 25%,transparent)}
        .row{display:grid;grid-template-columns:1fr;gap:12px}
        button{font:700 17px/1.2 var(--f-label);min-height:52px;border:0;border-radius:999px;background:var(--c-accent);color:var(--c-on-accent);cursor:pointer}
        button[disabled]{opacity:.6;cursor:progress}
        .msg{margin:0;padding:12px 14px;border-radius:12px;background:#fff;text-align:center}
        .msg.is-error{background:#fff4f2;color:#8a1f12}
        .result{display:grid;gap:12px;justify-items:center;text-align:center;background:#fff;border-radius:calc(var(--radius) + 10px);padding:20px}
        .result[hidden],.msg[hidden],form[hidden]{display:none}
        .result .label{font:600 14px/1.4 var(--f-label);color:var(--c-soft);margin:0}
        .result .table{font:700 clamp(40px,14vw,64px)/1.1 var(--f-display);color:var(--c-accent);margin:0}
        .hall-map{width:100%}
        .hall-map svg{display:block;width:100%;height:auto}
        .hall-map .m-hall{fill:color-mix(in srgb,var(--c-surface) 70%,var(--c-bg));stroke:color-mix(in srgb,var(--c-gold) 45%,transparent);stroke-width:3}
        .hall-map .m-stage{fill:color-mix(in srgb,var(--c-gold) 30%,var(--c-bg))}
        .hall-map .m-t{fill:color-mix(in srgb,var(--c-soft) 22%,var(--c-bg))}
        .hall-map .m-n{font-family:var(--f-label);font-weight:600;fill:var(--c-soft);text-anchor:middle;dominant-baseline:central}
        .hall-map .m-mine{fill:var(--c-accent)}
        .hall-map .m-ring{fill:none;stroke:var(--c-gold);stroke-width:4}
        .hall-map .m-mine-n{font-family:var(--f-label);font-weight:700;fill:var(--c-on-accent);text-anchor:middle;dominant-baseline:central}
        .again{background:none;color:var(--c-accent);min-height:44px;text-decoration:underline}
        .closed{text-align:center;background:#fff;border-radius:calc(var(--radius) + 10px);padding:20px;margin:0}
    </style>
    @vite('resources/js/find-table.js')
</head>
<body>
<main data-find-table data-state="{{ $state }}" data-url="{{ $lookupUrl }}" data-locale="{{ $locale }}">
    <p class="couple">{{ $title }}</p>
    <h1>{{ __('venues.find.title') }}</h1>

    @if ($state === 'open')
        <p class="sub">{{ __('venues.find.subtitle') }}</p>
        <form data-find-form novalidate>
            <label>{{ __('venues.find.name') }} <small>{{ __('venues.find.name_hint') }}</small>
                <input name="name" autocomplete="name" maxlength="120" data-find-name>
            </label>
            <label>{{ __('venues.find.phone_last4') }} <small>{{ __('venues.find.phone_last4_hint') }}</small>
                <input name="last4" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9٠-٩]{4}" data-find-last4>
            </label>
            <p class="or">{{ __('venues.find.or') }}</p>
            <label>{{ __('venues.find.phone') }}
                <input name="phone" type="tel" inputmode="numeric" autocomplete="tel" maxlength="20" data-find-phone>
            </label>
            <button type="submit" data-find-submit>{{ __('venues.find.search') }}</button>
        </form>
        <p class="msg" role="status" aria-live="polite" hidden data-find-msg></p>
        <section class="result" aria-live="polite" hidden data-find-result>
            <p class="label">{{ __('venues.find.your_table') }}</p>
            <p class="table" data-find-table-label></p>
            <div class="hall-map" role="img" aria-label="{{ __('venues.find.map') }}" data-find-map></div>
            <button type="button" class="again" data-find-again>{{ __('venues.find.again') }}</button>
        </section>
        <script type="application/json" data-find-strings>{!! json_encode(['too_many' => __('venues.find.too_many'), 'not_found' => __('venues.find.not_found')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @else
        <p class="closed">{{ __("venues.find.{$state}") }}</p>
    @endif
</main>
</body>
</html>
