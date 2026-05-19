<?php

namespace App\Services;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\Trade;
use Illuminate\Support\Facades\Http;

class DiscordService
{
    public function __construct(
        private readonly string $token,
        private readonly string $channelId,
        private readonly string $botUserId = '',
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

    public function postTradeAnnouncement(Trade $trade, Persona $persona): string
    {
        $isBuy = $trade->action === TradeAction::Buy;
        $actionLabel = $isBuy ? 'BUY' : 'SELL';
        $color = $isBuy ? 0x57F287 : 0xED4245;

        $shares = rtrim(rtrim(number_format((float) $trade->shares, 4), '0'), '.');
        $price = number_format((float) $trade->price_per_share, 2);

        $rationale = $trade->signal_reason;
        if ($trade->ai_rationale) {
            $rationale .= ' — '.$trade->ai_rationale;
        }

        $embed = [
            'title' => "🎲 {$trade->ticker} {$actionLabel} — {$persona->name}",
            'color' => $color,
            'fields' => [
                ['name' => 'Shares', 'value' => "{$shares} @ \${$price}", 'inline' => true],
                ['name' => 'Rationale', 'value' => $rationale, 'inline' => false],
            ],
            'footer' => ['text' => 'React 👍 good call • 👎 bad call — scored Friday'],
        ];

        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->token}",
            'Content-Type' => 'application/json',
        ])->post("https://discord.com/api/v10/channels/{$this->channelId}/messages", [
            'embeds' => [$embed],
        ])->throw()->json();

        $messageId = $response['id'];

        $this->selfReact($messageId, '👍');
        usleep(300_000);
        $this->selfReact($messageId, '👎');

        return $messageId;
    }

    /**
     * @return array<int, array{id: string, username: string}>
     */
    public function getReactions(string $messageId, string $emoji): array
    {
        $encoded = rawurlencode($emoji);

        $users = Http::withHeaders([
            'Authorization' => "Bot {$this->token}",
        ])->get("https://discord.com/api/v10/channels/{$this->channelId}/messages/{$messageId}/reactions/{$encoded}?limit=100")
            ->throw()
            ->json();

        return array_values(array_filter(
            $users,
            fn (array $user) => $user['id'] !== $this->botUserId,
        ));
    }

    private function selfReact(string $messageId, string $emoji): void
    {
        $encoded = rawurlencode($emoji);

        Http::withHeaders([
            'Authorization' => "Bot {$this->token}",
        ])->put("https://discord.com/api/v10/channels/{$this->channelId}/messages/{$messageId}/reactions/{$encoded}/@me")
            ->throw();
    }
}
