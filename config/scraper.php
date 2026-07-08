<?php

return [

    'base_url' => env('SCRAPER_BASE_URL', 'https://kcbazar.com'),

    'timeout' => env('SCRAPER_TIMEOUT', 30),

    'retry_count' => env('SCRAPER_RETRY_COUNT', 3),

    'retry_delay' => env('SCRAPER_RETRY_DELAY', 1000),

    'user_agent' => env('SCRAPER_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'),

    'delay_between_requests' => env('SCRAPER_DELAY_MS', 500),

    'image_directory' => env('SCRAPER_IMAGE_DIR', 'products'),

    'cache_ttl' => env('SCRAPER_CACHE_TTL', 3600),

    'max_pages' => env('SCRAPER_MAX_PAGES', 0),

    'download_images' => env('SCRAPER_DOWNLOAD_IMAGES', false),

    'allowed_image_types' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],

];
