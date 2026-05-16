<?php

use App\Models\Trade;
use App\Services\DiscordService;
use Illuminate\Support\Facades\Http;

it('posts a message with embeds to the configured Discord channel', function () {
    Http::fake([
        'discord.com/*' => Http::response([], 200),
    ]);

    $service = new DiscordService('test-token', '123456789');

    $service->postMessage([
        ['title' => 'Weekly Report', 'description' => 'Summary here.'],
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/channels/123456789/messages')
            && $request->hasHeader('Authorization', 'Bot test-token')
            && isset($request->data()['embeds']);
    });
});

it('throws when Discord returns an error', function () {
    Http::fake([
        'discord.com/*' => Http::response(['message' => 'Unknown Channel'], 404),
    ]);

    $service = new DiscordService('test-token', '999');

    expect(fn () => $service->postMessage([['title' => 'Test']]))->toThrow(Exception::class);
});

it('postTradeAnnouncement posts a green embed for buy trades and returns the message id', function () {
    Http::fake([
        'discord.com/api/v10/channels/123456789/messages' => Http::response(['id' => '999888777'], 200),
        'discord.com/*' => Http::response([], 204),
    ]);

    $service = new DiscordService('test-token', '123456789', 'bot-user-id');
    $trade = Trade::factory()->buy()->create([
        'ticker' => 'AAPL',
        'shares' => 5,
        'price_per_share' => 150.00,
        'signal_reason' => 'Momentum breakout',
    ]);

    $messageId = $service->postTradeAnnouncement($trade, $trade->persona);

    expect($messageId)->toBe('999888777');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages') || $request->method() !== 'POST') {
            return false;
        }
        $embed = $request->data()['embeds'][0];

        return $embed['color'] === 0x57F287
            && str_contains($embed['title'], 'AAPL')
            && str_contains($embed['title'], 'BUY')
            && str_contains($embed['footer']['text'], 'scored Friday');
    });
});

it('postTradeAnnouncement posts a red embed for sell trades', function () {
    Http::fake([
        'discord.com/api/v10/channels/123456789/messages' => Http::response(['id' => '111222333'], 200),
        'discord.com/*' => Http::response([], 204),
    ]);

    $service = new DiscordService('test-token', '123456789', 'bot-user-id');
    $trade = Trade::factory()->sell()->create(['ticker' => 'TSLA']);

    $service->postTradeAnnouncement($trade, $trade->persona);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages') || $request->method() !== 'POST') {
            return false;
        }

        return $request->data()['embeds'][0]['color'] === 0xED4245;
    });
});

it('postTradeAnnouncement self-reacts with thumbs up and thumbs down', function () {
    Http::fake([
        'discord.com/api/v10/channels/123456789/messages' => Http::response(['id' => '555666777'], 200),
        'discord.com/*' => Http::response([], 204),
    ]);

    $service = new DiscordService('test-token', '123456789', 'bot-user-id');
    $trade = Trade::factory()->buy()->create();

    $service->postTradeAnnouncement($trade, $trade->persona);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/@me') && str_contains($r->url(), '%F0%9F%91%8D') && $r->method() === 'PUT');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/@me') && str_contains($r->url(), '%F0%9F%91%8E') && $r->method() === 'PUT');
});

it('getReactions returns discord user ids for the given emoji excluding the bot', function () {
    Http::fake([
        'discord.com/*' => Http::response([
            ['id' => 'user1', 'username' => 'alice'],
            ['id' => 'bot-user-id', 'username' => 'MyBot'],
            ['id' => 'user2', 'username' => 'bob'],
        ], 200),
    ]);

    $service = new DiscordService('test-token', '123456789', 'bot-user-id');

    $result = $service->getReactions('msg123', '👍');

    expect($result)->toBe([
        ['id' => 'user1', 'username' => 'alice'],
        ['id' => 'user2', 'username' => 'bob'],
    ]);
});
