<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Page;

class ScrapeWSBPages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stocks:scrapeWSBPages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gets reddit WSB page for scraping';

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
        $page->doPageScrape();
    }
}
