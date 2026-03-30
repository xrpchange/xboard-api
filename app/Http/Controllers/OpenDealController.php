<?php

namespace App\Http\Controllers;

use App\Support\DealSellerOpenLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public landing page for seller deal links from email (HTTPS is clickable; xboard:// is often stripped by mail clients).
 */
class OpenDealController extends Controller
{
    public function show(Request $request): View|Response
    {
        $lotId = $request->query('lot_id');
        $nodeKey = $request->query('node_key');
        if (!is_string($lotId) || $lotId === '' || !is_string($nodeKey) || $nodeKey === '') {
            return response('Invalid link', 400);
        }
        if (strlen($lotId) > 32 || strlen($nodeKey) > 255) {
            return response('Invalid link', 400);
        }
        if (!preg_match('/^\d+$/', $lotId)) {
            return response('Invalid link', 400);
        }
        $nodeKeyNorm = strtolower(trim($nodeKey));
        $nodeKeyNorm = (string) preg_replace('/^api\./i', '', $nodeKeyNorm);
        if ($nodeKeyNorm === '') {
            return response('Invalid link', 400);
        }
        $query = http_build_query(
            [
                'lot_id' => $lotId,
                'node_key' => $nodeKeyNorm,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $base = rtrim(DealSellerOpenLinks::APP_DEEP_LINK_BASE, '?&');
        $sep = str_contains($base, '?') ? '&' : '?';
        $appDeepLink = $base . $sep . $query;

        return view('deal.open', [
            'appDeepLink' => $appDeepLink,
            'webUrl' => $request->fullUrl(),
        ]);
    }
}
