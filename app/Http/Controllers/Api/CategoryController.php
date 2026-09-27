<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * The category tree, top-level first, each with how many published
     * books sit on it directly. Empty branches are left out.
     */
    public function index(): JsonResponse
    {
        $categories = Category::withCount(['books' => fn ($books) => $books->approved()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $byParent = $categories->groupBy(fn (Category $c) => $c->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent): array {
            return ($byParent[$parentId] ?? collect())
                ->map(fn (Category $c) => [
                    'slug' => $c->slug,
                    'name' => $c->name,
                    'bookCount' => $c->books_count,
                    'children' => $build($c->id),
                ])
                ->filter(fn (array $node) => $node['bookCount'] > 0 || $node['children'] !== [])
                ->values()
                ->all();
        };

        return response()->json(['data' => $build(0)]);
    }
}
