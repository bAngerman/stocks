<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\BaseModel;
use Carbon\Carbon;

use App\Models\Comment;
use DB;

use voku\helper\HtmlDomParser;

class Page extends BaseModel
{
    use HasFactory;

    private $wsb_url = "https://old.reddit.com/r/wallstreetbets/new/";

    protected $fillable = ['slug', 'last_run'];
    protected $table = 'page';

    public function doPageScrape() {

        $page = HtmlDomParser::file_get_html($this->wsb_url);

        foreach( $page->find('.linklisting .thing a.comments') as $url ) {
            $href = $url->href;

            if ( $href ) {
                $row = Page::firstOrCreate([
                    'slug' => $href,
                ],[
                    'last_run' => Carbon::createFromTimestamp(0)->toDateString(),
                ]);
            }
        }

        return null;
    }

    public function doThreadScrape() {
        $comment = new Comment;

        // Get all pages that havent been run in the past hr.
        $pages = Page::where(
            'last_run', '<', Carbon::now()->subHours(1)
        )->limit(10)->get();

        if ( ! $pages->isEmpty() ) {
            foreach ( $pages as $page ) {
                $comment->doCommentScrape( $page->id );
                sleep(1);
            }
        }
    }
}
