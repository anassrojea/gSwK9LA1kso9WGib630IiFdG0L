<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Database\Seeders\DemoSeeder;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Placeholder landing page (Blade, SEO-friendly). Lists the demo
     * invitations outside production.
     */
    public function __invoke(): View
    {
        return view('marketing.home', [
            'demos' => app()->isProduction() ? [] : DemoSeeder::TOKENS,
        ]);
    }
}
