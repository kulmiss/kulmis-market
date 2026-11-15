<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Run a side effect at most once per key (webhooks, double-clicked submits,
 * retried jobs). The key row and the work commit in the same transaction.
 */
class Idempotency
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return array{0: bool, 1: mixed} [ranNow, result]
     */
    public static function once(string $scope, string $key, Closure $work): array
    {
        $full = $scope.':'.$key;
        $existing = DB::table('idempotency_keys')->where('key', $full)->first();
        if ($existing) {
            return [false, json_decode((string) $existing->result, true)];
        }

        try {
            return DB::transaction(function () use ($full, $scope, $work) {
                DB::table('idempotency_keys')->insert(['key' => $full, 'scope' => $scope, 'created_at' => now()]);
                $result = $work();
                DB::table('idempotency_keys')->where('key', $full)->update(['result' => json_encode($result)]);

                return [true, $result];
            });
        } catch (QueryException $e) {
            $existing = DB::table('idempotency_keys')->where('key', $full)->first();
            if ($existing) {
                return [false, json_decode((string) $existing->result, true)];
            }
            throw $e;
        }
    }
}
