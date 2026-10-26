<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Image;

use CMS\Modules\Media\Contracts\ImageProcessorInterface;

/**
 * Image backend with runtime capability detection.
 *
 * Imagick is preferred when present; GD is the supported fallback. Shared
 * hosting frequently ships one and not the other, so nothing here may assume a
 * library exists — every entry point degrades instead of fatally erroring.
 *
 * Metadata note: GD's encoders never write EXIF, so re-encoding through GD is
 * itself a metadata strip. Imagick uses stripImage() explicitly.
 */
final class ImageProcessor implements ImageProcessorInterface
{
    /** Formats the pipeline is willing to emit. */
    private const FORMATS = ['jpeg', 'png', 'webp'];

    private readonly string $driver;

    public function __construct()
    {
        $this->driver = $this->detectDriver();
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isAvailable(): bool
    {
        return $this->driver !== 'none';
    }

    public function supportsFormat(string $format): bool
    {
        $format = strtolower($format);

        if ($format === 'original') {
            return true;
        }

        if (!in_array($format, self::FORMATS, true)) {
            return false;
        }

        return match ($this->driver) {
            'imagick' => $this->imagickSupports($format),
            'gd' => $this->gdSupports($format),
            default => false,
        };
    }

    public function inspect(string $absolutePath): ?array
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        if ($this->driver === 'imagick') {
            try {
                $image = new \Imagick($absolutePath);

                return [
                    'width' => $image->getImageWidth(),
                    'height' => $image->getImageHeight(),
                    'mime' => $image->getImageMimeType(),
                ];
            } catch (\Throwable) {
                // fall through to getimagesize
            }
        }

        $info = @getimagesize($absolutePath);

        if ($info === false) {
            return null;
        }

        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'mime' => (string) ($info['mime'] ?? ''),
        ];
    }

    public function transform(
        string $sourcePath,
        string $destinationPath,
        string $format,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        int $quality = 82,
    ): ?array {
        $format = strtolower($format);

        if (!is_file($sourcePath) || !$this->isAvailable()) {
            return null;
        }

        return match ($this->driver) {
            'imagick' => $this->transformWithImagick($sourcePath, $destinationPath, $format, $maxWidth, $maxHeight, $quality),
            'gd' => $this->transformWithGd($sourcePath, $destinationPath, $format, $maxWidth, $maxHeight, $quality),
            default => null,
        };
    }

    public function stripMetadata(string $absolutePath): bool
    {
        if (!is_file($absolutePath)) {
            return false;
        }

        if ($this->driver === 'imagick') {
            try {
                $image = new \Imagick($absolutePath);
                $image->stripImage();
                $image->writeImage($absolutePath);
                $image->clear();

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        if ($this->driver === 'gd') {
            // Re-encoding through GD drops every metadata block, so a plain
            // re-save is the strip.
            $info = @getimagesize($absolutePath);

            if ($info === false) {
                return false;
            }

            $format = match ((string) ($info['mime'] ?? '')) {
                'image/jpeg' => 'jpeg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };

            if ($format === null) {
                return false;
            }

            return $this->transformWithGd($absolutePath, $absolutePath, $format, null, null, 92) !== null;
        }

        return false;
    }

    private function detectDriver(): string
    {
        if (class_exists(\Imagick::class)) {
            return 'imagick';
        }

        if (extension_loaded('gd') && function_exists('imagecreatefromjpeg')) {
            return 'gd';
        }

        return 'none';
    }

    private function imagickSupports(string $format): bool
    {
        try {
            $formats = \Imagick::queryFormats(strtoupper($format));

            return $formats !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function gdSupports(string $format): bool
    {
        if (!function_exists('gd_info')) {
            return false;
        }

        $info = gd_info();

        return match ($format) {
            'jpeg' => !empty($info['JPEG Support']),
            'png' => !empty($info['PNG Support']),
            'webp' => !empty($info['WebP Support']) && function_exists('imagewebp'),
            default => false,
        };
    }

    /**
     * @return array{width: int, height: int, filesize: int}|null
     */
    private function transformWithImagick(
        string $sourcePath,
        string $destinationPath,
        string $format,
        ?int $maxWidth,
        ?int $maxHeight,
        int $quality,
    ): ?array {
        try {
            $image = new \Imagick($sourcePath);

            // Normalize orientation before resizing so a rotated phone photo is
            // not cropped the wrong way.
            $image->autoOrient();

            if ($maxWidth !== null || $maxHeight !== null) {
                $image->thumbnailImage(
                    $maxWidth ?? 0,
                    $maxHeight ?? 0,
                    true,
                    true
                );
            }

            $target = $format === 'original'
                ? strtolower($image->getImageFormat())
                : $format;

            $image->setImageFormat($target === 'jpg' ? 'jpeg' : $target);
            $image->setImageCompressionQuality($quality);
            $image->stripImage();
            $image->writeImage($destinationPath);

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $image->clear();

            return [
                'width' => $width,
                'height' => $height,
                'filesize' => (int) (filesize($destinationPath) ?: 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{width: int, height: int, filesize: int}|null
     */
    private function transformWithGd(
        string $sourcePath,
        string $destinationPath,
        string $format,
        ?int $maxWidth,
        ?int $maxHeight,
        int $quality,
    ): ?array {
        $info = @getimagesize($sourcePath);

        if ($info === false) {
            return null;
        }

        $sourceMime = (string) ($info['mime'] ?? '');

        $source = match ($sourceMime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : null,
            default => null,
        };

        if ($source === false || $source === null) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        [$targetWidth, $targetHeight] = $this->fit($width, $height, $maxWidth, $maxHeight);

        if ($targetWidth !== $width || $targetHeight !== $height) {
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);

            // Preserve transparency for png/webp instead of painting a black
            // background.
            if (in_array($format, ['png', 'webp'], true) || $sourceMime === 'image/png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);
            }

            imagecopyresampled(
                $resized,
                $source,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $width,
                $height
            );

            imagedestroy($source);
            $source = $resized;
        }

        $written = match ($format) {
            'jpeg' => imagejpeg($source, $destinationPath, $quality),
            'png' => imagepng($source, $destinationPath, 6),
            'webp' => function_exists('imagewebp') ? imagewebp($source, $destinationPath, $quality) : false,
            'original' => imagejpeg($source, $destinationPath, $quality),
            default => false,
        };

        imagedestroy($source);

        if ($written === false) {
            return null;
        }

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
            'filesize' => (int) (filesize($destinationPath) ?: 0),
        ];
    }

    /**
     * Scale down to fit inside the box, never up.
     *
     * @return array{0: int, 1: int}
     */
    private function fit(int $width, int $height, ?int $maxWidth, ?int $maxHeight): array
    {
        if ($width <= 0 || $height <= 0) {
            return [max(1, $width), max(1, $height)];
        }

        $ratio = 1.0;

        if ($maxWidth !== null && $maxWidth > 0 && $width > $maxWidth) {
            $ratio = min($ratio, $maxWidth / $width);
        }

        if ($maxHeight !== null && $maxHeight > 0 && $height > $maxHeight) {
            $ratio = min($ratio, $maxHeight / $height);
        }

        if ($ratio >= 1.0) {
            return [$width, $height];
        }

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }
}
