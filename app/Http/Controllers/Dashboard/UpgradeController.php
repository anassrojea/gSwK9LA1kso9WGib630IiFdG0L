<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\OrderPresenter;
use App\Services\PublishGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /dashboard/events/{event}/upgrade: a bigger plan or add-ons for an
 * event that is already live (the same checkout as the wizard's review step).
 */
class UpgradeController extends Controller
{
    public function show(Request $request, Event $event, CheckoutService $checkout, PublishGate $gate, OrderPresenter $presenter): Response
    {
        Gate::authorize('update', $event);

        $pending = Order::query()->where('event_id', $event->getKey())->pending()->with('latestReceipt')->latest()->first();

        return Inertia::render('dashboard/Upgrade', [
            'event' => ['id' => $event->id, 'title' => $event->title, 'status' => $event->status],
            'plan' => $gate->currentPlan($event),
            'checkout' => [
                ...$checkout->options($event, $request->user()),
                // ?addon=photo_cloud_90d: the storage warning email's "Extend storage" (phase 6B)
                'preselect_addon' => is_string($addon = $request->query('addon')) && array_key_exists($addon, (array) config('payments.addons')) ? $addon : null,
            ],
            'order' => $pending ? $presenter->present($pending) : null,
            'urls' => ['dashboard' => route('dashboard.events.show', $event, false), 'orders' => route('dashboard.orders.index', absolute: false)],
        ]);
    }
}
