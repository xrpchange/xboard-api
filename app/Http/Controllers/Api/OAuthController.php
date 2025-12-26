<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * OAuth Callback Controller
 * 
 * Handles OAuth callbacks from providers (Yandex, Gmail, Outlook) for web applications.
 * 
 * This endpoint receives authorization codes from OAuth providers after user authorization
 * and redirects users back to the web application with the code.
 * 
 * Note: Android uses SDK authentication with deep links (xboard://yandex-oauth),
 * so Android apps don't use this endpoint directly.
 * 
 * @package App\Http\Controllers\Api
 */
class OAuthController extends BaseApiController
{
    /**
     * Handle Yandex OAuth callback for web applications
     * 
     * Yandex redirects here after user authorization with:
     * - code: authorization code (on success)
     * - error: error code (on failure)
     * - error_description: error description (on failure)
     * - state: optional state parameter for CSRF protection
     * 
     * This endpoint is registered as redirect URI in Yandex OAuth:
     * https://api.xdexboard.com/api/oauth/yandex/callback
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|JsonResponse
     */
    public function yandexCallback(Request $request)
    {
        $code = $request->query('code');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');
        $state = $request->query('state'); // Optional state parameter
        
        Log::info('Yandex OAuth callback received', [
            'has_code' => !empty($code),
            'has_error' => !empty($error),
            'error' => $error,
            'state' => $state
        ]);
        
        // Build redirect URL back to application
        // For web: redirect to web app URL
        // For mobile: redirect to deep link (xboard://yandex-oauth)
        $redirectUrl = $this->buildRedirectUrl($code, $error, $errorDescription);
        
        // Check if this is a direct API call (for testing) or OAuth callback from provider
        // If request has Accept: application/json header or ?format=json parameter, return JSON
        $returnJson = $request->wantsJson() 
            || $request->expectsJson() 
            || $request->query('format') === 'json'
            || $request->header('Accept') === 'application/json';
        
        if ($returnJson) {
            // Return JSON response for API calls or testing
            if ($code) {
                return response()->json([
                    'success' => true,
                    'code' => $code,
                    'state' => $state,
                    'redirect_url' => $redirectUrl,
                    'message' => 'Authorization code received. Use this code to exchange for access token.'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $error,
                    'error_description' => $errorDescription,
                    'message' => 'OAuth authorization failed'
                ], 400);
            }
        }
        
        // For browser requests from OAuth provider, redirect to application
        // This is the normal OAuth flow: provider redirects user here, we redirect back to app
        return redirect($redirectUrl);
    }
    
    /**
     * Build redirect URL based on platform
     * 
     * @param string|null $code
     * @param string|null $error
     * @param string|null $errorDescription
     * @return string
     */
    private function buildRedirectUrl(?string $code, ?string $error, ?string $errorDescription): string
    {
        // Get web app URL from config or use default
        $webAppUrl = config('app.web_app_url', env('APP_WEB_URL', 'https://xdexboard.com'));
        
        // For mobile apps, we can check User-Agent or use state parameter
        // For now, redirect to web app which will handle both web and mobile deep links
        
        if ($code) {
            // Success: redirect with code
            // Web app will handle redirecting to mobile deep link if needed
            return $webAppUrl . '/oauth/callback?provider=yandex&code=' . urlencode($code);
        } else {
            // Error: redirect with error
            $params = http_build_query([
                'provider' => 'yandex',
                'error' => $error,
                'error_description' => $errorDescription
            ]);
            return $webAppUrl . '/oauth/callback?' . $params;
        }
    }
    
    /**
     * Exchange Yandex OAuth authorization code for access token (server-side)
     * 
     * This endpoint is used by mobile apps to exchange authorization code for access token.
     * The client_secret is stored on the server and never exposed to mobile apps.
     * 
     * Request:
     * - code: Authorization code received from Yandex OAuth
     * - redirect_uri: Redirect URI used in authorization request (must match)
     * 
     * Response:
     * - access_token: Access token for Yandex API
     * - refresh_token: Refresh token (if provided by Yandex)
     * - expires_in: Token expiration time in seconds
     * - token_type: Token type (usually "bearer")
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function yandexExchangeToken(Request $request): JsonResponse
    {
        $code = $request->input('code');
        $redirectUri = $request->input('redirect_uri', 'xboard://yandex-oauth'); // Default for mobile
        
        if (empty($code)) {
            return response()->json([
                'success' => false,
                'error' => 'missing_code',
                'error_description' => 'Authorization code is required'
            ], 400);
        }
        
        $clientId = config('services.yandex_oauth.client_id');
        $clientSecret = config('services.yandex_oauth.client_secret');
        
        if (empty($clientId) || empty($clientSecret)) {
            Log::error('Yandex OAuth credentials not configured', [
                'has_client_id' => !empty($clientId),
                'has_client_secret' => !empty($clientSecret)
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'server_configuration_error',
                'error_description' => 'OAuth server configuration is missing'
            ], 500);
        }
        
        // Exchange code for token via Yandex OAuth API
        try {
            $httpClient = new \GuzzleHttp\Client();
            $response = $httpClient->post('https://oauth.yandex.ru/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);
            
            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            if (isset($tokenData['access_token'])) {
                Log::info('Yandex OAuth token exchange successful', [
                    'has_access_token' => true,
                    'has_refresh_token' => isset($tokenData['refresh_token']),
                    'expires_in' => $tokenData['expires_in'] ?? null,
                ]);
                
                return response()->json([
                    'success' => true,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_in' => $tokenData['expires_in'] ?? 0,
                    'token_type' => $tokenData['token_type'] ?? 'bearer',
                ]);
            } else {
                Log::error('Yandex OAuth token exchange failed: invalid response', [
                    'response' => $tokenData
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => $tokenData['error'] ?? 'unknown_error',
                    'error_description' => $tokenData['error_description'] ?? 'Token exchange failed'
                ], 400);
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errorBody, true);
            
            Log::error('Yandex OAuth token exchange failed', [
                'status' => $e->getResponse()->getStatusCode(),
                'error' => $errorData['error'] ?? 'unknown',
                'error_description' => $errorData['error_description'] ?? $errorBody,
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $errorData['error'] ?? 'token_exchange_failed',
                'error_description' => $errorData['error_description'] ?? 'Failed to exchange authorization code for token'
            ], $e->getResponse()->getStatusCode());
        } catch (\Exception $e) {
            Log::error('Yandex OAuth token exchange exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'server_error',
                'error_description' => 'Internal server error during token exchange'
            ], 500);
        }
    }
    
    /**
     * Handle Gmail OAuth callback for web applications
     * 
     * Google redirects here after user authorization with:
     * - code: authorization code (on success)
     * - error: error code (on failure)
     * - state: optional state parameter for CSRF protection
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|JsonResponse
     */
    public function gmailCallback(Request $request)
    {
        $code = $request->query('code');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');
        $state = $request->query('state');
        
        Log::info('Gmail OAuth callback received', [
            'has_code' => !empty($code),
            'has_error' => !empty($error),
            'error' => $error,
            'state' => $state
        ]);
        
        $redirectUrl = $this->buildGmailRedirectUrl($code, $error, $errorDescription);
        
        $returnJson = $request->wantsJson() 
            || $request->expectsJson() 
            || $request->query('format') === 'json'
            || $request->header('Accept') === 'application/json';
        
        if ($returnJson) {
            if ($code) {
                return response()->json([
                    'success' => true,
                    'code' => $code,
                    'state' => $state,
                    'redirect_url' => $redirectUrl,
                    'message' => 'Authorization code received. Use this code to exchange for access token.'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $error,
                    'error_description' => $errorDescription,
                    'message' => 'OAuth authorization failed'
                ], 400);
            }
        }
        
        return redirect($redirectUrl);
    }
    
    /**
     * Build redirect URL for Gmail OAuth
     */
    private function buildGmailRedirectUrl(?string $code, ?string $error, ?string $errorDescription): string
    {
        $webAppUrl = config('app.web_app_url', env('APP_WEB_URL', 'https://xdexboard.com'));
        
        if ($code) {
            return $webAppUrl . '/oauth/callback?provider=gmail&code=' . urlencode($code);
        } else {
            $params = http_build_query([
                'provider' => 'gmail',
                'error' => $error,
                'error_description' => $errorDescription
            ]);
            return $webAppUrl . '/oauth/callback?' . $params;
        }
    }
    
    /**
     * Exchange Gmail OAuth authorization code for access token (server-side)
     * 
     * This endpoint is used by mobile apps to exchange authorization code for access token.
     * The client_secret is stored on the server and never exposed to mobile apps.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function gmailExchangeToken(Request $request): JsonResponse
    {
        $code = $request->input('code');
        $redirectUri = $request->input('redirect_uri', 'xboard://gmail-oauth'); // Default for mobile
        
        if (empty($code)) {
            return response()->json([
                'success' => false,
                'error' => 'missing_code',
                'error_description' => 'Authorization code is required'
            ], 400);
        }
        
        $clientId = config('services.gmail_oauth.client_id');
        $clientSecret = config('services.gmail_oauth.client_secret');
        
        if (empty($clientId) || empty($clientSecret)) {
            Log::error('Gmail OAuth credentials not configured', [
                'has_client_id' => !empty($clientId),
                'has_client_secret' => !empty($clientSecret)
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'server_configuration_error',
                'error_description' => 'OAuth server configuration is missing'
            ], 500);
        }
        
        // Exchange code for token via Google OAuth API
        try {
            $httpClient = new \GuzzleHttp\Client();
            $response = $httpClient->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);
            
            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            if (isset($tokenData['access_token'])) {
                Log::info('Gmail OAuth token exchange successful', [
                    'has_access_token' => true,
                    'has_refresh_token' => isset($tokenData['refresh_token']),
                    'expires_in' => $tokenData['expires_in'] ?? null,
                ]);
                
                return response()->json([
                    'success' => true,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_in' => $tokenData['expires_in'] ?? 0,
                    'token_type' => $tokenData['token_type'] ?? 'bearer',
                ]);
            } else {
                Log::error('Gmail OAuth token exchange failed: invalid response', [
                    'response' => $tokenData
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => $tokenData['error'] ?? 'unknown_error',
                    'error_description' => $tokenData['error_description'] ?? 'Token exchange failed'
                ], 400);
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errorBody, true);
            
            Log::error('Gmail OAuth token exchange failed', [
                'status' => $e->getResponse()->getStatusCode(),
                'error' => $errorData['error'] ?? 'unknown',
                'error_description' => $errorData['error_description'] ?? $errorBody,
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $errorData['error'] ?? 'token_exchange_failed',
                'error_description' => $errorData['error_description'] ?? 'Failed to exchange authorization code for token'
            ], $e->getResponse()->getStatusCode());
        } catch (\Exception $e) {
            Log::error('Gmail OAuth token exchange exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'server_error',
                'error_description' => 'Internal server error during token exchange'
            ], 500);
        }
    }
    
    /**
     * Handle Mail.ru OAuth callback for web applications
     * 
     * Mail.ru redirects here after user authorization with:
     * - code: authorization code (on success)
     * - error: error code (on failure)
     * - state: optional state parameter for CSRF protection
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|JsonResponse
     */
    public function mailruCallback(Request $request)
    {
        $code = $request->query('code');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');
        $state = $request->query('state');
        
        Log::info('Mail.ru OAuth callback received', [
            'has_code' => !empty($code),
            'has_error' => !empty($error),
            'error' => $error,
            'state' => $state
        ]);
        
        $redirectUrl = $this->buildMailruRedirectUrl($code, $error, $errorDescription);
        
        $returnJson = $request->wantsJson() 
            || $request->expectsJson() 
            || $request->query('format') === 'json'
            || $request->header('Accept') === 'application/json';
        
        if ($returnJson) {
            if ($code) {
                return response()->json([
                    'success' => true,
                    'code' => $code,
                    'state' => $state,
                    'redirect_url' => $redirectUrl,
                    'message' => 'Authorization code received. Use this code to exchange for access token.'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $error,
                    'error_description' => $errorDescription,
                    'message' => 'OAuth authorization failed'
                ], 400);
            }
        }
        
        return redirect($redirectUrl);
    }
    
    /**
     * Build redirect URL for Mail.ru OAuth
     * 
     * IMPORTANT: Since Mail.ru doesn't accept custom scheme in redirect_uri,
     * we use web URL for authorization, then redirect to deep link for mobile apps.
     * 
     * For mobile apps: redirect to deep link (xboard://mailru-oauth)
     * For web apps: redirect to web app URL
     */
    private function buildMailruRedirectUrl(?string $code, ?string $error, ?string $errorDescription): string
    {
        // Always redirect to deep link for mobile apps
        // Mail.ru redirects to web URL (https://api.xdexboard.com/api/oauth/mailru/callback),
        // and we redirect from there to deep link
        if ($code) {
            return 'xboard://mailru-oauth?code=' . urlencode($code);
        } else {
            $params = http_build_query([
                'error' => $error,
                'error_description' => $errorDescription
            ]);
            return 'xboard://mailru-oauth?' . $params;
        }
    }
    
    /**
     * Exchange Mail.ru OAuth authorization code for access token (server-side)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function mailruExchangeToken(Request $request): JsonResponse
    {
        $code = $request->input('code');
        // IMPORTANT: redirect_uri must exactly match the one used in authorization request
        // Since we use web URL (https://api.xdexboard.com/api/oauth/mailru/callback) for authorization,
        // we must use the same web URL here, not the deep link
        $redirectUri = $request->input('redirect_uri', 'https://api.xdexboard.com/api/oauth/mailru/callback');
        
        Log::info('Mail.ru OAuth token exchange request', [
            'has_code' => !empty($code),
            'redirect_uri' => $redirectUri,
            'code_preview' => $code ? substr($code, 0, 20) . '...' : null,
        ]);
        
        if (empty($code)) {
            return response()->json([
                'success' => false,
                'error' => 'missing_code',
                'error_description' => 'Authorization code is required'
            ], 400);
        }
        
        $clientId = config('services.mailru_oauth.client_id');
        $clientSecret = config('services.mailru_oauth.client_secret');
        
        if (empty($clientId) || empty($clientSecret)) {
            Log::error('Mail.ru OAuth credentials not configured', [
                'has_client_id' => !empty($clientId),
                'has_client_secret' => !empty($clientSecret)
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'server_configuration_error',
                'error_description' => 'OAuth server configuration is missing'
            ], 500);
        }
        
        // Exchange code for token via Mail.ru OAuth API
        // IMPORTANT: redirect_uri must exactly match the one used in authorization request
        try {
            $httpClient = new \GuzzleHttp\Client();
            
            $tokenRequestParams = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
            ];
            
            Log::info('Mail.ru OAuth token exchange request params', [
                'grant_type' => $tokenRequestParams['grant_type'],
                'has_code' => !empty($tokenRequestParams['code']),
                'has_client_id' => !empty($tokenRequestParams['client_id']),
                'has_client_secret' => !empty($tokenRequestParams['client_secret']),
                'redirect_uri' => $tokenRequestParams['redirect_uri'],
            ]);
            
            $response = $httpClient->post('https://connect.mail.ru/oauth/token', [
                'form_params' => $tokenRequestParams,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $tokenData = json_decode($responseBody, true);
            
            Log::info('Mail.ru OAuth token exchange response', [
                'status' => $response->getStatusCode(),
                'response_body' => $responseBody,
                'has_access_token' => isset($tokenData['access_token']),
                'error' => $tokenData['error'] ?? null,
                'error_description' => $tokenData['error_description'] ?? null,
            ]);
            
            if (isset($tokenData['access_token'])) {
                Log::info('Mail.ru OAuth token exchange successful', [
                    'has_access_token' => true,
                    'has_refresh_token' => isset($tokenData['refresh_token']),
                    'expires_in' => $tokenData['expires_in'] ?? null,
                ]);
                
                return response()->json([
                    'success' => true,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_in' => $tokenData['expires_in'] ?? 0,
                    'token_type' => $tokenData['token_type'] ?? 'bearer',
                ]);
            } else {
                Log::error('Mail.ru OAuth token exchange failed: invalid response', [
                    'response' => $tokenData
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => $tokenData['error'] ?? 'unknown_error',
                    'error_description' => $tokenData['error_description'] ?? 'Token exchange failed'
                ], 400);
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errorBody, true);
            
            Log::error('Mail.ru OAuth token exchange failed', [
                'status' => $e->getResponse()->getStatusCode(),
                'error' => $errorData['error'] ?? 'unknown',
                'error_description' => $errorData['error_description'] ?? $errorBody,
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $errorData['error'] ?? 'token_exchange_failed',
                'error_description' => $errorData['error_description'] ?? 'Failed to exchange authorization code for token'
            ], $e->getResponse()->getStatusCode());
        } catch (\Exception $e) {
            Log::error('Mail.ru OAuth token exchange exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'server_error',
                'error_description' => 'Internal server error during token exchange'
            ], 500);
        }
    }
}

