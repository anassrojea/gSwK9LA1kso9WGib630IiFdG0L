<?php

use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\EntitlementsRelationManager;
use App\Filament\Resources\Events\RelationManagers\HouseholdsRelationManager;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Household;
use App\Models\Order;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Payments\OrderFulfillment;
use App\Services\PublishGate;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/**
 * Row counts and the latest change of every table a host's data lives in.
 */
function dataFingerprint(): array
{
    $tables = ['users', 'events', 'invitations', 'households', 'rsvps', 'link_opens', 'orders', 'payments', 'entitlements', 'audit_log', 'media', 'reminders'];

    return collect($tables)->mapWithKeys(fn (string $table) => [$table => [
        DB::table($table)->count(),
        in_array($table, ['audit_log', 'link_opens'], true) ? null : DB::table($table)->max('updated_at'),
    ]])->all();
}

function customerEvent(): Event
{
    $owner = User::factory()->create(['name' => 'Reem Host', 'country' => 'SY', 'locale' => 'ar']);
    $event = Payments::event($owner, ['title' => 'Reem & Omar']);
    $yes = Household::factory()->for($event)->create(['display_name' => 'Al-Qasem family', 'seats_allowed' => 4]);
    Rsvp::query()->create(['household_id' => $yes->id, 'attending' => true, 'people_count' => 3, 'kids_count' => 0, 'message' => 'Mabrook!', 'responded_at' => now()]);
    Household::factory()->for($event)->create(['display_name' => 'Al-Zein family', 'seats_allowed' => 2]);

    return $event->fresh();
}

it('shows a customer: sign-in methods, events, orders and what they paid per currency', function () {
    $event = customerEvent();
    $owner = $event->owner;
    $owner->forceFill(['google_id' => 'g-123'])->save();
    Order::factory()->create(['user_id' => $owner->id, 'event_id' => $event->id, 'currency' => 'SAR', 'total_cents' => 10900, 'status' => 'approved', 'paid_at' => now()]);
    Order::factory()->create(['user_id' => $owner->id, 'event_id' => $event->id, 'currency' => 'SAR', 'total_cents' => 3500, 'status' => 'paid', 'paid_at' => now()]);
    Order::factory()->create(['user_id' => $owner->id, 'event_id' => $event->id, 'currency' => 'SYP', 'total_cents' => 350000, 'status' => 'approved', 'paid_at' => now()]);
    Order::factory()->create(['user_id' => $owner->id, 'event_id' => $event->id, 'currency' => 'SAR', 'total_cents' => 99900, 'status' => 'refunded']);

    Livewire::test(ViewUser::class, ['record' => $owner->id])
        ->assertSee('Reem Host')
        ->assertSee(trans('admin.users.methods.password'))
        ->assertSee('Google')
        ->assertSee(trans('app.checkout.countries.SY'))
        // SAR and SYP stay apart: 109 + 35 riyals, 350,000 pounds, never one sum; the refund doesn't count
        ->assertSee('١٤٤')
        ->assertSee('٣٥٠٬٠٠٠')
        ->assertDontSee('١٬١٤٣');

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => ViewUser::class])
        ->assertCanSeeTableRecords([$event]);
    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => ViewUser::class])
        ->assertCanSeeTableRecords($owner->orders);
});

it('opens an event as the customer sees it, read-only, and nothing in it writes', function () {
    $event = customerEvent();
    $before = dataFingerprint();

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->assertSee('Reem & Omar')
        ->assertSee(trans('admin.events.read_only'))
        ->assertSee('data-admin-preview', escape: false)
        ->assertSee(trans('app.wizard.tiers.free'))
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');

    Livewire::test(HouseholdsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->assertCanSeeTableRecords($event->households)
        ->assertSee('Mabrook!')
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('edit')->table($event->households->first()))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($event->households->first()));
    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])->assertSuccessful();

    $this->get("/admin/events/{$event->id}")->assertOk()->assertSee(trans('admin.events.guests'))->assertSee(trans('admin.events.numbers'));
    // the invitation as guests get it: placeholder guest, no opens counted, replies switched off
    $html = $this->get(PreviewController::eventUrl($event))->assertOk()->getContent();
    preg_match('#"state":"([^"]+)"#', $html, $state);
    preg_match('#"rsvp":"([^"]+)"#', $html, $rsvp);
    $this->getJson(stripslashes($state[1]).'?open=1')->assertOk()->assertJsonPath('preview', true);
    $this->postJson(stripslashes($rsvp[1]), ['attending' => true, 'people_count' => 2])->assertUnprocessable();

    expect(dataFingerprint())->toBe($before);

    // there is no page to edit, create or delete an event
    $this->get("/admin/events/{$event->id}/edit")->assertNotFound();
    $this->get('/admin/events/create')->assertNotFound();
    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$event])
        ->assertActionDoesNotExist(TestAction::make('edit')->table($event))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($event));
});

