<?php

namespace App\Http\Controllers\Account;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\LoginCode;
use App\Models\Order;
use App\Services\Account\AccountDeletion;
use App\Services\Account\GoogleAuth;
use App\Services\Account\LoginCodes;
use App\Services\Account\SessionRegistry;
use App\Services\Account\UpgradeHints;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My account" (Inertia page account/Show), one URL per tab:
 *
 *   GET /account           Profile: name, email, phone, language, country and currency
 *   GET /account/security  password, Google, signed-in devices
 *   GET /account/billing   each event's plan and what it unlocks, orders, transfers waiting
 *   GET /account/privacy   export, delete, what we keep
 */
class AccountController extends Controller
{
    public const TABS = ['profile', 'security', 'billing', 'privacy'];

    public function profile(Request $request): Response
    {
        $user = $request->user();

        return $this->render($request, 'profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'country' => $user->country,
                'currency' => $user->currency,
                'email_verified' => $user->email_verified_at !== null,
            ],
            'options' => [
                'locales' => array_keys(config('app.locales')),
                'countries' => array_keys(config('payments.countries', [])),
                'currencies' => Currencies::all(),
            ],
        ]);
    }

    public function security(Request $request, SessionRegistry $sessions): Response
    {
        $user = $request->user();
        // this device is listed even on its first request
        $sessions->touch($user, $request);

        return $this->render($request, 'security', [
            'security' => [
                'google' => ['enabled' => GoogleAuth::enabled(), 'linked' => $user->google_id !== null],
                'methods' => $user->signInMethods(),
                'can_unlink_google' => $user->canUnlinkGoogle(),
                'login_codes' => LoginCodes::enabled(),
                'sessions' => $sessions->list($user, $request->session()->getId()),
            ],
        ]);
    }

    public function billing(Request $request, UpgradeHints $hints, OrderPresenter $presenter): Response
    {
        $user = $request->user();

        $events = Event::query()
            ->where('owner_id', $user->getKey())
            ->with('organization')
            ->orderByDesc('starts_at')
            ->get()
            ->map(function (Event $event) use ($hints) {
                $hint = $hints->for($event);

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'status' => $event->status,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    ...$hint,
                    'upgrade_url' => $hint['can_upgrade'] ? self::upgradeUrl($event) : null,
                    'url' => $event->status === 'draft'
                        ? route('create.step', [$event, 'details'], false)
                        : route('dashboard.events.show', $event, false),
                ];
            });

        $orders = Order::query()->where('user_id', $user->getKey())->with(['event:id,title,status', 'invoices'])->latest()->limit(20)->get();
        $pending = Order::query()
            ->where('user_id', $user->getKey())
            ->where('method', 'transfer')
            ->pending()
            ->with(['event:id,title,status', 'latestReceipt'])
            ->latest()
            ->get();

        return $this->render($request, 'billing', [
            'billing' => [
                'events' => $events,
                'orders' => $orders->map(fn (Order $o) => $presenter->present($o, details: false))->values(),
                'pending' => $pending->map(fn (Order $o) => $presenter->present($o))->values(),
                'orders_url' => route('dashboard.orders.index', absolute: false),
            ],
        ]);
    }

    public function privacy(Request $request, AccountDeletion $deletion): Response
    {
        $user = $request->user();

        return $this->render($request, 'privacy', [
            'privacy' => [
                'export_url' => route('account.export', absolute: false),
                'confirm_with' => $user->hasPassword() ? 'password' : 'code',
                'blockers' => $deletion->blockers($user),
                'grace_days' => AccountDeletion::graceDays(),
            ],
        ]);
    }

    /**
     * PUT /account/profile
     */
    public function updateProfile(Request $request, UpdateUserProfileInformation $action): RedirectResponse
    {
        $user = $request->user();
        $action->update($user, $request->only(['name', 'email', 'phone', 'locale', 'country', 'currency', 'current_password', 'email_code']));

        if ($request->filled('locale')) {
            $request->session()->put('locale', $user->locale);
        }

        // the profile page reloads in the new language when it changed
        return redirect()->route('account.profile')->with('status', trans('app.account.profile.saved', [], $user->locale));
    }

    /**
     * POST /account/email-code {email}: a code to the new address (accounts without a password).
     */
    public function sendEmailCode(Request $request, LoginCodes $codes): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $codes->send($data['email'], LoginCode::EMAIL, $request->ip(), $user->locale);

        return back()->with('status', trans('app.account.profile.code_sent', ['email' => LoginCodes::normalize($data['email'])]));
    }

    /**
     * Upgrading a live event happens on its dashboard; a draft picks its plan at the wizard's review step.
     */
    public static function upgradeUrl(Event $event): string
    {
        return $event->status === 'draft'
            ? route('create.step', [$event, 'review'], false)
            : route('dashboard.events.upgrade', $event, false);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function render(Request $request, string $tab, array $props): Response
    {
        $user = $request->user();

        return Inertia::render('account/Show', [
            'tab' => $tab,
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'has_password' => $user->hasPassword(),
                'deletion' => $user->deletion_requested_at ? [
                    'requested_at' => $user->deletion_requested_at->toIso8601String(),
                    'scheduled_for' => AccountDeletion::scheduledFor($user)?->toIso8601String(),
                    'date' => AccountDeletion::formatDate(AccountDeletion::scheduledFor($user), app()->getLocale()),
                ] : null,
            ],
            'urls' => collect(self::TABS)->mapWithKeys(fn (string $t) => [$t => route('account.'.$t, absolute: false)])->all(),
            ...$props,
        ]);
    }
}
