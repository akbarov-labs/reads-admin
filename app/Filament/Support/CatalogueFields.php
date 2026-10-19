<?php

namespace App\Filament\Support;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Filament\Resources\TranslatorResource;
use App\Models\Author;
use App\Models\Book;
use App\Models\Edition;
use App\Models\Publisher;
use App\Models\Translator;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Form pieces shared by every screen that adds or edits a book or an
 * edition — the admin's Books resource, a translator's portfolio, a
 * publisher's list and the taqriz form — so the four cannot drift apart.
 *
 * The recurring shape: pick the book (the work) first, creating it inline if
 * nobody has entered it yet, then describe this edition of it.
 */
class CatalogueFields
{
    /** "Katta o'yin — Piter Hopkirk" */
    public static function bookLabel(Book $book): string
    {
        $authors = $book->authors->pluck('name')->implode(', ');

        return $authors === '' ? $book->uzbek_title : "{$book->uzbek_title} — {$authors}";
    }

    /** "Katta o'yin — Piter Hopkirk · Asaxiy Books 2023 (Shodiyor Nishonboyev)" */
    public static function editionLabel(Edition $edition): string
    {
        $parts = [self::bookLabel($edition->book)];
        $print = trim(($edition->publisher?->name ?? '').' '.($edition->year ?? ''));

        if ($print !== '') {
            $parts[] = '· '.$print;
        }

        $translators = $edition->translators->pluck('name')->implode(', ');

        if ($translators !== '') {
            $parts[] = "({$translators})";
        }

        return implode(' ', $parts);
    }

