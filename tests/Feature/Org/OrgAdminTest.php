<?php

use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\RelationManagers\OrdersRelationManager;
use App\Filament\Widgets\SubscriptionsOverview;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrgSubscription;
use App\Models\User;
use App\Services\Org\OrgSubscriptions;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\OrgFixtures;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('lists organizations with their subscription and shows the org page', function () {
    [$org] = OrgFixtures::org(['name' => 'قاعة النور']);
    OrgFixtures::subscribe($org);
    [$plain] = OrgFixtures::org(['name' => 'Planner Co']);

    Livewire::test(ListOrganizations::class)->assertOk()->assertCanSeeTableRecords([$org, $plain])
        ->filterTable('status', 'active')->assertCanSeeTableRecords([$org])->assertCanNotSeeTableRecords([$plain]);

    $this->get("/admin/organizations/{$org->id}")->assertOk()->assertSee('قاعة النور');
});

it('extends a transfer subscription by days, audit-logged', function () {
    [$org] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);
    $end = $subscription->current_period_end;

    Livewire::test(ViewOrganization::class, ['record' => $org->id])
        ->callAction('extend', data: ['days' => 10, 'note' => 'goodwill'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($subscription->fresh()->current_period_end->equalTo($end->addDays(10)))->toBeTrue();
    $log = AuditLog::query()->where('action', 'org_subscription.extended')->sole();
    expect($log->actor_id)->toBe($this->admin->id)->and($log->data)->toMatchArray(['days' => 10, 'note' => 'goodwill']);
});

it('revives an expired transfer subscription by extending it', function () {
    [$org] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);
    app(OrgSubscriptions::class)->end($subscription, 'expired', 'test');
    expect($org->fresh()->plan_id)->toBeNull();

    Livewire::test(ViewOrganization::class, ['record' => $org->id])->callAction('extend', data: ['days' => 30, 'note' => 'paid late in cash']);

    expect($subscription->fresh()->status)->toBe('active')->and($org->fresh()->plan_id)->toBe('venue_pro');
});

it('cancels a subscription now, audit-logged, and the org loses its plan', function () {
    [$org] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);

    Livewire::test(ViewOrganization::class, ['record' => $org->id])
        ->callAction('cancel_subscription', data: ['reason' => 'fraud'])
        ->assertNotified();

    expect($subscription->fresh()->status)->toBe('canceled')
        ->and($org->fresh()->plan_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'org_subscription.canceled')->sole()->actor_id)->toBe($this->admin->id);
});

it('approves a transfer renewal from the org page with the usual order approval', function () {
    [$org] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);
    $end = $subscription->current_period_end;
    $order = app(OrgSubscriptions::class)->renewalOrder($subscription);

    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $org, 'pageClass' => ViewOrganization::class])
        ->assertCanSeeTableRecords([$order])
        ->callAction(TestAction::make('approve')->table($order), data: ['bank_reference' => 'RJHI-777']);

    expect($order->fresh()->status)->toBe('approved')
        ->and($subscription->fresh()->current_period_end->equalTo($end->addMonthNoOverflow()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'order.approved')->where('entity_id', $order->id)->sole()->actor_id)->toBe($this->admin->id);
});

it('computes MRR per currency (yearly ÷ 12, never across currencies) and churn this month', function () {
    $this->travelTo(now()->startOfMonth()->addDays(10));
    [$a] = OrgFixtures::org();
    [$b] = OrgFixtures::org();
    [$c] = OrgFixtures::org();
    [$d] = OrgFixtures::org();
    OrgFixtures::subscribe($a, 'venue_pro', attributes: ['currency' => 'SAR', 'amount_cents' => 36900, 'created_at' => now()->subMonths(2)]);
    OrgFixtures::subscribe($b, 'venue_pro_yearly', attributes: ['currency' => 'SAR', 'amount_cents' => 369000, 'created_at' => now()->subMonths(2)]);
    OrgFixtures::subscribe($c, 'venue_business', attributes: ['currency' => 'USD', 'amount_cents' => 14900, 'status' => 'past_due', 'past_due_since' => now(), 'created_at' => now()->subMonths(2)]);
    $gone = OrgFixtures::subscribe($d, 'venue_starter', attributes: ['currency' => 'USD', 'amount_cents' => 4900, 'created_at' => now()->subMonths(2)]);
    app(OrgSubscriptions::class)->end($gone, 'canceled', 'test');

    expect(OrgSubscriptions::mrr())->toBe(['SYP' => 0, 'SAR' => 36900 + 30750, 'USD' => 14900])
        ->and(SubscriptionsOverview::churn())->toBe(['ended' => 1, 'base' => 4, 'rate' => 25.0]);

    Livewire::test(SubscriptionsOverview::class)->assertOk()
        ->assertSee(trans('org.admin.widget.mrr', ['currency' => 'SAR']))
        ->assertSee(trans('org.admin.widget.churn'))
        ->assertSee('٢٥');
    expect(OrgSubscription::query()->count())->toBe(4)->and(Order::query()->count())->toBe(0);
});
