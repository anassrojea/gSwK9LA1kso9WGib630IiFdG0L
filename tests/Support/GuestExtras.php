<?php

namespace Tests\Support;

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Rsvp;
use App\Services\OgImage\Chromium;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Fixtures for the phase 7B tests: an event with a published invitation and
 * one household, on a plan, with the album / wall / wallet features, plus test
 * keys and certificates generated with openssl (never Apple's or Google's).
 */
class GuestExtras
{
    /**
     * @return array{0: Event, 1: Household}
     */
    public static function event(string $plan = 'signature', array $attributes = [], array $album = []): array
    {
        if (! Plan::query()->exists()) {
            app(PlanSeeder::class)->run();
        }
        Storage::fake(config('filesystems.media_disk'));
        // no real browser in tests: pass strips use the GD fallback
        app()->instance(Chromium::class, new class extends Chromium
        {
            public function available(): bool
            {
                return false;
            }
        });

        $event = Event::factory()->create($attributes + ['plan_id' => $plan, 'starts_at' => now()->subHour(), 'album' => $album ?: null]);
        Invitation::factory()->for($event)->create();
        $household = Household::factory()->for($event)->seats(3)->create(['display_name' => 'عائلة خالد العلي']);

        return [$event->fresh(), $household];
    }

    public static function grant(Event $event, string $feature): void
    {
        Entitlement::query()->create(['event_id' => $event->getKey(), 'feature' => $feature]);
    }

    public static function attending(Household $household, int $people = 2): Rsvp
    {
        return Rsvp::factory()->create(['household_id' => $household->getKey(), 'attending' => true, 'people_count' => $people]);
    }

    /**
     * A throwaway CA standing in for Apple's WWDR, and a "Pass Type ID" certificate it signed.
     *
     * @return array{dir: string, cert: string, wwdr: string, password: string}
     */
    public static function appleCertificates(): array
    {
        $dir = sys_get_temp_dir().'/daawa-test-pass-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $caKey = openssl_pkey_new($config);
        $caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test WWDR CA'], $caKey, $config), null, $caKey, 30, $config);
        openssl_x509_export($caCert, $caPem);

        $key = openssl_pkey_new($config);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Pass Type ID: pass.test.daawa', 'organizationalUnitName' => 'TEAMID1234'], $key, $config), $caCert, $caKey, 30, $config);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, 'secret-pass');

        file_put_contents("{$dir}/wwdr.pem", $caPem);
        file_put_contents("{$dir}/pass.pem", $certPem.$keyPem);

        config()->set('wallet.apple', [
            'cert_path' => "{$dir}/pass.pem",
            'cert_password' => 'secret-pass',
            'wwdr_path' => "{$dir}/wwdr.pem",
            'pass_type_id' => 'pass.test.daawa',
            'team_id' => 'TEAMID1234',
            'organization' => 'Daawa',
        ]);

        return ['dir' => $dir, 'cert' => "{$dir}/pass.pem", 'wwdr' => "{$dir}/wwdr.pem", 'password' => 'secret-pass'];
    }

    /**
     * A service account key file like Google's, with a fresh RSA key pair.
     *
     * @return array{path: string, public: string}
     */
    public static function googleServiceAccount(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $public = openssl_pkey_get_details($key)['key'];

        $path = sys_get_temp_dir().'/daawa-test-sa-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'client_email' => 'wallet@daawa-test.iam.gserviceaccount.com',
            'private_key' => $privatePem,
        ]));

        config()->set('wallet.google', ['issuer_id' => '3388000000012345678', 'service_account_json' => $path, 'issuer_name' => 'Daawa']);

        return ['path' => $path, 'public' => $public];
    }
}
