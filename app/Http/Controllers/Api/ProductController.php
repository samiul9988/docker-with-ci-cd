<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageDownloaderService;
use App\Services\ProductScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function __construct(
        protected ProductScraper $productScraper,
        protected ImageDownloaderService $imageDownloader,
    ) {}

    public function show(string $slug, Request $request): JsonResponse
    {
        $productUrl = $request->input('url');

        if (! $productUrl) {
            $productUrl = config('scraper.base_url') . '/product/' . $slug . '/';
        }

        $cacheKey = 'scraper_product_' . md5($productUrl);

        try {
            $product = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () use ($productUrl) {
                return $this->productScraper->scrapeProductDetail($productUrl);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        if (! $product) {
            return response()->json(['message' => 'Product not found or unable to access the target site.'], 404);
        }

        if (config('scraper.download_images', true)) {
            if (! empty($product['featured_image'])) {
                $featured = $this->imageDownloader->download(
                    $product['featured_image'],
                    $product['slug'],
                    0
                );
                $product['featured_image_local'] = $featured;
            }

            if (! empty($product['gallery_images'])) {
                $product['gallery_images_local'] = $this->imageDownloader->downloadGallery(
                    $product['gallery_images'],
                    $product['slug']
                );
            }
        }

        return response()->json($product);
    }

    public function latest(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 20);

        $cacheKey = "scraper_latest_products_{$limit}";

        $products = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () use ($limit) {
            return $this->productScraper->scrapeLatestProducts($limit);
        });

        return response()->json($products);
    }

    public function featured(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 20);

        $cacheKey = "scraper_featured_products_{$limit}";

        $products = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () use ($limit) {
            return $this->productScraper->scrapeFeaturedProducts($limit);
        });

        return response()->json($products);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 12);

        if (empty($query)) {
            return response()->json(['message' => 'Search query is required'], 400);
        }

        $cacheKey = "scraper_search_{$query}_page_{$page}_per_{$perPage}";

        $result = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () use ($query, $page, $perPage) {
            return $this->productScraper->searchProducts($query, $page, $perPage);
        });

        return response()->json($result);
    }
}
