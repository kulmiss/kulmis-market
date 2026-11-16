<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SearchController extends Controller
{
    /** Top-bar search: grouped results the signed-in staff member may open. */
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'type' => ['nullable', Rule::in(GlobalSearch::TYPES)],
            'status' => ['nullable', 'string', 'max:30', 'regex:/^[a-z_]+$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'min' => ['nullable', 'integer', 'min:0'],
            'max' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $search->search($request->user(), $data['q'], $data)]);
    }
}
