<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CatalogueSync;
use App\Services\ReaderFeedbackSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The bots' doors into the catalogue: the Kitoblarim bot's, and the Kitob
 * Marafoni bot's (which also uses upsert). Behind the shared-token
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

    /** The marathon bot pushes a book with no ISBN; it waits for an editor here. */
    public function book(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'authors' => ['nullable', 'array', 'max:20'],
            'authors.*' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65000'],
        ]);

        return response()->json($this->sync->pushBook($data));
    }

    /** Every rating total the bot's readers gave, per ISBN; anonymous. */
    public function ratings(Request $request, ReaderFeedbackSync $feedback): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.isbn' => ['required', 'string', 'max:32'],
            'items.*.count' => ['required', 'integer', 'min:1'],
            'items.*.total' => ['required', 'integer', 'min:1'],
            // How the stars split, ones first; older bots leave it out.
            'items.*.stars' => ['sometimes', 'array', 'size:5'],
            'items.*.stars.*' => ['integer', 'min:0'],
        ]);

        return response()->json($feedback->ratings($data['items']));
    }

    /** How many of the bot's readers want to read, are reading and have read each ISBN. */
    public function readers(Request $request, ReaderFeedbackSync $feedback): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.isbn' => ['required', 'string', 'max:32'],
            'items.*.want' => ['required', 'integer', 'min:0'],
            'items.*.reading' => ['required', 'integer', 'min:0'],
            'items.*.read' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($feedback->readers($data['items']));
    }

    /** Every review the bot's readers chose to publish. */
    public function reviews(Request $request, ReaderFeedbackSync $feedback): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.id' => ['required', 'string', 'max:64'],
            'items.*.isbn' => ['required', 'string', 'max:32'],
            'items.*.rating' => ['nullable', 'integer', 'between:1,5'],
            'items.*.text' => ['required', 'string', 'max:5000'],
            'items.*.authorName' => ['nullable', 'string', 'max:60'],
            'items.*.writtenAt' => ['required', 'date'],
        ]);

        return response()->json($feedback->reviews($data['items']));
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
