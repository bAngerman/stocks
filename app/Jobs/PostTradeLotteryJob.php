<?php

namespace App\Jobs;

use App\Models\GamificationPost;
use App\Models\Persona;
use App\Models\Trade;
use App\Services\DiscordService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostTradeLotteryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Trade $trade,
        public readonly Persona $persona,
    ) {}

    public function handle(DiscordService $discord): void
    {
        $messageId = $discord->postTradeAnnouncement($this->trade, $this->persona);

        GamificationPost::create([
            'trade_id' => $this->trade->id,
            'discord_message_id' => $messageId,
        ]);
    }
}
