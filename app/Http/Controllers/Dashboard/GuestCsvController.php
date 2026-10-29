<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksGuestLimit;
use App\Models\Event;
use App\Models\Household;
use App\Services\Dashboard\GuestCsv;
use App\Services\Digits;
use App\Services\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Add guests" from a CSV file: preview first (every row with its errors),
 * then import the rows that are fine. The file is sent again for the import,
 * so nothing is kept on the server in between.
 */
class GuestCsvController extends Controller
{
    use ChecksGuestLimit;

    public const MAX_KB = 1024;

    public function __construct(private Entitlements $entitlements) {}

    /**
     * POST /dashboard/events/{event}/guests/csv/preview {file}
     */
    public function preview(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('create', [Household::class, $event]);

        $parsed = $this->parse($request, $event);
        $valid = array_values(array_filter($parsed['rows'], fn ($row) => $row['errors'] === []));
        $seats = array_sum(array_column($valid, 'seats'));
        $limit = $this->entitlements->limit($event, 'guests');
        $used = (int) $event->households()->sum('seats_allowed');

        return response()->json([
            'rows' => $parsed['rows'],
            'header' => $parsed['header'],
            'too_many' => $parsed['too_many'],
            'valid' => count($valid),
            'invalid' => count($parsed['rows']) - count($valid),
            'seats' => $seats,
            'limit' => $limit,
            'left' => $limit === null ? null : max(0, $limit - $used),
            'fits' => $this->entitlements->canAddGuests($event, $seats),
        ]);
    }

    /**
     * POST /dashboard/events/{event}/guests/csv {file}: imports the valid rows.
     */
    public function import(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('create', [Household::class, $event]);

        $parsed = $this->parse($request, $event);
        $valid = array_values(array_filter($parsed['rows'], fn ($row) => $row['errors'] === []));

        if ($valid === []) {
            throw ValidationException::withMessages(['file' => trans('app.dashboard.csv.nothing_valid')]);
        }

        $this->ensureRoom($this->entitlements, $event, array_sum(array_column($valid, 'seats')), 'file');

        DB::transaction(function () use ($event, $valid) {
            foreach ($valid as $row) {
                $event->households()->create([
                    'display_name' => $row['name'],
                    'seats_allowed' => $row['seats'],
                    'phone' => $row['phone'],
                    'tags' => $row['tags'],
                ]);
            }
        });

        $count = count($valid);
        $message = trans_choice('app.wizard.guests.imported', $count, ['count' => Digits::localize($count)]);
        $request->session()->flash('status', $message);

        return response()->json(['imported' => $count, 'skipped' => count($parsed['rows']) - $count, 'message' => $message]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, header: bool, too_many: bool}
     */
    private function parse(Request $request, Event $event): array
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/x-csv,text/comma-separated-values,application/octet-stream'],
        ], [
            'file.mimetypes' => trans('app.dashboard.csv.not_csv'),
        ]);

        $contents = (string) file_get_contents($request->file('file')->getRealPath());
        $parsed = GuestCsv::parse($contents, $event->households()->pluck('display_name')->all());

        if ($parsed['rows'] === []) {
            throw ValidationException::withMessages(['file' => trans('app.dashboard.csv.empty')]);
        }
        if ($parsed['too_many']) {
            throw ValidationException::withMessages(['file' => trans('app.wizard.guests.too_many_lines', ['max' => Digits::localize(GuestCsv::MAX_ROWS)])]);
        }

        return $parsed;
    }
}
