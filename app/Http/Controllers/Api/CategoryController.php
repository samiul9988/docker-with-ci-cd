<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CategoryScraper;
use App\Services\ProductScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryScraper $categoryScraper,
        protected ProductScraper $productScraper,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cacheKey = 'scraper_categories_all';

        try {
            $categories = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () {
                $categories = $this->categoryScraper->scrape();

                if (empty($categories)) {
                    $categories = $this->categoryScraper->scrapeFromShopPage();
                }

                return $categories;
            });

            if (empty($categories)) {
                return response()->json([
                    'message' => 'Unable to fetch categories.',
                    'data' => [],
                ], 502);
            }

            return response()->json($categories);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => [],
            ], 502);
        }
    }

    public function show(string $slug, Request $request): JsonResponse
    {
        $categories = $this->getAllCategories();

        $category = collect($categories)->first(function ($cat) use ($slug) {
            return $cat['slug'] === $slug;
        });

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json($category);
    }

    public function brands(): JsonResponse
    {
        $cacheKey = 'scraper_brands_all';

        try {
            $brands = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () {
                return $this->categoryScraper->scrapeBrands();
            });

            return response()->json($brands ?: []);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function products(Request $request, ?string $slug = null): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 12);
        $sort = $request->input('sort', 'default');
        $search = $request->input('q');
        $minPrice = $request->input('min_price');
        $maxPrice = $request->input('max_price');
        $categoryUrl = $request->input('url');

        if (empty($slug) && $request->has('category')) {
            $slug = $request->input('category');
        }

        if (empty($slug) && empty($categoryUrl)) {
            return response()->json([
                'message' => 'Provide a category slug or url parameter',
            ], 400);
        }

        if (empty($categoryUrl) && $slug) {
            $categories = $this->getAllCategories();

            $match = collect($categories)->first(function ($cat) use ($slug) {
                return $cat['slug'] === $slug;
            });

            $categoryUrl = $match['url'] ?? null;

            if (! $categoryUrl) {
                $categoryUrl = config('scraper.base_url') . '/product-category/' . $slug . '/';
            }
        }

        if ($search) {
            return $this->searchProducts($search, $page, $perPage);
        }

        $cacheKey = 'scraper_cat_products_' . md5($categoryUrl) . "_{$page}_{$perPage}_{$sort}";

        try {
            $result = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () use ($categoryUrl, $page, $perPage) {
                return $this->productScraper->scrapeCategoryUrl($categoryUrl, $page, $perPage);
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'current_page' => $page,
                'data' => [],
                'total' => 0,
                'per_page' => $perPage,
                'last_page' => 0,
            ], 502);
        }

        if (empty($result['products'])) {
            return response()->json([
                'message' => 'No products found.',
                'current_page' => $page,
                'data' => [],
                'total' => 0,
                'per_page' => $perPage,
                'last_page' => 0,
            ], 200);
        }

        $products = collect($result['products']);

        if ($sort === 'price_asc') {
            $products = $products->sortBy('price');
        } elseif ($sort === 'price_desc') {
            $products = $products->sortByDesc('price');
        } elseif ($sort === 'name_asc') {
            $products = $products->sortBy('name');
        } elseif ($sort === 'name_desc') {
            $products = $products->sortByDesc('name');
        } elseif ($sort === 'rating') {
            $products = $products->sortByDesc('rating');
        }

        if ($minPrice !== null) {
            $products = $products->filter(fn ($item) => (float) $item['price'] >= (float) $minPrice);
        }

        if ($maxPrice !== null) {
            $products = $products->filter(fn ($item) => (float) $item['price'] <= (float) $maxPrice);
        }

        $productsCount = count($products);
        $offset = ($page - 1) * $perPage;
        $pagedProducts = $products->slice($offset, $perPage)->values();
        $totalPages = $perPage > 0 ? ceil($productsCount / $perPage) : 0;

        return response()->json([
            'current_page' => $page,
            'data' => $pagedProducts,
            'total' => $productsCount,
            'per_page' => $perPage,
            'last_page' => $totalPages,
            'total_pages' => $result['total_pages'] ?? $totalPages,
        ]);
    }

    protected function getAllCategories(): array
    {
        return Cache::store('file')->remember('scraper_categories_all', config('scraper.cache_ttl', 3600), function () {
            $categories = $this->categoryScraper->scrape();

            if (empty($categories)) {
                $categories = $this->categoryScraper->scrapeFromShopPage();
            }

            return $categories;
        });
    }

    protected function searchProducts(string $query, int $page, int $perPage): JsonResponse
    {
        $cacheKey = "scraper_search_{$query}_page_{$page}";

        $result = Cache::store('file')->remember($cacheKey, config('scraper.cache_ttl', 3600), function () use ($query, $page, $perPage) {
            return $this->productScraper->searchProducts($query, $page, $perPage);
        });

        return response()->json($result);
    }
}
