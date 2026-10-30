<?php

use App\Jobs\UpdateWalletPasses;
use App\Models\SeatAssignment;
use App\Models\WalletPass;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GuestExtras;

/**
 * @return array<string, string> the .pkpass's files
 */
function unzipPass(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'pk');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);

    return $files;
}

/**
 * Wallet's signature is a bare detached PKCS #7 (DER); openssl_pkcs7_verify reads S/MIME,
 * so the test wraps it back into a signed message around the manifest.
 */
function verifyPassSignature(string $manifest, string $der, string $caFile): bool
{
    $dir = sys_get_temp_dir().'/daawa-verify-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $boundary = 'b'.bin2hex(random_bytes(6));
    file_put_contents("{$dir}/msg", "MIME-Version: 1.0\r\nContent-Type: multipart/signed; protocol=\"application/x-pkcs7-signature\"; micalg=\"sha-256\"; boundary=\"{$boundary}\"\r\n\r\n"
        ."--{$boundary}\r\n{$manifest}\r\n--{$boundary}\r\nContent-Type: application/x-pkcs7-signature; name=\"smime.p7s\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"smime.p7s\"\r\n\r\n"
        .chunk_split(base64_encode($der), 64, "\r\n")."\r\n--{$boundary}--\r\n");

    $ok = openssl_pkcs7_verify("{$dir}/msg", PKCS7_BINARY, "{$dir}/signer.pem", [$caFile], null, "{$dir}/content");
    $content = @file_get_contents("{$dir}/content");
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);

    return $ok === true && $content === $manifest;
}

it('builds a signed Apple Wallet event ticket', function () {
    $certs = GuestExtras::appleCertificates();
    [$event, $household] = GuestExtras::event();
    GuestExtras::attending($household, 3);

    $response = $this->get("/i/{$household->token}/wallet/apple")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.apple.pkpass');

    $files = unzipPass($response->getContent());
    expect(array_keys($files))->toContain('pass.json', 'manifest.json', 'signature', 'icon.png', 'icon@2x.png', 'strip.png', 'strip@2x.png');

    // the manifest lists the SHA-1 of every other file
    $manifest = json_decode($files['manifest.json'], true);
    expect(array_keys($manifest))->toEqualCanonicalizing(array_values(array_diff(array_keys($files), ['manifest.json', 'signature'])));
    foreach ($manifest as $name => $sha1) {
        expect(sha1($files[$name]))->toBe($sha1);
    }

    expect(verifyPassSignature($files['manifest.json'], $files['signature'], $certs['wwdr']))->toBeTrue()
        // a changed manifest no longer matches the signature
        ->and(verifyPassSignature(str_replace('pass.json', 'pass.jsoN', $files['manifest.json']), $files['signature'], $certs['wwdr']))->toBeFalse();

    $pass = json_decode($files['pass.json'], true);
    $record = WalletPass::query()->sole();
    expect($pass['passTypeIdentifier'])->toBe('pass.test.daawa')
        ->and($pass['teamIdentifier'])->toBe('TEAMID1234')
        ->and($pass['serialNumber'])->toBe($record->serial)
        ->and($pass['authenticationToken'])->toBe($record->authentication_token)
        ->and(strlen($record->authentication_token))->toBeGreaterThanOrEqual(16)
        ->and($pass['eventTicket']['primaryFields'][0]['value'])->toBe('عائلة خالد العلي')
        ->and($pass['eventTicket']['primaryFields'][0]['textAlignment'])->toBe('PKTextAlignmentRight')
        ->and(collect($pass['eventTicket']['auxiliaryFields'])->firstWhere('key', 'hall')['value'])->toBe('قاعة الورود الملكية')
        ->and(collect($pass['eventTicket']['auxiliaryFields'])->firstWhere('key', 'seats')['value'])->toContain('٣')
        ->and($pass['barcodes'][0]['format'])->toBe('PKBarcodeFormatQR')
        ->and($pass['barcodes'][0]['message'])->toEndWith("/i/{$household->token}")
        ->and($pass['relevantDate'])->toBe('2026-12-17T20:30:00+03:00')
        ->and($pass['locations'][0])->toMatchArray(['latitude' => 31.95, 'longitude' => 35.91])
        ->and($pass['backgroundColor'])->toBe('rgb(168, 50, 90)');

    // the same household keeps its serial
    $this->get("/i/{$household->token}/wallet/apple")->assertOk();
    expect(WalletPass::query()->count())->toBe(1);
});

it('shows the table on the pass when the family is seated', function () {
    GuestExtras::appleCertificates();
    [$event, $household] = GuestExtras::event();
    GuestExtras::attending($household);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $household->id, 'table_id' => 'T7', 'seat_index' => 0]);

    $pass = json_decode(unzipPass($this->get("/i/{$household->token}/wallet/apple")->getContent())['pass.json'], true);

    expect(collect($pass['eventTicket']['auxiliaryFields'])->firstWhere('key', 'table')['value'])->toBe('٧');
});

