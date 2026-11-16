<?php

use App\Support\PageTextUpdates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Terms, privacy and rules pages: the original sentences get {if feature} parts (your own changes are kept). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('pages')->get(['id', 'body']) as $page) {
            $new = PageTextUpdates::apply((string) $page->body);
            if ($new !== $page->body) {
                DB::table('pages')->where('id', $page->id)->update(['body' => $new, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
