<?php

namespace App\Services;

use App\Models\Business;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Eye\SimpleCircleEye;
use BaconQrCode\Renderer\Eye\SquareEye;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Module\DotsModule;
use BaconQrCode\Renderer\Module\RoundnessModule;
use BaconQrCode\Renderer\Module\SquareModule;
use BaconQrCode\Renderer\RendererStyle\EyeFill;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Genera el QR público y descargable de un negocio a partir de sus opciones de marca.
 */
final class BusinessQrService
{
    /** @return array<string, mixed> */
    public function settings(Business $business): array
    {
        return array_merge(
            config('punto_plus.business_qr.defaults', []),
            $business->qr_settings ?? [],
        );
    }

    public function targetUrl(Business $business, ?array $settings = null): string
    {
        $settings ??= $this->settings($business);

        return match ($settings['destination_type'] ?? 'menu') {
            'website' => (string) $business->website,
            'custom' => (string) ($settings['custom_url'] ?? ''),
            default => url('/api/public/business/'.$business->slug.'/menu'),
        };
    }

    /** Genera el SVG de vista previa con el estilo configurado. */
    public function svg(Business $business): string
    {
        $settings = $this->settings($business);
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(
                (int) $settings['size'],
                (int) $settings['quiet_zone'],
                $this->module($settings['module_style']),
                ($settings['eye_style'] ?? 'square') === 'circle'
                    ? SimpleCircleEye::instance()
                    : SquareEye::instance(),
                $this->fill($settings),
            ),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($this->targetUrl($business, $settings), 'UTF-8', ErrorCorrectionLevel::H());

        if (! ($settings['include_logo'] ?? false) || blank($business->logo_path)) {
            return $svg;
        }

        $logo = $this->logoContents($business);
        $image = $logo === '' ? false : @getimagesizefromstring($logo);
        $mime = is_array($image) ? ($image['mime'] ?? null) : null;
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return $svg;
        }

        $size = (int) $settings['size'];
        $logoSize = (int) round($size * ((int) $settings['logo_scale'] / 100));
        $padding = max(6, (int) round($logoSize * 0.12));
        $boxSize = $logoSize + (2 * $padding);
        $position = (int) floor(($size - $boxSize) / 2);
        $encodedLogo = 'data:'.$mime.';base64,'.base64_encode($logo);
        $svgLogo = '<rect x="'.$position.'" y="'.$position.'" width="'.$boxSize.'" height="'.$boxSize.'" rx="'.(int) ($padding / 2).'" fill="#FFFFFF" />'
            .'<image x="'.($position + $padding).'" y="'.($position + $padding).'" width="'.$logoSize.'" height="'.$logoSize.'" preserveAspectRatio="xMidYMid meet" href="'.$encodedLogo.'" />';

