<?php

declare(strict_types=1);

namespace DAMDFE\Tests;

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
    }

    public function test_it_rejects_invalid_xml(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Damdfe('<invalid');
    }
}
