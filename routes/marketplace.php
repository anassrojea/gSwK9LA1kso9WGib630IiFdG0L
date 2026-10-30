<?php

use App\Http\Controllers\Marketplace\BuyController;
use App\Http\Controllers\Marketplace\DesignerPortalController;
use App\Http\Controllers\Marketplace\MarketplaceSiteController;
use App\Http\Controllers\Marketplace\RevisionPreviewController;
use App\Http\Controllers\Marketplace\VendorPortalController;
use App\Http\Middleware\SetSiteLocale;
use Illuminate\Support\Facades\Route;

/*
| Phase 8C: the template marketplace and vendor listings (docs/MARKETPLACE.md), loaded
| from routes/web.php. Public pages are Blade (SEO, ar without a prefix, en under /en);
| the designer and vendor portals and the purchase pages are Inertia behind sign-in.
*/

Route::pattern('template', '[a-z0-9-]{1,64}');
Route::pattern('revision', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

foreach (['ar' => '', 'en' => 'en'] as $locale => $prefix) {
    $name = $locale === 'ar' ? '' : '.en';

    Route::prefix($prefix)->middleware(SetSiteLocale::class.':'.$locale)->group(function () use ($name) {
        Route::get('/templates', [MarketplaceSiteController::class, 'templates'])->name('site.templates'.$name);
        Route::get('/designers/{designer:slug}', [MarketplaceSiteController::class, 'designer'])->where('designer', '[a-z0-9-]{1,64}')->name('site.designer'.$name);
        Route::get('/vendors', [MarketplaceSiteController::class, 'vendors'])->name('site.vendors'.$name);
        Route::get('/vendors/{listing:slug}', [MarketplaceSiteController::class, 'vendor'])->where('listing', '[a-z0-9-]{1,80}')->name('site.vendor'.$name);
    });
}

// a tap on a vendor's WhatsApp / Instagram: an anonymous daily count, then the vendor's own page
Route::get('/go/vendor/{listing:slug}/{channel}', [MarketplaceSiteController::class, 'contact'])
    ->where(['listing' => '[a-z0-9-]{1,80}', 'channel' => 'whatsapp|instagram'])
    ->middleware('throttle:60,1')
    ->name('vendors.contact');

Route::middleware('auth')->group(function () {
    // designer portal (plan section 2, "Designer"): apply, then themes, templates, sales and payouts
    Route::prefix('designer')->name('designer.')->group(function () {
        Route::get('/', [DesignerPortalController::class, 'index'])->name('index');
        Route::post('/apply', [DesignerPortalController::class, 'apply'])->middleware('throttle:10,1')->name('apply');

        Route::get('/themes/create', [DesignerPortalController::class, 'createTheme'])->name('themes.create');
        Route::post('/themes', [DesignerPortalController::class, 'storeTheme'])->middleware('throttle:30,1')->name('themes.store');
        Route::get('/themes/{theme}/edit', [DesignerPortalController::class, 'editTheme'])->where('theme', '[a-z0-9-]{1,64}')->name('themes.edit');
        Route::put('/themes/{theme}', [DesignerPortalController::class, 'updateTheme'])->where('theme', '[a-z0-9-]{1,64}')->middleware('throttle:60,1')->name('themes.update');

        Route::get('/templates/create', [DesignerPortalController::class, 'createTemplate'])->name('templates.create');
        Route::post('/templates', [DesignerPortalController::class, 'storeTemplate'])->middleware('throttle:30,1')->name('templates.store');
        Route::get('/templates/{template}/edit', [DesignerPortalController::class, 'editTemplate'])->name('templates.edit');
        Route::put('/templates/{template}', [DesignerPortalController::class, 'updateTemplate'])->middleware('throttle:autosave')->name('templates.update');
        Route::post('/templates/{template}/submit', [DesignerPortalController::class, 'submit'])->middleware('throttle:20,1')->name('templates.submit');
        Route::post('/templates/{template}/unpublish', [DesignerPortalController::class, 'unpublish'])->name('templates.unpublish');
        Route::post('/templates/{template}/republish', [DesignerPortalController::class, 'republish'])->name('templates.republish');
        Route::post('/templates/{template}/cover', [DesignerPortalController::class, 'cover'])->middleware('throttle:uploads')->name('templates.cover');

        // a revision rendered with the real guest view: its designer and admins only
        Route::get('/revisions/{revision}/preview', RevisionPreviewController::class)->name('revisions.preview');
    });

    // vendor portal: the vendor's own listing, featured spots and calligraphy requests
    Route::prefix('vendor-portal')->name('vendor-portal.')->group(function () {
        Route::get('/', [VendorPortalController::class, 'show'])->name('show');
        Route::put('/', [VendorPortalController::class, 'save'])->middleware('throttle:30,1')->name('save');
        Route::post('/photos', [VendorPortalController::class, 'addPhoto'])->middleware('throttle:uploads')->name('photos.store');
        Route::delete('/photos/{index}', [VendorPortalController::class, 'removePhoto'])->whereNumber('index')->name('photos.destroy');
        Route::patch('/calligraphy/{calligraphy}', [VendorPortalController::class, 'calligraphy'])->whereUuid('calligraphy')->name('calligraphy.update');
    });

    // one-item purchases through the usual checkout (gateways, transfer approval, discounts, invoices)
    Route::prefix('buy')->name('buy.')->group(function () {
        Route::get('/template/{template}', [BuyController::class, 'template'])->name('template');
        Route::post('/template/{template}', [BuyController::class, 'buyTemplate'])->middleware('throttle:20,1')->name('template.store');
        Route::get('/feature', [BuyController::class, 'feature'])->name('feature');
        Route::post('/feature', [BuyController::class, 'buyFeature'])->middleware('throttle:20,1')->name('feature.store');
        Route::get('/calligraphy/{listing:slug}', [BuyController::class, 'calligraphy'])->where('listing', '[a-z0-9-]{1,80}')->name('calligraphy');
        Route::post('/calligraphy/{listing:slug}', [BuyController::class, 'buyCalligraphy'])->where('listing', '[a-z0-9-]{1,80}')->middleware('throttle:20,1')->name('calligraphy.store');
    });
});
