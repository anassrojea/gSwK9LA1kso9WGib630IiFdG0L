<?php

namespace Database\Seeders;

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\Organization;
use App\Models\OrgMedia;
use App\Models\OrgMember;
use App\Models\User;
use App\Models\VenueProfile;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\HallTemplates;
use App\Services\Venues\VenueFeatures;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Phase 8B demo: the venue directory with three listed venues (Al Noor Hall
 * from DemoSeeder, a Jeddah hotel and a Riyadh garden), their halls with
 * banquet tables, sofas and a VIP row, generated photos, and phone numbers on
 * the event-day demo's families for "find your table" (/e/layan-karim/find,
 * e.g. phone 0790000001). Runs after DemoSeeder and EventDayDemoSeeder.
 */
class VenueDirectoryDemoSeeder extends Seeder
{
    public function run(HallTemplates $templates): void
    {
        $host = User::query()->where('email', 'host@daawa.test')->first();
        $alNoor = Organization::query()->where('name', 'قاعة النور')->first();
        if (! $host || ! $alNoor) {
            return;
        }

        $this->venue($alNoor, [
            'slug' => 'al-noor-hall',
            'name_en' => 'Al Noor Hall',
            'venue_type' => 'hall',
            'city' => 'amman',
            'area' => 'الصويفية',
            'description_ar' => "قاعة أفراح أنيقة في قلب الصويفية، تتسع لأكثر من ثلاثمئة ضيف.\nكوشة مضاءة، قسم مستقل للنساء، ومواقف واسعة.",
            'description_en' => "An elegant wedding hall in Sweifieh, for more than three hundred guests.\nA lit kosha, a separate women's section and plenty of parking.",
            'amenities' => ['parking', 'valet', 'prayer_room', 'women_section', 'bridal_room', 'kosha', 'sound_system', 'lighting'],
            'whatsapp' => '962790000000',
            'lat' => 31.9539, 'lng' => 35.8656,
            'featured' => true,
        ], ['#5A1A2C', '#C8A45D'], [
            ['Banquet hall', $templates->banquet()],
            ['Garden terrace', $templates->garden()],
        ]);
        HallLayout::query()->where('org_id', $alNoor->id)->update(['public' => true]);

        $hotel = Organization::query()->firstOrCreate(['name' => 'فندق اللؤلؤة'], ['type' => 'venue', 'plan_id' => 'venue_pro']);
        OrgMember::query()->updateOrCreate(['org_id' => $hotel->id, 'user_id' => $host->id], ['role' => OrgMember::ROLE_STAFF]);
        $this->venue($hotel, [
            'slug' => 'pearl-hotel-jeddah',
            'name_en' => 'The Pearl Hotel',
            'venue_type' => 'hotel',
            'city' => 'jeddah',
            'area' => 'الكورنيش',
            'description_ar' => 'قاعة الفندق الكبرى على كورنيش جدة، مع طاولات ولائم طويلة وجلسات لكبار الضيوف.',
            'description_en' => 'The grand ballroom on the Jeddah corniche, with long banquet tables and a VIP row.',
            'amenities' => ['parking', 'valet', 'catering', 'screens', 'wifi', 'accessible'],
            'whatsapp' => '966500000002',
            'capacity' => 600,
        ], ['#1F2A4A', '#E8B7C3'], [['Grand ballroom', $templates->banquet()]], public: true);

        $garden = Organization::query()->firstOrCreate(['name' => 'حديقة الياسمين'], ['type' => 'venue', 'plan_id' => 'venue_pro']);
        $this->venue($garden, [
            'slug' => 'jasmine-garden',
            'name_en' => 'Jasmine Garden',
            'venue_type' => 'garden',
            'city' => 'riyadh',
            'area' => 'حي الملقا',
            'description_ar' => 'حديقة مفتوحة لحفلات الخطوبة والأعراس الصغيرة، مع ركن للأطفال وبوفيه.',
            'description_en' => 'An open garden for engagements and small weddings, with a kids corner and a buffet.',
            'amenities' => ['parking', 'outdoor', 'kids_area', 'catering'],
            'whatsapp' => '966500000003',
        ], ['#1F4B3B', '#C8A45D'], [['Garden', $templates->garden()]], public: true);

        // "find your table": the event-day families have phone numbers (0790000001, 0790000002 …)
        $event = Event::query()->where('title', 'زفاف ليان وكريم')->first();
        if ($event) {
            Household::query()->where('event_id', $event->id)->whereNull('phone')->orderBy('created_at')->get()
                ->each(fn (Household $h, int $i) => $h->update(['phone' => '07900000'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)]));
        }

