<?php

namespace App\Http\Controllers;

use App\Support\DealSellerOpenLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public landing for /open-deal from email. Android mail WebViews: 302 to xboard:// (no JS / no browser_fallback).
 */
class OpenDealController extends Controller
{
    public function show(Request $request): View|RedirectResponse|Response
    {
        $lotId = $request->query('lot_id');
        $nodeKey = $request->query('node_key');
        if (! is_string($lotId) || $lotId === '' || ! is_string($nodeKey) || $nodeKey === '') {
            return response('Invalid link', 400);
        }
        if (strlen($lotId) > 32 || strlen($nodeKey) > 255) {
            return response('Invalid link', 400);
        }
        if (! preg_match('/^\d+$/', $lotId)) {
            return response('Invalid link', 400);
        }
        $nodeKeyNorm = strtolower(trim($nodeKey));
        $nodeKeyNorm = (string) preg_replace('/^api\./i', '', $nodeKeyNorm);
        if ($nodeKeyNorm === '') {
            return response('Invalid link', 400);
        }
        $params = [
            'lot_id' => $lotId,
            'node_key' => $nodeKeyNorm,
        ];
        $appDeepLink = DealSellerOpenLinks::appDeepLink($params);
        $httpsLanding = $request->fullUrl();
        $appIntentUrl = DealSellerOpenLinks::androidIntentUri($params);

        // Gmail and other mail WebViews ignore xboard:// in email and often ignore 302 to custom schemes.
        // Show a small landing with intent:// (works from WebView) instead of redirecting to xboard://.
        return view('deal.open', [
            'appDeepLink' => $appDeepLink,
            'appIntentUrl' => $appIntentUrl,
            'webUrl' => $httpsLanding,
        ]);
    }
}
