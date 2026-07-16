<?php

namespace App\Providers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Postar\EpostakConnectorAdapter;
use App\Services\Postar\PostarAdapterInterface;
use App\Services\Postar\PostarException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PostarAdapterInterface::class, function ($app) {
            $driver = config('postar.default');
            $config = config("postar.{$driver}");

            return match ($driver) {
                'epostak' => new EpostakConnectorAdapter(
                    $app->make(HttpFactory::class),
                    $app->make(Cache::class),
                    $config,
                ),
                default => throw new PostarException(
                    "Neznámy poštár '{$driver}' v konfigurácii postar.default.",
                    providerCode: 'UNKNOWN_DRIVER',
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('pagination.default');
        Paginator::defaultSimpleView('pagination.default');

        // Error-queue badge in the top navigation.
        View::composer('layouts.app', function ($view) {
            $view->with(
                'errorCount',
                Invoice::whereIn('status', InvoiceStatus::erroneous())->count()
            );
        });
    }
}
