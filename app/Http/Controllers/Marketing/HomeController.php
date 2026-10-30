<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * GET /: the landing page (SiteController::home) in the visitor's language:
     * Arabic for crawlers and new visitors, English after ?lang=en or the
     * language switch. Its canonical URL is / or /en accordingly.
     */
    public function __invoke(SiteController $site): View
    {
        return $site->home();
    }
}
