<?php

use App\Jobs\PostTradeLotteryJob;
use App\Models\GamificationPost;
use App\Models\Trade;
use App\Services\DiscordService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.discord.token' => 'token', 'services.discord.channel_id' => '123', 'services.discord.bot_user_id' => 'bot-id']);
});

it('stores a GamificationPost with the returned discord message id', function () {
    Http::fake([
        'discord.com/api/v10/channels/123/messages' => Http::response(['id' => '777666555'], 200),
        'discord.com/*' => Http::response([], 204),
    ]);

    $trade = Trade::factory()->buy()->create();

    PostTradeLotteryJob::dispatchSync($trade, $trade->persona);

    $post = GamificationPost::first();
    expect($post)->not->toBeNull()
        ->and($post->trade_id)->toBe($trade->id)
        ->and($post->discord_message_id)->toBe('777666555')
        ->and($post->resolved_at)->toBeNull();
});

it('calls postTradeAnnouncement on DiscordService', function () {
    $discord = Mockery::mock(DiscordService::class);
    $discord->shouldReceive('postTradeAnnouncement')->once()->andReturn('123456789');
    app()->instance(DiscordService::class, $discord);

    $trade = Trade::factory()->buy()->create();

    PostTradeLotteryJob::dispatchSync($trade, $trade->persona);
});
