<?php

namespace App\Enums;

enum TickerSource: string
{
    case Initial = 'initial';
    case AiDiscovered = 'ai_discovered';
    /** @deprecated No longer assigned; kept for backward compatibility with existing rows. */
    case GainersScan = 'gainers_scan';
}
