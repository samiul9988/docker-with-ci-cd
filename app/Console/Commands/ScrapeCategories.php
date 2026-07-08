<?php

namespace App\Console\Commands;

use App\Services\CategoryScraper;
use Illuminate\Console\Command;

class ScrapeCategories extends Command
{
    protected $signature = 'scrape:categories';

    protected $description = 'Scrape all product categories from KC Bazar';

    public function handle(CategoryScraper $scraper): int
    {
        $this->info('Scraping categories from ' . config('scraper.base_url') . '...');

        try {
            $categories = $scraper->scrape();

            if (empty($categories)) {
                $this->warn('No categories found on main page, trying shop page...');
                $categories = $scraper->scrapeFromShopPage();
            }

            if (empty($categories)) {
                $this->error('No categories found.');
                $this->newLine();
                $this->warn('If the target site uses Cloudflare protection, the scraper cannot bypass it.');
                $this->warn('Check the log file for details: storage/logs/laravel.log');

                return self::FAILURE;
            }

            $this->info('Found ' . count($categories) . ' categories:');
            $this->newLine();

            foreach ($categories as $category) {
                $this->line("  [{$category['id']}] {$category['name']}");
                $this->line("      Slug: {$category['slug']}");
                $this->line("      URL: {$category['url']}");
                $this->newLine();
            }
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
