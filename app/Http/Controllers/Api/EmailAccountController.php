<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Services\ImapMonitorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="Email Accounts",
 *     description="Endpoints for managing email accounts for payment verification"
 * )
 */
class EmailAccountController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/email-accounts",
     *     summary="Add email account for monitoring",
     *     tags={"Email Accounts"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password", "imap_host"},
     *             @OA\Property(property="email", type="string", example="user@gmail.com"),
     *             @OA\Property(property="password", type="string", example="app_password"),
     *             @OA\Property(property="imap_host", type="string", example="imap.gmail.com"),
     *             @OA\Property(property="imap_port", type="integer", example=993),
     *         )
     *     ),
     *     @OA\Response(response=201, description="Email account added"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:email_accounts,email',
            'password' => 'required|string',
            'imap_host' => 'required|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Test IMAP connection before saving
        $imapService = new ImapMonitorService();
        $connected = $imapService->connect(
            $request->email,
            $request->password,
            $request->imap_host,
            $request->imap_port ?? 993
        );

        if (!$connected) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to IMAP server. Please check your credentials.'
            ], 422);
        }

        $imapService->disconnect();

        // Create email account (password will be encrypted automatically)
        $emailAccount = new EmailAccount();
        $emailAccount->email = $request->email;
        $emailAccount->password = $request->password; // Automatically encrypted
        $emailAccount->imap_host = $request->imap_host;
        $emailAccount->imap_port = $request->imap_port ?? 993;
        $emailAccount->save();

        return response()->json([
            'success' => true,
            'message' => 'Email account added successfully. Monitoring will start automatically.',
            'data' => [
                'id' => $emailAccount->id,
                'email' => $emailAccount->email,
                'imap_host' => $emailAccount->imap_host,
            ]
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/email-accounts",
     *     summary="List email accounts",
     *     tags={"Email Accounts"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Successful response")
     * )
     */
    public function index(): JsonResponse
    {
        $accounts = EmailAccount::where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => $accounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'email' => $account->email,
                    'imap_host' => $account->imap_host,
                    'last_checked_at' => $account->last_checked_at,
                ];
            })
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/email-accounts/{id}",
     *     summary="Remove email account",
     *     tags={"Email Accounts"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Account removed")
     * )
     */
    public function destroy(EmailAccount $emailAccount): JsonResponse
    {
        $emailAccount->is_active = false;
        $emailAccount->save();

        return response()->json([
            'success' => true,
            'message' => 'Email account monitoring disabled'
        ]);
    }
}

