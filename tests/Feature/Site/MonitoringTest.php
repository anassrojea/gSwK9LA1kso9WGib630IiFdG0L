<?php

use App\Jobs\SendAnalyticsEvent;
use App\Models\Order;
use App\Services\Analytics\Analytics;
use App\Support\SentryScrubber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Sentry\Breadcrumb;
use Sentry\Event as SentryEvent;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

it('sends nothing without a PostHog key', function () {
    config(['services.posthog.key' => null]);
    Queue::fake();
    Http::fake();

    app(Analytics::class)->capture('order_paid', 'user-1', ['total' => 2900]);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('posts events to the PostHog capture API with a key', function () {
    config(['services.posthog.key' => 'phc_test', 'services.posthog.host' => 'https://eu.i.posthog.com']);
    Http::fake(['eu.i.posthog.com/*' => Http::response(['status' => 1])]);

    app(Analytics::class)->capture('order_paid', 'user-1', ['total' => 2900]);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://eu.i.posthog.com/i/v0/e/'
        && $r['api_key'] === 'phc_test'
        && $r['event'] === 'order_paid'
        && $r['distinct_id'] === 'user-1'
        && $r['properties']['total'] === 2900);
});

it('queues the event instead of posting during the request', function () {
    config(['services.posthog.key' => 'phc_test']);
    Queue::fake();

    app(Analytics::class)->captureAnonymous('design_yours_clicked', ['occasion' => 'wedding']);

    Queue::assertPushed(SendAnalyticsEvent::class, fn ($job) => $job->distinctId === 'anonymous'
        && $job->properties['$process_person_profile'] === false);
});

it('reports order_paid when an order is paid or approved', function () {
    config(['services.posthog.key' => 'phc_test']);
    Queue::fake();
    $order = Order::factory()->create(['status' => 'awaiting_payment', 'items' => [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900]]]);

    $order->update(['status' => 'receipt_received']);
    Queue::assertNothingPushed();

    $order->update(['status' => 'approved']);
    Queue::assertPushed(SendAnalyticsEvent::class, fn ($job) => $job->event === 'order_paid'
        && $job->distinctId === (string) $order->user_id
        && $job->properties['plan'] === 'premium'
        && $job->properties['order'] === $order->code);
});

it('scrubs phone numbers and emails from Sentry events', function () {
    $event = SentryEvent::createEvent();
    $event->setMessage('RSVP failed for +966 50 123 4567 (sara@example.com)');
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('Guest ٠٥٠١٢٣٤٥٦٧ not found'))]);
    $event->setBreadcrumb([new Breadcrumb('info', 'default', 'log', 'Sent to 00963912345678', ['phone' => '0912345678', 'count' => 3])]);
    $event->setExtra(['household' => ['display_name' => 'عائلة خالد', 'seats' => 2], 'note' => 'call 0501234567']);
    $event->setRequest(['url' => 'https://daawa.app/i/AbCdEf123456', 'headers' => ['cookie' => 'secret', 'authorization' => 'Bearer x'], 'data' => ['email' => 'a@b.co']]);
    $event->setUser(UserDataBag::createFromUserIdentifier('user-1')->setEmail('a@b.co')->setIpAddress('1.2.3.4'));

    $out = SentryScrubber::beforeSend($event);

    expect($out->getMessage())->toBe('RSVP failed for [Filtered] ([Filtered])')
        ->and($out->getExceptions()[0]->getValue())->toBe('Guest [Filtered] not found')
        ->and($out->getBreadcrumbs()[0]->getMessage())->toBe('Sent to [Filtered]')
        ->and($out->getBreadcrumbs()[0]->getMetadata())->toBe(['phone' => '[Filtered]', 'count' => 3])
        ->and($out->getExtra()['household'])->toBe(['display_name' => '[Filtered]', 'seats' => 2])
        ->and($out->getExtra()['note'])->toBe('call [Filtered]')
        ->and($out->getRequest()['headers'])->toBe(['cookie' => '[Filtered]', 'authorization' => '[Filtered]'])
        ->and($out->getRequest()['data']['email'])->toBe('[Filtered]')
        ->and($out->getUser()->getEmail())->toBeNull()
        ->and($out->getUser()->getIpAddress())->toBeNull()
        ->and($out->getUser()->getId())->toBe('user-1');
});

it('leaves dates, times, UUIDs and amounts alone', function () {
    expect(SentryScrubber::text('2026-11-03 20:30:00 order DW-4821 total 2900 event 9b2f6c1e-1a2b-4c3d-8e9f-0a1b2c3d4e5f'))
        ->toBe('2026-11-03 20:30:00 order DW-4821 total 2900 event 9b2f6c1e-1a2b-4c3d-8e9f-0a1b2c3d4e5f');
});

it('is wired into the Sentry config with PII off', function () {
    expect(config('sentry.before_send'))->toBe([SentryScrubber::class, 'beforeSend'])
        ->and(config('sentry.send_default_pii'))->toBeFalse();
});

it('sends security headers on the site and the app, and a CSP fitting the guest pages', function () {
    config(['security.csp.posthog_host' => 'https://eu.i.posthog.com', 'security.csp.sentry_dsn' => 'https://abc123@o42.ingest.sentry.io/77']);

    $site = $this->get('/');
    $site->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security')
        ->assertHeaderMissing('Content-Security-Policy');
    $csp = $site->headers->get('Content-Security-Policy-Report-Only');
    expect($csp)->toContain("default-src 'self'")
        ->toContain('https://fonts.googleapis.com')
        ->toContain('font-src \'self\' https://fonts.gstatic.com')
        ->toContain('https://eu.i.posthog.com')
        ->toContain('https://o42.ingest.sentry.io')
        ->toContain('report-uri https://o42.ingest.sentry.io/api/77/security/?sentry_key=abc123')
        ->toContain("frame-ancestors 'self'")
        ->not->toContain("'unsafe-eval'");
    expect($site->headers->get('Permissions-Policy'))->toContain('camera=(self)');

    [, $household] = invitedHousehold();
    $invite = $this->get("/i/{$household->token}");
    $invite->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($invite->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain('https://fonts.gstatic.com')
        ->not->toContain('posthog');
});

it('enforces the CSP and sends HSTS when configured for production', function () {
    config(['security.csp.enforce' => true]);
    app()->detectEnvironment(fn () => 'production');

    $this->get('https://daawa.app/en')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only')
        ->assertHeader('Content-Security-Policy');
});
