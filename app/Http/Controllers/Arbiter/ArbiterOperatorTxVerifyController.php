<?php

declare(strict_types=1);

namespace App\Http\Controllers\Arbiter;

use App\Http\Controllers\Controller;
use App\Models\ExchangeLot;
use App\Services\Deal\DealCryptoTxCoreVerifier;
use App\Support\ApiDeploymentEnvironment;
use App\Support\CryptoTxExplorerUrl;
use App\Support\DealArbitrationTxProofCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Signed link from operator escalation mail — re-run Core on-chain verification for a chat message proof.
 */
final class ArbiterOperatorTxVerifyController extends Controller
{
    public function __construct(
        private readonly DealCryptoTxCoreVerifier $dealCryptoTxCoreVerifier,
    ) {}

    public function show(Request $request, string $lot, string $message): View
    {
        Log::info('[arbiter] operator_tx_verify', [
            'lot_id' => $lot,
            'message_id' => $message,
        ]);

        $lotModel = ExchangeLot::query()->find((int) $lot);
        if ($lotModel === null) {
            abort(404, 'Lot not found');
        }

        $messageId = (int) $message;
        $result = $this->dealCryptoTxCoreVerifier->verifyChatMessage($lotModel, $messageId);

        $environment = ApiDeploymentEnvironment::current();
        $proofs = DealArbitrationTxProofCatalog::forLot($lotModel, $environment);
        $catalogProof = null;
        foreach ($proofs as $p) {
            if ((int) ($p['message_id'] ?? 0) === $messageId) {
                $catalogProof = $p;
                break;
            }
        }

        $explorerUrl = is_array($catalogProof) ? ($catalogProof['explorer_url'] ?? null) : null;
        if ($explorerUrl === null && is_array($catalogProof) && ! empty($catalogProof['tx_id']) && ! empty($catalogProof['crypto_type'])) {
            $explorerUrl = CryptoTxExplorerUrl::forTx((string) $catalogProof['crypto_type'], (string) $catalogProof['tx_id'], $environment);
        }

        $storedCheck = is_array($catalogProof['core_tx_check'] ?? null) ? $catalogProof['core_tx_check'] : null;

        return view('arbiter.operator_tx_verify', [
            'lotId' => (string) $lotModel->id,
            'messageId' => (string) $messageId,
            'environmentLabel' => ApiDeploymentEnvironment::label($environment),
            'catalogProof' => $catalogProof,
            'explorerUrl' => $explorerUrl,
            'storedCheck' => $storedCheck,
            'verified' => (bool) ($result['verified'] ?? false),
            'verification' => is_array($result['verification'] ?? null) ? $result['verification'] : [],
            'error' => is_array($result['error'] ?? null) ? $result['error'] : null,
        ]);
    }
}
