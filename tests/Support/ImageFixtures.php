<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Generates real image files for upload tests.
 *
 * Tests must exercise the actual MIME inspection and the actual GD/Imagick
 * pipeline, so fixtures are real encodable images rather than fake byte strings.
 * When GD is unavailable the helpers return null and callers skip.
 */
final class ImageFixtures
{
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public static function makePng(string $path, int $width = 400, int $height = 300): ?string
    {
        return self::make($path, 'png', $width, $height);
    }

    public static function makeJpeg(string $path, int $width = 400, int $height = 300): ?string
    {
        return self::make($path, 'jpeg', $width, $height);
    }

    public static function makeWebp(string $path, int $width = 400, int $height = 300): ?string
    {
        if (!function_exists('imagewebp')) {
            return null;
        }

        return self::make($path, 'webp', $width, $height);
    }

    /**
     * An image carrying an EXIF-ish comment marker, for metadata tests.
     */
    public static function makeJpegWithComment(string $path, int $width = 200, int $height = 150): ?string
    {
        if (!self::available()) {
            return null;
        }

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            return null;
        }

        $colour = imagecolorallocate($image, 200, 60, 60);
        imagefilledrectangle($image, 0, 0, $width, $height, $colour);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }

    private static function make(string $path, string $format, int $width, int $height): ?string
    {
        if (!self::available()) {
            return null;
        }

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            return null;
        }

        $background = imagecolorallocate($image, 30, 110, 190);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $accent = imagecolorallocate($image, 240, 200, 40);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 3), (int) ($height / 3), $accent);

        $written = match ($format) {
            'jpeg' => imagejpeg($image, $path, 88),
            'webp' => function_exists('imagewebp') ? imagewebp($image, $path, 85) : false,
            default => imagepng($image, $path),
        };

        imagedestroy($image);

        return $written === false ? null : $path;
    }
}
