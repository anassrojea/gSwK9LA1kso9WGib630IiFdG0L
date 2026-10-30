<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\OrgSubscription;
use App\Models\Rsvp;
use App\Models\Template;
use App\Models\User;
use App\Services\Org\OrgSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Demo data for Venue & Planner Pro (phase 8A), after DemoSeeder and
 * EventDayDemoSeeder: قاعة النور gets an active Venue Pro subscription paid
 * by bank transfer, white label (brand color, sender name), a staff member,
 * door staff, client events across this month (one edited by the client)
 * and a white-labelled invitation at /i/VenueDemo01.
 *
 *   owner  host@daawa.test / password      staff  staff@daawa.test / password
 *   door   door@daawa.test / password      client client@daawa.test / password
 */
class VenueProDemoSeeder extends Seeder
{
    public const TOKEN = 'VenueDemo01';

    public function run(): void
    {
        $host = User::query()->where('email', 'host@daawa.test')->first();
        $venue = Organization::query()->where('name', 'قاعة النور')->first();
        $template = Template::query()->find('warda-rose') ?? Template::query()->first();
        if (! $host || ! $venue || ! $template) {
            return;
        }

        $user = function (string $email, string $name) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill(['name' => $name, 'password' => 'password', 'locale' => 'ar'])->forceFill(['email_verified_at' => now()])->save();

            return $user;
        };
        $staff = $user('staff@daawa.test', 'رامي الحلبي');
        $door = $user('door@daawa.test', 'أبو سامر');
        $client = $user('client@daawa.test', 'نور وسليم');

        OrgMember::query()->updateOrCreate(['org_id' => $venue->id, 'user_id' => $staff->id], ['role' => OrgMember::ROLE_STAFF]);
        OrgMember::query()->updateOrCreate(['org_id' => $venue->id, 'user_id' => $door->id], ['role' => OrgMember::ROLE_DOOR]);

        $venue->forceFill([
            ...$this->logo($venue),
            'city' => 'عمّان',
            'contact_phone' => '+962 79 000 0000',
            'contact_email' => 'events@alnoor.example',
            'white_label' => ['brand_color' => '#7a3e2b', 'sender_name' => 'قاعة النور'],
        ])->save();

        if (! OrgSubscription::query()->where('org_id', $venue->id)->live()->exists()) {
            $start = CarbonImmutable::now()->subDays(10)->startOfHour();
            OrgSubscription::query()->create([
                'org_id' => $venue->id,
                'user_id' => $host->id,
                'plan_id' => 'venue_pro',
                'interval' => 'month',
                'provider' => 'transfer',
                'currency' => 'SAR',
                'amount_cents' => 36900,
                'status' => 'active',
                'current_period_start' => $start,
                'current_period_end' => OrgSubscriptions::addInterval($start, 'month'),
            ]);
        }
        app(OrgSubscriptions::class)->applyOrgPlan($venue);

        $hall = HallLayout::query()->where('org_id', $venue->id)->first();
        $month = CarbonImmutable::now('Asia/Amman')->startOfMonth();
        $events = [
            ['زفاف نور وسليم', 'wedding', $month->addDays(17)->setTime(20, 0), $client, self::TOKEN],
            ['خطوبة دانة ومازن', 'engagement', $month->addDays(8)->setTime(19, 30), $staff, null],
            ['حفل تخرّج دفعة ٢٠٢٦', 'graduation', $month->addDays(23)->setTime(18, 0), $staff, null],
            ['حنّاء العروس رهف', 'henna', $month->addDays(17)->setTime(17, 0), $staff, null],
        ];

        foreach ($events as [$title, $occasion, $startsAt, $owner, $token]) {
            $this->event($venue, $hall, $template, $title, $occasion, $startsAt, $owner, $token);
        }
    }

    /**
     * A simple drawn logo (an arch in a rounded square) on the media disk, for the white-label footer.
     *
     * @return array{logo_path?: string, logo_url?: string}
     */
    private function logo(Organization $venue): array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return [];
        }

        $size = 256;
        $image = imagecreatetruecolor($size, $size);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $brown = imagecolorallocate($image, 0x7A, 0x3E, 0x2B);
        $gold = imagecolorallocate($image, 0xE0, 0xB8, 0x6A);
        imagefilledellipse($image, 128, 128, 250, 250, $brown);
        // an arch: a gold half-disc over a gold block, with a brown door inside
        imagefilledellipse($image, 128, 112, 120, 120, $gold);
        imagefilledrectangle($image, 68, 112, 188, 190, $gold);
        imagefilledellipse($image, 128, 128, 56, 56, $brown);
        imagefilledrectangle($image, 100, 128, 156, 190, $brown);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $disk = Storage::disk(config('filesystems.media_disk', 'public'));
        $path = "orgs/{$venue->id}/demo-logo.png";
        $disk->put($path, $png, ['visibility' => 'public']);

        return ['logo_path' => $path, 'logo_url' => $disk->url($path)];
    }

    private function event(Organization $venue, ?HallLayout $hall, Template $template, string $title, string $occasion, CarbonImmutable $startsAt, User $owner, ?string $token): void
    {
        $config = $template->default_config;
        $config['occasion'] = $occasion;
        foreach ($config['sections'] as $i => $section) {
            if ($section['type'] === 'date') {
                $config['sections'][$i]['start'] = $startsAt->toIso8601String();
            }
        }

        $event = Event::query()->updateOrCreate(['org_id' => $venue->id, 'title' => $title], [
            'owner_id' => $owner->id,
            'occasion' => $occasion,
            'starts_at' => $startsAt->utc(),
            'timezone' => 'Asia/Amman',
            'venue_name' => 'قاعة النور',
            'hall_layout_id' => $hall?->id,
            'status' => 'active',
            'plan_id' => 'signature',
            'org_quota_at' => now()->subDays(2),
        ]);

        Invitation::query()->updateOrCreate(['event_id' => $event->id], [
            'template_id' => $template->id,
            'config' => $config,
            'published_at' => now()->subDays(2),
            'slug' => 'venue-'.substr(md5($title), 0, 8),
        ]);

        if ($event->households()->exists()) {
            return;
        }

        $families = [
            ['عائلة الأستاذ فادي الزين', 4, 'men', true, 3, 1],
            ['عائلة أم خالد', 3, 'women', true, 3, 0],
            ['عائلة الدكتور سمير', 5, 'men', true, 4, 2],
            ['آل الشامي', 2, 'women', false, 0, 0],
            ['عائلة المهندس وائل', 4, 'women', null, 0, 0],
        ];
        foreach ($families as $i => [$name, $seats, $section, $attending, $people, $kids]) {
            $household = Household::query()->create([
                'event_id' => $event->id,
                'display_name' => $name,
                'seats_allowed' => $seats,
                'section' => $section,
                'token' => $i === 0 && $token ? $token : Household::generateToken(),
                'tags' => [],
            ]);
            if ($attending !== null) {
                Rsvp::query()->create(['household_id' => $household->id, 'attending' => $attending, 'people_count' => $people, 'kids_count' => $kids, 'responded_at' => now()->subDay()]);
            }
        }
    }
}
