<?php

namespace App\Models\Concerns;

use App\Support\SearchKeys;

/**
 * Keeps `search_key` in step with the names it is built from. Done in
 * save() rather than a `saving` listener because the catalogue sync and the
 * Asaxiy import write inside Model::withoutEvents(), and every row they
 * create needs a key too.
 */
trait HasSearchKey
{
    public function save(array $options = []): bool
    {
        if (! $this->exists || $this->isDirty(SearchKeys::SOURCES[static::class])) {
            $this->search_key = SearchKeys::of($this);
        }

        return parent::save($options);
    }
}
