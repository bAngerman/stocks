<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\BaseModel;

use Carbon\Carbon;

class DailyTicker extends BaseModel
{
    use HasFactory;

    public function updateDailyTickers($tickers) {
        $today = Carbon::today();
        foreach( $tickers as $ticker_name => $ticker_data ) {
            $d_ticker = DailyTicker::where('name', $ticker_name)
                ->whereDate('day', $today)->first();

            // Daily Ticker does not exist.
            if ( ! $d_ticker ) {
                $d_ticker = new DailyTicker;
                $d_ticker->name = $ticker_name;
                $d_ticker->count = $ticker_data['count'];
                $d_ticker->day = $today;
            } 
            // Ticker does exist, increment value.
            else {
                $d_ticker = (new DailyTicker)->where('name', $ticker_name)
                    ->whereDate('day', $today)->first();

                $d_ticker->count = $d_ticker->count + $ticker_data['count'];
            }

            $d_ticker->save();
        }
    }

    public function getDailyTickerCounts() {
        $today = Carbon::today();

        $d_tickers = DailyTicker::whereDate('day', $today)->get();
        
        return $d_tickers;
    }

    public function getTickersForDay($day = null) {
        $d_tickers = DailyTicker::whereDate('day', $day)->get();
        
        return $d_tickers;
    }
}
