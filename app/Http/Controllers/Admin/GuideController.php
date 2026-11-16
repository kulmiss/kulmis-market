<?php

namespace App\Http\Controllers\Admin;

use App\Help\AdminGuide;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The Super Admin guide inside the admin: list + search, one page, and its pictures. */
class GuideController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($p) => [
            'slug' => $p['slug'], 'title' => $p['title'], 'part' => $p['part'], 'pages' => $p['pages'],
            'text' => mb_substr(AdminGuide::plain($p['body']), 0, 4000),
        ], AdminGuide::pages())]);
    }

    public function show(string $slug): JsonResponse
    {
        $page = AdminGuide::find($slug);
        abort_if($page === null, 404);

        return response()->json(['data' => ['slug' => $slug, 'title' => $page['title'], 'part' => $page['part'], 'html' => AdminGuide::html($page['body'])]]);
    }

    public function pdf(): Response
    {
        return response(AdminGuide::pdf(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="admin-guide.pdf"', 'Cache-Control' => 'no-store, private']);
    }

    public function image(string $file): BinaryFileResponse
    {
        $path = base_path('docs/screenshots/'.$file);
        abort_unless(preg_match('/^[a-z0-9-]+\.(jpg|png)$/', $file) && is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
}
