<?php

namespace App\Console\Commands;

use App\Services\ImageDownloaderService;
use App\Services\ProductScraper;
use Illuminate\Console\Command;

class ScrapeCategory extends Command
{
    protected $signature = 'scrape:category
                            {slug : Category slug to scrape}
                            {--download-images : Download product images locally}
                            {--limit=0 : Max pages (0 = all)}';

    protected $description = 'Scrape all products from a specific category';

    public function handle(ProductScraper $productScraper, ImageDownloaderService $imageDownloader): int
    {
        $slug = $this->argument('slug');
        $downloadImages = $this->option('download-images');
        $limit = (int) $this->option('limit');

        $this->info("Scraping category: {$slug}");
        $this->newLine();

        $page = 1;
        $totalProducts = 0;
        $totalImages = 0;

        while (true) {
            $this->info("Page {$page}:");

            $result = $productScraper->scrapeCategory($slug, $page);
            $products = $result['products'];

            if (empty($products)) {
                $this->line('  No products found.');

                break;
            }

            foreach ($products as $product) {
                $totalProducts++;
                $this->line("  {$totalProducts}. {$product['name']} | {$product['price']} | Rating: {$product['rating']}");

                if ($downloadImages && ! empty($product['url'])) {
                    $detail = $productScraper->scrapeProductDetail($product['url']);

                    if ($detail && ! empty($detail['featured_image'])) {
                        $downloaded = $imageDownloader->download($detail['featured_image'], $detail['slug'], 0);

                        if ($downloaded) {
                            $totalImages++;
                            $this->line("      Image: {$downloaded['filename']}");
                        }
                    }

                    if ($detail && ! empty($detail['gallery_images'])) {
                        $gallery = $imageDownloader->downloadGallery($detail['gallery_images'], $detail['slug']);
                        $totalImages += count($gallery);

                        foreach ($gallery as $img) {
                            $this->line("      Gallery: {$img['filename']}");
                        }
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

        $this->newLine();
        $this->info('=== Complete ===');
        $this->info("Products scraped: {$totalProducts}");

        if ($downloadImages) {
            $this->info("Images downloaded: {$totalImages}");
        }

        return self::SUCCESS;
    }
}
