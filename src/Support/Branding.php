<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Colors\Color;
use AlfaSix\Alfakit\Support\Colors\CssColor;
use AlfaSix\Alfakit\Support\Url;
use Closure;
use Throwable;

/**
 * How documents look: the seller's name and logo, the accent colour and the font. Every
 * value defaults to the panel's own branding (`brandName()`, `brandLogo()`, the primary
 * colour and `font()`); `InvoicesPlugin::branding()` overrides them.
 */
final class Branding
{
    public const DEFAULT_COLOR = '#4f46e5';

    public function __construct(
        public readonly string $name,
        public readonly ?string $logo = null,
        public readonly string $color = self::DEFAULT_COLOR,
        public readonly ?string $font = null,
    ) {}

    /**
     * @param  array{name?: string|null, logo?: string|null, color?: string|null, font?: string|null}  $overrides
     */
    public static function fromPanel(?Panel $panel, array $overrides = []): self
    {
        $name = $overrides['name'] ?? null;
        $logo = $overrides['logo'] ?? null;
        $color = $overrides['color'] ?? null;
        $font = $overrides['font'] ?? null;

        if ($panel !== null) {
            try {
                $name ??= $panel->getBrandName();
            } catch (Throwable) {
                // Branding closures that need a request fall back to the app name.
            }

            $logo ??= self::panelLogo($panel);
            $color ??= self::panelColor($panel);

            try {
                $font ??= $panel->getFont();
            } catch (Throwable) {
                $font = null;
            }
        }

        $color = is_string($color) ? self::hex($color) : null;
        $logo = is_string($logo) && $logo !== '' ? Url::sanitize($logo) : null;
        $font = is_string($font) && preg_match('/^[\pL\pN _-]{1,64}$/u', $font) === 1 ? $font : null;

        return new self(
            is_string($name) && $name !== '' ? $name : (string) config('app.name', 'AlfaKit'),
            $logo,
            $color ?? self::DEFAULT_COLOR,
            $font,
        );
    }

    /** A CSS `font-family` value with safe fallbacks. */
    public function fontStack(bool $rtl = false): string
    {
        $fallback = $rtl ? '"Noto Naskh Arabic", "Geeza Pro", Tahoma, "DejaVu Sans", sans-serif' : '"Helvetica Neue", Helvetica, Arial, "DejaVu Sans", sans-serif';

        return $this->font === null ? $fallback : '"'.$this->font.'", '.$fallback;
    }

    /** Readable text on the accent colour. */
    public function contrastColor(): string
    {
        try {
            return Color::isLight($this->color) ? '#111827' : '#ffffff';
        } catch (Throwable) {
            return '#ffffff';
        }
    }

    private static function panelLogo(Panel $panel): ?string
    {
        try {
            // The panel keeps its logo (a string or a closure) to itself until it serialises.
            $logo = Closure::bind(fn (): mixed => $this->evaluate($this->brandLogo), $panel, Panel::class)();
        } catch (Throwable) {
            return null;
        }

        return is_string($logo) && ! str_starts_with(ltrim($logo), '<') ? $logo : null;
    }

    private static function panelColor(Panel $panel): ?string
    {
        try {
            $primary = $panel->getColors()['primary'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (is_array($primary)) {
            $primary = $primary[600] ?? $primary[500] ?? reset($primary);
        }

        return is_string($primary) ? $primary : null;
    }

    private static function hex(string $color): ?string
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $color) === 1) {
            return strtolower($color);
        }

        try {
            $safe = CssColor::sanitize($color);
            $hex = $safe === null ? null : Color::convertToHex($safe);
        } catch (Throwable) {
            return null;
        }

        return is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1 ? strtolower($hex) : null;
    }
}
