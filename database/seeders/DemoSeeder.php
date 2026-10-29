<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\LinkOpen;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\Rose;
use App\Models\Rsvp;
use App\Models\SeatAssignment;
use App\Models\Template;
use App\Models\User;
use App\Services\HallGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * One demo event per ported opening, so /i/{token} shows a working invitation.
 * Accounts use the password "password": never run this in production.
 */
class DemoSeeder extends Seeder
{
    public const TOKENS = [
        'layali-envelope' => 'LayaliDemo01',
        'warda-rose' => 'WardaDemo001',
        'khutuba-ringbox' => 'KhutubaDemo1',
        'henna-doors' => 'HennaDemo001',
        'birthday-gift' => 'BirthdayDemo',
        'graduation-diploma' => 'GradDemo0001',
    ];

    /** The household each demo link belongs to (shown as "To …" on the opening). */
    private const GUESTS = [
        'layali-envelope' => 'عائلة الأستاذ خالد العلي',
        'warda-rose' => 'عائلة الأستاذة هالة يوسف',
        'khutuba-ringbox' => 'عائلة الأستاذ سامر النجار',
        'henna-doors' => 'الغالية أم فيصل وبناتها',
        'birthday-gift' => 'يوسف وعائلته',
        'graduation-diploma' => 'الأستاذ وليد منصور وعائلته',
    ];

    public function run(HallGenerator $halls): void
    {
        $admin = User::query()->firstOrNew(['email' => 'admin@daawa.test']);
        $admin->fill(['name' => 'Daawa Admin', 'password' => 'password', 'locale' => 'ar'])->forceFill(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()])->save();

        $host = User::query()->firstOrNew(['email' => 'host@daawa.test']);
        $host->fill(['name' => 'ليان الحسن', 'password' => 'password', 'locale' => 'ar'])->forceFill(['email_verified_at' => now()])->save();

        $venue = Organization::query()->firstOrCreate(['name' => 'قاعة النور'], ['type' => 'venue', 'plan_id' => 'venue_pro']);
        OrgMember::query()->updateOrCreate(['org_id' => $venue->id, 'user_id' => $host->id], ['role' => OrgMember::ROLE_OWNER]);

        $layout = HallLayout::query()->firstOrCreate(
            ['org_id' => $venue->id, 'name' => 'Al Noor Ballroom'],
            ['layout' => $halls->generate(20, 8, 'split', name: 'Al Noor Ballroom', id: 'hall_al_noor_main')],
        );

        foreach (self::TOKENS as $templateId => $token) {
            $this->seedEvent(Template::query()->findOrFail($templateId), $token, $host, $layout);
        }

        $base = rtrim(config('app.invite_url'), '/');
        $this->command?->info('Demo invitations:');
        foreach (self::TOKENS as $templateId => $token) {
            $this->command?->line("  {$templateId}: {$base}/i/{$token}");
        }
        $this->command?->line('  admin: admin@daawa.test / password (/admin) · host: host@daawa.test / password');
    }

    private function seedEvent(Template $template, string $token, User $host, HallLayout $layout): void
    {
        $config = $template->default_config;
        $date = collect($config['sections'])->firstWhere('type', 'date');
        $place = collect($config['sections'])->firstWhere('type', 'place');
        $names = collect($config['sections'])->firstWhere('type', 'names');

        $kind = ['wedding' => 'زفاف', 'engagement' => 'خطوبة', 'henna' => 'حنّاء', 'birthday' => 'عيد ميلاد', 'graduation' => 'تخرّج'][$config['occasion']] ?? 'دعوة';
        $title = "{$kind} {$names['first']}".(isset($names['second']) ? " و{$names['second']}" : '');

        $event = Event::query()->updateOrCreate(
            ['owner_id' => $host->id, 'title' => $title],
            [
                'occasion' => $config['occasion'],
                'starts_at' => CarbonImmutable::parse($date['start'])->utc(),
                'timezone' => 'Asia/Amman',
                'venue_name' => $place['name'],
                'address' => $place['address'] ?? null,
                'lat' => $place['lat'] ?? null,
                'lng' => $place['lng'] ?? null,
                'hall_layout_id' => $layout->id,
                'status' => 'active',
                'plan_id' => 'premium',
            ],
        );

        Invitation::query()->updateOrCreate(
            ['event_id' => $event->id],
            ['template_id' => $template->id, 'config' => $config, 'published_at' => now(), 'slug' => str_replace('-', '', $template->id)],
        );

        $main = Household::query()->updateOrCreate(['token' => $token], [
            'event_id' => $event->id,
            'display_name' => self::GUESTS[$template->id] ?? 'عائلة الأستاذ خالد العلي',
            'seats_allowed' => 2,
            'section' => 'women',
            'tags' => ['أصدقاء'],
        ]);

        // a few more families with random tokens, one already replied and seated
        if ($event->households()->count() < 4) {
            Household::factory()->count(3)->sequence(
                ['display_name' => 'عائلة السيد أحمد الخطيب', 'seats_allowed' => 4, 'tags' => ['أقارب']],
                ['display_name' => 'أم فيصل', 'seats_allowed' => 3, 'tags' => ['جيران']],
                ['display_name' => 'سارة ومحمود', 'seats_allowed' => 2, 'tags' => ['زملاء']],
            )->create(['event_id' => $event->id]);

            $replied = $event->households()->where('display_name', 'عائلة السيد أحمد الخطيب')->first();
            Rsvp::query()->create(['household_id' => $replied->id, 'attending' => true, 'people_count' => 3, 'kids_count' => 1, 'message' => 'ألف مبروك', 'responded_at' => now()]);
            foreach (range(0, 2) as $seat) {
                SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $replied->id, 'table_id' => 'T7', 'seat_index' => $seat]);
            }
            LinkOpen::query()->create(['household_id' => $replied->id, 'opened_at' => now()->subDay(), 'ua_hash' => hash('sha256', 'demo')]);
            $replied->forceFill(['first_opened_at' => now()->subDay(), 'last_opened_at' => now()->subDay(), 'open_count' => 1])->save();
        }

        Rose::query()->updateOrCreate(['event_id' => $event->id], ['count' => 124]);
    }
}
