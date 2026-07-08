<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ProductScraper
{
    protected string $baseUrl;

    protected string $userAgent;

    protected int $timeout;

    protected int $retryCount;

    protected int $delayMs;

    protected int $maxPages;

    protected ?Client $httpClient = null;

    protected ?CookieJar $cookieJar = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('scraper.base_url'), '/');
        $this->userAgent = config('scraper.user_agent');
        $this->timeout = config('scraper.timeout', 30);
        $this->retryCount = config('scraper.retry_count', 3);
        $this->delayMs = config('scraper.delay_between_requests', 500);
        $this->maxPages = (int) config('scraper.max_pages', 0);
    }

    protected function getClient(): Client
    {
        if ($this->httpClient === null) {
            $this->cookieJar = new CookieJar;

            $this->httpClient = new Client([
                'verify' => false,
                'cookies' => $this->cookieJar,
                'timeout' => $this->timeout,
                'allow_redirects' => ['max' => 5],
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cache-Control' => 'no-cache',
                    'Pragma' => 'no-cache',
                ],
            ]);
        }

        return $this->httpClient;
    }

    public function scrapeCategory(string $categorySlug, int $page = 1, int $perPage = 12): array
    {
        $categoryUrl = $this->baseUrl.'/product-category/'.$categorySlug.'/';

        return $this->scrapeCategoryUrl($categoryUrl, $page, $perPage);
    }

    public function scrapeCategoryUrl(string $url, int $page = 1, int $perPage = 12): array
    {
        $url = rtrim($url, '/');

        if ($page > 1) {
            $url .= '/page/'.$page.'/';
        }

        $url .= '/';

        Log::info("Scraping products from: {$url}");

        try {
            $html = $this->fetchUrl($url);
        } catch (\Exception $e) {
            Log::error("Failed to fetch category page {$url}: ".$e->getMessage());

            return [
                'products' => [],
                'total_pages' => 0,
                'current_page' => $page,
                'total_products' => 0,
            ];
        }

        $crawler = new Crawler($html);

        $products = $this->parseProductList($crawler);

        $totalPages = $this->parsePagination($crawler, $page);

        if ($this->maxPages > 0 && $totalPages > $this->maxPages) {
            $totalPages = $this->maxPages;
        }

        $totalProducts = $this->parseTotalProducts($crawler);

        Log::info('Scraped '.count($products)." products from page {$page}, total pages: {$totalPages}");

        return [
            'products' => $products,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'total_products' => $totalProducts,
        ];
    }

    public function scrapeAllProducts(string $categorySlug): array
    {
        $allProducts = [];
        $page = 1;

        while (true) {
            $result = $this->scrapeCategory($categorySlug, $page);

            $allProducts = array_merge($allProducts, $result['products']);

            if ($result['total_pages'] <= $page) {
                break;
            }

            $page++;
        }

        return $allProducts;
    }

    public function scrapeProductDetail(string $productUrl): ?array
    {
        Log::info("Scraping product detail: {$productUrl}");

        try {
            $html = $this->fetchUrl($productUrl);
        } catch (\Exception $e) {
            Log::error("Failed to fetch product detail {$productUrl}: ".$e->getMessage());

            return null;
        }

        $crawler = new Crawler($html);

        return $this->parseProductDetail($crawler, $productUrl);
    }

    public function scrapeLatestProducts(int $limit = 20): array
    {
        $shopUrl = $this->baseUrl.'/shop/';

        Log::info('Scraping latest products from shop page');

        try {
            $html = $this->fetchUrl($shopUrl);
        } catch (\Exception $e) {
            Log::error('Failed to fetch shop page: '.$e->getMessage());

            return [];
        }

        $crawler = new Crawler($html);

        return array_slice($this->parseProductList($crawler), 0, $limit);
    }

    public function scrapeFeaturedProducts(int $limit = 20): array
    {
        Log::info('Scraping featured products from home page');

        try {
            $html = $this->fetchUrl($this->baseUrl.'/');
        } catch (\Exception $e) {
            Log::error('Failed to fetch home page: '.$e->getMessage());

            return [];
        }

        $crawler = new Crawler($html);

        $selectors = [
            '.featured-products',
            '.featured',
            '.products.featured',
            '.onsale',
            '[class*="featured"]',
            '.wc-block-featured-product',
            '.wp-block-woocommerce-featured-product',
            '.home-featured',
            '.product-carousel',
            '.products',
        ];

        foreach ($selectors as $selector) {
            $section = $crawler->filter($selector);
            if ($section->count() > 0) {
                $products = $this->parseProductList($section->first());
                if (! empty($products)) {
                    Log::info('Found '.count($products)." products via selector: {$selector}");

                    return array_slice($products, 0, $limit);
                }
            }
        }

        return array_slice($this->parseProductList($crawler), 0, $limit);
    }

    public function searchProducts(string $query, int $page = 1, int $perPage = 12): array
    {
        $searchUrl = $this->baseUrl.'/?s='.urlencode($query).'&post_type=product';

        if ($page > 1) {
            $searchUrl .= '&paged='.$page;
        }

        Log::info("Searching products with query: {$query}");

        try {
            $html = $this->fetchUrl($searchUrl);
        } catch (\Exception $e) {
            Log::error('Search failed: '.$e->getMessage());

            return [
                'products' => [],
                'total_pages' => 0,
                'current_page' => $page,
                'total_products' => 0,
            ];
        }

        $crawler = new Crawler($html);

        return [
            'products' => $this->parseProductList($crawler),
            'total_pages' => $this->parsePagination($crawler, $page),
            'current_page' => $page,
            'total_products' => $this->parseTotalProducts($crawler),
        ];
    }

    public function scrapeSpecialPage(string $page, int $pageNum = 1, int $perPage = 12): array
    {
        $url = $this->baseUrl.'/'.ltrim($page, '/').'/';

        return $this->scrapeCategoryUrl($url, $pageNum, $perPage);
    }

    public function scrapeBrandProducts(string $brandSlug, int $page = 1, int $perPage = 12): array
    {
        $url = $this->baseUrl.'/attribute/brand/'.$brandSlug.'/';

        return $this->scrapeCategoryUrl($url, $page, $perPage);
    }

    protected function parseProductList(Crawler $crawler): array
    {
        $products = [];
        $seen = [];

        $crawler->filter('li.product, .product, .product-item, [class*="product"]')->each(function (Crawler $node) use (&$products, &$seen) {
            if (! $node->attr('class')) {
                return;
            }

            $classes = explode(' ', $node->attr('class'));

            if (! in_array('product', $classes) && ! preg_grep('/^product-/i', $classes)) {
                return;
            }

            $product = $this->parseProductItem($node);

            if ($product && ! empty($product['name'])) {
                $key = $product['url'] ?: $product['slug'];

                if (! in_array($key, $seen)) {
                    $seen[] = $key;
                    $products[] = $product;
                }
            }
        });

        return $products;
    }

    protected function parseProductItem(Crawler $node): ?array
    {
        $nameNode = $node->filter('.woocommerce-loop-product__title, .product-title, .product-name, h2, h3, .name a');

        $name = $nameNode->count() > 0 ? trim($nameNode->first()->text()) : '';

        if (empty($name)) {
            return null;
        }

        $linkNode = $node->filter('a.woocommerce-LoopProduct-link, a.product-link, .product-title a, a[href*="/product/"]');

        $productUrl = '';

        if ($linkNode->count() > 0) {
            $productUrl = $this->normalizeUrl($linkNode->first()->attr('href'));
        }

        $slug = $this->extractSlug($productUrl);

        $imgNode = $node->filter('img');

        $imageUrl = '';

        if ($imgNode->count() > 0) {
            $imageUrl = $this->extractImageUrl($imgNode->first());
        }

        $regularPriceNode = $node->filter('del .woocommerce-Price-amount, del .amount, .regular-price .amount, .price del');

        $regularPrice = '';

        if ($regularPriceNode->count() > 0) {
            $regularPrice = $this->cleanPrice($regularPriceNode->first()->text());
        }

        $salePriceNode = $node->filter('ins .woocommerce-Price-amount, ins .amount, .sale-price .amount, .price ins');

        $salePrice = '';

        if ($salePriceNode->count() > 0) {
            $salePrice = $this->cleanPrice($salePriceNode->first()->text());
        }

        $priceNode = $node->filter('.price .woocommerce-Price-amount, .price .amount, .price');

        $price = '';

        if ($priceNode->count() > 0) {
            $priceText = $priceNode->first()->text();
            if (empty($salePrice) && empty($regularPrice)) {
                $price = $this->cleanPrice($priceText);
            }
        }

        if (empty($price) && ! empty($salePrice)) {
            $price = $salePrice;
        }

        if (empty($price) && ! empty($regularPrice)) {
            $price = $regularPrice;
        }

        $ratingNode = $node->filter('.star-rating, .rating');

        $rating = 0;

        if ($ratingNode->count() > 0) {
            $ratingSpan = $ratingNode->first()->attr('aria-label');
            if ($ratingSpan) {
                preg_match('/([\d.]+)/', $ratingSpan, $matches);
                $rating = isset($matches[1]) ? (float) $matches[1] : 0;
            }

            $ratingWidth = $ratingNode->first()->filter('span')->attr('style');
            if ($ratingWidth) {
                preg_match('/width:\s*([\d.]+)%/', $ratingWidth, $matches);
                $rating = isset($matches[1]) ? (float) $matches[1] * 5 / 100 : $rating;
            }
        }

        $stockNode = $node->filter('.stock, .out-of-stock, .in-stock, .outofstock');

        $stockStatus = 'in_stock';

        if ($stockNode->count() > 0) {
            $stockText = trim($stockNode->first()->text());
            if (stripos($stockText, 'out') !== false) {
                $stockStatus = 'out_of_stock';
            }
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'url' => $productUrl,
            'image' => $imageUrl,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'price' => $price,
            'rating' => $rating,
            'stock_status' => $stockStatus,
        ];
    }

    protected function parseProductDetail(Crawler $crawler, string $productUrl): array
    {
        $name = '';
        $nameNode = $crawler->filter('.product_title, .product-name, h1.product-title, h1');

        if ($nameNode->count() > 0) {
            $name = trim($nameNode->first()->text());
        }

        $slug = $this->extractSlug($productUrl);

        $regularPrice = '';
        $regularPriceNode = $crawler->filter('del .woocommerce-Price-amount, del .amount, .price del');

        if ($regularPriceNode->count() > 0) {
            $regularPrice = $this->cleanPrice($regularPriceNode->first()->text());
        }

        $salePrice = '';
        $salePriceNode = $crawler->filter('ins .woocommerce-Price-amount, ins .amount, .price ins');

        if ($salePriceNode->count() > 0) {
            $salePrice = $this->cleanPrice($salePriceNode->first()->text());
        }

        $price = '';
        $priceNode = $crawler->filter('.price .woocommerce-Price-amount, .price .amount, .price');

        if ($priceNode->count() > 0) {
            $price = $this->cleanPrice($priceNode->first()->text());
        }

        if (empty($price) && ! empty($salePrice)) {
            $price = $salePrice;
        }

        if (empty($price) && ! empty($regularPrice)) {
            $price = $regularPrice;
        }

        $shortDescription = '';
        $shortDescNode = $crawler->filter('.woocommerce-product-details__short-description, .product-short-description, .short-description');

        if ($shortDescNode->count() > 0) {
            $shortDescription = trim($shortDescNode->first()->text());
        }

        $fullDescription = '';
        $fullDescNode = $crawler->filter('.woocommerce-Tabs-panel--description, #tab-description, .product-description, .description');

        if ($fullDescNode->count() > 0) {
            $fullDescription = trim($fullDescNode->first()->text());
        }

        $featuredImage = '';
        $featuredImageNode = $crawler->filter('.woocommerce-product-gallery__image img, .wp-post-image, .product-image img, .featured-image img');

        if ($featuredImageNode->count() > 0) {
            $featuredImage = $this->extractImageUrl($featuredImageNode->first());
        }

        $galleryImages = [];
        $galleryNodes = $crawler->filter('.woocommerce-product-gallery__image img, .product-thumbnails img, .flex-control-nav img, .gallery-image img');

        $galleryNodes->each(function (Crawler $node) use (&$galleryImages) {
            $src = $this->extractImageUrl($node);
            if ($src && ! in_array($src, $galleryImages)) {
                $galleryImages[] = $src;
            }
        });

        $attributes = [];
        $crawler->filter('.woocommerce-product-attributes tr, .product-attributes tr')->each(function (Crawler $row) use (&$attributes) {
            $label = $row->filter('th');
            $value = $row->filter('td');

            if ($label->count() > 0 && $value->count() > 0) {
                $attributes[trim($label->first()->text())] = trim($value->first()->text());
            }
        });

        $sku = '';
        $skuNode = $crawler->filter('.sku, .product_meta .sku');

        if ($skuNode->count() > 0) {
            $sku = trim(str_replace('SKU:', '', $skuNode->first()->text()));
        }

        $categories = [];
        $crawler->filter('.product_meta .posted_in a, .product-categories a')->each(function (Crawler $node) use (&$categories) {
            $catName = trim($node->text());
            if ($catName) {
                $categories[] = $catName;
            }
        });

        $tags = [];
        $crawler->filter('.product_meta .tagged_as a, .product-tags a')->each(function (Crawler $node) use (&$tags) {
            $tagName = trim($node->text());
            if ($tagName) {
                $tags[] = $tagName;
            }
        });

        $brand = '';
        $brandNode = $crawler->filter('.brand, .product-brand, .product_meta .brand a, [class*="brand"]');

        if ($brandNode->count() > 0) {
            $brand = trim($brandNode->first()->text());
        }

        $rating = 0;
        $ratingNode = $crawler->filter('.star-rating, .woocommerce-product-rating .star-rating');
        if ($ratingNode->count() > 0) {
            $ariaLabel = $ratingNode->first()->attr('aria-label');
            if ($ariaLabel) {
                preg_match('/([\d.]+)/', $ariaLabel, $matches);
                $rating = isset($matches[1]) ? (float) $matches[1] : 0;
            }
        }

        $reviewCount = 0;
        $reviewNode = $crawler->filter('.woocommerce-review-link, .reviews-count, .rating-count');
        if ($reviewNode->count() > 0) {
            preg_match('/(\d+)/', $reviewNode->first()->text(), $matches);
            $reviewCount = isset($matches[1]) ? (int) $matches[1] : 0;
        }

        $stockStatus = 'in_stock';
        $stockNode = $crawler->filter('.stock, .stock-status, .product_meta .stock');
        if ($stockNode->count() > 0) {
            $stockText = trim($stockNode->first()->text());
            if (stripos($stockText, 'out') !== false || stripos($stockText, 'unavailable') !== false) {
                $stockStatus = 'out_of_stock';
            }
        }

        $weight = '';
        $weightNode = $crawler->filter('.product-weight, [class*="weight"]');
        if ($weightNode->count() > 0) {
            preg_match('/([\d.]+)\s*(kg|g|lbs?|oz)/i', $weightNode->first()->text(), $matches);
            $weight = isset($matches[0]) ? trim($matches[0]) : '';
        }

        $specifications = [];
        $crawler->filter('.woocommerce-product-attributes tr, .specifications tr, .specs tr, table.specs tr, table.attributes tr')->each(function (Crawler $row) use (&$specifications) {
            $label = $row->filter('th, .label, .spec-label');
            $value = $row->filter('td, .value, .spec-value');

            if ($label->count() > 0 && $value->count() > 0) {
                $key = trim($label->first()->text());
                $val = trim($value->first()->text());

                if (! empty($key) && ! empty($val)) {
                    $specifications[$key] = $val;
                }
            }
        });

        return [
            'name' => $name,
            'slug' => $slug,
            'url' => $productUrl,
            'categories' => $categories,
            'brand' => $brand,
            'sku' => $sku,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'price' => $price,
            'short_description' => $shortDescription,
            'full_description' => $fullDescription,
            'stock_status' => $stockStatus,
            'weight' => $weight,
            'attributes' => $attributes,
            'tags' => $tags,
            'specifications' => $specifications,
            'rating' => $rating,
            'review_count' => $reviewCount,
            'featured_image' => $featuredImage,
            'gallery_images' => $galleryImages,
        ];
    }

    protected function parsePagination(Crawler $crawler, int $currentPage): int
    {
        $totalPages = $currentPage;

        $pageLinks = $crawler->filter('.woocommerce-pagination .page-numbers, .pagination .page-numbers, .page-numbers li a, .pagination a.page-numbers');

        $pageLinks->each(function (Crawler $node) use (&$totalPages) {
            $text = trim($node->text());

            if (is_numeric($text)) {
                $pageNum = (int) $text;

                if ($pageNum > $totalPages) {
                    $totalPages = $pageNum;
                }
            }
        });

        $nextLink = $crawler->filter('.woocommerce-pagination .next, .pagination .next, .next.page-numbers');

        if ($currentPage > $totalPages && $nextLink->count() > 0) {
            $totalPages = $currentPage + 1;
        }

        if ($totalPages < $currentPage) {
            $totalPages = $currentPage;
        }

        return $totalPages;
    }

    protected function parseTotalProducts(Crawler $crawler): int
    {
        $countNode = $crawler->filter('.woocommerce-result-count, .result-count, .products-count, .total-products');

        if ($countNode->count() > 0) {
            $text = $countNode->first()->text();

            preg_match('/\d+/', str_replace(',', '', $text), $matches);

            if (isset($matches[0])) {
                $nums = [];
                preg_match_all('/\d+/', str_replace(',', '', $text), $nums);

                return isset($nums[0]) ? (int) end($nums[0]) : 0;
            }
        }

        return 0;
    }

    protected function cleanPrice(string $priceText): string
    {
        $priceText = strip_tags($priceText);
        $priceText = html_entity_decode($priceText, ENT_QUOTES, 'UTF-8');
        $priceText = preg_replace('/[^\d.,]/', '', $priceText);

        if (str_contains($priceText, '-') || str_contains($priceText, '–')) {
            $parts = preg_split('/[-–]/', $priceText);
            $priceText = trim(end($parts));
        }

        return trim($priceText);
    }

    protected function extractSlug(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! $path) {
            return '';
        }

        $path = rtrim($path, '/');
        $parts = explode('/', $path);
        $slug = end($parts);

        if ($slug === 'product' || $slug === 'shop') {
            return '';
        }

        return $slug;
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (empty($url) || $url === '#') {
            return '';
        }

        if (! str_starts_with($url, 'http')) {
            $url = $this->baseUrl.'/'.ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    protected function extractImageUrl(Crawler $node): string
    {
        $src = $node->attr('data-large_image');
        if ($src && $this->isValidImageUrl($src)) {
            return $src;
        }

        $src = $node->attr('data-src');
        if ($src && $this->isValidImageUrl($src)) {
            return $src;
        }

        $src = $node->attr('src');
        if ($src && $this->isValidImageUrl($src)) {
            return $src;
        }

        $src = $node->attr('data-lazy-src');
        if ($src && $this->isValidImageUrl($src)) {
            return $src;
        }

        $srcset = $node->attr('srcset');
        if ($srcset) {
            $parts = explode(',', $srcset);
            $largest = '';
            $largestW = 0;
            foreach ($parts as $part) {
                $part = trim($part);
                if (preg_match('/^(\S+)\s+(\d+)w$/', $part, $m)) {
                    if ((int) $m[2] > $largestW) {
                        $largestW = (int) $m[2];
                        $largest = $m[1];
                    }
                } elseif ($largest === '') {
                    $largest = $part;
                }
            }
            if ($largest && $this->isValidImageUrl($largest)) {
                return $largest;
            }
        }

        return '';
    }

    protected function isValidImageUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        $lower = strtolower($url);

        $placeholders = ['lazy.svg', 'placeholder', 'blank', 'transparent', '1x1', 'spacer', 'pixel'];
        foreach ($placeholders as $p) {
            if (str_contains($lower, $p)) {
                return false;
            }
        }

        $extensions = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.bmp', '.svg'];
        $hasImageExt = false;
        foreach ($extensions as $ext) {
            if (str_contains($lower, $ext)) {
                $hasImageExt = true;
                break;
            }
        }

        if (! $hasImageExt && ! str_contains($lower, '/uploads/') && ! str_contains($lower, '/wp-content/')) {
            return false;
        }

        return true;
    }

    public function fetchUrl(string $url): string
    {
        $client = $this->getClient();
        $attempts = 0;

        while ($attempts < $this->retryCount) {
            try {
                $response = $client->get($url);
                $body = (string) $response->getBody();

                if ($response->getStatusCode() === 200) {
                    if ($this->isCloudflareChallenge($body)) {
                        throw $this->cloudflareException();
                    }
                    usleep($this->delayMs * 1000);

                    return $body;
                }

                Log::warning("Product attempt {$attempts} failed for {$url}: HTTP {$response->getStatusCode()}");
                $attempts++;

                if ($attempts < $this->retryCount) {
                    usleep($this->delayMs * 1000);
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Exception $e) {
                Log::warning("Product connection attempt {$attempts} failed: {$e->getMessage()}");
                $attempts++;

                if ($attempts < $this->retryCount) {
                    usleep($this->delayMs * 1000);
                }
            }
        }

        throw new \RuntimeException("Failed to fetch {$url} after {$this->retryCount} attempts");
    }

    protected function cloudflareException(): \RuntimeException
    {
        return new \RuntimeException(
            'Target site is protected by Cloudflare anti-bot protection. '.
            'Standard HTTP requests are blocked. Cannot bypass JavaScript challenge. '.
            'Consider using a headless browser like Puppeteer or Selenium for this site.'
        );
    }

    protected function isCloudflareChallenge(string $html): bool
    {
        if (stripos($html, 'cf_chl_opt') !== false) {
            return true;
        }
        if (stripos($html, 'cf-browser-verification') !== false) {
            return true;
        }
        if (stripos($html, 'Just a moment') !== false && stripos($html, 'Enable JavaScript') !== false) {
            return true;
        }

        return false;
    }
}
