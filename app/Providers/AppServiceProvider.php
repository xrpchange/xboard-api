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
        // Создаем необходимые директории для кэша при первом запуске
        $this->ensureCacheDirectoriesExist();
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
