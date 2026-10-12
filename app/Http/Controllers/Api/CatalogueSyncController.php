<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CatalogueSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Kitoblarim bot's two doors into the catalogue. Behind the shared-token
 * middleware (routes/api.php), never in the public read group.
 */
class CatalogueSyncController extends Controller
{
    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    public function __construct(private readonly CatalogueSync $sync) {}

    /** The bot pushes a book its moderators approved. */
    public function upsert(Request $request, string $isbn): JsonResponse
    {
        $normalized = CatalogueSync::normalizeIsbn($isbn);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'isbn' => 'The ISBN must be 13 digits. ISBN-10 is not supported yet.',
            ]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'authors' => ['nullable', 'array', 'max:20'],
            'authors.*' => ['nullable', 'string', 'max:255'],
            'translators' => ['nullable', 'array', 'max:20'],
            'translators.*' => ['nullable', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1000,9999'],
            // The columns are unsigned smallints.
            'pages' => ['nullable', 'integer', 'between:1,65535'],
            'description' => ['nullable', 'string', 'max:65000'],
            'updatedAt' => ['required', 'date'],
            // The bot has never synced this book: only fill our blanks.
            'fillOnly' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->sync->push($normalized, $data));
    }

    /** The bot pulls what changed here, and does its first backfill the same way. */
    public function index(Request $request): JsonResponse
    {
        // A "+00:00" offset pasted into a URL arrives as " 00:00".
        if (is_string($since = $request->query('since'))) {
            $request->query->set('since', preg_replace('/ (\d{2}:?\d{2})$/', '+$1', trim($since)));
        }

        $query = $request->validate([
            'since' => ['nullable', 'date'],
            'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer'],
        ]);

        $limit = min(self::MAX_LIMIT, max(1, (int) ($query['limit'] ?? self::DEFAULT_LIMIT)));

        return response()->json($this->sync->feed(
            isset($query['since']) ? CarbonImmutable::parse($query['since']) : null,
            isset($query['after']) ? (int) $query['after'] : null,
            $limit,
        ));
    }
}
