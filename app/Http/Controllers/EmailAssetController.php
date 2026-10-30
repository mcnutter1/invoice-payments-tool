<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\PrivateDocument;
use App\Services\Notifications\EmailTracker;
use Illuminate\Http\Request;

/** Unauthenticated assets referenced from outgoing emails. */
class EmailAssetController extends Controller
{
    /** Open-tracking pixel. Always returns the same image so it reveals nothing about token validity. */
    public function pixel(Request $request, string $token, EmailTracker $tracker)
    {
        $tracker->recordOpen($token, $request);

        return response(base64_decode(EmailTracker::PIXEL_GIF), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /** Business logo for email headers (logos are already shown on every client document). */
    public function logo(string $ulid)
    {
        $business = Business::where('ulid', strtolower($ulid))->first();
        $doc = $business?->logo_document_id
            ? PrivateDocument::where('business_id', $business->id)->find($business->logo_document_id)
            : null;
        abort_unless($doc && in_array($doc->mime, ['image/png', 'image/jpeg'], true) && is_file($doc->absolutePath()), 404);

        return response()->file($doc->absolutePath(), [
            'Content-Type' => $doc->mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
