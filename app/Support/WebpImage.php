<?php

namespace App\Support;

use GdImage;

/** Image bytes as WebP, the format the catalogue keeps its covers in. */
class WebpImage
{
    /** WebP bytes, or null when the bytes are not an image GD can read. */
    public static function from(string $bytes, int $quality = 82): ?string
    {
        // Already WebP: re-encoding would only lose quality.
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return $bytes;
        }

        // GD built without WebP (the production image was, until it got
        // --with-webp) has no imagewebp at all.
        if (! function_exists('imagewebp')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, $quality);
        $webp = ob_get_clean();

        return $ok && $webp !== '' && $webp !== false ? $webp : null;
    }
}
