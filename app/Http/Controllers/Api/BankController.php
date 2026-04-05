<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Country;
use App\Services\SandboxVirtualBankService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="Banks",
 *     description="API endpoints for working with banks"
 * )
 */

class BankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Log::info('RefData: GET /banks called', [
            'host' => $request->getHost(),
            'url' => $request->fullUrl(),
        ]);

        $banks = Bank::active()
            ->with(['country', 'fiatCurrencies'])
            ->when($request->country_id, fn($q, $id) => $q->where('country_id', $id))
            ->when($request->for_individuals === 'true', fn($q) => $q->forIndividuals())
            ->when($request->for_legal_entities === 'true', fn($q) => $q->forLegalEntities())
            ->when($request->search, fn($q, $search) => 
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('swift_code', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get();

        $data = $banks->map(fn (Bank $bank) => SandboxVirtualBankService::mapBankModelToArray($bank));

        $host = $request->getHost();
        $sandboxHost = SandboxVirtualBankService::isSandboxApiHost($host);
        $receiptCountryCode = SandboxVirtualBankService::receiptCountryCode();
        $virtualDiag = [
            'host' => $host,
            'sandbox_host_match' => $sandboxHost,
            'receipt_country_code' => $receiptCountryCode,
            'country_id_query' => $request->query('country_id'),
            'virtual_bank_id' => SandboxVirtualBankService::virtualBankId(),
            'outcome' => 'not_attempted',
        ];

        // Clients (Search, Create lot) load GET /banks without country_id and filter by country in the app.
        // Virtual sandbox receipt bank is appended at the end of the list; country_id filter is handled below.
        if (! $sandboxHost) {
            $virtualDiag['outcome'] = 'skipped_host';
            $virtualDiag['hint'] = 'Host must match ^(sandbox.|dev.) or be listed in SANDBOX_VIRTUAL_BANK_EXTRA_PREPEND_HOSTS; see docs/SANDBOX_VIRTUAL_BANK.md';
        } elseif (($ru = Country::query()->whereRaw('UPPER(TRIM(code)) = ?', [$receiptCountryCode])->first()) === null) {
            $virtualDiag['outcome'] = 'skipped_no_ru_country';
        } elseif (! (! $request->filled('country_id') || (int) $request->country_id === (int) $ru->id)) {
            $virtualDiag['outcome'] = 'skipped_country_id_not_ru';
            $virtualDiag['receipt_country_id'] = $ru->id;
        } elseif (($virtual = SandboxVirtualBankService::virtualBankArrayForCountry($ru)) === null) {
            $virtualDiag['outcome'] = 'skipped_virtual_array_null';
        } else {
            $vid = SandboxVirtualBankService::virtualBankId();
            $already = $data->contains(fn ($row) => (int) ($row['id'] ?? 0) === $vid);
            if ($already) {
                $virtualDiag['outcome'] = 'already_present';
            } else {
                $data = $data->push($virtual)->values();
                $virtualDiag['outcome'] = 'appended';
            }
        }

        $virtualDiag['virtual_in_response'] = $data->contains(fn ($row) => (int) ($row['id'] ?? 0) === SandboxVirtualBankService::virtualBankId());
        Log::info('RefData: GET /banks sandbox_virtual_bank', $virtualDiag);

        Log::info('RefData: GET /banks response', ['count' => $data->count()]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Request $request, string $bank): JsonResponse
    {
        if (ctype_digit($bank) && SandboxVirtualBankService::isVirtualBankId((int) $bank)) {
            if (! SandboxVirtualBankService::isSandboxApiHost($request->getHost())) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
            $ru = Country::query()->whereRaw('UPPER(TRIM(code)) = ?', [SandboxVirtualBankService::receiptCountryCode()])->first();
            $virtual = SandboxVirtualBankService::virtualBankArrayForCountry($ru);
            if ($virtual === null) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            return response()->json(['success' => true, 'data' => $virtual]);
        }

        $bankModel = Bank::query()->findOrFail((int) $bank);
        $bankModel->load(['country', 'fiatCurrencies']);

        return response()->json(['success' => true, 'data' => SandboxVirtualBankService::mapBankModelToArray($bankModel)]);
    }

    public function byCountry(Country $country, Request $request): JsonResponse
    {
        $banks = $country->banks()
            ->active()
            ->with('fiatCurrencies')
            ->when($request->for_individuals === 'true', fn($q) => $q->forIndividuals())
            ->when($request->for_legal_entities === 'true', fn($q) => $q->forLegalEntities())
            ->orderBy('name')
            ->get();

        $data = $banks->map(fn (Bank $bank) => SandboxVirtualBankService::mapBankModelToArray($bank));
        $data = SandboxVirtualBankService::appendVirtualReceiptBankForSandbox($request, $country, $data);

        return response()->json(['success' => true, 'data' => $data]);
    }
} 