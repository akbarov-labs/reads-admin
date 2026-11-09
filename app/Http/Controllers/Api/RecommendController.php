<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookResource;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The site's "recommend me a book" quiz: a few books for what the reader
 * picked, best first. Every answer is optional:
 *
 *   ?genre=    a category slug; a parent includes its children
 *   ?length=   short (under 200 pages) | medium (200–399) | long (400 and up)
 *   ?pick=     popular (the default) | new (newest edition first)
 *   ?page=     the next few, for "show me others"
 *
 * Only books with a cover are suggested: a list of grey placeholders does
 * not make anyone want to read.
 *
 * "Popular" is what readers have shown, on the site and in the bot:
 * book-page views over the last 90 days, plus 10 for every bot reader who
 * wants, is reading or has read it, plus 10 a star above three for every
 * rating (a five is +20, a one is −20).
 */
class RecommendController extends Controller
{
    private const PER_PAGE = 8;

    private const VIEW_DAYS = 90;

    private const PER_READER = 10;

    private const PER_STAR = 10;

    /** Pages, from and to inclusive; null is open. */
    private const LENGTHS = [
        'short' => [1, 199],
        'medium' => [200, 399],
        'long' => [400, null],
    ];

    private const LATEST_YEAR = '(select max(e.year) from editions e where e.book_id = books.id and e.status = ?)';

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'genre' => ['nullable', 'string', 'max:100'],
            'length' => ['nullable', 'in:'.implode(',', array_keys(self::LENGTHS))],
            'pick' => ['nullable', 'in:popular,new'],
        ]);

        $approved = ModerationStatus::Approved->value;

        $query = Book::approved()
            ->select('books.*')
            ->with(BookResource::relations())
            ->whereHas('editions', fn (Builder $e) => $e->approved()->whereNotNull('cover_path'));

        if (filled($slug = $request->query('genre'))) {
            $query->whereHas('categories', fn (Builder $c) => $c->where(fn (Builder $either) => $either->where('slug', $slug)
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('slug', $slug))));
        }

        if (filled($length = $request->query('length'))) {
            [$from, $to] = self::LENGTHS[$length];
            $query->whereHas('editions', fn (Builder $e) => $e->approved()
                ->where('pages', '>=', $from)
                ->when($to !== null, fn (Builder $upTo) => $upTo->where('pages', '<=', $to)));
        }

        $views = DB::table('book_views')
            ->select('book_id', DB::raw('sum(views) as recent_views'))
            ->where('day', '>=', now('Asia/Tashkent')->subDays(self::VIEW_DAYS)->toDateString())
            ->groupBy('book_id');

        $readers = DB::table('editions')
            ->select(
                'book_id',
                DB::raw('sum(reader_want_count + reader_reading_count + reader_read_count) as readers'),
                DB::raw('sum(reader_rating_total) - 3 * sum(reader_rating_count) as stars_above_three'),
            )
            ->where('status', $approved)
            ->groupBy('book_id');

        $query->leftJoinSub($views, 'recent', 'recent.book_id', '=', 'books.id')
            ->leftJoinSub($readers, 'bot', 'bot.book_id', '=', 'books.id');

        $score = 'coalesce(recent.recent_views, 0)'
            .' + '.self::PER_READER.' * coalesce(bot.readers, 0)'
            .' + '.self::PER_STAR.' * coalesce(bot.stars_above_three, 0)';

        if ($request->query('pick') === 'new') {
            $query->orderByRaw(self::LATEST_YEAR.' is null', [$approved])
                ->orderByRaw(self::LATEST_YEAR.' desc', [$approved]);
        }

        $query->orderByRaw($score.' desc')->orderByDesc('books.id');

        return BookResource::collection($query->paginate(self::PER_PAGE)->withQueryString());
    }
}
