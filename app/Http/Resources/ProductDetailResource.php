<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this['name'] ?? '',
            'slug' => $this['slug'] ?? '',
            'url' => $this['url'] ?? '',
            'categories' => $this['categories'] ?? [],
            'brand' => $this['brand'] ?? null,
            'sku' => $this['sku'] ?? null,
            'regular_price' => $this['regular_price'] ?? '',
            'sale_price' => $this['sale_price'] ?? '',
            'price' => $this['price'] ?? '',
            'short_description' => $this['short_description'] ?? '',
            'full_description' => $this['full_description'] ?? '',
            'stock_status' => $this['stock_status'] ?? 'in_stock',
            'weight' => $this['weight'] ?? null,
            'attributes' => $this['attributes'] ?? [],
            'tags' => $this['tags'] ?? [],
            'specifications' => $this['specifications'] ?? [],
            'rating' => (float) ($this['rating'] ?? 0),
            'review_count' => (int) ($this['review_count'] ?? 0),
            'featured_image' => $this['featured_image'] ?? '',
            'featured_image_local' => $this['featured_image_local'] ?? null,
            'gallery_images' => $this['gallery_images'] ?? [],
            'gallery_images_local' => $this['gallery_images_local'] ?? [],
        ];
    }
}
