<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's numeric limits ("throttle:10,1") count every route with the same
 * numbers in ONE bucket per visitor, so adding to the cart could block a
 * checkout. This keeps a separate counter per route (and per visitor).
 * Named limiters ("throttle:login") are unchanged.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route ? implode(',', $route->methods()).' '.$route->uri() : '';

        return sha1($scope.'|'.parent::resolveRequestSignature($request));
    }
}
