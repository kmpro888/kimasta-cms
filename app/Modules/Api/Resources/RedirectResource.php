<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

/**
 * Explicit redirect projection.
 *
 * The service returns plain arrays for redirects, so this resource normalises them
 * into the same shape as every other resource: named keys, typed values, no
 * pass-through of whatever the repository happened to select.
 */
final class RedirectResource implements ResourceInterface
{
    /**
     * @param array{source_path: string, target_url: string, status_code: int} $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!is_array($subject)) {
            throw new \InvalidArgumentException('RedirectResource expects a redirect row.');
        }

        return [
            'source_path' => (string) ($subject['source_path'] ?? ''),
            'target_url' => (string) ($subject['target_url'] ?? ''),
            'status_code' => (int) ($subject['status_code'] ?? 301),
        ];
    }
}
