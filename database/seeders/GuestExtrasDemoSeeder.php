<?php

namespace Database\Seeders;

use App\Jobs\MakeThumbnails;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\GuestbookEntry;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Rsvp;
use App\Models\Template;
use App\Models\User;
use App\Services\Album\Album;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local demo of the phase 7B guest extras (run after DemoSeeder, never in production):
 *
 *   php artisan db:seed --class=GuestExtrasDemoSeeder
 *
 * Puts the Arabic rose wedding (/i/WardaDemo001) and an English wedding
 * (/i/EvergreenDemo) on Signature, starts the party, adds generated album
 * photos, guestbook messages and an RSVP, and unlocks the AI poem and video
 * guestbook add-ons. AI calls stay fake with AI_FAKE=true.
 */
class GuestExtrasDemoSeeder extends Seeder
{
    public function run(Album $album): void
    {
        abort_if(app()->isProduction(), 403, 'Demo data is for local use only.');

        $host = User::query()->where('email', 'host@daawa.test')->firstOrFail();
        $arabic = Household::query()->where('token', 'WardaDemo001')->firstOrFail();
        $this->prepare($arabic->event, $album, ['عائلة السيد أحمد الخطيب', 'أم فيصل', 'سارة ومحمود'], ['ألف ألف مبروك، الله يتمم عليكم بخير', 'يا رب السعادة الدائمة لأحلى عروسين']);
        $this->attend($arabic, 2);

        $english = $this->englishEvent($host);
        $this->prepare($english->event, $album, ['The Haddad family', 'Lina & Sam', 'Uncle Fadi'], ['Congratulations! Wishing you a lifetime of love.', 'What a beautiful night. Thank you for having us!']);
        $this->attend($english, 2);

        $this->command?->line('  album: /i/WardaDemo001/album · /i/EvergreenDemo/album · wall: /e/wardarose/wall · /e/evergreenringbox/wall');
    }

    private function prepare(Event $event, Album $album, array $names, array $wishes): void
    {
        $event->update(['plan_id' => 'signature', 'starts_at' => now()->subHours(2)]);
        foreach (['ai_poem', 'video_guestbook'] as $feature) {
            Entitlement::query()->firstOrCreate(['event_id' => $event->id, 'feature' => $feature]);
        }
        $album->update($event, ['moderate' => false, 'upload_before_event' => true]);

        if (Media::query()->album()->where('event_id', $event->id)->exists()) {
            return;
        }

        $families = collect($names)->map(fn ($name) => $event->households()->where('display_name', $name)->first()
            ?? Household::factory()->create(['event_id' => $event->id, 'display_name' => $name, 'seats_allowed' => 3]));

        foreach (range(0, 13) as $i) {
            $household = $families[$i % $families->count()];
            $id = (string) Str::uuid();
            $key = $album->path($event, $id, 'jpg');
            $album->disk()->put($key, $this->photo($i));
            $media = new Media([
                'event_id' => $event->id,
                'household_id' => $household->id,
                'kind' => 'photo',
                'source' => Media::SOURCE_GUEST,
                'storage_key' => $key,
                'mime' => 'image/jpeg',
                'bytes' => $album->disk()->size($key),
                // two waiting for approval, to show moderation
                'hidden' => $i >= 12,
                'approved_at' => $i >= 12 ? null : now(),
            ]);
            $media->id = $id;
            $media->created_at = now()->subMinutes(5 * (14 - $i));
            $media->save();
            MakeThumbnails::dispatchSync($id);
        }

        foreach ($wishes as $i => $text) {
            GuestbookEntry::query()->create(['event_id' => $event->id, 'household_id' => $families[$i]->id, 'kind' => 'text', 'text' => $text]);
        }
    }

    private function attend(Household $household, int $people): void
    {
        Rsvp::query()->updateOrCreate(['household_id' => $household->id], ['attending' => true, 'people_count' => $people, 'kids_count' => 0, 'responded_at' => now()]);
    }

    private function englishEvent(User $host): Household
    {
        $template = Template::query()->findOrFail('evergreen-ringbox');
        $config = $template->default_config;
        $date = collect($config['sections'])->firstWhere('type', 'date');
        $place = collect($config['sections'])->firstWhere('type', 'place');

        $event = Event::query()->updateOrCreate(['owner_id' => $host->id, 'title' => 'Evergreen wedding (demo)'], [
            'occasion' => $config['occasion'],
            'starts_at' => CarbonImmutable::parse($date['start'])->utc(),
            'timezone' => 'Asia/Dubai',
            'venue_name' => $place['name'] ?? null,
            'lat' => $place['lat'] ?? null,
            'lng' => $place['lng'] ?? null,
            'status' => 'active',
            'plan_id' => 'signature',
        ]);
        Invitation::query()->updateOrCreate(['event_id' => $event->id], ['template_id' => $template->id, 'config' => $config, 'published_at' => now(), 'slug' => 'evergreenringbox']);

        return Household::query()->updateOrCreate(['token' => 'EvergreenDemo'], ['event_id' => $event->id, 'display_name' => 'The Rahman family', 'seats_allowed' => 3]);
    }

    /** A generated "party photo": warm light, bokeh and a silhouette band, no real people. */
    private function photo(int $seed): string
    {
        mt_srand($seed * 7919 + 13);
        $portrait = $seed % 3 === 1;
        [$w, $h] = $portrait ? [1080, 1440] : [1600, 1066];
        $image = imagecreatetruecolor($w, $h);
        $palettes = [[[255, 183, 94], [120, 40, 70]], [[250, 214, 165], [70, 50, 110]], [[255, 140, 120], [40, 30, 60]], [[240, 200, 120], [20, 60, 70]], [[255, 210, 220], [150, 40, 80]]];
        [$top, $bottom] = $palettes[$seed % count($palettes)];
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $color = imagecolorallocate($image, (int) ($top[0] * (1 - $t) + $bottom[0] * $t), (int) ($top[1] * (1 - $t) + $bottom[1] * $t), (int) ($top[2] * (1 - $t) + $bottom[2] * $t));
            imageline($image, 0, $y, $w, $y, $color);
        }
        imagealphablending($image, true);
        for ($i = 0; $i < 60; $i++) {
            $r = mt_rand(20, 120);
            $glow = imagecolorallocatealpha($image, 255, mt_rand(200, 255), mt_rand(150, 230), mt_rand(70, 115));
            imagefilledellipse($image, mt_rand(0, $w), mt_rand(0, (int) ($h * .7)), $r, $r, $glow);
        }
        $shadow = imagecolorallocatealpha($image, 15, 10, 20, 25);
        for ($x = -50; $x < $w + 50; $x += mt_rand(90, 170)) {
            $head = mt_rand(70, 100);
            $base = (int) ($h * mt_rand(68, 80) / 100);
            imagefilledellipse($image, $x, $base, $head, (int) ($head * 1.2), $shadow);
            imagefilledellipse($image, $x, $base + (int) ($head * 1.6), (int) ($head * 2.4), (int) ($head * 2.4), $shadow);
        }
        imagefilledrectangle($image, 0, (int) ($h * .88), $w, $h, $shadow);

        ob_start();
        imagejpeg($image, null, 86);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
