<?php

namespace App\Providers\Filament;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Orders\OrderResource;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The admin dashboard (plan section 4.3), for users with role = admin only
 * (User::canAccessPanel). Arabic by default and RTL, English from the user
 * menu; every label lives in lang/{ar,en}/admin.php or app.php "admin".
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Daawa · دعوة')
            ->colors([
                'primary' => Color::Rose,
            ])
            // Arabic and Latin in one family
            ->font('IBM Plex Sans Arabic', provider: GoogleFontProvider::class)
            // labels are closures: the panel is built before the admin's locale is known
            ->navigationGroups(collect(AdminNavigation::cases())
                ->mapWithKeys(fn (AdminNavigation $group) => [$group->name => NavigationGroup::make()->label(fn () => $group->getLabel())])
                ->all())
            ->navigationItems([
                // the daily queue gets its own entry (plan section 4.3: "with count badge")
                NavigationItem::make(fn () => trans('app.admin.orders.waiting'))
                    ->group(AdminNavigation::Sales)
                    ->icon(Heroicon::OutlinedClock)
                    ->sort(2)
                    ->url(fn () => OrderResource::getUrl('index', ['activeTab' => 'waiting']))
                    ->isActiveWhen(fn () => request()->routeIs(OrderResource::getRouteBaseName().'.index') && request()->query('activeTab') === 'waiting')
                    ->badge(fn () => ($count = Order::query()->where('status', 'receipt_received')->count()) > 0 ? (string) $count : null, 'warning'),
            ])
            ->userMenuItems([
                Action::make('switch-locale')
                    ->label(fn () => app()->getLocale() === 'ar' ? 'English' : 'العربية')
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(fn () => route('locale', app()->getLocale() === 'ar' ? 'en' : 'ar')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([SetAdminLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
