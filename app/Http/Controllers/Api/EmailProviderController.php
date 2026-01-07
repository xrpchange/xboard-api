<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EmailProviderController extends BaseApiController
{
    /**
     * Get list of active email providers
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $providers = DB::table('email_providers')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function ($provider) {
                // Convert to array and ensure boolean fields are proper booleans (not 1/0)
                return [
                    'id' => (int) $provider->id,
                    'name' => $provider->name,
                    'imap_host' => $provider->imap_host,
                    'imap_port' => (int) $provider->imap_port,
                    'pop3_host' => $provider->pop3_host,
                    'pop3_port' => (int) $provider->pop3_port,
                    'use_ssl' => (bool) $provider->use_ssl,
                    'sort_order' => (int) $provider->sort_order,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $providers
        ]);
    }
}
