<?php

namespace App\Enums;

enum TickerStatus: string
{
    case Active = 'active';
    case Candidate = 'candidate';
}
