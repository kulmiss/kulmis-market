<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Sorting for admin and member tables: ?sort=amount or ?sort=-amount.
 * Only the columns in $map can be sorted (name the table shows => database
 * column); anything else falls back to $default. Ties are broken by id so
 * pages never repeat or skip a row.
 */
final class TableQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $map
     * @return Builder<TModel>
     */
    public static function sort(Builder $query, Request $request, array $map, string $default = '-id'): Builder
    {
        $sort = $request->query('sort');
        $sort = is_string($sort) && isset($map[ltrim($sort, '-')]) ? $sort : $default;
        $key = ltrim($sort, '-');
        $column = $map[$key] ?? $key;
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy($column, $dir);
        if ($column !== 'id') {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), $dir);
        }

        return $query;
    }
}
