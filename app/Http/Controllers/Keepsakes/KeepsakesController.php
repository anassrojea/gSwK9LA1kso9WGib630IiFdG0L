<?php

namespace App\Http\Controllers\Keepsakes;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Media;
use App\Models\OpenLink;
use App\Models\PhotoBook;
use App\Services\Album\Album;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\Keepsakes\Ffmpeg;
use App\Services\Keepsakes\HighlightVideos;
use App\Services\Keepsakes\PhotoBookPdf;
use App\Services\Keepsakes\PhotoBooks;
use App\Services\Keepsakes\ThankYouCards;
use App\Services\Payments\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /dashboard/events/{event}/keepsakes: after the event (phase 8D) — the
 * thank-you card, the highlight video and the printed photo book, each with
 * its own checkout when it is not bought yet.
 */
class KeepsakesController extends Controller
{
    public function show(Request $request, Event $event, ThankYouCards $cards, HighlightVideos $videos, PhotoBooks $books, Album $album, CheckoutService $checkout): Response
    {
        Gate::authorize('view', $event);
        $event->loadMissing(['invitation', 'openLink']);
        $user = $request->user();
        $canUpdate = $user->can('update', $event);

        $photos = $cards->photos($event);
        $card = $event->thankYouCard()->first();
        $gallery = $card ? InvitationConfig::section($card->config, 'thank_you_gallery') : null;
        $book = $canUpdate ? $books->draft($event, $user) : $books->current($event);
        $current = $books->current($event);
        $chosenBook = $book ? $books->chosen($book) : collect();

        $sample = $event->households()->whereRaw('not (? = any(tags))', [OpenLink::TAG])->value('token');

        return Inertia::render('dashboard/Keepsakes', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'storage_until' => $event->storage_until?->toDateString(),
                'published' => (bool) $event->invitation?->published_at,
            ],
            'can' => ['update' => $canUpdate],
            'photos' => $photos->map(fn (Media $m) => ['id' => $m->id, 'thumb' => $album->url($m, 'thumb', all: true), 'at' => $m->created_at?->toIso8601String()])->values(),
            'thankYou' => [
                'unlocked' => $cards->unlocked($event),
                'exists' => (bool) $card,
                'published' => (bool) $card?->published_at,
                'show_on_invite' => $card?->show_on_invite ?? true,
                'title' => $gallery['title'] ?? null,
                'message' => $gallery['message'] ?? trans('keepsakes.thank_you.default_message'),
                'signature' => $gallery['signature'] ?? null,
                'photos' => collect($gallery['photos'] ?? [])->map(fn (array $p) => str_starts_with($p['src'], 'media:') ? substr($p['src'], 6) : null)->filter()->values(),
                'max_photos' => (int) config('keepsakes.thank_you.max_photos', 24),
                'show_after_hours' => (int) config('keepsakes.thank_you.show_after_hours', 12),
                'preview_url' => $card?->published_at && $sample ? $album->inviteUrl(route('thank-you.show', $sample, false)) : null,
                'checkout' => $canUpdate && ! $cards->unlocked($event) ? $checkout->options($event, $user, ['thank_you']) : null,
            ],
            'video' => [
                'unlocked' => $videos->unlocked($event),
                'available' => app(Ffmpeg::class)->available(),
                'latest' => $videos->present($videos->latest($event)),
                'tracks' => $videos->tracks(),
                'photo_count' => $photos->count(),
                'clip_count' => $videos->clips($event)->count(),
                'checkout' => $canUpdate && ! $videos->unlocked($event) ? $checkout->options($event, $user, ['highlight_video']) : null,
            ],
            'book' => $book ? [
                'id' => $book->id,
                'status' => $book->status,
                'title' => $book->title,
                'subtitle' => $book->subtitle,
                'size' => $book->size,
                'pages' => $book->pages,
                'auto_pick' => $book->auto_pick,
                'photo_ids' => $book->photo_ids ?? [],
                'chosen' => $chosenBook->pluck('id')->values(),
                'capacity' => PhotoBooks::capacity($book->pages),
                'capacities' => collect(PhotoBook::PAGES)->mapWithKeys(fn (int $p) => [$p => PhotoBooks::capacity($p)]),
                'copies' => $book->copies,
                'ship' => [
                    'name' => $book->ship_name,
                    'country' => $book->ship_country,
                    'city' => $book->ship_city,
                    'address' => $book->ship_address,
                    'phone' => $book->ship_phone,
                ],
                'pdf_url' => $book->pdf_key ? route('photo-books.pdf', $book, false) : null,
                'pdf_pages' => $book->pdf_pages,
                'can_render' => app(PhotoBookPdf::class)->available(),
                'checkout' => $canUpdate ? $checkout->options($event, $user, [$book->addonId()]) : null,
            ] : null,
            'ordered' => $current && $current->status !== 'draft' ? [
                'id' => $current->id,
                'status' => $current->status,
                'pages' => $current->pages,
                'copies' => $current->copies,
                'tracking_notes' => $current->tracking_notes,
                'order' => $current->order?->code,
            ] : null,
            'urls' => [
                'thank_you' => route('events.thank-you.update', $event, false),
                'thank_you_publish' => route('events.thank-you.publish', $event, false),
                'video' => route('events.highlight-video.store', $event, false),
                'book' => route('events.photo-book.update', $event, false),
                'book_pdf' => route('events.photo-book.preview', $event, false),
                'book_order' => route('events.photo-book.order', $event, false),
                'album' => route('dashboard.events.album', $event, false),
                'dashboard' => route('dashboard.events.show', $event, false),
            ],
        ]);
    }
}
