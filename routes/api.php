<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;

Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/products', [CategoryController::class, 'products']);
Route::get('categories/{slug}', [CategoryController::class, 'show'])->where('slug', '.*');
Route::get('categories/{slug}/products', [CategoryController::class, 'products'])->where('slug', '.*');

Route::get('products/latest', [ProductController::class, 'latest']);
Route::get('products/featured', [ProductController::class, 'featured']);
Route::get('products/search', [ProductController::class, 'search']);
Route::get('products/{slug}', [ProductController::class, 'show'])->where('slug', '.*');

Route::get('search', [ProductController::class, 'search']);
