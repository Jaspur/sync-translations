<?php

namespace Jaspur\SyncTranslations;

use Illuminate\Support\ServiceProvider;
use Jaspur\SyncTranslations\Commands\SyncTranslationsCommand;

final class SyncTranslationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncTranslationsCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/translations-sync.php' => config_path('translations-sync.php'),
            ], 'config');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/translations-sync.php',
            'translations-sync'
        );
    }
}
