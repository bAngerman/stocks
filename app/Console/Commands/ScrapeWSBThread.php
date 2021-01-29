<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Page;

class scrapeWSBThread extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stocks:scrapeWSBThread';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape comments from a WSB thread';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $page = new Page;
        $page->doThreadScrape();
    }
}