        return preg_replace('~</svg>\s*$~', $svgLogo.'</svg>', $svg) ?? $svg;
    }

    /**
     * Rasteriza una versión PNG o WebP. GD se usa directamente para conservar
     * los estilos de módulos y la marca del negocio sin depender de Imagick.
     */
    public function raster(Business $business, string $format): string
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('La generación de QR rasterizados requiere la extensión GD.');
        }

        if ($format === 'webp' && ! function_exists('imagewebp')) {
            throw new RuntimeException('El servidor no tiene soporte WebP habilitado en GD.');
        }

        if (! in_array($format, ['png', 'webp'], true)) {
            throw new RuntimeException('Formato de QR no soportado.');
        }

        $settings = $this->settings($business);
        $qr = Encoder::encode($this->targetUrl($business, $settings), ErrorCorrectionLevel::H(), 'UTF-8');
        $matrix = $qr->getMatrix();
        $dimension = $matrix->getWidth();
        $size = (int) $settings['size'];
        $margin = (int) $settings['quiet_zone'];
        $totalModules = $dimension + (2 * $margin);
        $canvas = imagecreatetruecolor($size, $size);

        if ($canvas === false) {
            throw new RuntimeException('No se pudo reservar memoria para generar el QR.');
        }

        $background = $this->gdColor($canvas, $settings['background_color']);
        $foreground = $this->gdColor($canvas, $settings['foreground_color']);
        $eyeColor = $this->gdColor($canvas, $settings['eye_color']);
        imagefill($canvas, 0, 0, $background);

        for ($y = 0; $y < $dimension; $y++) {
            for ($x = 0; $x < $dimension; $x++) {
                if (! $matrix->get($x, $y) || $this->isFinderCell($x, $y, $dimension)) {
                    continue;
                }

                [$left, $top, $right, $bottom] = $this->cellBounds($x, $y, $size, $totalModules, $margin);
                $this->drawModule($canvas, $foreground, $left, $top, $right, $bottom, $settings['module_style']);
            }
        }

        $eyeStyle = $settings['eye_style'] ?? 'square';
        $this->drawFinder($canvas, 0, 0, $size, $totalModules, $margin, $eyeColor, $background, $eyeStyle);
        $this->drawFinder($canvas, $dimension - 7, 0, $size, $totalModules, $margin, $eyeColor, $background, $eyeStyle);
        $this->drawFinder($canvas, 0, $dimension - 7, $size, $totalModules, $margin, $eyeColor, $background, $eyeStyle);

        if (($settings['include_logo'] ?? false) && filled($business->logo_path)) {
            $this->drawLogo($canvas, $business, $settings, $size);
        }

        ob_start();
        $written = $format === 'webp'
            ? imagewebp($canvas, null, 95)
            : imagepng($canvas, null, 6);
        $contents = ob_get_clean();
        imagedestroy($canvas);

        if (! $written || ! is_string($contents) || $contents === '') {
            throw new RuntimeException('No se pudo codificar el QR en el formato solicitado.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $settings */
    private function module(string $style): SquareModule|DotsModule|RoundnessModule
    {
        return match ($style) {
            'dots' => new DotsModule(DotsModule::MEDIUM),
            'rounded' => new RoundnessModule(RoundnessModule::SOFT),
            default => SquareModule::instance(),
        };
    }

    /** @param array<string, mixed> $settings */
    private function fill(array $settings): Fill
    {
        $eye = $this->rgb($settings['eye_color']);

        return Fill::withForegroundColor(
            $this->rgb($settings['background_color']),
            $this->rgb($settings['foreground_color']),
            EyeFill::uniform($eye),
            EyeFill::uniform($eye),
            EyeFill::uniform($eye),
        );
    }

    private function rgb(string $color): Rgb
    {
        return new Rgb(hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2)));
    }

    private function gdColor(\GdImage $image, string $color): int
    {
        return imagecolorallocate(
            $image,
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
        );
    }

    private function isFinderCell(int $x, int $y, int $dimension): bool
    {
        return ($x < 7 && $y < 7)
            || ($x >= $dimension - 7 && $y < 7)
            || ($x < 7 && $y >= $dimension - 7);
    }

    /** @return array{int, int, int, int} */
    private function cellBounds(int $x, int $y, int $size, int $totalModules, int $margin): array
    {
        return [
            (int) floor(($x + $margin) * $size / $totalModules),
            (int) floor(($y + $margin) * $size / $totalModules),
            (int) floor(($x + $margin + 1) * $size / $totalModules) - 1,
            (int) floor(($y + $margin + 1) * $size / $totalModules) - 1,
        ];
    }

    private function drawModule(\GdImage $image, int $color, int $left, int $top, int $right, int $bottom, string $style): void
    {
        if ($style === 'dots') {
            $width = max(1, (int) round(($right - $left + 1) * 0.78));
            $height = max(1, (int) round(($bottom - $top + 1) * 0.78));
            imagefilledellipse($image, (int) round(($left + $right) / 2), (int) round(($top + $bottom) / 2), $width, $height, $color);

            return;
        }

        if ($style === 'rounded') {
            $this->drawRoundedBox($image, $left, $top, $right, $bottom, $color);

            return;
        }

        imagefilledrectangle($image, $left, $top, $right, $bottom, $color);
    }

    private function drawFinder(\GdImage $image, int $moduleX, int $moduleY, int $size, int $totalModules, int $margin, int $eye, int $background, string $style): void
    {
        $left = (int) floor(($moduleX + $margin) * $size / $totalModules);
        $top = (int) floor(($moduleY + $margin) * $size / $totalModules);
        $right = (int) floor(($moduleX + 7 + $margin) * $size / $totalModules) - 1;
        $bottom = (int) floor(($moduleY + 7 + $margin) * $size / $totalModules) - 1;
        $innerLeft = (int) floor(($moduleX + 1 + $margin) * $size / $totalModules);
        $innerTop = (int) floor(($moduleY + 1 + $margin) * $size / $totalModules);
        $innerRight = (int) floor(($moduleX + 6 + $margin) * $size / $totalModules) - 1;
        $innerBottom = (int) floor(($moduleY + 6 + $margin) * $size / $totalModules) - 1;
        $coreLeft = (int) floor(($moduleX + 2 + $margin) * $size / $totalModules);
        $coreTop = (int) floor(($moduleY + 2 + $margin) * $size / $totalModules);
        $coreRight = (int) floor(($moduleX + 5 + $margin) * $size / $totalModules) - 1;
        $coreBottom = (int) floor(($moduleY + 5 + $margin) * $size / $totalModules) - 1;

        if ($style === 'rounded') {
            $this->drawRoundedBox($image, $left, $top, $right, $bottom, $eye);
            $this->drawRoundedBox($image, $innerLeft, $innerTop, $innerRight, $innerBottom, $background);
            $this->drawRoundedBox($image, $coreLeft, $coreTop, $coreRight, $coreBottom, $eye);

            return;
        }

        if ($style === 'circle') {
            imagefilledellipse($image, (int) round(($left + $right) / 2), (int) round(($top + $bottom) / 2), $right - $left + 1, $bottom - $top + 1, $eye);
            imagefilledellipse($image, (int) round(($innerLeft + $innerRight) / 2), (int) round(($innerTop + $innerBottom) / 2), $innerRight - $innerLeft + 1, $innerBottom - $innerTop + 1, $background);
            imagefilledellipse($image, (int) round(($coreLeft + $coreRight) / 2), (int) round(($coreTop + $coreBottom) / 2), $coreRight - $coreLeft + 1, $coreBottom - $coreTop + 1, $eye);

            return;
        }

        imagefilledrectangle($image, $left, $top, $right, $bottom, $eye);
        imagefilledrectangle($image, $innerLeft, $innerTop, $innerRight, $innerBottom, $background);
        imagefilledrectangle($image, $coreLeft, $coreTop, $coreRight, $coreBottom, $eye);
    }

    private function drawRoundedBox(\GdImage $image, int $left, int $top, int $right, int $bottom, int $color): void
    {
        $radius = (int) floor(min($right - $left + 1, $bottom - $top + 1) * 0.24);
        if ($radius < 1) {
            imagefilledrectangle($image, $left, $top, $right, $bottom, $color);

            return;
        }

        imagefilledrectangle($image, $left + $radius, $top, $right - $radius, $bottom, $color);
        imagefilledrectangle($image, $left, $top + $radius, $right, $bottom - $radius, $color);
        imagefilledellipse($image, $left + $radius, $top + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $right - $radius, $top + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $left + $radius, $bottom - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $right - $radius, $bottom - $radius, $radius * 2, $radius * 2, $color);
    }

    /** @param array<string, mixed> $settings */
    private function drawLogo(\GdImage $canvas, Business $business, array $settings, int $size): void
    {
        $contents = $this->logoContents($business);
        $logo = @imagecreatefromstring($contents);
        if ($logo === false) {
            return;
        }

        $logoSize = max(1, (int) round($size * ((int) $settings['logo_scale'] / 100)));
        $padding = max(6, (int) round($logoSize * 0.12));
        $boxSize = $logoSize + (2 * $padding);
        $left = (int) floor(($size - $boxSize) / 2);
        $top = $left;
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $this->drawRoundedBox($canvas, $left, $top, $left + $boxSize, $top + $boxSize, $white);

        imagealphablending($canvas, true);
        imagealphablending($logo, true);
        imagecopyresampled(
            $canvas,
            $logo,
            $left + $padding,
            $top + $padding,
            0,
            0,
            $logoSize,
            $logoSize,
            imagesx($logo),
            imagesy($logo),
        );

        imagedestroy($logo);
    }

    private function logoContents(Business $business): string
    {
        if (blank($business->logo_path)) {
            return '';
        }

        try {
            $contents = Storage::disk(config('punto_plus.assets.disk', 'public'))->get($business->logo_path);

            return is_string($contents) ? $contents : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
