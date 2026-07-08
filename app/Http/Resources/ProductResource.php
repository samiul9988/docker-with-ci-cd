<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this['name'] ?? '',
            'slug' => $this['slug'] ?? '',
            'url' => $this['url'] ?? '',
            'image' => $this['image'] ?? '',
            'regular_price' => $this['regular_price'] ?? '',
            'sale_price' => $this['sale_price'] ?? '',
            'price' => $this['price'] ?? '',
            'rating' => (float) ($this['rating'] ?? 0),
            'stock_status' => $this['stock_status'] ?? 'in_stock',
        ];
    }
}
