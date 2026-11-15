<?php

namespace App\Demo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Demo rows disappear everywhere while "Show demo data" is off, and rows a
 * demo customer creates (or that belong to a demo user) are demo too.
 *
 * @mixin Model
 */
trait HidesDemo
{
    public static function bootHidesDemo(): void
    {
        static::addGlobalScope('demo', function (Builder $q) {
            if (! Demo::visible()) {
                $q->where($q->getModel()->qualifyColumn('is_demo'), false);
            }
        });
        static::creating(function (Model $m) {
            if ($m->getAttribute('is_demo')) {
                return;
            }
            if (Demo::actingDemoCustomer() || static::belongsToDemo($m)) {
                $m->setAttribute('is_demo', true);
            }
        });
    }

    protected static function belongsToDemo(Model $m): bool
    {
        foreach (static::demoOwnerColumns() as $col) {
            if ($m->getAttribute($col) !== null && Demo::isDemoUser((int) $m->getAttribute($col))) {
                return true;
            }
        }
        foreach (static::demoParents() as $col => $table) {
            $id = $m->getAttribute($col);
            if ($id !== null && DB::table($table)->where('id', $id)->value('is_demo')) {
                return true;
            }
        }
        $morph = static::demoMorphParent();
        if ($morph !== null && $m->getAttribute($morph.'_type') && $m->getAttribute($morph.'_id')) {
            $class = Relation::getMorphedModel((string) $m->getAttribute($morph.'_type')) ?? (string) $m->getAttribute($morph.'_type');
            if (class_exists($class)) {
                $table = (new $class)->getTable();
                if (in_array($table, Demo::TABLES, true)) {
                    return (bool) DB::table($table)->where('id', $m->getAttribute($morph.'_id'))->value('is_demo');
                }
            }
        }

        return false;
    }

    /** @return list<string> columns pointing at the user who owns the row */
    protected static function demoOwnerColumns(): array
    {
        return ['user_id'];
    }

    /** @return array<string, string> column => parent table */
    protected static function demoParents(): array
    {
        return [];
    }

    /** Morph relation name whose parent decides (e.g. "payable"). */
    protected static function demoMorphParent(): ?string
    {
        return null;
    }
}
