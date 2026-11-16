<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DownloadGrant;
use App\Shop\DigitalDelivery;
use App\Support\Features;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    public function __invoke(string $token): StreamedResponse|Response
    {
        abort_if(Features::disabled('shop.digital'), 404);
        $grant = DownloadGrant::query()->with(['file', 'order'])->where('token_hash', hash('sha256', $token))->first();
        abort_if(! $grant || ! DigitalDelivery::usable($grant), 410, 'This link has ended. Open My orders for a new one, or contact us.');
        // Count first, atomically: two clicks at once can't go over the limit.
        $counted = DownloadGrant::query()->whereKey($grant->id)->whereColumn('downloads', '<', 'max_downloads')->increment('downloads');
        abort_if($counted === 0, 410, 'This link has been used up.');
        $file = $grant->file;
        if ($file->kind === 'video') {
            return response()->view('store.video-link', ['file' => $file, 'url' => $file->video_url], 200)
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }
        abort_unless($file->path && Storage::disk('local')->exists($file->path), 404);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->title).'.'.pathinfo((string) $file->path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($file->path, $name, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
