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

        if ($request->filled('country_id') && SandboxVirtualBankService::isSandboxApiHost($request->getHost())) {
            $ru = Country::query()->whereRaw('UPPER(code) = ?', ['RU'])->first();
            if ($ru && (int) $request->country_id === (int) $ru->id) {
                $virtual = SandboxVirtualBankService::virtualBankArrayForCountry($ru);
                if ($virtual !== null) {
                    $data = $data->prepend($virtual)->values();
                }
            }
        }

        Log::info('RefData: GET /banks response', ['count' => $data->count()]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Request $request, string $bank): JsonResponse
    {
        if (ctype_digit($bank) && SandboxVirtualBankService::isVirtualBankId((int) $bank)) {
            if (! SandboxVirtualBankService::isSandboxApiHost($request->getHost())) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
            $ru = Country::query()->whereRaw('UPPER(code) = ?', ['RU'])->first();
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
        $data = SandboxVirtualBankService::prependVirtualRuForSandbox($request, $country, $data);

        return response()->json(['success' => true, 'data' => $data]);
    }
} 