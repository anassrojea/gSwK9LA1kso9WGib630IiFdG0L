<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('journey.anniversary.unsubscribed.title') }}</title>
    <style>
        body{margin:0;min-height:100svh;display:grid;place-items:center;background:#FBF6F2;color:#3A1420;font:18px/1.8 system-ui,sans-serif;text-align:center;padding:24px}
        main{display:grid;gap:20px;max-width:28rem}
        h1{font-size:1.4rem;margin:0;color:#5A1A2C}
        p{margin:0;color:#86636C}
        a{color:#5A1A2C;font-weight:700}
    </style>
</head>
<body>
<main>
    <h1>{{ __('journey.anniversary.unsubscribed.title') }}</h1>
    <p>{{ __('journey.anniversary.unsubscribed.text') }}</p>
    <p><a href="{{ \App\Support\SiteUrl::to('home', $locale) }}">{{ __('journey.anniversary.unsubscribed.back') }}</a></p>
</main>
</body>
</html>