        $this->command?->line('  venue directory (phase 8B): /venues · /venues/al-noor-hall · find your table /e/layan-karim/find (phone 0790000001)');
    }

    /**
     * @param  list<array{0: string, 1: array}>  $halls
     */
    private function venue(Organization $org, array $values, array $colors, array $halls, bool $public = false): void
    {
        // listed through an org-level grant, so the demo does not depend on a live subscription (phase 8A)
        Entitlement::query()->firstOrCreate(['org_id' => $org->id, 'event_id' => null, 'feature' => VenueFeatures::DIRECTORY_LISTING]);

        $profile = VenueProfile::query()->updateOrCreate(['org_id' => $org->id], $values + [
            'listed' => true,
            'status' => VenueProfile::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        foreach ($halls as [$name, $layout]) {
            HallLayout::query()->firstOrCreate(
                ['org_id' => $org->id, 'name' => $name],
                ['layout' => HallLayoutData::normalize(['id' => null] + $layout, $name), 'public' => $public],
            );
        }

        if (! OrgMedia::query()->where('org_id', $org->id)->exists()) {
            $logo = $this->image($org, OrgMedia::KIND_LOGO, 256, 256, $colors, 0);
            $profile->update(['logo_id' => $logo->id]);
            foreach (range(1, 5) as $i) {
                $this->image($org, OrgMedia::KIND_PHOTO, 1200, 800, $colors, $i);
            }
        }
    }

    /** A generated picture (gradient with soft lights), so the demo needs no image files. */
    private function image(Organization $org, string $kind, int $w, int $h, array $colors, int $seed): OrgMedia
    {
        [$a, $b] = array_map(fn ($hex) => sscanf($hex, '#%02x%02x%02x'), $colors);
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = ($y / $h + $seed * 0.13) - floor($y / $h + $seed * 0.13);
            $c = imagecolorallocate($img, (int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t));
            imageline($img, 0, $y, $w, $y, $c);
        }
        mt_srand(crc32($org->id.$seed));
        for ($i = 0; $i < 14; $i++) {
            $glow = imagecolorallocatealpha($img, 255, 245, 225, 100);
            $r = mt_rand((int) ($w / 30), (int) ($w / 10));
            imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, (int) ($h * 0.6)), $r, $r, $glow);
        }
        if ($kind === OrgMedia::KIND_LOGO) {
            $white = imagecolorallocate($img, 255, 255, 255);
            imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w * 0.6), (int) ($h * 0.6), $white);
            imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w * 0.36), (int) ($h * 0.36), imagecolorallocate($img, ...$a));
        } else {
            // tables and chairs, seen from above
            $white = imagecolorallocatealpha($img, 255, 255, 255, 40);
            for ($i = 0; $i < 6; $i++) {
                $cx = (int) ($w * (0.15 + 0.14 * $i));
                $cy = (int) ($h * (0.7 + 0.08 * ($i % 2)));
                imagefilledellipse($img, $cx, $cy, (int) ($w * 0.09), (int) ($w * 0.045), $white);
            }
        }
        ob_start();
        imagejpeg($img, null, 82);
        $bytes = (string) ob_get_clean();

        $id = (string) Str::uuid();
        $key = "orgs/{$org->id}/{$id}.jpg";
        OrgMedia::disk()->put($key, $bytes, ['visibility' => 'public']);

        $media = new OrgMedia(['org_id' => $org->id, 'kind' => $kind, 'storage_key' => $key, 'width' => $w, 'height' => $h, 'bytes' => strlen($bytes), 'sort' => $seed]);
        $media->id = $id;
        $media->save();

        return $media;
    }
}
