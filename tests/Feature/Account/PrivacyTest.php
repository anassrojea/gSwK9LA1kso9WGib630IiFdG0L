<?php

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\LoginCode;
use App\Models\Media;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rsvp;
use App\Models\TransferReceipt;
use App\Models\User;
use App\Notifications\AccountDeleted;
use App\Notifications\AccountDeletionRequested;
use App\Notifications\LoginCodeNotification;
use App\Services\Account\AccountDeletion;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('public');
});

/**
 * A host with one event (invitation, two families, one reply, an upload on disk) and a paid order.
 *
 * @return array{0: User, 1: Event, 2: Order}
 */
function hostWithData(array $userAttributes = []): array
{
    $user = User::factory()->create(['name' => 'Huda Host', 'email' => 'huda@example.test', 'phone' => '+966551234567'] + $userAttributes);
    $event = Event::factory()->create(['owner_id' => $user->id, 'title' => 'Huda & Sami']);
    Invitation::factory()->for($event)->create();
    $family = Household::factory()->for($event)->create(['display_name' => 'Abu Faisal', 'phone' => '+962791111111']);
    Household::factory()->for($event)->create(['display_name' => 'Umm Omar']);
    Rsvp::factory()->create(['household_id' => $family->id, 'attending' => true, 'people_count' => 3, 'message' => 'Mabrook!']);

    Storage::disk('public')->put("events/{$event->id}/photo.webp", 'image-bytes');
    Storage::disk('public')->put("og/{$event->id}/open-abc.png", 'png-bytes');
    Media::factory()->create(['event_id' => $event->id, 'storage_key' => "events/{$event->id}/photo.webp"]);

    $order = Order::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'status' => 'approved', 'total_cents' => 10900, 'currency' => 'SAR']);
    Payment::factory()->create(['order_id' => $order->id, 'amount_cents' => 10900, 'raw' => ['customer_details' => ['email' => 'huda@example.test', 'name' => 'Huda Host'], 'amount_total' => 10900]]);
    TransferReceipt::factory()->create(['order_id' => $order->id, 'note' => 'sent from my sister\'s account', 'bank_reference' => 'REF-1']);

    return [$user, $event, $order];
}

// ---------------------------------------------------------------- export

it('exports the profile, events, configs, households, replies, uploads and orders', function () {
    [$user, $event, $order] = hostWithData();
    $someoneElse = Event::factory()->create(['title' => 'Not mine']);

    $response = $this->actingAs($user)->get('/account/export')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertDownload();

    $data = json_decode($response->getContent(), true);

    expect($data['format'])->toBe('daawa-account-export')
        ->and($data['profile'])->toMatchArray(['name' => 'Huda Host', 'email' => 'huda@example.test', 'phone' => '+966551234567'])
        ->and($data['events'])->toHaveCount(1)
        ->and($data['events'][0]['title'])->toBe('Huda & Sami')
        ->and($data['events'][0]['invitation']['config'])->toBeArray()->not->toBeEmpty()
        ->and(collect($data['events'][0]['households'])->pluck('name')->sort()->values()->all())->toBe(['Abu Faisal', 'Umm Omar'])
        ->and(collect($data['events'][0]['households'])->firstWhere('name', 'Abu Faisal')['rsvp'])->toMatchArray(['attending' => true, 'people_count' => 3, 'message' => 'Mabrook!'])
        ->and($data['events'][0]['media'][0]['url'])->toEndWith("events/{$event->id}/photo.webp")
        ->and($data['orders'][0])->toMatchArray(['code' => $order->code, 'total_minor' => 10900, 'currency' => 'SAR'])
        ->and($data['orders'][0]['payments'][0]['amount_minor'])->toBe(10900)
        ->and($response->getContent())->not->toContain('Not mine')
        ->and($response->getContent())->not->toContain($user->password);

    expect($someoneElse->exists)->toBeTrue();
});

it('exports only to the signed-in user', function () {
    $this->get('/account/export')->assertRedirect('/login');

    [$user] = hostWithData();
    $other = User::factory()->create();

    $data = json_decode($this->actingAs($other)->get('/account/export')->getContent(), true);
    expect($data['events'])->toBe([])->and($data['orders'])->toBe([])->and($data['profile']['email'])->toBe($other->email);
});

// ---------------------------------------------------------------- request & cancel

it('requests deletion with the password, emails the date, and can be cancelled', function () {
    [$user] = hostWithData();

    $this->actingAs($user)->post('/account/deletion', ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($user->fresh()->deletion_requested_at)->toBeNull();

    $this->actingAs($user)->post('/account/deletion', ['password' => 'password'])->assertRedirect('/account/privacy')->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->deletion_requested_at)->not->toBeNull()
        ->and(AccountDeletion::scheduledFor($user)->isSameDay(now()->addDays(14)))->toBeTrue();
    Notification::assertSentTo($user, AccountDeletionRequested::class);

    // the grace banner on every page
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.user.deletion_scheduled_for', AccountDeletion::scheduledFor($user)->toIso8601String()));

    $this->actingAs($user)->delete('/account/deletion')->assertSessionHas('status');
    expect($user->fresh()->deletion_requested_at)->toBeNull();
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.user.deletion_scheduled_for', null));
});

