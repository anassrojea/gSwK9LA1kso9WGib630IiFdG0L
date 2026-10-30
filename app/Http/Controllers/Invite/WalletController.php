<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Household;
use App\Models\Invitation;
use App\Services\Album\QrSvg;
use App\Services\Entitlements;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\Wallet\ApplePass;
use App\Services\Wallet\GooglePass;
use App\Services\Wallet\PassData;
use App\Services\Wallet\PassImages;
use App\Services\Wallet\WalletPasses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Wallet passes for attending guests (plan section 10):
 *
 *   GET /i/{token}/wallet/apple        the signed .pkpass
 *   GET /i/{token}/wallet/google       redirect to "Save to Google Wallet"
 *   GET /i/{token}/pass                the same pass as a web page ("Add to home screen")
 *   GET /i/{token}/pass.webmanifest    so the web pass installs like an app
 *   GET /e/{slug}/wallet-strip.png     the strip image Google Wallet fetches
 *
 * Personal links only: a pass belongs to one household with a "yes" reply.
 */
class WalletController extends Controller
{
    use ResolvesHousehold;

    public function __construct(private WalletPasses $passes, private Entitlements $entitlements) {}

    public function show(string $token, string $platform, ApplePass $apple, GooglePass $google): Response|RedirectResponse
    {
        $household = $this->attending($token);
        $event = $household->event;
        abort_unless($this->passes->platforms($event)[$platform] ?? false, 404);

        $data = PassData::for($household, $this->entitlements);
        $pass = $this->passes->record($household, $platform, $data);

        if ($platform === 'google') {
            $url = $google->saveUrl($event, $household, $data, $pass);
            $pass->update(['pass_url' => GooglePass::SAVE_URL]);

            return redirect()->away($url);
        }

        return response($apple->build($event, $data, $pass), 200, [
            'Content-Type' => ApplePass::MIME,
            'Content-Disposition' => 'attachment; filename="'.$this->fileName($data).'.pkpass"',
            'Last-Modified' => now()->toRfc7231String(),
        ]);
    }

    public function pass(string $token): Response|RedirectResponse
    {
        [$household, , $config] = $this->resolve($token);
        abort_unless($this->passes->enabled($household->event), 404);

        if (! $household->rsvp?->attending) {
            return redirect()->to(route('invite.show', $token, false).'#rsvp');
        }

        $data = PassData::for($household, $this->entitlements);

        return response()->view('wallet.pass', [
            'data' => $data,
            'dir' => $data->rtl() ? 'rtl' : 'ltr',
            'themeVars' => ThemeStyles::cssVariables($config['theme']),
            'fontsUrl' => ThemeStyles::fontsUrl($config['theme']),
            'qr' => QrSvg::make($data->url),
            'platforms' => $this->passes->platforms($household->event),
            'token' => $token,
        ]);
    }

    public function manifest(string $token): JsonResponse
    {
        [$household, , $config] = $this->resolve($token);
        abort_unless($this->passes->enabled($household->event), 404);
        $data = PassData::for($household, $this->entitlements);

        return response()->json([
            'name' => $data->eventName,
            'short_name' => $data->couple,
            'start_url' => route('invite.pass', $token, false),
            'scope' => route('invite.pass', $token, false),
            'display' => 'standalone',
            'dir' => $data->rtl() ? 'rtl' : 'ltr',
            'lang' => $data->locale,
            'background_color' => $config['theme']['colors']['bg'] ?? '#ffffff',
            'theme_color' => $data->colors['background'],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function strip(string $slug, PassImages $images): Response
    {
        $invitation = Invitation::query()->with('event')->where('slug', $slug)->first();
        abort_if(! $invitation?->published_at || $invitation->event->isArchived() || ! $this->passes->enabled($invitation->event), 404);
        SetLocale::apply($invitation->renderConfig()['locale']);

        $household = new Household(['display_name' => '', 'seats_allowed' => 1]);
        $household->token = 'stripPreview';
        $household->setRelation('event', $invitation->event->setRelation('invitation', $invitation));
        $household->setRelation('rsvp', null);

        return response($images->strip($invitation->event, PassData::for($household, $this->entitlements)), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function attending(string $token): Household
    {
        [$household] = $this->resolve($token);
        abort_unless($household->rsvp?->attending, 404);

        return $household;
    }

    private function fileName(PassData $data): string
    {
        return Str::slug($data->couple, '-', 'ar') ?: 'daawa';
    }
}
