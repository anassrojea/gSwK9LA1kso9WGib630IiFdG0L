{{-- The guests' album page (phase 7B): App\Http\Controllers\Invite\AlbumController@show, driven by resources/js/album.js.
     No cookies: the PIN key stays in localStorage and travels in the X-Album-Key header. --}}
@php($t = fn ($key, $replace = []) => __('album.guest.'.$key, $replace))
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $t('page_title') }} · {{ $couple }}</title>
    <meta name="theme-color" content="{{ $config['theme']['colors']['accent'] ?? '#7A2E3A' }}">
    @if ($fontsUrl)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif
    <style>:root{ {!! $themeVars !!} }</style>
    @vite(['resources/css/album.css', 'resources/js/album.js'])
</head>
<body>
<div class="album">
    <header class="al-head">
        <span class="al-kicker">{{ $t('page_title') }}</span>
        <h1>{{ $couple }}</h1>
        <p>{{ $t('subtitle') }}</p>
    </header>

    <main class="al-main">
        @if (! $enabled)
            <div class="al-card al-msg" role="status">{{ $t('not_enabled') }}</div>
        @else
            {{-- PIN: shown by album.js when the album has one and this device has no key yet --}}
            <form class="al-card al-pin" data-pin hidden>
                <h2>{{ $t('pin_title') }}</h2>
                <p class="al-muted">{{ $t('pin_text') }}</p>
                <label for="al-pin">{{ $t('pin_label') }}</label>
                <input id="al-pin" name="pin" inputmode="numeric" autocomplete="one-time-code" maxlength="8" dir="ltr" required>
                <p class="al-error" data-pin-error role="alert" hidden></p>
                <button class="al-btn solid" type="submit">{{ $t('pin_submit') }}</button>
            </form>

            <div data-unlocked hidden>
                <section class="al-card al-upload" aria-labelledby="al-upload-title">
                    <h2 id="al-upload-title">{{ $t('title') }}</h2>
                    @if ($open)
                        @if ($shared)
                            <label for="al-name">{{ $t('your_name') }}</label>
                            <input id="al-name" data-name maxlength="120" autocomplete="name" placeholder="{{ $t('name_placeholder') }}">
                        @else
                            <p class="al-muted">{{ __('album.guest.as', ['name' => $guestName]) }}</p>
                        @endif
                        <input type="file" id="al-files" accept="image/*,video/*" multiple class="sr-only" data-files>
                        <input type="file" id="al-camera" accept="image/*" capture="environment" class="sr-only" data-camera>
                        <div class="al-actions">
                            <label for="al-files" class="al-btn solid big" data-choose>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 16l4.6-4.6a2 2 0 0 1 2.8 0L16 16m-2-2 1.6-1.6a2 2 0 0 1 2.8 0L20 14M14 8h.01M6 20h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ $t('choose') }}
                            </label>
                            <label for="al-camera" class="al-btn">{{ $t('camera') }}</label>
                        </div>
                        <p class="al-note">{{ $t('compress_note', ['seconds' => \App\Services\Digits::localize(config('album.video.max_seconds'), $locale)]) }}</p>
                        <ul class="al-queue" data-queue aria-live="polite"></ul>
                    @else
                        <p class="al-msg">{{ $t('closed') }}</p>
                    @endif
                </section>

                <section class="al-card al-book" aria-labelledby="al-book-title">
                    <h2 id="al-book-title">{{ $t('guestbook_title') }}</h2>
                    <form data-guestbook>
                        @if ($shared)
                            <label for="al-book-name">{{ $t('your_name_required') }}</label>
                            <input id="al-book-name" name="name" maxlength="120" autocomplete="name" required>
                        @endif
                        <label for="al-book-text">{{ $t('guestbook_label') }}</label>
                        <textarea id="al-book-text" name="text" maxlength="500" rows="3" required placeholder="{{ $t('guestbook_placeholder') }}"></textarea>
                        <p class="al-error" data-book-error role="alert" hidden></p>
                        <button class="al-btn" type="submit">{{ $t('guestbook_send') }}</button>
                    </form>
                    @if ($boot['guestbookVideo'] && $open)
                        <input type="file" id="al-book-video" accept="video/*" capture="user" class="sr-only" data-book-video>
                        <label for="al-book-video" class="al-btn ghost">{{ $t('guestbook_video', ['seconds' => \App\Services\Digits::localize($boot['guestbookVideo']['max_seconds'], $locale)]) }}</label>
                    @endif
                </section>

                <section class="al-gallery" aria-labelledby="al-gallery-title">
                    <h2 id="al-gallery-title">{{ $t('gallery') }}</h2>
                    <p class="al-muted" data-empty hidden>{{ $t('empty') }}</p>
                    <ul class="al-grid" data-grid></ul>
                    <button type="button" class="al-btn" data-more hidden>{{ $t('load_more') }}</button>
                </section>
            </div>
        @endif

        @if ($inviteUrl)
            <a class="al-back" href="{{ $inviteUrl }}">{{ $t('back') }}</a>
        @endif
    </main>

    <dialog class="al-lightbox" data-lightbox aria-label="{{ $t('gallery') }}">
        <button type="button" class="al-x" data-close aria-label="{{ $t('close') }}">×</button>
        <figure><div data-media></div><figcaption data-caption></figcaption></figure>
        <div class="al-lb-nav">
            <button type="button" class="al-btn" data-prev>{{ $t('previous') }}</button>
            <a class="al-btn solid" data-download download>{{ $t('download') }}</a>
            <button type="button" class="al-btn" data-next>{{ $t('next') }}</button>
        </div>
    </dialog>
    <div class="al-toast" data-toast role="status" aria-live="polite"></div>
</div>
<script type="application/json" id="album-boot">@json($boot)</script>
</body>
</html>
