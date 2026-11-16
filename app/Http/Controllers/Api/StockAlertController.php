<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Shop\StockAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAlertController extends Controller
{
    /** "Tell me when it's back" (works for guests; the page is cached, so no session here). */
    public function store(Request $request, StockAlerts $alerts): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'contact' => ['required', 'string', 'max:160'],
            'website' => ['nullable', 'max:0'], // honeypot: people never fill it
        ]);
        $product = Product::query()->where('status', 'active')->findOrFail($data['product_id']);
        $variant = isset($data['variant_id']) ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($data['variant_id']) : null;
        $alerts->subscribe($product, $variant, $data['contact'], $request->user());

        return response()->json(['message' => 'Done. We will send you one message when it is back.'], 201);
    }
}
