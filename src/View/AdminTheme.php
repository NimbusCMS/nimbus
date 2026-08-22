<?php

declare(strict_types=1);

namespace Nimbus\View;

/**
 * The admin theme allow-list (ADR-free; see docs/design/admin-experience.md).
 *
 * A theme slug selects a `[data-theme="…"]` token block in the inlined stylesheet
 * and rides on `<html data-theme>`. Because the slug flows user-input → DB →
 * an HTML attribute *and* a CSS selector, it is validated by **allow-list at both
 * ends** — {@see sanitize()} is called at write (the picker) and again at render
 * (the layout), so a value written out of band (a direct DB edit, a slug removed
 * in a later release) still resolves to a known, safe theme. Output is
 * additionally HTML-escaped; the allow-list is the primary control.
 */
final class AdminTheme
{
    public const DEFAULT = 'nimbus';

    /**
     * The selectable themes: slug => display metadata for the picker. The keys
     * are the allow-list. Add a theme here and give it a `[data-theme]` block in
     * `theme.css`; nothing else changes.
     *
     * @var array<string,array{name:string,mood:string,gradient:string}>
     */
    public const THEMES = [
        'nimbus'   => [
            'name'     => 'Nimbus',
            'mood'     => 'Night sky & gold — the default.',
            'gradient' => 'linear-gradient(180deg,#1b1547,#2c1e63)',
        ],
        'nocturne' => [
            'name'     => 'Nocturne',
            'mood'     => 'The observatory at 2 a.m. — full dark.',
            'gradient' => 'linear-gradient(180deg,#0b0920,#1b1547)',
        ],
        'daybreak' => [
            'name'     => 'Daybreak',
            'mood'     => 'The broom at dawn — high blue & sun-gold.',
            'gradient' => 'linear-gradient(180deg,#123f74,#2d7bd0)',
        ],
        'grimoire' => [
            'name'     => 'Grimoire',
            'mood'     => 'A candlelit spellbook library — parchment & brass.',
            'gradient' => 'linear-gradient(180deg,#17251d,#274a38)',
        ],
    ];

    /** A stored/submitted slug resolved to a known theme — anything unknown → the default. */
    public static function sanitize(?string $slug): string
    {
        return $slug !== null && isset(self::THEMES[$slug]) ? $slug : self::DEFAULT;
    }

    /** True when the slug is a real, selectable theme (no default fallback). */
    public static function isKnown(?string $slug): bool
    {
        return $slug !== null && isset(self::THEMES[$slug]);
    }
}
