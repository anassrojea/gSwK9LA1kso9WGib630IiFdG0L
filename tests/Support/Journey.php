<?php

namespace Tests\Support;

use App\Models\Event;
use App\Models\Media;
use App\Models\Order;
use App\Models\User;
use App\Services\OgImage\Chromium;
use App\Services\Payments\OrderFulfillment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 8D test helpers: album photos as real JPEGs on the fake media disk,
 * paying orders, and whether ffmpeg / Chromium are there for the tests that
 * run them for real (they are skipped otherwise).
 */
class Journey
{
    /**
     * $count guest photos (JPEG, $w × $h, each a different colour), uploaded a minute apart.
     *
     * @return Collection<int, Media>
     */
    public static function photos(Event $event, int $count, int $w = 1200, int $h = 900, array $attributes = []): Collection
    {
        $disk = Storage::disk(config('filesystems.media_disk'));

        return collect(range(1, $count))->map(function (int $i) use ($event, $disk, $w, $h, $count, $attributes) {
            $id = (string) Str::uuid();
            $key = "events/{$event->id}/album/{$id}.jpg";
            $disk->put($key, static::jpeg($w, $h, $i));

            return Media::query()->forceCreate([
                'id' => $id,
                'event_id' => $event->id,
                'kind' => 'photo',
                'source' => Media::SOURCE_GUEST,
                'storage_key' => $key,
                'mime' => 'image/jpeg',
                'width' => $w,
                'height' => $h,
                'bytes' => strlen((string) $disk->get($key)),
                'hidden' => false,
                'created_at' => now()->subMinutes($count - $i),
                'updated_at' => now(),
            ] + $attributes);
        });
    }

    public static function jpeg(int $w, int $h, int $seed = 1): string
    {
        $image = imagecreatetruecolor($w, $h);
        $base = [($seed * 67) % 255, ($seed * 131) % 255, ($seed * 29) % 255];
        for ($y = 0; $y < $h; $y += 8) {
            $color = imagecolorallocate($image, $base[0], (int) ($base[1] * $y / $h), $base[2]);
            imagefilledrectangle($image, 0, $y, $w, $y + 8, $color);
        }
        imagefilledellipse($image, (int) ($w / 2), (int) ($h / 2), (int) ($w / 3), (int) ($h / 3), imagecolorallocate($image, 250, 240, 220));
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * POST /checkout as the owner, then approve the transfer (or pay the zero total at once).
     */
    public static function buy(Event $event, array $data, ?User $as = null): Order
    {
        $user = $as ?? $event->owner;
        test()->actingAs($user)->post('/checkout', $data + ['event_id' => $event->id, 'currency' => 'SAR', 'gateway' => 'transfer', 'from' => 'dashboard'])
            ->assertSessionHasNoErrors();
        $order = Order::query()->where('event_id', $event->id)->latest()->firstOrFail();

        return static::pay($order);
    }

    public static function pay(Order $order): Order
    {
        if ($order->isFulfilled()) {
            return $order->fresh();
        }
        $admin = User::query()->where('role', User::ROLE_ADMIN)->first() ?? User::factory()->admin()->create();
        app(OrderFulfillment::class)->approveTransfer($order, $admin, 'BANK-'.$order->code);

        return $order->fresh();
    }

    public static function ffmpeg(): bool
    {
        $path = (string) config('keepsakes.video.ffmpeg');

        return $path !== '' && is_file($path) && is_executable($path);
    }

    public static function chromium(): bool
    {
        return (new Chromium)->available();
    }
}
