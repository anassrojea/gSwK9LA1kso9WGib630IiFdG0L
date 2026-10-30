<?php

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;

function renderMail(string $locale): string
{
    App::setLocale($locale);

    return (string) (new MailMessage)->greeting('مرحباً')->line('تم تأكيد حضوركم.')->action('افتح الدعوة', 'https://example.com')->render();
}

it('renders Arabic emails right to left', function () {
    $html = renderMail('ar');

    expect($html)->toContain('dir="rtl"')
        ->and($html)->toMatch('/<p[^>]*text-align: right/')
        ->and($html)->not->toMatch('/<p[^>]*text-align: left[^>]*>تم تأكيد/');
});

it('keeps English emails left to right', function () {
    $html = renderMail('en');

    expect($html)->toContain('dir="ltr"')
        ->and($html)->not->toContain('text-align: right');
});
