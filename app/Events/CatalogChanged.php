<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when anything shown on public pages changes (page cache purge). */
class CatalogChanged
{
    use Dispatchable;

    public function __construct(public ?Model $model = null) {}
}