it('signs a Save to Google Wallet JWT with the class and object inline', function () {
    $account = GuestExtras::googleServiceAccount();
    [$event, $household] = GuestExtras::event();
    GuestExtras::attending($household, 2);

    $location = $this->get("/i/{$household->token}/wallet/google")->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://pay.google.com/gp/v/save/');

    $claims = JWT::decode(substr($location, strlen('https://pay.google.com/gp/v/save/')), new Key($account['public'], 'RS256'));
    $class = $claims->payload->eventTicketClasses[0];
    $object = $claims->payload->eventTicketObjects[0];
    $record = WalletPass::query()->where('platform', 'google')->sole();

    expect($claims->iss)->toBe('wallet@daawa-test.iam.gserviceaccount.com')
        ->and($claims->aud)->toBe('google')
        ->and($claims->typ)->toBe('savetowallet')
        ->and($class->id)->toBe("3388000000012345678.daawa-event-{$event->id}")
        ->and($class->eventName->defaultValue->value)->toContain('ريم')
        ->and($class->venue->name->defaultValue->value)->toBe('قاعة الورود الملكية')
        ->and($class->dateTime->start)->toBe('2026-12-17T20:30:00+03:00')
        ->and($class->hexBackgroundColor)->toBe('#A8325A')
        ->and($object->classId)->toBe($class->id)
        ->and($object->id)->toBe("3388000000012345678.{$record->serial}")
        ->and($object->ticketHolderName)->toBe('عائلة خالد العلي')
        ->and($object->barcode->type)->toBe('QR_CODE')
        ->and($object->barcode->value)->toEndWith("/i/{$household->token}");

    // a JWT signed by another key does not verify
    $other = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048]))['key'];
    expect(fn () => JWT::decode(substr($location, 34), new Key($other, 'RS256')))->toThrow(Exception::class);
});

it('serves passes only for attending families on events with the wallet feature and a configured platform', function () {
    GuestExtras::appleCertificates();
    [, $household] = GuestExtras::event();

    // no reply yet
    $this->get("/i/{$household->token}/wallet/apple")->assertNotFound();
    GuestExtras::attending($household);
    $this->get("/i/{$household->token}/wallet/apple")->assertOk();
    // Google not configured
    $this->get("/i/{$household->token}/wallet/google")->assertNotFound();

    [$premium, $other] = GuestExtras::event('premium');
    GuestExtras::attending($other);
    $this->get("/i/{$other->token}/wallet/apple")->assertNotFound();
    $this->get("/i/{$other->token}/pass")->assertNotFound();

    // the wallet add-on on Premium
    GuestExtras::grant($premium, 'wallet');
    $this->get("/i/{$other->token}/wallet/apple")->assertOk();
});

it('shows the wallet buttons on the ticket only when configured', function () {
    [$event, $household] = GuestExtras::event();
    config()->set('wallet.apple.cert_path', null);
    config()->set('wallet.google.issuer_id', null);

    $this->get("/i/{$household->token}")->assertOk()
        ->assertSee("/i/{$household->token}/pass", false)
        ->assertDontSee('/wallet/apple', false)
        ->assertDontSee('/wallet/google', false);

    GuestExtras::appleCertificates();
    GuestExtras::googleServiceAccount();
    $this->get("/i/{$household->token}")->assertOk()
        ->assertSee("/i/{$household->token}/wallet/apple", false)
        ->assertSee("/i/{$household->token}/wallet/google", false)
        ->assertSee(__('invite.wallet.apple'));

    [, $premium] = GuestExtras::event('premium');
    $this->get("/i/{$premium->token}")->assertOk()->assertDontSee('data-wallet', false);
});

it('shows the web pass with a QR and add to home screen', function () {
    [, $household] = GuestExtras::event();

    $this->get("/i/{$household->token}/pass")->assertRedirect("/i/{$household->token}#rsvp");

    GuestExtras::attending($household, 2);
    $this->get("/i/{$household->token}/pass")
        ->assertOk()
        ->assertSee('عائلة خالد العلي')
        ->assertSee('قاعة الورود الملكية')
        ->assertSee('<svg', false)
        ->assertSee('rel="manifest"', false)
        ->assertSee(__('wallet.web.add_home'));

    $this->getJson("/i/{$household->token}/pass.webmanifest")->assertOk()->assertJsonPath('display', 'standalone');
});

it('queues a pass update when the time or the table changes', function () {
    Queue::fake();
    [$event, $household] = GuestExtras::event();

    $event->update(['starts_at' => now()->addDay()]);
    Queue::assertPushed(UpdateWalletPasses::class, fn ($job) => $job->eventId === $event->id && $job->reason === 'event');

    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $household->id, 'table_id' => 'T2', 'seat_index' => 0]);
    Queue::assertPushed(UpdateWalletPasses::class, fn ($job) => $job->householdId === $household->id && $job->reason === 'table');
});
