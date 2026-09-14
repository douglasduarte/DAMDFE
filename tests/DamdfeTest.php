<?php

declare(strict_types=1);

namespace DAMDFE\Tests;

use DAMDFE\Config\DamdfeConfig;
use DAMDFE\Damdfe;
use PHPUnit\Framework\TestCase;

final class DamdfeTest extends TestCase
{
    public function test_it_renders_a_pdf_from_mdfe_xml(): void
    {
        $xml = file_get_contents(__DIR__ . '/fixtures/mdfe-minimal.xml');

        self::assertIsString($xml);

        $pdf = (new Damdfe($xml))->render();

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('DAMDFE', $pdf);
        self::assertGreaterThanOrEqual(2, substr_count($pdf, '/Subtype /Image'));
        self::assertStringContainsString('/Count 1', $pdf);
    }

    public function test_it_rejects_invalid_xml(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Damdfe('<invalid');
    }

    public function test_it_uses_dacte_compatible_visual_defaults(): void
    {
        $config = new DamdfeConfig();

        self::assertSame('Times', $config->font);
        self::assertSame(2.0, $config->marginTop);
        self::assertSame(2.0, $config->marginRight);
        self::assertSame(2.0, $config->marginBottom);
        self::assertSame(2.0, $config->marginLeft);
    }
}
