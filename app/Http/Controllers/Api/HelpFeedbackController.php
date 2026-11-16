<?php

namespace App\Http\Controllers\Api;

use App\Help\HelpArticles;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** "Was this helpful?" Yes / No under a help article (counts only, nothing about the person). */
class HelpFeedbackController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        abort_unless(HelpArticles::exists($slug), 404);
        $col = $data['helpful'] ? 'yes' : 'no';
        DB::table('help_feedback')->insertOrIgnore(['slug' => $slug, 'yes' => 0, 'no' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('help_feedback')->where('slug', $slug)->update([$col => DB::raw("$col + 1"), 'updated_at' => now()]);
        HelpArticles::forget();

        return response()->json(['ok' => true, 'message' => $data['helpful'] ? 'Thank you!' : 'Thank you. We will make it better.']);
    }
}
