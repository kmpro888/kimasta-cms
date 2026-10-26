<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Seo\Entity\SeoMeta;

/**
 * Explicit SEO projection.
 *
 * Mirrors the finished `SeoMeta` the SEO service produces, so a client reads exactly
 * the values that will be rendered — it never reassembles them.
 */
final class SeoResource implements ResourceInterface
{
    /**
     * @param SeoMeta $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof SeoMeta) {
            throw new \InvalidArgumentException('SeoResource expects a SeoMeta value object.');
        }

        return [
            'title' => $subject->title,
            'description' => $subject->description,
            'canonical' => $subject->canonical,
            'robots' => $subject->robots(),
            'robots_index' => $subject->robotsIndex,
            'robots_follow' => $subject->robotsFollow,
            'og_title' => $subject->ogTitle,
            'og_description' => $subject->ogDescription,
            'og_image' => $subject->ogImage,
            'og_type' => $subject->ogType,
            'schema_type' => $subject->schemaType,
            'site_name' => $subject->siteName,
            'locale' => $subject->locale,
        ];
    }
}
