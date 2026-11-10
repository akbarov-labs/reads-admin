<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product page of an online store, as last read (see the
 * store_listings migration).
 */
class StoreListing extends Model
{
    protected $fillable = [
        'store',
        'url',
        'isbn',
        'title',
        'author',
        'publisher',
        'pages',
        'language',
        'price',
        'in_stock',
        'stock_left',
        'image_url',
        'edition_id',
        'lastmod',
        'fetched_at',
        'applied_at',
        'filled',
        'differences',
    ];

    protected function casts(): array
    {
        return [
            'pages' => 'integer',
            'price' => 'integer',
            'in_stock' => 'boolean',
            'stock_left' => 'integer',
            'lastmod' => 'datetime',
            'fetched_at' => 'datetime',
            'applied_at' => 'datetime',
            'filled' => 'array',
            'differences' => 'array',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }
}
