<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * OAuth Callback Controller
 * 
 * OAuth support has been removed. This controller is kept for backward compatibility.
 * 
 * @package App\Http\Controllers\Api
 */
class OAuthController extends BaseApiController
{
    // OAuth functionality has been removed
    // Email access now uses IMAP/POP3 with username/password authentication
}

