<?php

namespace App\Console\Commands;

use App\Services\CategoryScraper;
use App\Services\ImageDownloaderService;
use App\Services\ProductScraper;
use Illuminate\Console\Command;

class ScrapeAll extends Command
{
    protected $signature = 'scrape:all
                            {--download-images : Download all product images locally}
                            {--limit=0 : Max pages per category (0 = all)}';

    protected $description = 'Scrape all categories and all products from KC Bazar';

    public function handle(
        CategoryScraper $categoryScraper,
        ProductScraper $productScraper,
        ImageDownloaderService $imageDownloader,
    ): int {
        $downloadImages = $this->option('download-images');
        $limit = (int) $this->option('limit');

        $this->info('=== KC Bazar Full Scraper ===');
        $this->newLine();

        $this->info('Step 1: Scraping categories...');
        $categories = $categoryScraper->scrape();

        if (empty($categories)) {
            $categories = $categoryScraper->scrapeFromShopPage();
        }

        if (empty($categories)) {
            $this->error('No categories found. Aborting.');

            return self::FAILURE;
        }

        $this->info('Found '.count($categories).' categories.');
        $this->newLine();

        $totalProducts = 0;
        $totalImages = 0;
        $totalCategories = count($categories);
        $processedCategories = 0;

        foreach ($categories as $category) {
            $processedCategories++;
            $this->info("[{$processedCategories}/{$totalCategories}] Category: {$category['name']}");

            $page = 1;
            $categoryProductCount = 0;

            while (true) {
                $result = $productScraper->scrapeCategory($category['slug'], $page);
                $products = $result['products'];

                if (empty($products)) {
                    $this->line('  No products found on this page.');

                    break;
                }

                foreach ($products as $product) {
                    $categoryProductCount++;
                    $totalProducts++;
                    $this->line("  {$categoryProductCount}. {$product['name']} | {$product['price']}");

                    if ($downloadImages && ! empty($product['url'])) {
                        $detail = $productScraper->scrapeProductDetail($product['url']);

                        if ($detail && ! empty($detail['featured_image'])) {
                            $downloaded = $imageDownloader->download($detail['featured_image'], $detail['slug'], 0);

                            if ($downloaded) {
                                $totalImages++;
                            }
                        }

                        if ($detail && ! empty($detail['gallery_images'])) {
                            $gallery = $imageDownloader->downloadGallery($detail['gallery_images'], $detail['slug']);
                            $totalImages += count($gallery);
                        }
                    }
                }

                if ($result['total_pages'] <= $page) {
                    break;
                }

                if ($limit > 0 && $page >= $limit) {
                    break;
                }

                $page++;
            }

            $this->info("  -> {$categoryProductCount} products in this category.");
            $this->newLine();
        }

        $this->info('=== Scraping Complete ===');
        $this->info("Total categories: {$totalCategories}");
        $this->info("Total products: {$totalProducts}");

        if ($downloadImages) {
            $this->info("Total images downloaded: {$totalImages}");
        }

        return self::SUCCESS;
    }
}
