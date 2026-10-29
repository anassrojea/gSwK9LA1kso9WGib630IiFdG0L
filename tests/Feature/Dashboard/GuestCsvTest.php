<?php

use App\Services\Dashboard\GuestCsv;
use Illuminate\Http\UploadedFile;
use Tests\Support\DashboardFixtures;

/**
 * @return list<list<string>>
 */
function csvLines(string $csv): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, substr($csv, 3));
    rewind($stream);
    $lines = [];
    while (($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $lines[] = $line;
    }

    return $lines;
}

function csvUpload(string $contents, string $name = 'guests.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

it('reads a UTF-8 file with a BOM and Arabic headers in any order', function () {
    $csv = "\xEF\xBB\xBF"."الهاتف,الاسم,الوسوم,المقاعد\r\n+962 79 123 4567,عائلة خالد,أقارب | أصدقاء,٤\r\n,أم فيصل,,\r\n";

    $parsed = GuestCsv::parse($csv);

    expect($parsed['header'])->toBeTrue()
        ->and($parsed['rows'])->toHaveCount(2)
        ->and($parsed['rows'][0])->toMatchArray(['line' => 2, 'name' => 'عائلة خالد', 'seats' => 4, 'phone' => '+962791234567', 'tags' => ['أقارب', 'أصدقاء'], 'errors' => []])
        ->and($parsed['rows'][1])->toMatchArray(['name' => 'أم فيصل', 'seats' => 1, 'phone' => null, 'tags' => []]);
});

it('reads files without a BOM or header, with semicolons', function () {
    $parsed = GuestCsv::parse("Khaled Ali;2;;friends\nSara;3;;\n");

    expect($parsed['header'])->toBeFalse()
        ->and(array_column($parsed['rows'], 'name'))->toBe(['Khaled Ali', 'Sara'])
        ->and(array_column($parsed['rows'], 'seats'))->toBe([2, 3]);
});

it('reads English headers and Windows-1256 files from old Excel', function () {
    $csv = iconv('UTF-8', 'WINDOWS-1256', "Name,Seats\nعائلة الزين,2\n");

    expect(GuestCsv::parse($csv)['rows'][0])->toMatchArray(['name' => 'عائلة الزين', 'seats' => 2]);
});

it('flags bad rows one by one', function () {
    $parsed = GuestCsv::parse("name,seats,phone\n,2,\nسارة,عشرة,\nعمر,200,\nليلى,2,call me\nOK,1,\n", ['ok']);
    $rows = $parsed['rows'];

    expect($rows[0]['errors'])->toHaveCount(1)  // no name
        ->and($rows[1]['errors'])->toHaveCount(1) // seats not a number
        ->and($rows[2]['errors'])->toHaveCount(1) // too many seats
        ->and($rows[3]['errors'])->toHaveCount(1) // phone
        ->and($rows[4]['errors'])->toBe([])
        ->and($rows[4]['warnings'])->toHaveCount(1); // already on the list
});

it('previews a file with per-row errors before importing', function () {
    [$event] = DashboardFixtures::event();
    $csv = "\xEF\xBB\xBF"."الاسم,المقاعد\nعائلة خالد,2\n,3\nأم فيصل,1\n";

    $this->actingAs($event->owner)
        ->post("/dashboard/events/{$event->id}/guests/csv/preview", ['file' => csvUpload($csv)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['header' => true, 'valid' => 2, 'invalid' => 1, 'seats' => 3, 'limit' => 20, 'left' => 7, 'fits' => true])
        ->assertJsonPath('rows.1.line', 3)
        ->assertJsonCount(1, 'rows.1.errors');

    expect($event->households()->count())->toBe(5); // nothing imported yet
});

it('imports only the valid rows', function () {
    [$event] = DashboardFixtures::event();
    $csv = "\xEF\xBB\xBF"."الاسم,المقاعد,الهاتف,الوسوم\nعائلة خالد,2,0791234567,أقارب\n,3,,\nأم فيصل,1,,جيران\n";

    $this->actingAs($event->owner)
        ->post("/dashboard/events/{$event->id}/guests/csv", ['file' => csvUpload($csv)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['imported' => 2, 'skipped' => 1]);

    $khaled = $event->households()->where('display_name', 'عائلة خالد')->first();
    expect($event->households()->count())->toBe(7)
        ->and($khaled->seats_allowed)->toBe(2)
        ->and($khaled->phone)->toBe('0791234567')
        ->and($khaled->tags)->toBe(['أقارب'])
        ->and(strlen($khaled->token))->toBe(12);
});

it('respects the plan\'s guest limit', function () {
    [$event] = DashboardFixtures::event(); // 13 of 20 seats used

    $this->actingAs($event->owner)
        ->post("/dashboard/events/{$event->id}/guests/csv/preview", ['file' => csvUpload("name,seats\nA,4\nB,4\n")], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['seats' => 8, 'left' => 7, 'fits' => false]);

    $this->post("/dashboard/events/{$event->id}/guests/csv", ['file' => csvUpload("name,seats\nA,4\nB,4\n")], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect($event->households()->count())->toBe(5);
});

it('refuses an empty file', function () {
    [$event] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->post("/dashboard/events/{$event->id}/guests/csv/preview", ['file' => csvUpload("\xEF\xBB\xBF"."الاسم,المقاعد\n\n")], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('exports the guest list with replies as UTF-8 CSV with a BOM', function () {
    [$event, $h] = DashboardFixtures::event();

    $response = $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}/guests.csv?lang=ar")->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");

    $lines = csvLines($csv);
    expect($lines[0])->toBe(GuestCsv::exportHeaders('ar'))
        ->and($lines[0][0])->toBe('الاسم')
        ->and($lines)->toHaveCount(6);

    $attending = collect($lines)->first(fn ($l) => $l[0] === 'عائلة ستحضر');
    expect($attending[1])->toBe('4')
        ->and($attending[4])->toBe('سيحضر')
        ->and($attending[5])->toBe('3')
        ->and($attending[14])->toEndWith('/i/'.$h['attending']->token);

    // and the export reads back in as an import
    expect(array_column(GuestCsv::parse($csv)['rows'], 'name'))->toContain('عائلة ستحضر');
});

it('filters the export by status and tag', function () {
    [$event] = DashboardFixtures::event();
    $this->actingAs($event->owner);

    $names = fn (string $query) => collect(csvLines($this->get("/dashboard/events/{$event->id}/guests.csv?{$query}")->streamedContent()))
        ->skip(1)->pluck(0)->sort()->values()->all();

    expect($names('status=attending'))->toBe(['Omar Family', 'عائلة ستحضر'])
        ->and($names('status=not_opened'))->toBe(['عائلة لم تفتح'])
        ->and($names('status=opened'))->toBe(['عائلة فتحت'])
        ->and($names('status=declined'))->toBe(['عائلة اعتذرت'])
        ->and($names('tag=أقارب'))->toBe(['عائلة ستحضر', 'عائلة لم تفتح'])
        ->and($names('status=attending&tag=جيران'))->toBe(['عائلة ستحضر']);

    $this->get("/dashboard/events/{$event->id}/guests.csv?status=bogus")->assertSessionHasErrors('status');
});

it('keeps spreadsheet formulas out of the export', function () {
    [$event, $h] = DashboardFixtures::event();
    $h['opened']->update(['display_name' => '=HYPERLINK("http://evil")']);

    $csv = $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}/guests.csv")->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")
        ->and(collect(GuestCsv::parse($csv)['rows'])->pluck('name'))->toContain('=HYPERLINK("http://evil")');
});
