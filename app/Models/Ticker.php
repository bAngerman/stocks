<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\BaseModel;

use App\Models\DailyTicker;

class Ticker extends BaseModel
{
    use HasFactory;

    private $whitelist = [];

    protected $fillable = ['name', 'count'];

    public function __construct(array $attributes = []) {
        parent::__construct($attributes);

        $this->whitelist = $this->generateWhitelist();
    }

    private function generateWhitelist() {
        $tickers = [];

        $file_handle = fopen( __DIR__ . '/tickers.csv', 'r');
        while ( ! feof($file_handle) ) {
            $tickers[] = fgetcsv($file_handle, 0)[0];
        }
        fclose($file_handle);

        return $tickers;
    }

    private function inWhitelist($ticker) {
        if ( ! $ticker ) {
            return false;
        }

        return in_array($ticker, $this->whitelist);
    }

    public function extractTickers($content) {
        $pattern = "/\b[A-Z]+\b/";

        $matches = preg_grep( $pattern, explode(" ", $content ) );

        foreach ($matches as $idx => $ticker) {

            $matches[$idx] = preg_replace( "/(?![A-Z])./", "", $matches[$idx] );
            $matches[$idx] = trim( preg_replace("/\t/", '', $matches[$idx] ) );

            if ( ! $this->inWhitelist( $matches[$idx] ) ) {
                unset( $matches[$idx] );
            }
        }

        $c_matches = $this->consolidateMatches($matches);

        $this->updateTickers($c_matches);

        (new DailyTicker)->updateDailyTickers($c_matches);

        return $c_matches;
    }

    public function consolidateMatches($matches) {
        $results = [];

        foreach( $matches as $ticker ) {
            if ( isset( $results[ $ticker ] ) ) {
                $results[ $ticker ]['count'] = $results[ $ticker ]['count'] + 1;
            } else {
                $results[ $ticker ] = [
                    'count' => 1
                ];
            }
        }

        return $results;
    }

    public function updateTickers($tickers) {
        
        foreach( $tickers as $ticker_name => $ticker_data ) {
            $ticker = Ticker::where('name', $ticker_name)->first();

            // New ticker.
            if ( ! $ticker ) {
                $ticker = new Ticker;
                $ticker->name = $ticker_name;
                $ticker->count = $ticker_data['count'];
            } 
            // Old ticker, increment and move on.
            else {          
                $ticker = (new Ticker)->where('name', $ticker_name)->first();
                $ticker->count = $ticker->count + $ticker_data['count'];
            }

            $ticker->save();
        }
    }
    
    public function getAllTickers() {
        $tickers = Ticker::all();

        return $tickers;
    }
}
