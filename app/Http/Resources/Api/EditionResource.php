<?php

namespace App\Http\Resources\Api;

use App\Models\Edition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One printing of a book, as listed on the book's page.
 *
 * @mixin Edition
 */
class EditionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'isbn' => $this->isbn,
            'publisher' => $this->publisher ? [
                'slug' => $this->publisher->slug,
                'name' => $this->publisher->name,
                'logoUrl' => $this->publisher->logo_url,
            ] : null,
            'translators' => TranslatorCreditResource::collection($this->translators),
            'year' => $this->year,
            'language' => $this->language,
            'script' => $this->script,
            'pages' => $this->pages,
            'coverType' => $this->cover_type,
            'coverMaterial' => $this->cover_material,
            'paperFormat' => $this->paper_format,
            'pageSurface' => $this->page_surface,
            'printCountry' => $this->print_country,
            'weight' => $this->weight,
            'dimensions' => $this->dimensions,
            'volumeCount' => $this->volume_count,
            'volumePart' => $this->volume_part,
            'coverUrl' => $this->cover_url,
            'images' => $this->whenLoaded('images', fn () => $this->images->pluck('url')->values()),
            'description' => $this->description,
            'excerpt' => $this->hasExcerpt() ? self::excerpt($this->resource) : null,
        ];
    }

    /** @return array<string, ?string> */
    public static function excerpt(Edition $edition): array
    {
        return [
            'sourceLanguage' => $edition->excerpt_source_language,
            'sourceText' => $edition->excerpt_source_text,
            'translatedText' => $edition->excerpt_translated_text,
        ];
    }
}
