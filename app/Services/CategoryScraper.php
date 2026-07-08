<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class CategoryScraper
{
    protected string $baseUrl;

    protected string $userAgent;

    protected int $timeout;

    protected int $retryCount;

    protected int $delayMs;

    protected ?Client $httpClient = null;

    protected ?CookieJar $cookieJar = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('scraper.base_url'), '/');
        $this->userAgent = config('scraper.user_agent');
        $this->timeout = config('scraper.timeout', 30);
        $this->retryCount = config('scraper.retry_count', 3);
        $this->delayMs = config('scraper.delay_between_requests', 500);
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

    public function scrape(): array
    {
        Log::info('Starting category scraping from '.$this->baseUrl);

        try {
            $html = $this->fetchUrl($this->baseUrl);
        } catch (\Exception $e) {
            Log::error('Failed to fetch base URL: '.$e->getMessage());

            return [];
        }

        $crawler = new Crawler($html);

        $categories = [];

        $categoryLinks = $crawler->filter('a[href*="/product-category/"]');

        if ($categoryLinks->count() === 0) {
            $categoryLinks = $crawler->filter('.product-categories a, .categories-menu a, .product-category a, nav a[href*="category"]');
        }

        if ($categoryLinks->count() === 0) {
            $categoryLinks = $crawler->filter('a')->reduce(function (Crawler $node) {
                return str_contains($node->attr('href'), '/product-category/');
            });
        }

        $seen = [];

        $categoryLinks->each(function (Crawler $node) use (&$categories, &$seen) {
            $url = $this->normalizeUrl($node->attr('href'));

            if (! $url || in_array($url, $seen)) {
                return;
            }

            if ($this->hasQueryFilters($url)) {
                return;
            }

            $name = trim($node->text());

            if (empty($name)) {
                return;
            }

            $slug = $this->extractSlug($url);

            $seen[] = $url;

            $categories[] = [
                'id' => count($categories) + 1,
                'name' => $name,
                'slug' => $slug,
                'url' => $url,
            ];
        });

        $categories = array_values(array_filter($categories, function ($cat) {
            return ! empty($cat['slug']) && $cat['slug'] !== 'product-category';
        }));

        Log::info('Scraped '.count($categories).' categories');

        return $categories;
    }

    public function scrapeBrands(): array
    {
        Log::info('Scraping brands from shop page');

        $shopUrl = $this->baseUrl.'/shop/';

        try {
            $html = $this->fetchUrl($shopUrl);
        } catch (\Exception $e) {
            Log::error('Failed to fetch shop page: '.$e->getMessage());

            return [];
        }

        $crawler = new Crawler($html);

        $brands = [];
        $seen = [];

        $crawler->filter('a[href*="/attribute/brand/"]')->each(function (Crawler $node) use (&$brands, &$seen) {
            $url = $this->normalizeUrl($node->attr('href'));

            if (! $url || in_array($url, $seen)) {
                return;
            }

            $name = trim($node->text());

            if (empty($name)) {
                return;
            }

            $path = parse_url($url, PHP_URL_PATH);
            $path = trim($path, '/');
            $parts = explode('/', $path);
            $slug = end($parts);

            $seen[] = $url;

            $brands[] = [
                'id' => count($brands) + 1,
                'name' => $name,
                'slug' => $slug,
                'url' => $url,
            ];
        });

        Log::info('Scraped '.count($brands).' brands');

        return $brands;
    }

    public function scrapeFromShopPage(): array
    {
        Log::info('Starting category scraping from shop page');

        $shopUrl = $this->baseUrl.'/shop/';

        try {
            $html = $this->fetchUrl($shopUrl);
        } catch (\Exception $e) {
            Log::error('Failed to fetch shop page: '.$e->getMessage());

            return [];
        }

        $crawler = new Crawler($html);

        $categories = [];
        $seen = [];

        $crawler->filter('a')->each(function (Crawler $node) use (&$categories, &$seen) {
            $href = $node->attr('href');

            if (! $href) {
                return;
            }

            if (str_contains($href, '/product-category/')) {
                $url = $this->normalizeUrl($href);

                if (! $url || in_array($url, $seen)) {
                    return;
                }

                if ($this->hasQueryFilters($url)) {
                    return;
                }

                $name = trim($node->text());

                if (empty($name)) {
                    return;
                }

                $slug = $this->extractSlug($url);

                $seen[] = $url;

                $categories[] = [
                    'id' => count($categories) + 1,
                    'name' => $name,
                    'slug' => $slug,
                    'url' => $url,
                ];
            }
        });

        $categories = array_values(array_filter($categories, function ($cat) {
            return ! empty($cat['slug']) && $cat['slug'] !== 'product-category';
        }));

        Log::info('Scraped '.count($categories).' categories from shop page');

        return $categories;
    }

    protected function extractSlug(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! $path) {
            return '';
        }

        $path = trim($path, '/');
        $prefix = 'product-category/';

        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        if (empty($path)) {
            return '';
        }

        return $path;
    }

    protected function extractCategoryPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! $path) {
            return '';
        }

        $path = trim($path, '/');
        $prefix = 'product-category/';

        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        return $path ?: '';
    }

    protected function hasQueryFilters(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return ! empty($query);
    }

    protected function normalizeCategoryUrl(string $url): string
    {
        $url = $this->normalizeUrl($url);
        $pos = strpos($url, '?');

        if ($pos !== false) {
            $url = substr($url, 0, $pos);
        }

        return $url;
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

        $url = rtrim($url, '/');

        return $url;
    }

    protected function fetchUrl(string $url): string
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

                Log::warning("Attempt {$attempts} failed for {$url}: HTTP {$response->getStatusCode()}");
                $attempts++;

                if ($attempts < $this->retryCount) {
                    usleep($this->delayMs * 1000);
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Exception $e) {
                Log::warning("Connection attempt {$attempts} failed: {$e->getMessage()}");
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
