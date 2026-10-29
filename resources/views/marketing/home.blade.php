<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('app.name') }} · {{ __('app.tagline') }}</title>
    <meta name="description" content="{{ __('app.tagline') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&family=Katibeh&display=swap">
    @vite('resources/css/app.css')
</head>
<body class="min-h-svh bg-gradient-to-b from-rose-50 to-background">
    <header class="mx-auto flex max-w-4xl items-center justify-between p-6">
        <span class="text-2xl font-bold text-primary">{{ __('app.name') }}</span>
        <nav class="flex items-center gap-4 text-sm">
            <a href="{{ route('locale', $locale === 'ar' ? 'en' : 'ar') }}" class="text-muted-foreground hover:text-foreground">{{ __('app.switch_language') }}</a>
            @auth
                <a href="{{ route('dashboard') }}" class="rounded-md bg-primary px-4 py-2 text-primary-foreground">{{ __('app.dashboard') }}</a>
            @else
                <a href="{{ route('login') }}" class="hover:underline">{{ __('app.login') }}</a>
                <a href="{{ route('register') }}" class="rounded-md bg-primary px-4 py-2 text-primary-foreground">{{ __('app.register') }}</a>
            @endauth
        </nav>
    </header>
    <main class="mx-auto grid max-w-4xl gap-10 px-6 py-16 text-center">
        <h1 class="text-5xl leading-tight font-bold text-primary" style="font-family: Katibeh, serif">{{ __('app.name') }}</h1>
        <p class="text-xl text-muted-foreground">{{ __('app.tagline') }}</p>
        @if ($demos)
            <section class="grid gap-3">
                <h2 class="text-lg font-semibold">{{ __('app.demo_invites') }}</h2>
                <div class="flex flex-wrap justify-center gap-3">
                    @foreach ($demos as $template => $token)
                        <a href="{{ route('invite.show', $token) }}" class="rounded-full border border-primary px-5 py-2 text-primary hover:bg-primary hover:text-primary-foreground">{{ $template }}</a>
                    @endforeach
                </div>
            </section>
        @endif
    </main>
</body>
</html>
