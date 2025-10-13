<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Node;

class NodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Создаем основной узел XBoard
        // Get URLs from config to avoid hardcoding
        $coreUrlSandbox = config('services.core.url');
        $coreUrlProduction = config('services.core_production.url');
        
        Node::updateOrCreate(
            ['api_url' => 'https://api.xdexboard.com'],
            [
                'name' => 'XBoard Main',
                'domain' => 'xdexboard.com',
                'api_url' => 'https://api.xdexboard.com',
                'country_code' => 'USA',
                'owner' => 'XBoard Team',
                'is_default' => true,
                'is_required' => true,
                'is_active' => true,
                'is_trusted' => true,
                'trust_level' => 10,
                'user_connections' => 0,
                'priority' => 100,
                'description' => 'The XBoard main node is the entry point to the network.',
                'endpoints' => [
                    'production' => 'https://api.xdexboard.com',
                    'sandbox' => 'https://sandbox.api.xdexboard.com',
                    'dev' => 'https://dev.api.xdexboard.com',
                    'core_production' => $coreUrlProduction,
                    'core_sandbox' => $coreUrlSandbox,
                ],
                'endpoint_permissions' => [
                    'production' => 'public',
                    'sandbox' => 'developer',
                    'dev' => 'developer',
                    'core_production' => 'public',
                    'core_sandbox' => 'developer',
                    'core_dev' => 'developer'
                ],
                'features' => [
                    'escrow_tokens',
                    'multi_currency',
                    'node_discovery',
                    'trust_management'
                ],
                'health_status' => 'online'
            ]
        );

        $this->command->info('Nodes seeded successfully!');
        $this->command->info('Main node: https://api.xdexboard.com');
        $this->command->info('Only real nodes are added - no fake examples');
    }
}