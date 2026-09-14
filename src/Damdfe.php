<?php

declare(strict_types=1);

namespace DAMDFE;

use DAMDFE\Barcode\BarcodeGeneratorInterface;
use DAMDFE\Barcode\MilonBarcodeGenerator;
use DAMDFE\Config\DamdfeConfig;
use DOMDocument;
use DOMElement;
use DOMXPath;
use FPDF;
use InvalidArgumentException;
use RuntimeException;

final class Damdfe
{
    private DOMDocument $document;
    private DOMXPath $xpath;
    private string $key;
    /** @var list<string> */
    private array $temporaryImages = [];

    public function __construct(
        string $xml,
        private readonly DamdfeConfig $config = new DamdfeConfig(),
        private readonly ?BarcodeGeneratorInterface $barcode = null,
    ) {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $this->document->loadXML($xml, LIBXML_NOBLANKS | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            throw new InvalidArgumentException('XML do MDF-e inválido.');
        }

        $this->xpath = new DOMXPath($this->document);
        $infMdfe = $this->first('//*[local-name()="infMDFe"]');
        $this->key = substr((string) $infMdfe?->getAttribute('Id'), 4);

        if ($this->key === '') {
            throw new InvalidArgumentException('O XML não contém infMDFe com chave de acesso.');
        }
    }

    public function render(): string
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins($this->config->marginLeft, $this->config->marginTop, $this->config->marginRight);
        $pdf->SetAutoPageBreak(false, $this->config->marginBottom);
        $pdf->SetTitle('DAMDFE');
        $pdf->AddPage();

