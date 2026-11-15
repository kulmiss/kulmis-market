<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Query strings carry single values only: `?sort[]=x` or `?phone[]=1` are
 * dropped instead of crashing a page (500) and flooding the logs, and
 * `per_page` is kept between 1 and 200 so nobody can ask for a million rows.
 */
class NormalizeQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query->all();
        $clean = array_filter($query, fn ($v) => ! is_array($v));
        if (array_key_exists('per_page', $clean)) {
            $clean['per_page'] = (string) max(1, min(200, (int) $clean['per_page']));
        }
        if ($clean !== $query) {
            $request->query->replace($clean);
            // input() reads the merged bag: drop the same keys there (form bodies are not touched).
            foreach (array_diff_key($query, $clean) as $key => $_) {
                if (! $request->request->has($key)) {
                    $request->offsetUnset($key);
                }
            }
            if (isset($clean['per_page']) && ! $request->request->has('per_page')) {
                $request->merge(['per_page' => $clean['per_page']]);
            }
        }

        return $next($request);
    }
}
