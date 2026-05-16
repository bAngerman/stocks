<?php

namespace App\Providers;

use App\Services\DiscordService;
use App\Services\MarketDataService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarketDataService::class, fn () => new MarketDataService);

        $this->app->singleton(DiscordService::class, fn ($app) => new DiscordService(
            token: $app['config']['services.discord.token'],
            channelId: $app['config']['services.discord.channel_id'],
            botUserId: $app['config']['services.discord.bot_user_id'] ?? '',
        ));
    }
}
