<?php

namespace App\Console\Commands;

use App\Services\CategoryScraper;
use App\Services\ProductScraper;
use Illuminate\Console\Command;

class ScrapeProducts extends Command
{
    protected $signature = 'scrape:products
                            {--category= : Category slug to scrape}
                            {--page=1 : Page number to start from}
                            {--limit=0 : Max pages to scrape (0 = all)}';

    protected $description = 'Scrape products from all categories or a specific category';

    public function handle(CategoryScraper $categoryScraper, ProductScraper $productScraper): int
    {
        $categorySlug = $this->option('category');

        if ($categorySlug) {
            return $this->scrapeSingleCategory($productScraper, $categorySlug);
        }

        return $this->scrapeAllCategories($categoryScraper, $productScraper);
    }

    protected function scrapeSingleCategory(ProductScraper $scraper, string $slug): int
    {
        $page = (int) $this->option('page');
        $limit = (int) $this->option('limit');

        $this->info("Scraping products from category: {$slug}");

        $allProducts = [];
        $currentPage = $page;
        $maxPages = $limit > 0 ? $limit : PHP_INT_MAX;

        $progressBar = null;

        while (true) {
            $this->info("Fetching page {$currentPage}...");

            $result = $scraper->scrapeCategory($slug, $currentPage);
            $products = $result['products'];
            $totalPages = $result['total_pages'];

            if ($progressBar === null) {
                $progressBar = $this->output->createProgressBar($totalPages - $page + 1);
                $progressBar->start();
            }

            foreach ($products as $product) {
                $allProducts[] = $product;
                $this->line("  - {$product['name']} | Price: {$product['price']} | Rating: {$product['rating']}");
            }

            $progressBar->advance();

            if ($currentPage >= $totalPages || count($allProducts) >= $maxPages) {
                break;
            }

            $currentPage++;
        }

        if ($progressBar) {
            $progressBar->finish();
        }

        $this->newLine(2);
        $this->info("Total products scraped: " . count($allProducts));

        return self::SUCCESS;
    }

    protected function scrapeAllCategories(CategoryScraper $categoryScraper, ProductScraper $productScraper): int
    {
        $this->info('Fetching categories...');

        $categories = $categoryScraper->scrape();

        if (empty($categories)) {
            $categories = $categoryScraper->scrapeFromShopPage();
        }

        if (empty($categories)) {
            $this->error('No categories found.');

            return self::FAILURE;
        }

        $this->info('Found ' . count($categories) . ' categories.');

        $totalProducts = 0;

        foreach ($categories as $category) {
            $this->info("\nScraping category: {$category['name']} ({$category['slug']})");

            $page = 1;

            while (true) {
                $result = $productScraper->scrapeCategory($category['slug'], $page);
                $products = $result['products'];

                foreach ($products as $product) {
                    $this->line("  - {$product['name']} | Price: {$product['price']}");
                    $totalProducts++;
                }

                if ($result['total_pages'] <= $page) {
                    break;
                }

                $page++;
            }
        }

        $this->newLine();
        $this->info("Total products scraped across all categories: {$totalProducts}");

        return self::SUCCESS;
    }
}
