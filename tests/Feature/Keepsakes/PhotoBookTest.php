<?php

use App\Filament\Resources\PhotoBooks\Pages\ManagePhotoBooks;
use App\Models\Order;
use App\Models\PhotoBook;
use App\Models\User;
use App\Services\Keepsakes\PhotoBookPdf;
use App\Services\Keepsakes\PhotoBooks;
use App\Services\OgImage\Chromium;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\GuestExtras;
use Tests\Support\Journey;
use Tests\Support\Payments;

/*
| The printed photo book (plan section 14.5): photos (picked or auto-picked),
| a print-ready PDF with bleed and crop marks, the photo_book order item with
| a shipping address, and the print status the admin manages.
*/

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    [$this->event] = GuestExtras::event('signature', ['starts_at' => now()->subDay()]);
    $this->owner = $this->event->owner;
    $this->owner->forceFill(['email_verified_at' => now()])->save();
});

it('auto-picks photos spread over the evening, never hidden ones, and lays out exactly 20 or 40 pages', function () {
    $photos = Journey::photos($this->event, 50);
    $photos[0]->update(['hidden' => true]);
    $books = app(PhotoBooks::class);
    $book = $books->draft($this->event, $this->owner);

    $chosen = $books->chosen($book);
    expect(PhotoBooks::capacity(20))->toBe(28)->and(PhotoBooks::capacity(40))->toBe(58)
        ->and($chosen)->toHaveCount(28)
        ->and($chosen->pluck('id'))->not->toContain($photos[0]->id)
        ->and($chosen->first()->id)->toBe($photos[1]->id)
        ->and($chosen->last()->id)->toBe($photos[49]->id);

    foreach ([20, 40] as $pages) {
        foreach ([3, 28, 60] as $count) {
            $layout = PhotoBooks::layout($pages, $photos->take($count));
            expect($layout)->toHaveCount($pages)
                ->and($layout[0]['kind'])->toBe('cover')
                ->and(end($layout)['kind'])->toBe('back');
        }
    }
});

it('keeps the host\'s own choice, in order, within the layout', function () {
    $photos = Journey::photos($this->event, 5);
    $books = app(PhotoBooks::class);
    $book = $books->draft($this->event, $this->owner);

    $this->actingAs($this->owner)->put("/events/{$this->event->id}/photo-book", [
        'title' => 'ليلة العمر', 'size' => 'square_21', 'pages' => 20, 'auto_pick' => false, 'copies' => 2,
        'photo_ids' => [$photos[3]->id, $photos[1]->id],
    ])->assertSessionHasNoErrors();

    expect($books->chosen($book->fresh())->pluck('id')->all())->toBe([$photos[3]->id, $photos[1]->id])
        ->and($book->fresh())->size->toBe('square_21')->copies->toBe(2);
});

it('prints a PDF with the page count and bleed + crop-mark margins', function () {
    if (! (new Chromium)->available()) {
        $this->markTestSkipped('Chromium is not installed (OG_CHROME_PATH).');
    }
    config(['og.embed_fonts' => false, 'og.chrome_no_sandbox' => true, 'keepsakes.photo_book.bleed_mm' => 3, 'keepsakes.photo_book.slug_mm' => 6]);
    app()->instance(Chromium::class, new Chromium);
    Journey::photos($this->event, 6, 800, 600);
    $book = app(PhotoBooks::class)->draft($this->event, $this->owner);

    $key = app(PhotoBookPdf::class)->generate($book);

    $pdf = Storage::disk(config('filesystems.media_disk'))->get($key);
    $boxes = PhotoBookPdf::mediaBoxes($pdf);
    $pt = fn (float $mm) => $mm * 72 / 25.4;

    expect($key)->toStartWith("events/{$this->event->id}/photobook/")
        ->and(PhotoBookPdf::pageCount($pdf))->toBe(20)
        ->and($book->fresh()->pdf_pages)->toBe(20)
        // A4 landscape 297 × 210 + 2 × (3 mm bleed + 6 mm slug)
        ->and($boxes[0][0])->toEqualWithDelta($pt(297 + 18), 1.5)
        ->and($boxes[0][1])->toEqualWithDelta($pt(210 + 18), 1.5);

    $this->actingAs($this->owner)->get("/photo-books/{$book->id}.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('orders the book with its address through the normal checkout', function () {
    Journey::photos($this->event, 4);
    $books = app(PhotoBooks::class);
    $book = $books->draft($this->event, $this->owner);
    $this->actingAs($this->owner)->put("/events/{$this->event->id}/photo-book", [
        'title' => 'ليلة العمر', 'size' => 'a4_landscape', 'pages' => 40, 'auto_pick' => true, 'copies' => 3,
        'ship_name' => '', 'ship_country' => 'SA', 'ship_city' => 'الرياض', 'ship_address' => 'حي الملقا', 'ship_phone' => '+966500000000',
    ]);

    $this->actingAs($this->owner)->post("/events/{$this->event->id}/photo-book/order", ['currency' => 'SAR', 'gateway' => 'transfer'])
        ->assertSessionHasErrors('ship_name');

    $book->fresh()->update(['ship_name' => 'ريم']);
    $this->actingAs($this->owner)->post("/events/{$this->event->id}/photo-book/order", ['currency' => 'SAR', 'gateway' => 'transfer'])
        ->assertRedirect();

    $order = Order::query()->where('event_id', $this->event->id)->latest()->firstOrFail();
    expect($order->items[0])->toMatchArray(['type' => 'photo_book', 'id' => 'photo_book_40', 'quantity' => 3, 'amount' => 3 * 29900])
        ->and($book->fresh()->order_id)->toBe($order->id);

    Journey::pay($order);
    expect($book->fresh())->status->toBe('pending')->copies->toBe(3);

    // ordered: no more edits
    expect(fn () => $books->update($book->fresh(), ['title' => 'x']))->toThrow(ValidationException::class);
});

it('lets the admin move a paid book along with tracking notes, in order only', function () {
    $book = PhotoBook::query()->create(['event_id' => $this->event->id, 'title' => 'Book', 'photo_ids' => [], 'status' => 'pending', 'paid_at' => now()]);
    $admin = User::factory()->admin()->create();
    $books = app(PhotoBooks::class);

    expect(fn () => $books->transition($book, 'delivered', $admin))->toThrow(ValidationException::class);

    $books->transition($book, 'printing', $admin);
    $books->transition($book->fresh(), 'shipped', $admin, 'Aramex 123456');
    $books->transition($book->fresh(), 'delivered', $admin);

    expect($book->fresh())->status->toBe('delivered')
        ->printing_at->not->toBeNull()->shipped_at->not->toBeNull()->delivered_at->not->toBeNull()
        ->tracking_notes->toContain('Aramex 123456');
});

it('manages photo books in Filament', function () {
    $admin = User::factory()->admin()->create();
    $book = PhotoBook::query()->create(['event_id' => $this->event->id, 'title' => 'Book', 'photo_ids' => [], 'status' => 'pending', 'paid_at' => now(), 'ship_city' => 'جدة']);
    $this->actingAs($admin);

    Livewire::test(ManagePhotoBooks::class)
        ->assertCanSeeTableRecords([$book])
        ->callTableAction('move_printing', $book, data: ['note' => 'Sent to the print shop'])
        ->assertHasNoTableActionErrors();

    expect($book->fresh())->status->toBe('printing')->tracking_notes->toContain('Sent to the print shop');

    $this->get('/admin/photo-books')->assertOk()->assertSee('جدة');
    $this->get('/admin/bundles')->assertOk();
    $this->get('/admin/music-tracks')->assertOk();
});
