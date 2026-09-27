<?php

namespace Tests\Concerns;

use App\Enums\BookRole;
use App\Enums\UserRole;
use App\Models\Author;
use App\Models\Book;
use App\Models\Edition;
use App\Models\Publisher;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds a book the way the catalogue now stores one: the work, its
 * authors, and an edition carrying the publisher, year, cover and credits.
 */
trait BuildsCatalogue
{
    protected function translatorWithAccount(string $slug = 'ilhom', string $name = 'Ilhom'): Translator
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$slug}@example.test",
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        return Translator::create(['user_id' => $user->id, 'slug' => $slug, 'name' => $name, 'years_active' => 5]);
    }

    protected function author(string $name = 'Ernest Hemingway'): Author
    {
        return Author::firstOrCreate(['name' => $name], ['slug' => Str::slug($name)]);
    }

    protected function publisher(string $name = 'Yangi asr avlodi'): Publisher
    {
        return Publisher::firstOrCreate(['name' => $name], ['slug' => Str::slug($name)]);
    }

    /**
     * @param  array<string, mixed>  $book  Book columns
     * @param  array<string, mixed>  $edition  Edition columns
     * @param  list<Author>|null  $authors  null for Hemingway, [] for none
     * @param  array<int, array{0: Translator, 1: BookRole}|Translator>  $credits
     */
    protected function catalogueBook(array $book = [], array $edition = [], ?array $authors = null, array $credits = []): Book
    {
        $work = Book::create([
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'source_language' => 'en',
            ...$book,
        ]);

        foreach (array_values($authors ?? [$this->author()]) as $position => $author) {
            $work->authors()->attach($author->id, ['position' => $position]);
        }

        $this->edition($work, $edition, $credits);

        return $work;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{0: Translator, 1: BookRole}|Translator>  $credits
     */
    protected function edition(Book $book, array $attributes = [], array $credits = []): Edition
    {
        $edition = $book->editions()->create(['year' => 1952, ...$attributes]);

        foreach (array_values($credits) as $position => $credit) {
            [$translator, $role] = is_array($credit) ? $credit : [$credit, BookRole::Translator];
            $edition->translators()->attach($translator->id, ['role' => $role->value, 'position' => $position]);
        }

        return $edition;
    }
}
