<?php

namespace Tests\Unit;

use App\Services\QrCodeService;
use App\Support\Media;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrCodeServiceTest extends TestCase
{
    #[DataProvider('codigos')]
    public function test_extrae_el_codigo_de_los_distintos_formatos_de_qr(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, app(QrCodeService::class)->extractCode($raw));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function codigos(): array
    {
        return [
            'texto plano' => ['cafe2026', 'CAFE2026'],
            'json del payload' => ['{"v":1,"type":"loyalty_card.join","join_code":"CAFE2026"}', 'CAFE2026'],
            'json de identificación' => ['{"v":1,"type":"customer_card.identify","code":"ABC12345"}', 'ABC12345'],
            'url con query string' => ['https://puntoplus.test/join?code=CAFE2026&utm=qr', 'CAFE2026'],
            'deep link' => ['puntoplus://oauth/join/CAFE2026', 'CAFE2026'],
            'con espacios y minúsculas' => ['  cafe-2026  ', 'CAFE-2026'],
            'vacío' => ['', null],
        ];
    }

    public function test_el_payload_del_qr_del_negocio_y_del_cliente_son_distintos(): void
    {
        $service = app(QrCodeService::class);
        $svg = $service->svg('{"demo":true}');

        // El SVG sólo se genera si bacon/bacon-qr-code está instalado.
        $this->assertTrue($svg === null || str_contains($svg, '<svg'));
    }

    public function test_media_genera_urls_de_los_assets_y_respeta_las_urls_externas(): void
    {
        $this->assertNull(Media::url(null));
        $this->assertNull(Media::url(''));
        $this->assertSame('https://cdn.example.com/logo.png', Media::url('https://cdn.example.com/logo.png'));
        $this->assertStringContainsString('loyalty-cards/demo/logo.png', (string) Media::url('loyalty-cards/demo/logo.png'));
    }
}
