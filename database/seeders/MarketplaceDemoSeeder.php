<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Invitation;
use App\Models\Template;
use App\Models\User;
use App\Services\Marketplace\Designers;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\PayoutStatements;
use App\Services\Marketplace\TemplateReview;
use App\Services\Marketplace\TemplateStudio;
use App\Services\Marketplace\VendorListings;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\PaymentGateways;
use App\Services\Wizard\DraftCreator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Phase 8C demo: a designer with templates on sale (one sold, one in review), a pending
 * designer, vendors in Damascus and Riyadh (one featured, one calligrapher), and two host
 * events in Damascus: one that bought a marketplace template, one draft using another.
 * Accounts use the password "password": never run this in production.
 */
class MarketplaceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@daawa.test')->firstOrFail();
        $host = User::query()->where('email', 'host@daawa.test')->firstOrFail();

        $designer = $this->user('designer@daawa.test', 'نور الشامي');
        $profile = app(Designers::class)->apply($designer, [
            'display_name_ar' => 'استوديو نور',
            'display_name_en' => 'Studio Noor',
            'bio_ar' => 'مصمّمة دعوات من دمشق، أحب الزخارف الشامية والخط الديواني والألوان الهادئة.',
            'bio_en' => 'Invitation designer from Damascus: Levantine ornaments, Diwani calligraphy and calm colours.',
            'portfolio_links' => ['https://behance.net/studionoor'],
            'payout_details' => ['holder' => 'Noor Al Shami', 'bank' => 'Bank of Syria', 'iban' => 'SY00 0000 0000 0001', 'note' => null],
            'country' => 'SY',
            'accept_terms' => true,
        ]);
        app(Designers::class)->approve($profile, $admin);

        $pending = $this->user('pending.designer@daawa.test', 'Sami Haddad');
        app(Designers::class)->apply($pending, [
            'display_name_ar' => 'سامي حداد', 'display_name_en' => 'Sami Haddad', 'country' => 'SA', 'accept_terms' => true,
            'portfolio_links' => ['https://dribbble.com/samihaddad'],
        ]);

        $studio = app(TemplateStudio::class);
        $review = app(TemplateReview::class);

        $jasmine = $studio->saveTheme($profile, null, [
            'name_ar' => 'ياسمين الشام', 'name_en' => 'Damascus Jasmine',
            'tokens' => ['colors' => ['bg' => '#FBF7F0', 'ink' => '#2E2A24', 'accent' => '#2F5D50', 'soft' => '#7B7466', 'gold' => '#B8955A', 'surface' => '#F3EBDD', 'backdrop' => '#1F3D35'], 'radius' => 18],
            'fonts' => ['display' => 'Aref Ruqaa', 'body' => 'Amiri', 'label' => 'Almarai'],
        ]);
        $plum = $studio->saveTheme($profile, null, [
            'name_ar' => 'برقوق ليلي', 'name_en' => 'Night Plum',
            'tokens' => ['colors' => ['bg' => '#FFF7FA', 'ink' => '#2B1530', 'accent' => '#6B2D5C', 'soft' => '#8C6F86', 'gold' => '#D4A85A', 'backdrop' => '#3A1638'], 'radius' => 24],
            'fonts' => ['display' => 'Reem Kufi', 'body' => 'Noto Naskh Arabic', 'label' => 'Tajawal'],
        ]);

        $publish = function (array $data) use ($profile, $studio, $review, $admin): Template {
            $template = $studio->createTemplate($profile, $data);
            $revision = $studio->submit($profile, $template);
            $review->approve($revision, $admin);
            $studio->uploadCover($profile, $template, $this->image($template->id, $data['_colors']));

            return $template->fresh();
        };

        $sham = $publish([
            'name_ar' => 'ياسمين الشام', 'name_en' => 'Damascus Jasmine',
            'description_ar' => 'دعوة زفاف شامية بزخارف الياسمين وافتتاحية الوردة.', 'description_en' => 'A Levantine wedding invitation with jasmine ornaments and the rose opening.',
            'occasion' => 'wedding', 'opening_id' => 'rose', 'theme_id' => $jasmine->id,
            'default_config' => DraftCreator::configFor('wedding', Template::query()->find('warda-rose')),
            'prices' => ['USD' => 1200, 'SAR' => 4500, 'SYP' => 150000], 'included_in_signature' => true,
            '_colors' => ['#1F3D35', '#B8955A'],
        ]);
        $plumTemplate = $publish([
            'name_ar' => 'ليلة البرقوق', 'name_en' => 'Plum Night',
            'description_ar' => 'خطوبة أنيقة بألوان البرقوق وعلبة الخاتم.', 'description_en' => 'An elegant engagement in plum with the ring box opening.',
            'occasion' => 'engagement', 'opening_id' => 'ringbox', 'theme_id' => $plum->id,
            'default_config' => DraftCreator::configFor('engagement', Template::query()->find('khutuba-ringbox')),
            'prices' => ['USD' => 900, 'SAR' => 3400], 'included_in_signature' => false,
            '_colors' => ['#3A1638', '#D4A85A'],
        ]);

        // a henna design waiting in the admin's review queue
        $henna = $studio->createTemplate($profile, [
            'name_ar' => 'حنّاء الياسمين', 'name_en' => 'Jasmine Henna', 'occasion' => 'henna', 'opening_id' => 'doors', 'theme_id' => $jasmine->id,
            'default_config' => DraftCreator::configFor('henna', Template::query()->find('henna-doors')),
            'prices' => ['USD' => 800, 'SAR' => 3000, 'SYP' => 100000],
        ]);
        $studio->submit($profile, $henna);

        // two host events in Damascus: one bought "Damascus Jasmine" (approved transfer), one draft using "Plum Night"
        // the demo needs a transfer account even where none is configured (.env)
        if (! filled(config('payments.transfer.accounts.SYP.account_number')) && ! filled(config('payments.transfer.accounts.SYP.iban'))) {
            config(['payments.transfer.enabled' => true, 'payments.transfer.accounts.SYP' => ['account_name' => 'Daawa (demo)', 'bank' => 'Demo bank', 'account_number' => 'SY-DEMO-0001', 'iban' => null, 'swift' => null, 'note' => null]]);
            app()->forgetInstance(PaymentGateways::class);
        }
        $bought = $this->hostEvent($host, 'زفاف سلمى وكريم', 'wedding', $sham, 'active');
        $order = app(MarketplaceCheckout::class)->buyTemplate($host, $bought, $sham, ['currency' => 'SYP', 'gateway' => 'transfer', 'country' => 'SY']);
        if ($order->status === 'awaiting_payment') {
            app(OrderFulfillment::class)->approveTransfer($order, $admin, 'DEMO-MKT-'.$order->code);
        }
        $bought->invitation->update(['published_at' => now()]);
        $this->hostEvent($host, 'خطوبة دانة وفارس', 'engagement', $plumTemplate, 'draft');

        $this->vendors($admin);

        // this month's statement (the scheduler issues last month's on the 1st)
        config(['marketplace.payout_minimum.SYP' => 1000]);
        app(PayoutStatements::class)->run(CarbonImmutable::now()->startOfMonth());

        $this->command?->line('  marketplace: designer@daawa.test / password (/designer) · vendor: vendor.photo@daawa.test / password (/vendor-portal) · /templates · /vendors');
    }

    private function hostEvent(User $host, string $title, string $occasion, Template $template, string $status): Event
    {
        $event = Event::query()->updateOrCreate(['owner_id' => $host->id, 'title' => $title], [
            'occasion' => $occasion,
            'starts_at' => now()->addMonths(2)->setTime(20, 0),
            'timezone' => 'Asia/Damascus',
            'venue_name' => 'صالة الشام الكبرى',
            'address' => 'دمشق، المزة',
            'status' => $status,
            'plan_id' => null,
        ]);
        Invitation::query()->updateOrCreate(['event_id' => $event->id], [
            'template_id' => $template->id,
            'config' => $template->default_config,
            'published_at' => null,
            'slug' => 'mkt'.substr(md5($title), 0, 8),
        ]);

        return $event->fresh('invitation');
    }

    private function vendors(User $admin): void
    {
        $listings = app(VendorListings::class);
        $rows = [
            ['vendor.photo@daawa.test', ['name_ar' => 'عدسة دمشق', 'name_en' => 'Damascus Lens', 'category' => 'photographer', 'cities' => ['damascus', 'homs'], 'price_min' => 1500000, 'price_max' => 4000000, 'price_currency' => 'SYP', 'whatsapp' => '963944000111', 'instagram' => 'damascus.lens', 'description_ar' => "تصوير أعراس وحفلات خطوبة في دمشق وحمص منذ ٢٠١٥.\n\nفريق رجالي ونسائي، تسليم الصور خلال أسبوعين.", 'description_en' => 'Wedding and engagement photography in Damascus and Homs since 2015.'], ['#2F5D50', '#E9D8B4'], true],
            ['vendor.kosha@daawa.test', ['name_ar' => 'كوشة الياسمين', 'name_en' => 'Jasmine Kosha', 'category' => 'kosha', 'cities' => ['damascus'], 'occasions' => ['wedding', 'engagement', 'henna'], 'whatsapp' => '963944000222', 'description_ar' => 'تنسيق كوشات وورود طبيعية للأعراس والخطوبة.'], ['#A8325A', '#F6E3E6'], false],
            ['vendor.band@daawa.test', ['name_ar' => 'فرقة الزفة الشامية', 'name_en' => 'Levant Zaffa Band', 'category' => 'band', 'cities' => ['damascus', 'aleppo'], 'whatsapp' => '963944000333', 'instagram' => 'levant.zaffa'], ['#5A1A2C', '#C8A45D'], false],
            ['vendor.cake@daawa.test', ['name_ar' => 'حلويات الريّان', 'name_en' => 'Al Rayyan Sweets', 'category' => 'cake', 'cities' => ['riyadh', 'jeddah'], 'price_min' => 30000, 'price_max' => 250000, 'price_currency' => 'SAR', 'whatsapp' => '966500000444'], ['#7A4E2D', '#F4E6C8'], false],
            ['vendor.calligraphy@daawa.test', ['name_ar' => 'خطّاط القلم', 'name_en' => 'Al Qalam Calligraphy', 'category' => 'calligraphy', 'cities' => ['damascus', 'riyadh'], 'whatsapp' => '963944000555', 'calligraphy_prices' => ['USD' => 2500, 'SAR' => 9500, 'SYP' => 300000], 'description_ar' => 'شعارات أسماء بالخط الديواني والثلث، تُخطّ باليد وتُسلَّم بصيغة رقمية.', 'description_en' => 'Name logos in Diwani and Thuluth, hand-drawn and delivered as digital files.'], ['#13213D', '#C8A45D'], false],
        ];

        foreach ($rows as [$email, $data, $colors, $feature]) {
            $user = $this->user($email, $data['name_en']);
            $listing = $listings->save($user, $data);
            if (empty($listing->photos)) {
                $listings->addPhoto($listing, $this->image($listing->slug, $colors));
            }
            $listings->approve($listing, $admin);
            if ($feature && ! VendorListings::isFeatured($listing, 'damascus')) {
                $listings->feature($listing, 'damascus', 30, null, $admin);
            }
        }

        // one waiting for the admin
        $listings->save($this->user('vendor.makeup@daawa.test', 'Lama Makeup'), ['name_ar' => 'لمى للمكياج', 'name_en' => 'Lama Makeup', 'category' => 'makeup', 'cities' => ['latakia'], 'whatsapp' => '963944000666']);
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill(['name' => $name, 'password' => 'password', 'locale' => 'ar'])->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * A small gradient JPEG standing in for a real photo or cover (GD), so the demo pages have images.
     *
     * @param  array{0: string, 1: string}  $colors
     */
    private function image(string $name, array $colors): UploadedFile
    {
        $dir = storage_path('framework/testing');
        @mkdir($dir, 0775, true);
        $path = "{$dir}/demo-{$name}.jpg";

        if (function_exists('imagecreatetruecolor')) {
            [$w, $h] = [800, 1000];
            $img = imagecreatetruecolor($w, $h);
            $from = sscanf($colors[0], '#%02x%02x%02x');
            $to = sscanf($colors[1], '#%02x%02x%02x');
            for ($y = 0; $y < $h; $y++) {
                $t = $y / $h;
                $c = imagecolorallocate($img, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
                imageline($img, 0, $y, $w, $y, $c);
            }
            $ring = imagecolorallocatealpha($img, 255, 255, 255, 70);
            imagesetthickness($img, 6);
            imageellipse($img, (int) ($w / 2), (int) ($h * 0.42), 420, 420, $ring);
            imageellipse($img, (int) ($w / 2), (int) ($h * 0.42), 360, 360, $ring);
            imagejpeg($img, $path, 82);
            imagedestroy($img);
        }

        return new UploadedFile($path, basename($path), 'image/jpeg', null, true);
    }
}