        try {
            $this->draw($pdf);
            return $pdf->Output('S');
        } finally {
            foreach ($this->temporaryImages as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function draw(FPDF $pdf): void
    {
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);

        $this->drawWatermarks($pdf);
        $this->drawHeader($pdf);
        $this->drawIdentification($pdf);
        $this->drawIssuer($pdf);
        $this->drawDocuments($pdf);
        $this->drawTotals($pdf);
        $this->drawFooter($pdf);
    }

    private function drawHeader(FPDF $pdf): void
    {
        $x = $this->config->marginLeft;
        $y = $this->config->marginTop;
        $width = 200.0;
        $height = 28.0;
        $pdf->Rect($x, $y, $width, $height);

        if ($this->config->logo && is_file($this->config->logo)) {
            $pdf->Image($this->config->logo, $x + 2, $y + 2, 30, 20);
        }

        $pdf->SetFont($this->config->font, 'B', 14);
        $pdf->SetXY($x + 34, $y + 4);
        $pdf->Cell(80, 7, 'DAMDFE', 0, 2, 'L');
        $pdf->SetFont($this->config->font, '', 8);
        $pdf->Cell(80, 5, 'Documento Auxiliar do MDF-e', 0, 2, 'L');

        $pdf->SetFont($this->config->font, 'B', 8);
        $pdf->SetXY($x + 122, $y + 4);
        $pdf->Cell(76, 5, 'CHAVE DE ACESSO', 0, 2, 'R');
        $pdf->SetFont($this->config->font, '', 8);
        $pdf->Cell(76, 5, $this->formatKey($this->key), 0, 2, 'R');

        $barcode = $this->barcodeGenerator()->code128($this->key);
        $path = $this->temporaryImage($barcode, 'barcode');
        $pdf->Image($path, $x + 122, $y + 14, 76, 10, 'PNG');
    }

    private function drawIdentification(FPDF $pdf): void
    {
        $y = 38.0;
        $this->boxTitle($pdf, 5, $y, 200, 5, 'IDENTIFICAÇÃO DO MDF-e');
        $this->field($pdf, 5, $y + 5, 38, 10, 'MODELO', $this->value('mod', '57'));
        $this->field($pdf, 43, $y + 5, 38, 10, 'SÉRIE', $this->value('serie'));
        $this->field($pdf, 81, $y + 5, 42, 10, 'NÚMERO', $this->value('nMDF'));
        $this->field($pdf, 123, $y + 5, 82, 10, 'DATA/HORA DE EMISSÃO', $this->value('dhEmi'));
        $this->field($pdf, 5, $y + 15, 100, 10, 'MODAL', $this->modal());
        $this->field($pdf, 105, $y + 15, 100, 10, 'AMBIENTE', $this->value('tpAmb') === '1' ? 'Produção' : 'Homologação');
    }

    private function drawIssuer(FPDF $pdf): void
    {
        $y = 68.0;
        $this->boxTitle($pdf, 5, $y, 200, 5, 'EMITENTE');
        $issuer = $this->first('//*[local-name()="emit"]');
        $this->field($pdf, 5, $y + 5, 100, 10, 'RAZÃO SOCIAL', $this->text($issuer, 'xNome'));
        $this->field($pdf, 105, $y + 5, 100, 10, 'CNPJ/CPF', $this->documentNumber($issuer));
        $this->field($pdf, 5, $y + 15, 130, 10, 'ENDEREÇO', trim($this->text($issuer, 'xLgr') . ', ' . $this->text($issuer, 'nro')));
        $this->field($pdf, 135, $y + 15, 70, 10, 'MUNICÍPIO/UF', $this->text($issuer, 'xMun') . '/' . $this->text($issuer, 'UF'));
    }

    private function drawDocuments(FPDF $pdf): void
    {
        $y = 98.0;
        $this->boxTitle($pdf, 5, $y, 200, 5, 'DOCUMENTOS TRANSPORTADOS');
        $pdf->SetFont($this->config->font, 'B', 7);
        $pdf->SetXY(6, $y + 6);
        $pdf->Cell(48, 5, 'MUNICÍPIO DE DESCARGA', 1);
        $pdf->Cell(78, 5, 'CHAVE DO DOCUMENTO', 1);
        $pdf->Cell(74, 5, 'TIPO', 1);

        $nodes = $this->xpath->query('//*[local-name()="infMunDescarga"]');
        $line = $y + 11;
        $pdf->SetFont($this->config->font, '', 7);

        if ($nodes !== false) {
            foreach ($nodes as $municipio) {
                if (!$municipio instanceof DOMElement) {
                    continue;
                }
                $cidade = $this->text($municipio, 'xMunDescarga');
                foreach (['infCTe' => 'CT-e', 'infNFe' => 'NF-e'] as $tag => $tipo) {
                    foreach ($this->children($municipio, $tag) as $documento) {
                        $chave = $this->text($documento, $tag === 'infCTe' ? 'chCTe' : 'chNFe');
                        $pdf->SetXY(6, $line);
                        $pdf->Cell(48, 5, $cidade, 1);
                        $pdf->Cell(78, 5, $chave, 1);
                        $pdf->Cell(74, 5, $tipo, 1);
                        $line += 5;
                        if ($line > 245) {
                            break 2;
                        }
                    }
                }
            }
        }
    }

    private function drawTotals(FPDF $pdf): void
    {
        $y = 220.0;
        $this->boxTitle($pdf, 5, $y, 200, 5, 'TOTAIS');
        $total = $this->first('//*[local-name()="tot"]');
        $this->field($pdf, 5, $y + 5, 65, 12, 'PESO BRUTO (KG)', $this->text($total, 'qTotPeso'));
        $this->field($pdf, 70, $y + 5, 65, 12, 'VALOR DA CARGA', $this->text($total, 'vCarga'));
        $this->field($pdf, 135, $y + 5, 70, 12, 'QUANTIDADE DE DOCUMENTOS', $this->countDocuments());
    }

    private function drawFooter(FPDF $pdf): void
    {
        $qr = $this->text($this->first('//*[local-name()="infMDFeSupl"]'), 'qrCodMDFe');
        if ($qr !== '') {
            $qrPath = $this->temporaryImage($this->barcodeGenerator()->qrCode($qr), 'qrcode');
            $pdf->Image($qrPath, 177, 236, 24, 24, 'PNG');
        }
        $pdf->SetFont($this->config->font, '', 6);
        $pdf->SetXY(5, 255);
        $pdf->Cell(200, 4, 'Documento auxiliar — não possui validade como documento fiscal.', 0, 0, 'C');
    }

    private function drawWatermarks(FPDF $pdf): void
    {
        if ($this->value('tpAmb') === '2' || $this->first('//*[local-name()="protMDFe"]') === null) {
            $pdf->SetTextColor(220, 150, 150);
            $pdf->SetFont($this->config->font, 'B', 34);
            $pdf->SetXY(35, 135);
            $pdf->Cell(135, 15, 'SEM VALOR FISCAL', 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        if ($this->value('tpEmis') === '2') {
            $pdf->SetTextColor(160, 160, 160);
            $pdf->SetFont($this->config->font, 'B', 18);
            $pdf->SetXY(50, 153);
            $pdf->Cell(105, 10, 'EMISSÃO EM CONTINGÊNCIA', 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    private function boxTitle(FPDF $pdf, float $x, float $y, float $width, float $height, string $title): void
    {
        $pdf->SetFillColor(235, 235, 235);
        $pdf->Rect($x, $y, $width, $height, 'DF');
        $pdf->SetFont($this->config->font, 'B', 7);
        $pdf->SetXY($x + 1, $y + 1);
        $pdf->Cell($width - 2, $height - 2, $title, 0, 0, 'L');
    }

    private function field(FPDF $pdf, float $x, float $y, float $width, float $height, string $label, string $value): void
    {
        $pdf->Rect($x, $y, $width, $height);
        $pdf->SetFont($this->config->font, '', 5);
        $pdf->SetXY($x + 1, $y + 1);
        $pdf->Cell($width - 2, 3, $label, 0, 2, 'L');
        $pdf->SetFont($this->config->font, 'B', 7);
        $pdf->Cell($width - 2, $height - 5, $this->truncate($value, $width), 0, 0, 'L');
    }

    private function value(string $tag, string $default = ''): string
    {
        return trim((string) ($this->first('//*[local-name()="ide"]/*[local-name()="' . $tag . '"]')?->textContent ?? $default));
    }

    private function modal(): string
    {
        return match ($this->value('modal')) {
            '1' => 'Rodoviário',
            '2' => 'Aéreo',
            '3' => 'Aquaviário',
            '4' => 'Ferroviário',
            default => $this->value('modal'),
        };
    }

    private function documentNumber(?DOMElement $node): string
    {
        return $this->text($node, 'CNPJ') ?: $this->text($node, 'CPF');
    }

    private function countDocuments(): string
    {
        $ctes = $this->xpath->query('//*[local-name()="infCTe"]')?->length ?? 0;
        $nfes = $this->xpath->query('//*[local-name()="infNFe"]')?->length ?? 0;
        return (string) ($ctes + $nfes);
    }

    private function text(?DOMElement $node, string $tag): string
    {
        if (!$node) {
            return '';
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tag) {
                return trim($child->textContent);
            }
        }
        return '';
    }

    /** @return list<DOMElement> */
    private function children(DOMElement $node, string $tag): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $tag) {
                $children[] = $child;
            }
        }
        return $children;
    }

    private function first(string $query): ?DOMElement
    {
        $nodes = $this->xpath->query($query);
        $node = $nodes !== false ? $nodes->item(0) : null;
        return $node instanceof DOMElement ? $node : null;
    }

    private function formatKey(string $key): string
    {
        return trim(chunk_split($key, 4, ' '));
    }

    private function truncate(string $value, float $width): string
    {
        $max = max(10, (int) ($width * 2.1));
        return mb_strimwidth($value, 0, $max, '…', 'UTF-8');
    }

    private function barcodeGenerator(): BarcodeGeneratorInterface
    {
        return $this->barcode ?? new MilonBarcodeGenerator();
    }

    private function temporaryImage(string $contents, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'damdfe-' . $prefix . '-');
        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Não foi possível criar imagem temporária do DAMDFE.');
        }
        $this->temporaryImages[] = $path;
        return $path;
    }
}
