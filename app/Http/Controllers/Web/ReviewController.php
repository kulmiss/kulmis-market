<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Shop\Reviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReviewController extends Controller
{
    public function store(Request $request, Reviews $reviews, Product $product): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:80'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.Reviews::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ], ['body.min' => 'Please write a little more (at least 10 letters).']);
        try {
            $reviews->submit($user, $product, $data, $request->file('photos', []));
        } catch (RuntimeException $e) {
            return back()->withErrors(['photos' => 'One photo could not be read. Try another one.'])->withInput();
        }

        return redirect('/shop/'.$product->slug.'#reviews')->with('status', 'Thank you! Your review shows after a quick check.');
    }
}
