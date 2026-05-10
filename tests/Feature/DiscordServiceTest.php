<?php

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

    expect(fn () => $service->postMessage([['title' => 'Test']]))->toThrow(\Exception::class);
});
