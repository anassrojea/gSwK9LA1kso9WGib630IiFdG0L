<?php

namespace App\Services\Venues;

use App\Http\Controllers\Marketing\VenueDirectoryController;
use App\Models\Event;
use App\Models\OrgMedia;
use App\Models\VenueProfile;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\OgImage\FontEmbedder;
use App\Services\Pdf\PdfRenderer;
use App\Services\Qr\QrCodes;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Printable QR posters (phase 8B, plan section 9 items 2 and 9, "Smart Hall"),
 * printed by headless Chromium (PdfRenderer) in A4 or A5, Arabic and English
 * on one page:
 *
 *  venue   "Book your invitation with us" for the hall's reception: the venue's
 *          logo and a QR to /v/{code} (counted, then the directory page)
 *  find    "Find your table" at the entrance of one event: a QR to /e/{slug}/find
 *
 * The HTML is self-contained (fonts and the logo inlined, the QR as SVG):
 * the renderer blocks every network request.
 */
class Posters
{
    public const SIZES = ['A4', 'A5'];

    public function __construct(
        private PdfRenderer $pdf,
        private QrCodes $qr,
        private FontEmbedder $fonts,
    ) {}

    public function available(): bool
    {
        return $this->pdf->available();
    }

    public function venueHtml(VenueProfile $profile, string $size = 'A4'): string
    {
        $url = VenueDirectoryController::posterUrl($profile);

        return view('pdf.venue-poster', [
            'event' => (object) ['title' => $profile->displayName('ar')],
            'page' => $size.' portrait',
            'margin' => '0',
            'size' => $size,
            'nameAr' => $profile->displayName('ar'),
            'nameEn' => $profile->name_en,
            'logo' => $this->logoDataUri($profile),
            'qr' => $this->qrSvg($url),
            'url' => preg_replace('#^https?://#', '', $url),
            'fontFaces' => $this->fontFaces(),
            'themeVars' => '',
        ])->render();
    }

    public function findTableHtml(Event $event, string $url, string $size = 'A4'): string
    {
        $event->loadMissing('invitation');
        $theme = $event->invitation?->renderConfig()['theme'] ?? null;

        return view('pdf.find-table-poster', [
            'event' => $event,
            'page' => $size.' portrait',
            'margin' => '0',
            'size' => $size,
            'qr' => $this->qrSvg($url),
            'url' => preg_replace('#^https?://#', '', $url),
            'fontFaces' => $theme ? $this->fonts->css($theme) : $this->fontFaces(),
            'themeVars' => $theme ? ThemeStyles::cssVariables($theme) : '',
        ])->render();
    }

    /**
     * @throws RuntimeException when Chromium is missing or fails
     */
    public function render(string $html, string $what): string
    {
        try {
            return $this->pdf->render($html);
        } catch (Throwable $e) {
            Log::warning('Poster PDF could not be rendered.', ['poster' => $what, 'error' => $e->getMessage()]);

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function qrSvg(string $url): string
    {
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $this->qr->svg($url, '#1f1a1c', null));
    }

    private function fontFaces(): string
    {
        return $this->fonts->css(['fonts' => ['display' => 'Almarai', 'body' => 'Almarai']]);
    }

    private function logoDataUri(VenueProfile $profile): ?string
    {
        $logo = $profile->logo ?? OrgMedia::query()->where('org_id', $profile->org_id)->where('kind', OrgMedia::KIND_LOGO)->latest()->first();
        // else the organization's white-label logo (organizations.logo_path, phase 8A)
        $key = $logo?->storage_key ?? $profile->organization?->getAttribute('logo_path');
        if (! is_string($key) || $key === '' || str_contains($key, '://')) {
            return null;
        }
        try {
            $bytes = OrgMedia::disk()->get($key);
        } catch (Throwable) {
            return null;
        }
        if (! $bytes || strlen($bytes) > 4 * 1024 * 1024) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';

        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? "data:{$mime};base64,".base64_encode($bytes) : null;
    }
}
