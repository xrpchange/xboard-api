<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100); // Node name (e.g., "XRPChange")
            $table->string('domain', 255)->unique(); // Main node domain (e.g., "xrpchange.site")
            $table->string('api_url', 255); // Main API URL (e.g., "https://api.xrpchange.site")
            $table->string('country_code', 3)->nullable(); // Country code (ISO 3166-1 alpha-3)
            $table->string('owner', 255)->nullable(); // Node owner (organization/company)
            $table->boolean('is_default')->default(false); // Default node (shown first)
            $table->boolean('is_required')->default(false); // Required node (always connected, cannot be disabled)
            $table->boolean('is_active')->default(true); // Whether the node is active
            $table->integer('priority')->default(0); // Display priority (higher = more important)
            $table->text('description')->nullable(); // Node description
            $table->json('endpoints')->nullable(); // ALL node entry points (production, sandbox, dev, core - all in ONE node!)
            $table->json('endpoint_permissions')->nullable(); // Access rights for entry points (public/developer/admin)
            $table->json('features')->nullable(); // Additional node capabilities
            $table->timestamp('last_health_check')->nullable(); // Last availability check
            $table->enum('health_status', ['online', 'offline', 'degraded'])->default('online');
            $table->timestamps();
            
            // Indexes
            $table->index('domain');
            $table->index('is_active');
            $table->index('is_default');
            $table->index('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};

