<?php

namespace App\Providers;

use App\Services\DiscordService;
use App\Services\MarketDataService;
use Illuminate\Support\ServiceProvider;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\ApiClientFactory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApiClient::class, fn () => ApiClientFactory::createApiClient());

        $this->app->singleton(MarketDataService::class, function () {
            return new MarketDataService(app(ApiClient::class));
        });

        $this->app->singleton(DiscordService::class, function () {
            return new DiscordService(
                token: config('services.discord.token'),
                channelId: config('services.discord.channel_id'),
            );
        });
    }

    public function boot(): void {}
}
