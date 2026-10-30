<?php

namespace App\Services\Wallet;

use App\Models\Event;
use App\Models\Household;
use App\Models\WalletPass;
use App\Services\Entitlements;
use Illuminate\Support\Str;

/**
 * Which wallet buttons a guest sees and the wallet_passes rows behind them.
 * A platform is offered when the event has the "wallet" feature (Signature,
 * or the wallet add-on) and the platform is configured (config/wallet.php);
 * the web pass (/i/{token}/pass) needs only the feature.
 */
class WalletPasses
{
    public function __construct(
        private Entitlements $entitlements,
        private ApplePass $apple,
        private GooglePass $google,
    ) {}

    public function enabled(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, Entitlements::WALLET);
    }

    /**
     * @return array{apple: bool, google: bool, web: bool}
     */
    public function platforms(Event $event): array
    {
        $enabled = $this->enabled($event);

        return [
            'apple' => $enabled && $this->apple->configured(),
            'google' => $enabled && $this->google->configured(),
            'web' => $enabled,
        ];
    }

    /**
     * The household's pass row for a platform, made on first download. The serial
     * and authenticationToken stay the same for the life of the pass.
     */
    public function record(Household $household, string $platform, PassData $data): WalletPass
    {
        $pass = WalletPass::query()->firstOrNew(['household_id' => $household->getKey(), 'platform' => $platform]);
        $pass->serial ??= 'dw-'.Str::lower(Str::random(24));
        $pass->authentication_token ??= Str::random(40);
        $pass->content_hash = $data->contentHash();
        $pass->save();

        return $pass;
    }
}
