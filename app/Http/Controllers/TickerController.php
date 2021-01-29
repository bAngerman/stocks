<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Ticker;

class TickerController extends Controller
{
    public function index() {
        return view('index');
    }

    public function getWeekTickers() {
        $payload = [
            'status' => 200,
            'data' => [
                'tickers' => (new Ticker)->getWeekTickers(),
            ],
        ];

        return response()->json($payload);
    }
}
