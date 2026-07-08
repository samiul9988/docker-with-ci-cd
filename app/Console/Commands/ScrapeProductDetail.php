<?php

namespace App\Console\Commands;

use App\Services\ImageDownloaderService;
use App\Services\ProductScraper;
use Illuminate\Console\Command;

class ScrapeProductDetail extends Command
{
    protected $signature = 'scrape:product {url : Product detail URL}';

    protected $description = 'Scrape a single product detail page';

    public function handle(ProductScraper $scraper, ImageDownloaderService $imageDownloader): int
    {
        $url = $this->argument('url');

        $this->info("Scraping product: {$url}");

        $product = $scraper->scrapeProductDetail($url);

        if (! $product) {
            $this->error('Failed to scrape product.');

            return self::FAILURE;
        }

        $this->info('Product Details:');
        $this->line("  Name: {$product['name']}");
        $this->line("  Slug: {$product['slug']}");
        $this->line("  Price: {$product['price']}");
        $this->line("  Regular Price: {$product['regular_price']}");
        $this->line("  Sale Price: {$product['sale_price']}");
        $this->line("  SKU: {$product['sku']}");
        $this->line("  Brand: {$product['brand']}");
        $this->line("  Stock: {$product['stock_status']}");
        $this->line("  Rating: {$product['rating']} ({$product['review_count']} reviews)");
        $this->line("  Categories: " . implode(', ', $product['categories']));
        $this->line("  Tags: " . implode(', ', $product['tags']));
        $this->line("  Featured Image: {$product['featured_image']}");
        $this->line("  Gallery Images: " . count($product['gallery_images']));

        if (! empty($product['featured_image'])) {
            $downloaded = $imageDownloader->download($product['featured_image'], $product['slug'], 0);

            if ($downloaded) {
                $this->line("  Local Image: {$downloaded['url']}");
            }
        }

        return self::SUCCESS;
    }
}
