<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageDownloaderService
{
    protected string $disk;

    protected string $directory;

    protected array $allowedTypes;

    protected int $timeout;

    protected int $retryCount;

    protected int $retryDelay;

    public function __construct()
    {
        $this->disk = 'public';
        $this->directory = config('scraper.image_directory', 'products');
        $this->allowedTypes = config('scraper.allowed_image_types', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $this->timeout = config('scraper.timeout', 30);
        $this->retryCount = config('scraper.retry_count', 3);
        $this->retryDelay = config('scraper.retry_delay', 1000);
    }

    public function download(string $imageUrl, string $productSlug, ?int $index = null): ?array
    {
        if (! $this->isValidUrl($imageUrl)) {
            Log::warning("Invalid image URL: {$imageUrl}");

            return null;
        }

        $extension = $this->getExtension($imageUrl);

        if (! in_array(strtolower($extension), $this->allowedTypes)) {
            Log::warning("Unsupported image type: {$extension} for URL: {$imageUrl}");

            return null;
        }

        $filename = $this->generateFilename($productSlug, $extension, $index);

        $savePath = $this->directory . '/' . $productSlug;

        $fullPath = $savePath . '/' . $filename;

        if (Storage::disk($this->disk)->exists($fullPath)) {
            return [
                'path' => $fullPath,
                'url' => Storage::disk($this->disk)->url($fullPath),
                'filename' => $filename,
            ];
        }

        $attempts = 0;

        $client = new Client([
            'verify' => false,
            'timeout' => $this->timeout,
            'cookies' => new CookieJar(),
            'headers' => [
                'User-Agent' => config('scraper.user_agent'),
            ],
            'allow_redirects' => true,
        ]);

        while ($attempts < $this->retryCount) {
            try {
                $response = $client->get($imageUrl);

                if ($response->getStatusCode() === 200) {
                    $imageContent = (string) $response->getBody();

                    if ($this->isValidImage($imageContent)) {
                        Storage::disk($this->disk)->makeDirectory($savePath);

                        Storage::disk($this->disk)->put($fullPath, $imageContent);

                        Log::info("Downloaded image: {$imageUrl} -> {$fullPath}");

                        return [
                            'path' => $fullPath,
                            'url' => Storage::disk($this->disk)->url($fullPath),
                            'filename' => $filename,
                        ];
                    }
                }

                $attempts++;

                if ($attempts < $this->retryCount) {
                    usleep($this->retryDelay * 1000);
                }
            } catch (\Exception $e) {
                Log::error("Connection failed for image {$imageUrl}: {$e->getMessage()}");

                $attempts++;

                if ($attempts < $this->retryCount) {
                    usleep($this->retryDelay * 1000);
                }
            }
        }

        Log::error("Failed to download image after {$this->retryCount} attempts: {$imageUrl}");

        return null;
    }

    public function downloadGallery(array $imageUrls, string $productSlug): array
    {
        $images = [];

        foreach ($imageUrls as $index => $imageUrl) {
            $result = $this->download($imageUrl, $productSlug, $index);

            if ($result) {
                $images[] = $result;
            }

            if (count($imageUrls) > 1) {
                usleep(config('scraper.delay_between_requests', 500) * 1000);
            }
        }

        return $images;
    }

    protected function generateFilename(string $productSlug, string $extension, ?int $index = null): string
    {
        $suffix = $index !== null ? '-' . ($index + 1) : '-1';
        $hash = substr(md5($productSlug . $suffix . time()), 0, 8);

        return Str::slug($productSlug) . $suffix . '-' . $hash . '.' . $extension;
    }

    protected function getExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! $path) {
            return 'jpg';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $extension = preg_replace('/[?#].*$/', '', $extension);

        if (empty($extension) || strlen($extension) > 5) {
            return 'jpg';
        }

        return $extension;
    }

    protected function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && Str::startsWith($url, ['http://', 'https://']);
    }

    protected function isValidImage(string $content): bool
    {
        if (empty($content) || strlen($content) < 100) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);

        return str_starts_with($mime, 'image/');
    }
}
