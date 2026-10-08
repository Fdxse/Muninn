<?php

declare(strict_types=1);

namespace Muninn\Api\Attachments;

/**
 * Recognises the image formats Muninn accepts, from the file's own bytes only (SECURITY.md:
 * never trust the browser's MIME type or the filename).
 *
 * Accepted: PNG, JPEG, GIF and WebP. SVG is deliberately NOT accepted: it is XML that can carry
 * scripts. Detection needs no PHP extension: the magic bytes are compared here and
 * getimagesizefromstring() (part of PHP's standard library) must agree and read the dimensions,
 * so a file that merely starts with the right bytes is still refused.
 */
final class ImageInspector
{
    /** Largest width or height accepted, which also bounds the memory a browser needs to show it. */
    public const MAXIMUM_DIMENSION_PIXELS = 12_000;

    /** Media type => [magic byte prefix, offset of the prefix, PHP IMAGETYPE_* constant, extension]. */
    private const ACCEPTED_FORMATS = [
        'image/png' => ["\x89PNG\r\n\x1A\n", 0, IMAGETYPE_PNG, 'png'],
        'image/jpeg' => ["\xFF\xD8\xFF", 0, IMAGETYPE_JPEG, 'jpg'],
        'image/gif' => ['GIF8', 0, IMAGETYPE_GIF, 'gif'],
        'image/webp' => ['WEBP', 8, IMAGETYPE_WEBP, 'webp'],
    ];

    /**
     * Returns what the bytes are, or null when they are not one of the accepted image formats
     * (or are an image too large to accept).
     *
     * @return array{media_type: string, extension: string, width: int, height: int}|null
     */
    public static function inspect(string $fileBytes): ?array
    {
        foreach (self::ACCEPTED_FORMATS as $mediaType => [$magicPrefix, $prefixOffset, $phpImageType, $extension]) {
            if (substr($fileBytes, $prefixOffset, strlen($magicPrefix)) !== $magicPrefix) {
                continue;
            }
            // WebP is a RIFF container: "RIFF" + size + "WEBP".
            if ($mediaType === 'image/webp' && !str_starts_with($fileBytes, 'RIFF')) {
                return null;
            }

            $imageInfo = @getimagesizefromstring($fileBytes);
            if ($imageInfo === false || $imageInfo[2] !== $phpImageType) {
                return null;
            }
            [$widthPixels, $heightPixels] = $imageInfo;
            if ($widthPixels < 1 || $heightPixels < 1
                || $widthPixels > self::MAXIMUM_DIMENSION_PIXELS || $heightPixels > self::MAXIMUM_DIMENSION_PIXELS) {
                return null;
            }

            return ['media_type' => $mediaType, 'extension' => $extension, 'width' => $widthPixels, 'height' => $heightPixels];
        }

        return null;
    }
}
