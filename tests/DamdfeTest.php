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

    public function test_it_identifies_documents_without_fiscal_value_only_when_required(): void
    {
        $xml = file_get_contents(__DIR__ . '/fixtures/mdfe-minimal.xml');

        self::assertIsString($xml);

        $pageContent = static function (string $variant): string {
            $pdf = (new Damdfe($variant))->render();
            self::assertSame(1, preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $stream));
            $content = gzuncompress($stream[1]);
            self::assertIsString($content);

            return $content;
        };

        $notProtocolledLabel = mb_convert_encoding('NÃO PROTOCOLADO', 'Windows-1252', 'UTF-8');
        $homologation = $pageContent($xml);
        self::assertStringContainsString('SEM VALOR FISCAL', $homologation);
        self::assertStringContainsString(mb_convert_encoding('EMITIDO EM AMBIENTE DE HOMOLOGAÇÃO', 'Windows-1252', 'UTF-8'), $homologation);
        self::assertStringNotContainsString($notProtocolledLabel, $homologation);

        $withoutProtocol = preg_replace('~<protMDFe>.*?</protMDFe>~s', '', $xml);
        self::assertIsString($withoutProtocol);
        $unprotocolled = $pageContent($withoutProtocol);
        self::assertStringContainsString('SEM VALOR FISCAL', $unprotocolled);
        self::assertStringContainsString($notProtocolledLabel, $unprotocolled);

        $production = $pageContent(str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $xml));
        self::assertStringNotContainsString('SEM VALOR FISCAL', $production);
        self::assertStringNotContainsString($notProtocolledLabel, $production);

        $unprotocolledProduction = $pageContent(str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $withoutProtocol));
        self::assertStringContainsString('SEM VALOR FISCAL', $unprotocolledProduction);
        self::assertStringContainsString($notProtocolledLabel, $unprotocolledProduction);
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
