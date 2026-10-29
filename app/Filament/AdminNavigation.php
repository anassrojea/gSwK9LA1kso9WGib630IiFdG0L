<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * The admin's navigation groups, in this order (labels in lang/{ar,en}/admin.php).
 */
enum AdminNavigation implements HasLabel
{
    case Sales;
    case Customers;
    case Catalog;
    case System;

    public function getLabel(): string
    {
        return trans('admin.nav.'.strtolower($this->name));
    }
}
