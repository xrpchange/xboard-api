<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Node;

class NodeSeeder extends Seeder
{
    /**
     * Seed the nodes table with initial XBoard and Core nodes
     */
    public function run(): void
    {
        // XRPChange Node - currently the only node in the system
        // ALL entry points (including Core) are parts of ONE node
        $nodes = [
            [
                'name' => 'XRPChange',
                'domain' => 'xrpchange.site',
                'api_url' => 'https://api.xrpchange.site', // Main API
                'country_code' => null,
                'is_default' => true,
                'is_required' => true,
                'is_active' => true,
                'priority' => 100,
                'description' => 'XRPChange - P2P cryptocurrency exchange. All entry points (production, sandbox, dev, core) are parts of this node.',
                'endpoints' => [
                    // XBoard API endpoints (bulletin board)
                    'production_api' => 'https://api.xrpchange.site',
                    'sandbox_api' => 'https://sandbox.api.xrpchange.site',
                    'dev_api' => 'https://dev.api.xrpchange.site',
                    // Core API endpoints (blockchain operations)
                    'production_core' => 'https://api.core.xrpchange.site',
                    'sandbox_core' => 'https://sandbox.api.core.xrpchange.site'
                ],
                'endpoint_permissions' => [
                    'production_api' => 'public',      // Available to everyone
                    'sandbox_api' => 'public',         // Available to everyone
                    'dev_api' => 'developer',          // Developers only
                    'production_core' => 'public',     // Available to everyone
                    'sandbox_core' => 'public'         // Available to everyone
                ],
                'features' => [
                    'escrow_tokens',
                    'exchange_lots',
                    'buy_triggers',
                    'transactions',
                    'multi_crypto',
                    'core_integration'
                ]
            ]
            
            // Future: Other NODES from different providers will be added here:
            // [
            //     'name' => 'AnotherExchange',
            //     'domain' => 'anotherexchange.com',
            //     'api_url' => 'https://api.anotherexchange.com',
            //     'endpoints' => [
            //         'production_api' => 'https://api.anotherexchange.com',
            //         'sandbox_api' => 'https://sandbox.anotherexchange.com'
            //     ],
            //     ...
            // ]
        ];

        foreach ($nodes as $nodeData) {
            Node::create($nodeData);
        }
    }
}

