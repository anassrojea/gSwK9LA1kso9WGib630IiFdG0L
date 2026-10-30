<?php

use App\Http\Controllers\Marketing\DesignYoursController;
use App\Http\Controllers\Marketing\SeoController;
use App\Http\Controllers\Marketing\SiteController;
use App\Http\Controllers\Marketing\VenueDirectoryController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetSiteLocale;
use App\Http\Middleware\TrackSession;
use App\Support\SiteUrl;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| The public site (phase 6A), loaded from routes/web.php. Arabic pages have no
| prefix ("/" is HomeController in web.php), English pages live under /en.
| Both are listed in sitemap.xml with hreflang alternates.
*/

$occasions = implode('|', array_map('preg_quote', array_keys(config('marketing.occasions'))));
$legal = implode('|', SiteUrl::LEGAL);

foreach (['ar' => '', 'en' => 'en'] as $locale => $prefix) {
    $name = $locale === 'ar' ? '' : '.en';

    Route::prefix($prefix)->middleware(SetSiteLocale::class.':'.$locale)->group(function () use ($locale, $name, $occasions, $legal) {
        if ($locale === 'en') {
            Route::get('/', [SiteController::class, 'home'])->name('home.en');
        }
        Route::get('/pricing', [SiteController::class, 'pricing'])->name('site.pricing'.$name);
        Route::get('/about', [SiteController::class, 'about'])->name('site.about'.$name);
        Route::get('/occasions/{occasion}', [SiteController::class, 'occasion'])->where('occasion', $occasions)->name('site.occasion'.$name);
        Route::get('/legal/{page}', [SiteController::class, 'legal'])->where('page', $legal)->name('site.legal'.$name);
    });
}

// Plain files for crawlers: no session or cookies, so Cloudflare can cache them.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, AddQueuedCookiesToResponse::class, TrackSession::class, HandleInertiaRequests::class])
    ->group(function () {
        Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
        Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');

        // "صمّم دعوتك" at the end of every guest invitation: an anonymous count, then the landing page
        Route::get('/r/design-yours', DesignYoursController::class)->middleware('throttle:60,1')->name('design-yours');
    });

/*
| Venue directory (phase 8B): /venues and /venues/{slug} in Arabic, /en/venues in
| English, listed in sitemap.xml; /v/{code} is the venue's QR poster (an anonymous
| scan count, then the venue page). VenueDirectoryController.
*/
foreach (['ar' => '', 'en' => 'en'] as $locale => $prefix) {
    $name = $locale === 'ar' ? '' : '.en';

    Route::prefix($prefix)->middleware(SetSiteLocale::class.':'.$locale)->group(function () use ($name) {
        Route::get('/venues', [VenueDirectoryController::class, 'index'])->middleware('throttle:120,1')->name('venues.index'.$name);
        Route::get('/venues/{venue}', [VenueDirectoryController::class, 'show'])->where('venue', '[a-z0-9-]{3,80}')->middleware('throttle:120,1')->name('venues.show'.$name);
    });
}

Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, AddQueuedCookiesToResponse::class, TrackSession::class, HandleInertiaRequests::class])
    ->get('/v/{code}', [VenueDirectoryController::class, 'scan'])
    ->where('code', '[A-Za-z0-9]{8}')
    ->middleware('throttle:60,1')
    ->name('venues.scan');
