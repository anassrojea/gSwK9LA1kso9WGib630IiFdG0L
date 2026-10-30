<?php

namespace Database\Seeders;

use App\Models\AccountCredit;
use App\Models\BundleCredit;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Order;
use App\Models\Referral;
use App\Models\Template;
use App\Models\User;
use App\Services\Journey\Referrals;
use App\Services\Keepsakes\PhotoBooks;
use App\Services\Keepsakes\ThankYouCards;
use App\Services\Wizard\EventSync;
use Illuminate\Database\Seeder;

/**
 * Local demo data for phase 8D (after DemoSeeder; runs GuestExtrasDemoSeeder for the album):
 *
 *   - the Warda wedding: thank-you card published (/t/WardaDemo001), the highlight video
 *     and photo book unlocked, a photo book draft (dashboard → After the event)
 *   - a save-the-date card, published: /i/SaveDateDemo
 *   - host@daawa.test: two bundle credits, a referral reward credit, and a paid plan
 *     on another event (so the loyalty discount shows at checkout)
 */
class JourneyDemoSeeder extends Seeder
{
    public function run(ThankYouCards $cards, PhotoBooks $books, Referrals $referrals): void
    {
        abort_if(app()->isProduction(), 403, 'Demo data is for local use only.');

        $host = User::query()->where('email', 'host@daawa.test')->first();
        $warda = Household::query()->where('token', 'WardaDemo001')->first();
        if (! $host || ! $warda) {
            return;
        }

        if (! Media::query()->album()->where('event_id', $warda->event_id)->exists()) {
            $this->call(GuestExtrasDemoSeeder::class);
        }
        $event = $warda->event->fresh();

        foreach (['thank_you', 'highlight_video'] as $feature) {
            Entitlement::query()->firstOrCreate(['event_id' => $event->id, 'feature' => $feature]);
        }
        $card = $cards->forEvent($event);
        $cards->update($card, ['message' => 'كان حضوركم أجمل ما في ليلتنا. شكراً لكل دعوة صادقة، ولكل ابتسامة، ولكل صورة شاركتمونا إياها.', 'signature' => 'ريم وعمر']);
        $cards->publish($card->fresh());
        $books->draft($event, $host)->update(['ship_name' => $host->name, 'ship_country' => 'SA', 'ship_city' => 'الرياض', 'ship_address' => 'حي الملقا، شارع الأمير', 'ship_phone' => '+966500000000']);

        $this->saveTheDate($host);

        // the host's family journey: bundle credits, a referral reward, a paid plan (loyalty)
        if (! BundleCredit::query()->where('user_id', $host->id)->exists()) {
            foreach (['signature', 'premium'] as $plan) {
                BundleCredit::query()->create(['user_id' => $host->id, 'bundle_id' => 'wedding-baby', 'plan_id' => $plan, 'expires_at' => now()->addYears(2)]);
            }
        }
        $friend = User::query()->firstOrCreate(['email' => 'friend@daawa.test'], ['name' => 'سارة الأحمد', 'password' => 'password', 'locale' => 'ar']);
        $referrals->codeFor($host);
        if (! Referral::query()->where('referred_user_id', $friend->id)->exists()) {
            $referral = Referral::query()->create(['referrer_user_id' => $host->id, 'referred_user_id' => $friend->id, 'code' => $host->fresh()->referral_code, 'reward_status' => Referral::REWARDED, 'rewarded_at' => now()]);
            AccountCredit::query()->create(['user_id' => $host->id, 'currency' => 'SAR', 'amount' => 1900, 'kind' => AccountCredit::REFERRAL_REWARD, 'referral_id' => $referral->id]);
        }
        $host->forceFill(['referral_footer' => true])->save();
        if (! Order::query()->where('user_id', $host->id)->fulfilled()->exists()) {
            Order::query()->create([
                'user_id' => $host->id, 'event_id' => $event->id, 'currency' => 'SAR', 'method' => 'transfer', 'status' => 'approved', 'paid_at' => now()->subMonth(),
                'items' => [['type' => 'plan', 'id' => 'signature', 'name' => 'Signature', 'quantity' => 1, 'unit_amount' => 29900, 'amount' => 29900]],
                'total_cents' => 29900,
            ]);
        }

        $this->command?->line('  after the event: /t/WardaDemo001 · save the date: /i/SaveDateDemo · /account/journey');
    }

    private function saveTheDate(User $host): void
    {
        $template = Template::query()->findOrFail('save-the-date-envelope');
        $config = $template->default_config;

        $event = Event::query()->where('owner_id', $host->id)->where('occasion', 'save_the_date')->first() ?? new Event(['owner_id' => $host->id]);
        EventSync::apply($event, $config);
        $event->forceFill(['status' => 'active', 'timezone' => 'Asia/Riyadh'])->save();
        Invitation::query()->updateOrCreate(['event_id' => $event->id], ['template_id' => $template->id, 'config' => $config, 'published_at' => now(), 'slug' => 'savethedate']);
        Entitlement::query()->firstOrCreate(['event_id' => $event->id, 'feature' => 'save_the_date']);
        Household::query()->updateOrCreate(['token' => 'SaveDateDemo'], ['event_id' => $event->id, 'display_name' => 'عائلة خالد العلي', 'seats_allowed' => 4]);
        Household::query()->firstOrCreate(['event_id' => $event->id, 'display_name' => 'عائلة الزين'], ['seats_allowed' => 3]);
    }
}