    /**
     * The book this edition is of: search the catalogue, or add the work
     * inline. New works made here get `$newBookStatus`, so a taqrizchi's
     * additions wait for an admin while a translator's go straight in.
     */
    public static function bookSelect(ModerationStatus $newBookStatus = ModerationStatus::Approved): Forms\Components\Select
    {
        return Forms\Components\Select::make('book_id')
            ->label('Book')
            ->relationship('book', 'uzbek_title')
            ->getOptionLabelFromRecordUsing(fn (Book $record) => self::bookLabel($record))
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => self::searchBooks($search))
            ->required()
            ->helperText('Search by title or author. Not there yet? Add it — then fill in this edition below.')
            ->createOptionForm(self::bookFields())
            ->createOptionUsing(fn (array $data): int => self::createBook($data, $newBookStatus)->getKey());
    }

    /**
     * The work itself: what every edition of it has in common.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function bookFields(): array
    {
        return [
            Forms\Components\TextInput::make('uzbek_title')
                ->label('Title')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('original_title')
                ->label('Original title')
                ->helperText('In the language it was written in, if that differs.')
                ->maxLength(255),
            self::authorsSelect(),
            Forms\Components\TextInput::make('source_language')
                ->label('Original language')
                ->maxLength(255),
        ];
    }

    /**
     * Authors by id, with inline create. Not a ->relationship() select: it is
     * also used inside createOptionForm, where there is no book yet to
     * relate to. createBook() attaches the chosen ids.
     */
    public static function authorsSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('author_ids')
            ->label('Authors')
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Author::query()
                ->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('original_name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->limit(50)
                ->pluck('name', 'id')
                ->all())
            ->getOptionLabelsUsing(fn (array $values) => Author::whereKey($values)->pluck('name', 'id')->all())
            ->createOptionForm([
                Forms\Components\TextInput::make('name')->label('Author name')->required()->maxLength(255),
            ])
            ->createOptionUsing(fn (array $data): int => Author::create([
                'name' => $data['name'],
                'slug' => Author::generateUniqueSlug($data['name']),
            ])->getKey());
    }

    /** @param  array<string, mixed>  $data */
    public static function createBook(array $data, ModerationStatus $status): Book
    {
        return DB::transaction(function () use ($data, $status) {
            $book = Book::create([
                'uzbek_title' => $data['uzbek_title'],
                'original_title' => $data['original_title'] ?? null,
                'source_language' => $data['source_language'] ?? null,
                'status' => $status,
            ]);

            foreach (array_values($data['author_ids'] ?? []) as $position => $authorId) {
                $book->authors()->attach($authorId, ['position' => $position]);
            }

            return $book;
        });
    }

    /** @return array<int, string> */
    public static function searchBooks(string $search): array
    {
        return Book::query()
            ->where(fn (Builder $q) => $q->where('uzbek_title', 'like', "%{$search}%")
                ->orWhere('original_title', 'like', "%{$search}%")
                ->orWhereHas('authors', fn (Builder $a) => $a->where('name', 'like', "%{$search}%")))
            ->with('authors')
            ->orderBy('uzbek_title')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Book $book) => [$book->id => self::bookLabel($book)])
            ->all();
    }

    public static function publisherSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('publisher_id')
            ->label('Publisher')
            ->relationship('publisher', 'name')
            ->searchable()
            ->createOptionForm([
                Forms\Components\TextInput::make('name')->label('Publisher name')->required()->maxLength(255),
            ])
            ->createOptionUsing(fn (array $data): int => Publisher::create([
                'name' => $data['name'],
                'slug' => Publisher::generateUniqueSlug($data['name']),
            ])->getKey());
    }

    /**
     * Who worked on the edition and as what, in credit order. A translator
     * not in the list can be added by name; they become an account-less
     * profile an admin can later give a login (see TranslatorObserver).
     */
    public static function creditsRepeater(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('credits')
            ->label('Translators & editors')
            ->relationship()
            ->orderColumn('position')
            ->defaultItems(0)
            ->addActionLabel('Add a translator')
            ->schema([
                Forms\Components\Select::make('translator_id')
                    ->label('Person')
                    ->getSearchResultsUsing(fn (string $search) => Translator::where('name', 'like', "%{$search}%")->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Translator::whereKey($value)->value('name'))
                    ->searchable()
                    ->required()
                    ->distinct()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->label('Translator name')->required()->maxLength(255),
                    ])
                    ->createOptionUsing(fn (array $data): int => Translator::create([
                        'name' => $data['name'],
                        'slug' => Translator::generateUniqueSlug($data['name']),
                    ])->getKey()),
                Forms\Components\Select::make('role')
                    ->options(BookRole::class)
                    ->default(BookRole::Translator)
                    ->required(),
            ])
            ->columns(2)
            ->columnSpanFull();
    }

    /**
     * This printing: the cover and the details on its copyright page.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function editionFields(): array
    {
        return [
            Forms\Components\TextInput::make('title')
                ->label('Title on this edition')
                ->helperText("Only if it differs from the book's title — e.g. a Russian edition.")
                ->maxLength(255),
            Forms\Components\TextInput::make('isbn')
                ->label('ISBN')
                ->maxLength(17)
                // Stored as digits only, so "978-9943-23-198-6" and
                // "9789943231986" are the same edition.
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? strtoupper(preg_replace('/[^0-9Xx]/', '', $state)) : null)
                ->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('year')
                ->numeric()
                ->minValue(Edition::FIRST_YEAR)
                ->maxValue(fn (): int => Edition::lastYear()),
            Forms\Components\TextInput::make('language')
                ->label('Language of this edition')
                ->placeholder("O'zbek tili"),
            Forms\Components\FileUpload::make('cover_path')
                ->label('Cover image')
                ->image()
                // @see TranslatorResource::SAFE_IMAGE_TYPES — no SVG.
                ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                ->maxSize(4096)
                ->disk('public')
                ->directory('covers')
                ->imageEditor()
                ->imageEditorAspectRatios(['2:3'])
                ->columnSpanFull(),
            Forms\Components\Section::make('Print details (optional)')
                ->schema([
                    Forms\Components\TextInput::make('pages')->numeric()->minValue(1)->maxValue(65535),
                    Forms\Components\Select::make('script')->options(['Lotin' => 'Lotin', 'Kirill' => 'Kirill', 'Kirill, Lotin' => 'Kirill, Lotin']),
                    Forms\Components\TextInput::make('cover_type')->label('Binding')->placeholder('Yumshoq / Qattiq'),
                    Forms\Components\TextInput::make('cover_material')->label('Cover material'),
                    Forms\Components\TextInput::make('paper_format')->label('Paper format')->placeholder('A5'),
                    Forms\Components\TextInput::make('page_surface')->label('Page surface')->placeholder('Jilosiz / Yaltiroq'),
                    Forms\Components\TextInput::make('print_country')->label('Printed in'),
                    Forms\Components\TextInput::make('weight'),
                    Forms\Components\TextInput::make('dimensions'),
                    Forms\Components\TextInput::make('volume_count')->label('Volumes in set')->numeric()->minValue(1),
                    Forms\Components\TextInput::make('volume_part')->label('This volume')->numeric()->minValue(1),
                    Forms\Components\Textarea::make('description')->label("Publisher's description")->rows(4)->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(),
        ];
    }

    public static function excerptSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Side-by-side excerpt (optional)')
            ->description('Shown in the "Compare the text" section of the translator\'s public profile.')
            ->schema([
                Forms\Components\TextInput::make('excerpt_source_language')
                    ->label('Source language label'),
                Forms\Components\Textarea::make('excerpt_source_text')
                    ->label('Original text')
                    ->rows(4),
                Forms\Components\Textarea::make('excerpt_translated_text')
                    ->label('Translated text')
                    ->rows(4),
            ])
            ->columns(1)
            ->columnSpanFull()
            ->collapsible()
            ->collapsed();
    }

    public static function statusSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('status')
            ->options(ModerationStatus::class)
            ->required()
            ->default(ModerationStatus::Approved)
            ->visible(fn () => auth()->user()?->isAdmin() ?? false);
    }
}
