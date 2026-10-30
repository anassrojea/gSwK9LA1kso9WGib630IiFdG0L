<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Rsvp;
use App\Models\SeatAssignment;
use App\Models\Template;
use App\Models\User;
use App\Services\Seating\SeatingPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A Signature wedding happening tonight in the venue's Al Noor Ballroom, with
 * thirty confirmed families, so the seating planner, the door app and the
 * guest's live day panel (phase 7A) have something to show. Runs after
 * DemoSeeder (it needs its host and hall). Guest link: /i/SeatingDemo1.
 */
class EventDayDemoSeeder extends Seeder
{
    public const TOKEN = 'SeatingDemo1';

    private const FAMILIES = [
        // name, seats, people, kids, section, meal
        ['عائلة الأستاذ خالد العلي', 5, 4, 2, 'men', 'لحم'],
        ['عائلة السيد أحمد الخطيب', 4, 4, 1, 'men', 'دجاج'],
        ['أم فيصل وبناتها', 4, 3, 1, 'women', 'دجاج'],
        ['سارة ومحمود', 2, 2, 0, null, 'سمك'],
        ['عائلة الدكتور سامر النجار', 6, 5, 3, 'men', 'لحم'],
        ['الحاجة أم يوسف', 2, 2, 0, 'women', 'دجاج'],
        ['عائلة المهندس وليد منصور', 4, 4, 2, 'men', 'لحم'],
        ['عائلة هالة يوسف', 3, 3, 1, 'women', 'سمك'],
        ['عائلة رامي حداد', 5, 5, 2, 'men', 'لحم'],
        ['ليلى وصديقاتها', 4, 4, 0, 'women', 'دجاج'],
        ['عائلة عمر الشامي', 3, 3, 1, 'men', 'دجاج'],
        ['عائلة نور القاسم', 4, 3, 0, 'women', 'سمك'],
        ['The Haddad family', 3, 3, 1, null, 'Chicken'],
        ['عائلة جمال عيسى', 6, 6, 3, 'men', 'لحم'],
        ['عائلة ريم الأحمد', 5, 4, 2, 'women', 'دجاج'],
        ['عائلة باسل الصالح', 2, 2, 0, 'men', 'لحم'],
        ['أم كريم وأخواتها', 5, 5, 0, 'women', 'دجاج'],
        ['عائلة طارق عوض', 4, 4, 2, 'men', 'سمك'],
        ['عائلة دانة سليمان', 3, 2, 0, 'women', 'دجاج'],
        ['عائلة فادي مراد', 4, 3, 1, 'men', 'لحم'],
        ['عائلة سلمى الحسيني', 4, 4, 2, 'women', 'دجاج'],
        ['عائلة زياد قطيش', 3, 3, 0, 'men', 'دجاج'],
        ['عائلة منى الكردي', 2, 2, 0, 'women', 'سمك'],
        ['عائلة حسام الدين', 5, 5, 2, 'men', 'لحم'],
        ['The Saleh family', 4, 4, 2, null, 'Fish'],
        ['عائلة رنا عبيدات', 3, 3, 1, 'women', 'دجاج'],
        ['عائلة مازن العمري', 4, 2, 0, 'men', 'لحم'],
        ['عائلة لينا البيطار', 3, 3, 0, 'women', 'دجاج'],
        ['عائلة يزن الخوري', 2, 2, 0, 'men', 'سمك'],
        ['عائلة غادة الزعبي', 4, 4, 1, 'women', 'دجاج'],
    ];

