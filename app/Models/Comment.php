<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\BaseModel;
use Carbon\Carbon;

use voku\helper\HtmlDomParser;

use App\Models\Page;
use App\Models\Ticker;

use Log;

class Comment extends BaseModel
{
    use HasFactory;

    private $base_url = "https://old.reddit.com";
    protected $table = 'comment';

    protected $fillable = ['content', 'thing_id', 'tickers', 'page_id'];

    public function doCommentScrape($page_id) {
        $ticker = new Ticker;

        if ( ! $page_id ) {
            return;
        }

        $page = Page::find($page_id);

        if ( ! $page ) {
            return;
        }

        try {
            $thread = HtmlDomParser::file_get_html( $page->slug );
            Log::info(sprintf("Got thread: %s", $page->slug));
        } catch(Exception $e) {
            Log::err(sprintf("Failed thread: %s", $page->slug));
        }

        $newComments = [];

        foreach( $thread->find('.thing.comment > .entry > form') as $comment ) {

            $thing_id = $comment->findOne('input')->getAttribute('value');
            $comment_text = $comment->findOne('.md')->text();

            // Comment already exists, skip.
            if ( Comment::where(['thing_id' => $thing_id])->first() ) {
                echo sprintf("Skipped comment %s\n", $thing_id);
                continue;
            }
            
            $tickers = $ticker->extractTickers($comment_text);

            $comment = Comment::where([
                ['thing_id' => $thing_id]
            ])->first();

            if ( $comment === null ) {
                $comment = new Comment([
                    'thing_id' => $thing_id,
                    'content'  => $comment_text,
                    'page_id'  => $page_id,
                    'tickers'  => implode( ',', array_keys($tickers) )
                ]);
                $comment->save();

                $newComments[] = $comment;
            }
        }

        if ( count($newComments) === 0 ) {
            $page->last_run = Carbon::tomorrow();
        } else {
            // Update the page last run so we do not run it again soon.
            $page->last_run = Carbon::now();
        }

        $page->save();

        return;
    }
}
