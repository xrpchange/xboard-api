<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Create necessary cache directories on first startup
        $this->ensureCacheDirectoriesExist();
        
        // Validate required configuration
        $this->validateRequiredConfig();
    }
    
    /**
     * Validate required configuration parameters
     */
    private function validateRequiredConfig(): void
    {
        $verificationEmail = config('mail.verification_email');
        if (!$verificationEmail) {
            throw new \RuntimeException(
                'PAYMENT_VERIFICATION_EMAIL is not set in .env file. ' .
                'This parameter is required. Please add PAYMENT_VERIFICATION_EMAIL=check@xdexboard.com to your .env file.'
            );
        }
    }

    private function ensureCacheDirectoriesExist(): void
    {
        $directories = [
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }
}
