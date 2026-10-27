<?php

declare(strict_types=1);

namespace CMS\Modules\Advertising\Support;

/**
 * Which placements this installation can actually render, and why the rest cannot.
 *
 * The advertising model has always had seven placements and the theme has always printed none of
 * them: `renderPlacements()` filled a view-model key that no template read. That is the worst kind
 * of feature — configurable in the admin, invisible on the site.
 *
 * The registry is the honest inventory. A placement is either rendered by the active theme, or it is
 * named here with the reason it is not, so the admin screen can say so instead of offering a choice
 * that does nothing.
 */
final class PlacementRegistry
{
    /** Every placement the model knows. */
    public const ALL = [
        'header',
        'sidebar',
        'before_article',
        'middle_article',
        'after_article',
        'sticky',
        'footer',
    ];

    /**
     * Placements the default theme prints.
     *
     * The theme is single-column with a site header, an article body rendered from one HTML string,
     * and a footer. Those facts decide the list.
     */
    public const SUPPORTED = [
        'header',
        'before_article',
        'after_article',
        'footer',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    /**
     * @return array<string, string> placement => why the theme cannot render it
     */
    public static function unsupported(): array
    {
        return [
            'sidebar' => 'This theme is single-column and has no sidebar region. Printing an ad in a '
                . 'column that does not exist would mean inventing layout the theme does not have.',
            'middle_article' => 'The article body is rendered from one HTML string, so there is no '
                . 'mid-body boundary to split at. Splitting the markup would break a block that '
                . 'spans paragraphs.',
            'sticky' => 'The theme has a static header and no sticky container. A fixed overlay would '
                . 'be a different visual design, not a placement in this one.',
        ];
    }

    public static function isSupported(string $placement): bool
    {
        return in_array($placement, self::SUPPORTED, true);
    }

    public static function isKnown(string $placement): bool
    {
        return in_array($placement, self::ALL, true);
    }
}
