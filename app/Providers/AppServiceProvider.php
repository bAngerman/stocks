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

        $this->app->singleton(MarketDataService::class, fn ($app) => new MarketDataService($app->make(ApiClient::class)));

        // DiscordService constructor: __construct(string $token, string $channelId)
        $this->app->singleton(DiscordService::class, fn ($app) => new DiscordService(
            token: $app['config']['services.discord.token'],
            channelId: $app['config']['services.discord.channel_id'],
        ));
    }
}
