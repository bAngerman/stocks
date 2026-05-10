<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DiscordService
{
    public function __construct(
        private readonly string $token,
        private readonly string $channelId,
    ) {}

    /** @param array<int, array<string, mixed>> $embeds */
    public function postMessage(array $embeds): void
    {
        Http::withHeaders([
            'Authorization' => "Bot {$this->token}",
            'Content-Type' => 'application/json',
        ])->post("https://discord.com/api/v10/channels/{$this->channelId}/messages", [
            'embeds' => $embeds,
        ])->throw();
    }
}
