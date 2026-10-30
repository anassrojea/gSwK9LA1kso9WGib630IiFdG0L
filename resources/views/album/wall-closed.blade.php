{{-- The photo wall cannot be shown: not in the plan, or a private wall opened without its secret. --}}
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('album.wall.title') }}</title>
    @if ($fontsUrl)
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif
    <style>
        :root{ {!! $themeVars !!} }
        body{margin:0;min-height:100svh;display:grid;place-items:center;background:var(--c-backdrop);color:#fff;font:400 20px/1.7 var(--f-body);text-align:center;padding:24px}
        h1{font:700 40px/1.4 var(--f-display);color:var(--c-gold-light);margin:0 0 12px}
    </style>
</head>
<body>
<main>
    <h1>{{ $couple }}</h1>
    <p>{{ $message }}</p>
</main>
</body>
</html>
