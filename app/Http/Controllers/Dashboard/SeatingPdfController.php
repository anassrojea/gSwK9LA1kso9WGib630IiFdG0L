<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Household;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\OgImage\FontEmbedder;
use App\Services\Pdf\PdfRenderer;
use App\Services\Qr\QrCodes;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\SeatingPlan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Printables (plan sections 4.2, 7.3 and 9), printed by headless Chromium
 * from Blade views in both languages, in the invitation's own fonts:
 *
 *   GET /events/{event}/seating.pdf      the hall with table numbers, then who sits where
 *   GET /events/{event}/table-cards.pdf  one card per table, six per A4 page
 *   GET /events/{event}/qr-codes.pdf     every family's QR code with its name, for paper invitations
 */
class SeatingPdfController extends Controller
{
    public function __construct(
        private PdfRenderer $pdf,
        private SeatingPlan $plan,
        private Entitlements $entitlements,
        private FontEmbedder $fonts,
    ) {}

    public function chart(Event $event): Response
    {
        [$layout, $tables] = $this->seating($event);

        $seated = [];
        foreach ($this->plan->assignments($event) as $a) {
            $seated[$a['table_id']][] = $a['seat_index'];
        }

        return $this->download('pdf.seating-chart', $event, 'seating-chart', [
            'layout' => $layout,
            'tables' => $tables,
            'seated' => $seated,
        ]);
    }

    public function cards(Event $event): Response
    {
        [, $tables] = $this->seating($event);

        return $this->download('pdf.table-cards', $event, 'table-cards', [
            'tables' => array_values(array_filter($tables, fn ($t) => $t['families'] !== [])),
        ]);
    }

    public function qrCodes(Event $event, QrCodes $qr): Response
    {
        Gate::authorize('update', $event);
        abort_unless(
            $this->entitlements->hasFeature($event, Entitlements::PERSONAL_LINKS) || $this->entitlements->hasFeature($event, Entitlements::QR_CHECKIN),
            403,
            trans('seating.qr.locked'),
        );

        $guests = Household::query()
            ->where('event_id', $event->getKey())
            ->orderBy('display_name')
            ->orderBy('id')
            ->get()
            ->map(fn (Household $h) => [
                'name' => $h->display_name,
                'seats' => $h->seats_allowed,
                'svg' => preg_replace('/^<\?xml[^>]*>\s*/', '', $qr->forHousehold($h)),
            ])
            ->all();

        return $this->download('pdf.qr-codes', $event, 'qr-codes', ['guests' => $guests]);
    }

    /**
     * @return array{0: array, 1: list<array{id: string, number: string, section: string, seats: int, people: int, families: list<array{name: string, count: int}>}>}
     */
    private function seating(Event $event): array
    {
        Gate::authorize('view', $event);
        abort_unless($this->entitlements->hasFeature($event, Entitlements::SEATING), 403, trans('seating.locked.title'));

        $layout = $this->plan->layout($event);
        abort_if($layout === null, 404, trans('seating.errors.no_hall'));

        $names = $this->plan->namesByTable($event);
        $tables = array_map(fn (array $t) => [
            'id' => $t['id'],
            'number' => HallLayoutData::tableNumber($t['id']),
            'section' => $t['section'] ?? 'mixed',
            'seats' => (int) $t['seats'],
            'people' => (int) collect($names[$t['id']] ?? [])->sum('count'),
            'families' => $names[$t['id']] ?? [],
        ], $layout['tables']);

        return [$layout, $tables];
    }

    private function download(string $view, Event $event, string $name, array $data): Response
    {
        abort_unless($this->pdf->available(), 503, trans('seating.pdf.unavailable'));

        $event->loadMissing('invitation');
        $theme = $event->invitation?->renderConfig()['theme'] ?? null;

        $html = view($view, $data + [
            'event' => $event,
            'date' => $event->starts_at
                ? Digits::localize($event->starts_at->setTimezone($event->timezone ?: config('app.timezone'))->format('Y/m/d'), 'ar').' · '.$event->starts_at->setTimezone($event->timezone ?: config('app.timezone'))->format('j M Y')
                : null,
            'fontFaces' => $theme ? $this->fonts->css($theme) : '',
            'themeVars' => $theme ? ThemeStyles::cssVariables($theme) : '',
        ])->render();

        try {
            $pdf = $this->pdf->render($html);
        } catch (Throwable $e) {
            Log::warning('PDF could not be rendered.', ['view' => $view, 'event' => $event->id, 'error' => $e->getMessage()]);
            abort(503, trans('seating.pdf.unavailable'));
        }

        $filename = $name.'-'.(Str::slug($event->invitation?->slug ?? '') ?: substr($event->id, 0, 8)).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
