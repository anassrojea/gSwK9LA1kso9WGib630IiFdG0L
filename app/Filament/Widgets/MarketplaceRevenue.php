<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Marketplace\DesignerPayoutResource;
use App\Models\DesignerLedgerEntry;
use App\Models\DesignerPayout;
use App\Models\Order;
use App\Services\Payments\Currencies;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Marketplace revenue this month per currency (phase 8C; currencies are never added up):
 * template sales net of VAT and discounts from the designer ledger, split into the
 * designers' share and the platform's, plus paid featured spots and calligraphy orders.
 */
class MarketplaceRevenue extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return count(Currencies::all()) + 1;
    }

    /**
     * @return array<string, array{net: int, designers: int, platform: int, vendors: int}>
     */
    public static function totals(CarbonImmutable $from): array
    {
        $ledger = DesignerLedgerEntry::query()
            ->whereIn('kind', ['sale', 'refund'])
            ->where('created_at', '>=', $from)
            ->groupBy('currency', 'account')
            ->selectRaw('currency, account, sum(credit_cents) - sum(debit_cents) as amount')
            ->get();

        $vendorOrders = Order::query()->fulfilled()->where('paid_at', '>=', $from)
            ->where(fn ($q) => $q->whereJsonContains('items', [['type' => 'vendor_feature']])->orWhereJsonContains('items', [['type' => 'calligraphy_request']]))
            ->groupBy('currency')
            ->selectRaw('currency, sum(total_cents - vat_cents) as amount')
            ->pluck('amount', 'currency');

        return collect(Currencies::all())->mapWithKeys(function (string $c) use ($ledger, $vendorOrders) {
            $rows = $ledger->where('currency', $c);
            $designers = (int) $rows->where('account', 'designer_payable')->sum('amount');
            $platform = (int) $rows->where('account', 'platform_share')->sum('amount');

            return [$c => ['net' => $designers + $platform, 'designers' => $designers, 'platform' => $platform, 'vendors' => (int) ($vendorOrders[$c] ?? 0)]];
        })->all();
    }

    protected function getStats(): array
    {
        $locale = app()->getLocale();
        $totals = static::totals(CarbonImmutable::now()->startOfMonth());
        $stats = [];

        foreach ($totals as $currency => $t) {
            $stats[] = Stat::make(trans('marketplace.admin.widget.title', ['currency' => $currency]), Currencies::format($t['net'], $currency, $locale))
                ->description(trans('marketplace.admin.widget.split', [
                    'designers' => Currencies::format($t['designers'], $currency, $locale),
                    'platform' => Currencies::format($t['platform'], $currency, $locale),
                    'vendors' => Currencies::format($t['vendors'], $currency, $locale),
                ]))
                ->icon(Heroicon::OutlinedSparkles)
                ->extraAttributes(['data-marketplace-revenue' => $currency]);
        }

        $pending = DesignerPayout::query()->where('status', 'pending')->count();
        $stats[] = Stat::make(trans('marketplace.admin.widget.payouts'), (string) $pending)
            ->description(trans('marketplace.admin.widget.payouts_hint'))
            ->color($pending > 0 ? 'warning' : 'gray')
            ->icon(Heroicon::OutlinedBanknotes)
            ->url(DesignerPayoutResource::getUrl('index'));

        return $stats;
    }
}
