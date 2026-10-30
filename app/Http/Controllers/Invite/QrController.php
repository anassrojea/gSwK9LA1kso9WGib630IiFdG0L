<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Services\Qr\QrCodes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QrController extends Controller
{
    use ResolvesHousehold;

    /**
     * GET /i/{token}/qr.svg: the household's QR code, which is simply its
     * personal link (plan section 9), error correction H. The code never
     * changes for a token, so it is cached for a day in the browser and a
     * week at the edge. Personal links are a paid feature, and so is their
     * code (QR check-in shows it on the ticket; printed invitations use it).
     * Never counted as an open.
     */
    public function svg(Request $request, string $token, QrCodes $qr, Entitlements $entitlements): Response
    {
        $household = $this->findHousehold($token);
        [$household] = $this->resolve($token, $household);

        $event = $household->event;
        abort_unless(
            $entitlements->hasFeature($event, Entitlements::QR_CHECKIN) || $entitlements->hasFeature($event, Entitlements::PERSONAL_LINKS),
            404,
        );

        $svg = $qr->forHousehold($household);
        $etag = '"'.substr(sha1($svg), 0, 20).'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=86400, s-maxage=604800']);
        }

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400, s-maxage=604800',
            'ETag' => $etag,
            'Content-Disposition' => 'inline; filename="daawa-qr.svg"',
        ]);
    }
}