    public function run(): void
    {
        $host = User::query()->where('email', 'host@daawa.test')->first();
        $venue = Organization::query()->where('name', 'قاعة النور')->first();
        $hall = HallLayout::query()->where('org_id', $venue?->id)->where('name', 'Al Noor Ballroom')->first();
        $template = Template::query()->find('layali-envelope');
        if (! $host || ! $hall || ! $template) {
            return;
        }

        $config = $template->default_config;
        $timezone = 'Asia/Amman';
        $start = CarbonImmutable::now($timezone)->setTime(20, 0);
        foreach ($config['sections'] as $i => $section) {
            if ($section['type'] === 'date') {
                $config['sections'][$i]['start'] = $start->toIso8601String();
            }
            if ($section['type'] === 'rsvp') {
                $config['sections'][$i]['askKids'] = true;
                $config['sections'][$i]['mealOptions'] = ['لحم', 'دجاج', 'سمك'];
            }
        }

        $event = Event::query()->updateOrCreate(
            ['owner_id' => $host->id, 'title' => 'زفاف ليان وكريم'],
            [
                'org_id' => $venue->id,
                'occasion' => 'wedding',
                'starts_at' => $start->utc(),
                'timezone' => $timezone,
                'venue_name' => 'قاعة النور',
                'address' => 'عمّان، الصويفية',
                'lat' => 31.9539,
                'lng' => 35.8656,
                'hall_layout_id' => $hall->id,
                'status' => 'active',
                'plan_id' => 'signature',
                'live_stream_url' => 'https://www.youtube.com/live/daawa-demo',
            ],
        );

        Invitation::query()->updateOrCreate(
            ['event_id' => $event->id],
            ['template_id' => $template->id, 'config' => $config, 'published_at' => now(), 'slug' => 'layan-karim'],
        );

        if ($event->households()->exists()) {
            return;
        }

        $families = [];
        foreach (self::FAMILIES as $i => [$name, $seats, $people, $kids, $section, $meal]) {
            $household = Household::query()->create([
                'event_id' => $event->id,
                'display_name' => $name,
                'seats_allowed' => $seats,
                'section' => $section,
                'token' => $i === 0 ? self::TOKEN : Household::generateToken(),
                'tags' => [],
            ]);
            $household->forceFill(['created_at' => now()->subMinutes(60 - $i)])->save();
            Rsvp::query()->create([
                'household_id' => $household->id,
                'attending' => true,
                'people_count' => $people,
                'kids_count' => $kids,
                'extras' => ['meal' => $meal],
                'responded_at' => now()->subDays(3),
            ]);
            $families[$household->id] = ['name' => $name, 'people' => $people];
        }

        // a few families still without a reply, and one who declined
        foreach (['عائلة نبيل حمدان' => 3, 'عائلة سوسن الربيع' => 2] as $name => $seats) {
            Household::query()->create(['event_id' => $event->id, 'display_name' => $name, 'seats_allowed' => $seats, 'tags' => []]);
        }
        $declined = Household::query()->create(['event_id' => $event->id, 'display_name' => 'عائلة شادي يونس', 'seats_allowed' => 2, 'tags' => []]);
        Rsvp::query()->create(['household_id' => $declined->id, 'attending' => false, 'people_count' => 0, 'kids_count' => 0, 'responded_at' => now()->subDay()]);

        // the first families already sit at their tables; the rest wait for the planner
        $rows = [];
        $ids = array_keys($families);
        $place = [[0, 'T1'], [1, 'T2'], [4, 'T3'], [2, 'T11'], [5, 'T11'], [7, 'T12']];
        $next = [];
        foreach ($place as [$index, $table]) {
            for ($p = 0; $p < $families[$ids[$index]]['people']; $p++) {
                $seat = $next[$table] = ($next[$table] ?? -1) + 1;
                $rows[] = ['household_id' => $ids[$index], 'table_id' => $table, 'seat_index' => $seat];
            }
        }
        foreach (SeatingPlan::label($rows, $families, array_column($hall->layout['tables'], 'id')) as $row) {
            SeatAssignment::query()->create($row + ['event_id' => $event->id]);
        }

        $this->command?->line('  event day (phase 7A): '.rtrim(config('app.invite_url'), '/').'/i/'.self::TOKEN.' · seating /dashboard/events/'.$event->id.'/seating · door /door/'.$event->id);
    }
}
