<?php

namespace App\Enums;

enum TickerSource: string
{
    case Initial = 'initial';
    case AiDiscovered = 'ai_discovered';
}