it('confirms deletion with an email code when the account has no password', function () {
    $user = User::factory()->create(['password' => null, 'email' => 'nopass@example.test']);

    $this->actingAs($user)->get('/account/privacy')->assertInertia(fn ($page) => $page->where('privacy.confirm_with', 'code'));
    $this->actingAs($user)->post('/account/deletion', ['code' => '123456'])->assertSessionHasErrors('code');

    $this->actingAs($user)->post('/account/deletion/code')->assertSessionHas('status');
    $code = null;
    Notification::assertSentOnDemand(LoginCodeNotification::class, function ($n) use (&$code) {
        $code = $n->code;

        return $n->purpose === LoginCode::DELETE;
    });

    $this->actingAs($user)->post('/account/deletion', ['code' => $code])->assertSessionHasNoErrors();
    expect($user->fresh()->deletion_requested_at)->not->toBeNull();
});

it('blocks deletion while a transfer receipt waits for an admin', function () {
    [$user, $event] = hostWithData();
    Order::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'status' => 'receipt_received']);

    $this->actingAs($user)->get('/account/privacy')->assertInertia(fn ($page) => $page->where('privacy.blockers', ['pending_transfer']));
    $this->actingAs($user)->post('/account/deletion', ['password' => 'password'])
        ->assertSessionHasErrors(['deletion' => trans('app.account.privacy.blocked.pending_transfer')]);

    expect($user->fresh()->deletion_requested_at)->toBeNull();
});

it('does not let the last admin delete their account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/account/deletion', ['password' => 'password'])
        ->assertSessionHasErrors(['deletion' => trans('app.account.privacy.blocked.last_admin')]);

    User::factory()->admin()->create();
    $this->actingAs($admin)->post('/account/deletion', ['password' => 'password'])->assertSessionHasNoErrors();
    expect($admin->fresh()->deletion_requested_at)->not->toBeNull();
});

// ---------------------------------------------------------------- the purge

it('waits for the grace period before deleting', function () {
    [$user] = hostWithData();
    $this->actingAs($user)->post('/account/deletion', ['password' => 'password']);

    $this->travel(13)->days();
    $this->artisan('account:purge-deleted')->assertSuccessful();
    expect(User::query()->find($user->id))->not->toBeNull();

    $this->travel(2)->days();
    $this->artisan('account:purge-deleted')->assertSuccessful();
    expect(User::query()->find($user->id))->toBeNull();
});

it('keeps orders and payments without the person, and deletes events, guests and files', function () {
    [$user, $event, $order] = hostWithData();
    $unpaid = Order::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'status' => 'awaiting_payment']);
    AuditLog::record('order.created', $order, ['total' => 10900], $user);
    $user->forceFill(['deletion_requested_at' => now()->subDays(15)])->save();
    $bystander = Event::factory()->create();

    $this->artisan('account:purge-deleted')->expectsOutputToContain('Deleted 1 account(s)')->assertSuccessful();

    // the person is gone
    expect(User::query()->find($user->id))->toBeNull()
        ->and(Event::query()->find($event->id))->toBeNull()
        ->and(Household::query()->where('event_id', $event->id)->count())->toBe(0)
        ->and(Rsvp::query()->count())->toBe(0)
        ->and(Media::query()->where('event_id', $event->id)->count())->toBe(0)
        ->and(Invitation::query()->where('event_id', $event->id)->count())->toBe(0);
    Storage::disk('public')->assertMissing("events/{$event->id}/photo.webp");
    Storage::disk('public')->assertMissing("og/{$event->id}/open-abc.png");

    // accounting keeps the money, not the name
    $order->refresh();
    expect($order->user_id)->toBeNull()
        ->and($order->code)->not->toBeEmpty()
        ->and($order->total_cents)->toBe(10900)
        ->and($order->currency)->toBe('SAR')
        ->and($order->status)->toBe('approved')
        ->and($order->event_id)->toBeNull()
        ->and($unpaid->fresh()->status)->toBe('expired');

    $payment = Payment::query()->where('order_id', $order->id)->sole();
    expect($payment->amount_cents)->toBe(10900)
        ->and($payment->raw['amount_total'])->toBe(10900)
        ->and(json_encode($payment->raw))->not->toContain('huda@example.test')->not->toContain('Huda Host');

    $receipt = TransferReceipt::query()->where('order_id', $order->id)->sole();
    expect($receipt->note)->toBeNull()->and($receipt->bank_reference)->toBe('REF-1');

    expect(AuditLog::query()->where('action', 'order.created')->sole()->actor_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.purged')->exists())->toBeTrue();

    expect($bystander->fresh())->not->toBeNull();
    Notification::assertSentOnDemand(AccountDeleted::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'huda@example.test');
});

it('postpones the purge while a transfer receipt waits for an admin', function () {
    [$user, $event] = hostWithData();
    Order::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'status' => 'receipt_received']);
    $user->forceFill(['deletion_requested_at' => now()->subDays(20)])->save();

    $this->artisan('account:purge-deleted')->expectsOutputToContain('postponed 1')->assertSuccessful();

    expect(User::query()->find($user->id))->not->toBeNull()
        ->and(Event::query()->find($event->id))->not->toBeNull();
});

it('shows the consent and retention text on the privacy tab', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/privacy')
        ->assertInertia(fn ($page) => $page
            ->where('translations.account.privacy.consent', trans('app.account.privacy.consent'))
            ->where('privacy.grace_days', 14)
            ->where('privacy.confirm_with', 'password'));
});