it('extends storage by 90 days, a year or to a date, audit-logged', function () {
    $event = customerEvent();
    $event->update(['storage_until' => null]);

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->callAction('extend_storage', data: ['preset' => '90d', 'note' => 'Asked on WhatsApp'])
        ->assertHasNoActionErrors()
        ->assertNotified();
    expect($event->fresh()->storage_until->toDateString())->toBe(today()->addDays(90)->toDateString());

    Livewire::test(ViewEvent::class, ['record' => $event->id])->callAction('extend_storage', data: ['preset' => '1y']);
    expect($event->fresh()->storage_until->toDateString())->toBe(today()->addDays(90 + 365)->toDateString());

    Livewire::test(ListEvents::class)
        ->callAction(TestAction::make('extend_storage')->table($event), data: ['preset' => 'custom', 'until' => today()->subDay()->toDateString()])
        ->assertHasActionErrors(['until']);
    Livewire::test(ListEvents::class)
        ->callAction(TestAction::make('extend_storage')->table($event), data: ['preset' => 'custom', 'until' => '2030-01-31']);
    expect($event->fresh()->storage_until->toDateString())->toBe('2030-01-31');

    $logs = AuditLog::query()->where('action', 'event.storage_extended')->orderBy('id')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs->first()->only('actor_id', 'entity_id'))->toBe(['actor_id' => $this->admin->id, 'entity_id' => $event->id])
        ->and($logs->first()->data)->toMatchArray(['to' => today()->addDays(90)->toDateString(), 'note' => 'Asked on WhatsApp'])
        ->and($logs->last()->data['to'])->toBe('2030-01-31');
});

it('grants and revokes features through the entitlement path payments use, audit-logged', function () {
    $event = customerEvent();
    $entitlements = app(Entitlements::class);
    $gate = app(PublishGate::class);

    // named households need personal links: not covered on Free
    expect($gate->check($event)['missing'])->toContain('personal_links');

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->callAction('grant', data: ['feature' => 'personal_links', 'note' => 'Launch gift'])
        ->assertHasNoActionErrors();

    expect($entitlements->hasFeature($event->fresh(), 'personal_links'))->toBeTrue()
        ->and($gate->check($event->fresh())['missing'])->not->toContain('personal_links')
        ->and(Entitlement::query()->where('event_id', $event->id)->sole()->only('feature', 'source_order_id', 'until'))->toBe(['feature' => 'personal_links', 'source_order_id' => null, 'until' => null]);

    // a guest pack raises the guest limit exactly as a bought one does
    Livewire::test(EntitlementsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->callTableAction('grant', data: ['feature' => 'guests_200', 'until' => now()->addMonth()->toDateTimeString(), 'note' => 'Big family'])
        ->assertHasNoTableActionErrors();
    expect($event->fresh()->guest_limit)->toBe(250);

    $pack = Entitlement::query()->where('feature', 'guests_200')->sole();
    Livewire::test(EntitlementsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
        ->callAction(TestAction::make('revoke')->table($pack), data: ['reason' => 'Granted by mistake'])
        ->assertHasNoActionErrors();
    expect($event->fresh()->guest_limit)->toBeNull()
        ->and($entitlements->hasFeature($event->fresh(), 'guests_200'))->toBeFalse();

    expect(AuditLog::query()->where('action', 'entitlement.granted')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'entitlement.granted')->first()->data)->toMatchArray(['feature' => 'personal_links', 'note' => 'Launch gift'])
        ->and(AuditLog::query()->where('action', 'entitlement.revoked')->sole()->only('actor_id', 'entity_id'))->toBe(['actor_id' => $this->admin->id, 'entity_id' => $event->id]);

    // only admins: the same path refuses anyone else
    expect(fn () => app(OrderFulfillment::class)->grantFeature($event, 'seating', null, $event->owner))
        ->toThrow(AuthorizationException::class);
});
