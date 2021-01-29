<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Ticker;
use App\Models\DailyTicker;

class TickerController extends Controller
{
    public function index() {
        return view('index');
    }

    public function getWeekTickers() {
        $payload = [
            'status' => 200,
            'data' => [
                'tickers' => (new DailyTicker)->getTickersForWeek(),
            ],
        ];

        return response()->json($payload);
    }
}
