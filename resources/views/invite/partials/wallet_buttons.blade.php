{{-- Wallet buttons on the RSVP ticket (phase 7B, plan section 10): personal links only, when the event has the
     wallet feature; Apple / Google only when configured. invite.js hides the other phone's button. --}}
@php
    $walletToken = ($openLink ?? false) ? null : request()->route('token');
    $walletPlatforms = $walletToken ? app(\App\Services\Wallet\WalletPasses::class)->platforms($event) : ['web' => false];
@endphp
@if ($walletPlatforms['web'])
    <div class="wallet-btns" data-wallet>
        @if ($walletPlatforms['apple'])
            <a class="wallet-btn apple" href="{{ route('invite.wallet', [$walletToken, 'apple'], false) }}" data-wallet-platform="apple">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16.4 12.6c0-2.5 2-3.7 2.1-3.8-1.2-1.7-3-1.9-3.6-2-1.5-.2-3 .9-3.8.9-.8 0-2-.9-3.3-.9-1.7 0-3.3 1-4.1 2.5-1.8 3.1-.5 7.6 1.3 10.1.8 1.2 1.8 2.6 3.1 2.5 1.2 0 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4.9-1.4 1.3-2.7 1.3-2.8 0 0-2.4-.9-2.4-4.1zM14 5.3c.7-.8 1.1-1.9 1-3-1 0-2.2.7-2.9 1.5-.6.7-1.2 1.9-1 2.9 1.1.1 2.2-.6 2.9-1.4z"/></svg>
                {{ __('invite.wallet.apple') }}
            </a>
        @endif
        @if ($walletPlatforms['google'])
            <a class="wallet-btn google" href="{{ route('invite.wallet', [$walletToken, 'google'], false) }}" data-wallet-platform="google" rel="noopener">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M3 6.5A2.5 2.5 0 0 1 5.5 4h13A2.5 2.5 0 0 1 21 6.5V9H3z"/><path fill="#34A853" d="M3 9h18v3H3z"/><path fill="#FBBC04" d="M3 12h18v3H3z"/><path fill="#EA4335" d="M3 15h18v2.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/></svg>
                {{ __('invite.wallet.google') }}
            </a>
        @endif
        <a class="wallet-btn web" href="{{ route('invite.pass', $walletToken, false) }}">{{ __('invite.wallet.web') }}</a>
    </div>
@endif
