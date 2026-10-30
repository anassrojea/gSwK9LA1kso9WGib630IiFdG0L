<?php

namespace App\Providers;

use App\Jobs\UpdateWalletPasses;
use App\Models\Event;
use App\Models\Household;
use App\Models\SeatAssignment;
use App\Services\Ai\AiWriter;
use App\Services\Ai\ClaudeAiWriter;
use App\Services\Ai\FakeAiWriter;
use App\Services\Album\AlbumAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 7B, guest extras: the album and live photo wall, wallet passes and AI
 * writing. Binds the AI writer (FakeAiWriter when AI_FAKE=true, never in
 * production), the rate limits of their routes, and the hooks that mark
 * wallet passes out of date.
 */
class GuestExtrasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiWriter::class, fn ($app) => config('ai.fake') && ! $app->isProduction()
            ? new FakeAiWriter
            : new ClaudeAiWriter);
    }

    public function boot(): void
    {
        $this->rateLimits();

        // a pass shows the time, the hall and the table: when they change, issued passes need an update
        Event::updated(function (Event $event) {
            if ($event->wasChanged(['starts_at', 'venue_name', 'lat', 'lng'])) {
                UpdateWalletPasses::dispatch($event->getKey(), null, 'event');
            }
        });
        foreach (['saved', 'deleted'] as $hook) {
            SeatAssignment::$hook(fn (SeatAssignment $seat) => UpdateWalletPasses::dispatch($seat->event_id, $seat->household_id, 'table'));
        }
    }

    private function rateLimits(): void
    {
        $token = fn (Request $request) => (string) $request->route('token');

        // per household per hour (per device on a shared link), plus a ceiling for a shared link
        RateLimiter::for('album-uploads', fn (Request $request) => array_filter([
            Limit::perHour((int) config('album.uploads_per_hour'))->by('u|'.AlbumAccess::limiterKey($token($request), $request->ip())),
            strlen($token($request)) !== Household::TOKEN_LENGTH ? Limit::perHour((int) config('album.shared_uploads_per_hour'))->by('s|'.$token($request)) : null,
            Limit::perMinute(30)->by('ip|'.$request->ip()),
        ]));

        RateLimiter::for('album-read', fn (Request $request) => Limit::perMinute(120)->by($token($request).'|'.$request->ip()));

        RateLimiter::for('album-pin', fn (Request $request) => [
            Limit::perMinute(10)->by('m|'.$token($request).'|'.$request->ip()),
            Limit::perHour(50)->by('h|'.$token($request)),
        ]);

        RateLimiter::for('album-guestbook', fn (Request $request) => Limit::perHour((int) config('album.guestbook_per_hour'))
            ->by(AlbumAccess::limiterKey($token($request), $request->ip())));

        RateLimiter::for('album-files', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        RateLimiter::for('wall', fn (Request $request) => Limit::perMinute(60)->by($request->route('slug').'|'.$request->ip()));

        RateLimiter::for('wallet', fn (Request $request) => Limit::perMinute(20)->by($token($request).'|'.$request->ip()));

        // AI calls cost money: per user and per event, per hour (the monthly allowance is in AiController)
        RateLimiter::for('ai', fn (Request $request) => [
            Limit::perHour((int) config('ai.per_user_hourly'))->by('u|'.$request->user()?->getKey()),
            Limit::perHour((int) config('ai.per_event_hourly'))->by('e|'.(is_object($event = $request->route('event')) ? $event->getKey() : (string) $event)),
        ]);

        RateLimiter::for('ai-poll', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->getKey()));
    }
}
