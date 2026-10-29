{{-- Shown for unknown invitation links too, without saying whether a token exists. --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('invite.not_found_title', [], 'ar') }} · Not found</title>
    <style>
        body{margin:0;min-height:100svh;display:grid;place-items:center;background:#FBF6F2;color:#3A1420;font:18px/1.8 system-ui,sans-serif;text-align:center;padding:24px}
        main{display:grid;gap:28px;max-width:28rem}
        h1{font-size:1.4rem;margin:0;color:#5A1A2C}
        p{margin:0;color:#86636C}
        [lang=en]{direction:ltr}
    </style>
</head>
<body>
<main>
    <section lang="ar"><h1>{{ __('invite.not_found_title', [], 'ar') }}</h1><p>{{ __('invite.not_found_text', [], 'ar') }}</p></section>
    <section lang="en"><h1>{{ __('invite.not_found_title', [], 'en') }}</h1><p>{{ __('invite.not_found_text', [], 'en') }}</p></section>
</main>
</body>
</html>
