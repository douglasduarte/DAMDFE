<?php

declare(strict_types=1);

namespace DAMDFE;

use DAMDFE\Barcode\BarcodeGeneratorInterface;
use DAMDFE\Barcode\MilonBarcodeGenerator;
use DAMDFE\Config\DamdfeConfig;
use DAMDFE\Pdf\DamdfePdf;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
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
        $pdf = new DamdfePdf('P', 'mm', 'A4');
        $pdf->SetMargins($this->config->marginLeft, $this->config->marginTop, $this->config->marginRight);
        $pdf->SetAutoPageBreak(false, $this->config->marginBottom);
        $pdf->SetTitle('DAMDFE');
        $pdf->SetAuthor($this->issuerName());
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

    private function draw(DamdfePdf $pdf): void
    {
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.15);

        $this->drawHeader($pdf);
        $y = 93.0;
        $y = $this->drawTollVoucher($pdf, $y);
        $y = $this->drawRoute($pdf, $y);
        $y = $this->drawDocuments($pdf, $y);
        $y = $this->drawInsurance($pdf, $y);
        $y = $this->drawCiot($pdf, $y);
        $this->drawAdditionalInformation($pdf, $y);
        $this->drawWatermarks($pdf);
    }

    private function drawHeader(DamdfePdf $pdf): void
    {
        $x = $this->config->marginLeft;
        $y = $this->config->marginTop;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $middle = $x + ($width / 2);

        $pdf->Rect($x, $y, $width, 88);
        $pdf->Line($middle, $y, $middle, $y + 88);
        $pdf->Line($x, $y + 25, $middle, $y + 25);

        $this->drawIssuer($pdf, $x, $y, $width / 2);
        $this->drawQrCode($pdf, $middle, $y, $width / 2);

        $pdf->SetFont($this->config->font, '', 5.8);
        $pdf->SetXY($x, $y + 25.4);
        $pdf->Cell($width / 2, 2.6, $this->pdfText('DAMDFE - Documento Auxiliar do Manifesto de Documentos Fiscais Eletrônicos'), 0, 0, 'C');

        $this->drawIdentification($pdf, $x, $y + 28, $width / 2);
        $this->drawFiscalControl($pdf, $middle, $y + 25, $width / 2);
        $this->drawRoadSummary($pdf, $x, $y + 50, $width);
    }

    private function drawIssuer(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $textX = $x + 2;
        $textWidth = $width - 4;

        if ($this->config->logo && is_file($this->config->logo)) {
            $pdf->Image($this->config->logo, $x + 2, $y + 2, 21, 20);
            $textX = $x + 25;
            $textWidth = $width - 27;
        }

        $issuer = $this->first('//*[local-name()="emit"]');
        $address = $this->first('//*[local-name()="emit"]/*[local-name()="enderEmit"]');
        $lines = array_filter([
            $this->text($issuer, 'xNome'),
            trim($this->text($address, 'xLgr') . ' ' . $this->text($address, 'nro')),
            trim($this->text($address, 'xBairro') . ' ' . $this->formatCep($this->text($address, 'CEP'))),
            trim($this->text($address, 'xMun') . ' - ' . $this->text($address, 'UF'), ' -'),
            'CNPJ: ' . $this->formatDocument($this->documentNumber($issuer)) . ' IE: ' . $this->text($issuer, 'IE'),
            'RNTRC: ' . $this->roadText('RNTRC') . ' TELEFONE: ' . $this->formatPhone($this->text($address, 'fone')),
        ], static fn (string $line): bool => trim($line, ' :-') !== '');

        $pdf->SetFont($this->config->font, '', 7);
        $pdf->SetXY($textX, $y + 3.5);
        $pdf->MultiCell($textWidth, 3, $this->pdfText(implode("\n", $lines)), 0, 'L');
    }

    private function drawQrCode(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $qr = $this->descendantText($this->first('//*[local-name()="infMDFeSupl"]'), 'qrCodMDFe');
        if ($qr === '') {
            $qr = sprintf(
                'https://dfe-portal.svrs.rs.gov.br/mdfe/qrCode?chMDFe=%s&tpAmb=%s',
                $this->key,
                $this->ideText('tpAmb', '2'),
            );
        }

        $path = $this->temporaryImage($this->barcodeGenerator()->qrCode($qr), 'qrcode');
        $size = 21.0;
        $pdf->Image($path, $x + (($width - $size) / 2), $y + 2, $size, $size, 'PNG');
    }

    private function drawIdentification(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $rowOne = [13.0, 8.0, 11.0, 7.0, 20.0, 14.0, $width - 73.0];
        $labels = ['MODELO', 'SÉRIE', 'NÚMERO', 'FL', 'DATA E HORA', 'UF CARREG', 'UF DESCARREG'];
        $values = [
            $this->ideText('mod', '58'),
            $this->ideText('serie'),
            $this->ideText('nMDF'),
            '1/1',
            $this->formatDateTime($this->ideText('dhEmi')),
            $this->ideText('UFIni'),
            $this->ideText('UFFim'),
        ];
        $cursor = $x;

        foreach ($rowOne as $index => $cellWidth) {
            $this->compactField($pdf, $cursor, $y, $cellWidth, 7, $labels[$index], $values[$index], 'C');
            $cursor += $cellWidth;
        }

        $emission = $this->ideText('tpEmis') === '2' ? 'CONTINGÊNCIA' : 'NORMAL';
        $this->compactField($pdf, $x, $y + 7, 24, 7, 'FORMA DE EMISSÃO', $emission, 'C');
        $this->compactField($pdf, $x + 24, $y + 7, 40, 7, 'PREVISÃO DE INÍCIO DA VIAGEM', $this->formatDateTime($this->ideText('dhIniViagem')), 'C');
        $this->compactField($pdf, $x + 64, $y + 7, $width - 64, 7, 'INSC. SUFRAMA', $this->issuerText('IEST'), 'C');

        $emitter = match ($this->ideText('tpEmit')) {
            '1' => 'PRESTADOR DE SERVIÇO DE TRANSPORTE',
            '2' => 'TRANSPORTADOR DE CARGA PRÓPRIA',
            '3' => "PRESTADOR DE SERVIÇO DE TRANSPORTE\n(CT-e GLOBALIZADO)",
            default => $this->ideText('tpEmit'),
        };
        $environment = $this->ideText('tpAmb') === '1' ? 'PRODUÇÃO' : 'HOMOLOGAÇÃO';
        $this->compactField($pdf, $x, $y + 14, 44, 8, 'TIPO DO EMITENTE', $emitter, 'C', 5.4);
        $this->compactField($pdf, $x + 44, $y + 14, 27, 8, 'TIPO DO AMBIENTE', $environment, 'C');
        $this->compactField($pdf, $x + 71, $y + 14, $width - 71, 8, 'CARGA POSTERIOR', $this->ideText('indCarregaPosterior'), 'C');
    }

    private function drawFiscalControl(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $pdf->SetFont($this->config->font, '', 6.5);
        $pdf->SetXY($x + 1, $y + 0.5);
        $pdf->Cell($width - 2, 3, 'CONTROLE DO FISCO');

        $barcode = $this->temporaryImage($this->barcodeGenerator()->code128($this->key), 'barcode');
        $pdf->Image($barcode, $x + 8, $y + 5, $width - 16, 16, 'PNG');

        $pdf->SetFont($this->config->font, '', 5.8);
        $pdf->SetXY($x, $y + 22.5);
        $pdf->Cell($width, 3, 'Consulta em https://dfe-portal.svrs.rs.gov.br/MDFE/Consulta', 0, 0, 'C');
        $pdf->SetFont($this->config->font, 'B', 6.5);
        $pdf->SetXY($x, $y + 27);
        $pdf->Cell($width, 3, $this->key, 0, 0, 'C');
        $pdf->SetFont($this->config->font, 'B', 6);
        $pdf->SetXY($x, $y + 31.5);
        $pdf->Cell($width, 3, $this->pdfText('PROTOCOLO DE AUTORIZAÇÃO DE USO'), 0, 0, 'C');
        $pdf->SetFont($this->config->font, '', 6);
        $pdf->SetXY($x, $y + 35.5);
        $pdf->Cell($width, 3, $this->pdfText($this->protocol()), 0, 0, 'C');
    }

    private function drawRoadSummary(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $half = $width / 2;
        $this->titleRow($pdf, $x, $y, $half, 5, 'MODAL ' . mb_strtoupper($this->modal(), 'UTF-8') . ' DE CARGA');
        $this->titleRow($pdf, $x, $y + 5, $half, 5, 'INFORMAÇÕES PARA ANTT');

        $totals = $this->first('//*[local-name()="tot"]');
        $cells = [
            ['QTD. CT-e', $this->text($totals, 'qCTe')],
            ['QTD. NF-e', $this->text($totals, 'qNFe')],
            ['PESO TOTAL', $this->formatQuantity($this->text($totals, 'qCarga'))],
            ['VALOR TOTAL', $this->formatMoney($this->text($totals, 'vCarga'))],
        ];
        foreach ($cells as $index => [$label, $value]) {
            $this->compactField($pdf, $x + ($index * ($half / 4)), $y + 10, $half / 4, 7, $label, $value);
        }

        $this->titleRow($pdf, $x, $y + 17, $half, 4, 'VEÍCULOS');
        $this->titleRow($pdf, $x + $half, $y + 17, $half, 4, 'CONDUTORES');
        $this->drawVehiclesAndDrivers($pdf, $x, $y + 21, $width, 17);
    }

    private function drawVehiclesAndDrivers(DamdfePdf $pdf, float $x, float $y, float $width, float $height): void
    {
        $half = $width / 2;
        $vehicles = $this->vehicles();
        $drivers = $this->drivers();
        $vehicleWidths = [24.0, 8.0, 23.0, 25.0, $half - 80.0];
        $vehicleLabels = ['PLACA', 'UF', 'RNTRC', 'RENAVAM', 'CPF'];
        $cursor = $x;

        foreach ($vehicleWidths as $index => $cellWidth) {
            $pdf->Rect($cursor, $y, $cellWidth, $height);
            $this->smallText($pdf, $cursor + 1, $y + 1, $vehicleLabels[$index], true, 5.7);
            $cursor += $cellWidth;
        }

        $lineY = $y + 4;
        foreach (array_slice($vehicles, 0, 4) as $vehicle) {
            $cursor = $x;
            foreach (array_values($vehicle) as $index => $value) {
                $this->smallText($pdf, $cursor + 1, $lineY, $value, false, 6);
                $cursor += $vehicleWidths[$index];
            }
            $lineY += 3;
        }

        $driverX = $x + $half;
        $cpfWidth = 30.0;
        $pdf->Rect($driverX, $y, $cpfWidth, $height);
        $pdf->Rect($driverX + $cpfWidth, $y, $half - $cpfWidth, $height);
        $this->smallText($pdf, $driverX + 1, $y + 1, 'CPF', true, 5.7);
        $this->smallText($pdf, $driverX + $cpfWidth + 1, $y + 1, 'CONDUTORES', true, 5.7);
        $lineY = $y + 4;
        foreach (array_slice($drivers, 0, 4) as $driver) {
            $this->smallText($pdf, $driverX + 1, $lineY, $driver['cpf'], false, 6);
            $this->smallText($pdf, $driverX + $cpfWidth + 1, $lineY, $driver['name'], false, 6);
            $lineY += 3;
        }
    }

    private function drawTollVoucher(DamdfePdf $pdf, float $y): float
    {
        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $this->titleRow($pdf, $x, $y, $width, 4, 'INFORMAÇÕES DE VALE PEDÁGIO');
        $column = $width / 4;
        $items = $this->tollVouchers();
        $voucher = $items[0] ?? ['supplier' => '', 'payer' => '', 'number' => '', 'value' => ''];
        $labels = ['CNPJ DA FORNECEDORA', 'CPF/CNPJ DO RESPONSÁVEL', 'NÚMERO DO COMPROVANTE', 'VALOR DO VALE-PEDÁGIO'];
        foreach (array_values($voucher) as $index => $value) {
            $this->compactField($pdf, $x + ($index * $column), $y + 4, $column, 10, $labels[$index], $value, 'C');
        }

        return $y + 14;
    }

    private function drawRoute(DamdfePdf $pdf, float $y): float
    {
        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $this->titleRow($pdf, $x, $y, $width, 4, 'PERCURSO');
        $pdf->Rect($x, $y + 4, $width, 5);
        $this->smallText($pdf, $x + 1, $y + 5, implode(' / ', $this->routeStates()), false, 6.5);

        return $y + 9;
    }

    private function drawDocuments(DamdfePdf $pdf, float $y): float
    {
        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $documents = $this->documents();
        $rows = max(3, min(8, (int) ceil(count($documents) / 2)));
        $this->titleRow($pdf, $x, $y, $width, 5, 'INFORMAÇÕES DA COMPOSIÇÃO DA CARGA');

        $municipalityWidth = 30.0;
        $keyWidth = ($width / 2) - $municipalityWidth;
        $headerY = $y + 5;
        foreach ([0.0, $width / 2] as $offset) {
            $pdf->Rect($x + $offset, $headerY, $municipalityWidth, 4);
            $pdf->Rect($x + $offset + $municipalityWidth, $headerY, $keyWidth, 4);
            $this->smallText($pdf, $x + $offset + 1, $headerY + 1, 'MUNICÍPIO', false, 5.3);
            $this->smallText($pdf, $x + $offset + $municipalityWidth + 1, $headerY + 1, 'INFORMAÇÕES DOS DOCS. FISCAIS VINCULADOS AO MANIFESTO', false, 4.8);
        }

        $contentY = $headerY + 4;
        $contentHeight = $rows * 4.0;
        $pdf->Rect($x, $contentY, $width / 2, $contentHeight);
        $pdf->Rect($x + ($width / 2), $contentY, $width / 2, $contentHeight);
        $pdf->Line($x + $municipalityWidth, $contentY, $x + $municipalityWidth, $contentY + $contentHeight);
        $pdf->Line($x + ($width / 2) + $municipalityWidth, $contentY, $x + ($width / 2) + $municipalityWidth, $contentY + $contentHeight);

        foreach (array_slice($documents, 0, $rows * 2) as $index => $document) {
            $column = $index % 2;
            $row = intdiv($index, 2);
            $cellX = $x + ($column * ($width / 2));
            $lineY = $contentY + ($row * 4) + 0.8;
            $this->smallText($pdf, $cellX + 1, $lineY, $document['municipality'], false, 5.4);
            $this->smallText($pdf, $cellX + $municipalityWidth + 1, $lineY, $document['key'], false, 5.2);
        }

        return $contentY + $contentHeight;
    }

    private function drawInsurance(DamdfePdf $pdf, float $y): float
    {
        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $height = 36.0;
        $this->titleRow($pdf, $x, $y, $width, 5, 'INFORMAÇÕES SOBRE OS SEGUROS');
        $pdf->Rect($x, $y + 5, $width, $height - 5);
        $lineY = $y + 6;
        $lastLineY = $y + $height - 3;

        foreach (array_slice($this->insurance(), 0, 6) as $insurance) {
            $lines = [sprintf(
                'NOME: %s  CNPJ: %s  APÓLICE: %s  AVERBAÇÕES: %s',
                $insurance['name'],
                $insurance['document'],
                $insurance['policy'],
                $insurance['endorsements'][0] ?? '',
            )];
            foreach (array_slice($insurance['endorsements'], 1) as $endorsement) {
                $lines[] = 'AVERBAÇÃO: ' . $endorsement;
            }
            foreach ($lines as $line) {
                if ($lineY > $lastLineY) {
                    break 2;
                }
                $this->smallText($pdf, $x + 1, $lineY, $this->truncate($line, $width - 2), false, 6);
                $lineY += 3.5;
            }
        }

        return $y + $height;
    }

    private function drawCiot(DamdfePdf $pdf, float $y): float
    {
        $ciots = $this->ciots();
        if ($ciots === []) {
            return $y;
        }

        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $height = 5 + (min(3, count($ciots)) * 3.5);
        $this->titleRow($pdf, $x, $y, $width, 5, 'INFORMAÇÕES DO CIOT');
        $pdf->Rect($x, $y + 5, $width, $height - 5);
        foreach (array_slice($ciots, 0, 3) as $index => $ciot) {
            $this->smallText($pdf, $x + 1, $y + 6 + ($index * 3.5), $ciot, false, 6);
        }

        return $y + $height;
    }

    private function drawAdditionalInformation(DamdfePdf $pdf, float $y): void
    {
        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $bottom = 292.0;
        $available = max(30.0, $bottom - $y);
        $contributorHeight = $available * 0.52;
        $fiscalHeight = $available - $contributorHeight;
        $additional = $this->first('//*[local-name()="infAdic"]');

        $this->titleRow($pdf, $x, $y, $width, 5, 'INFORMAÇÕES COMPLEMENTARES DE INTERESSE DO CONTRIBUINTE');
        $pdf->Rect($x, $y + 5, $width, $contributorHeight - 5);
        $this->wrappedText($pdf, $x + 1, $y + 6, $width - 2, $contributorHeight - 7, $this->text($additional, 'infCpl'));

        $fiscalY = $y + $contributorHeight;
        $this->titleRow($pdf, $x, $fiscalY, $width, 5, 'INFORMAÇÕES ADICIONAIS DE INTERESSE DO FISCO');
        $pdf->Rect($x, $fiscalY + 5, $width, $fiscalHeight - 5);
        $this->wrappedText(
            $pdf,
            $x + 1,
            $fiscalY + 6,
            $width - 2,
            $fiscalHeight - 7,
            str_replace(';', "\n", $this->text($additional, 'infAdFisco')),
        );
    }

    private function drawWatermarks(DamdfePdf $pdf): void
    {
        if ($this->ideText('tpAmb') === '2' || $this->first('//*[local-name()="protMDFe"]') === null) {
            $pdf->SetTextColor(220, 145, 145);
            $pdf->SetFont($this->config->font, 'B', 45);
            $pdf->RotatedText(38, 252, $this->pdfText('SEM VALOR FISCAL'), 55);
            $pdf->SetTextColor(0, 0, 0);
        }

        if ($this->ideText('tpEmis') === '2') {
            $pdf->SetTextColor(145, 145, 145);
            $pdf->SetFont($this->config->font, 'B', 17);
            $pdf->RotatedText(60, 230, $this->pdfText('EMISSÃO EM CONTINGÊNCIA'), 55);
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    private function titleRow(DamdfePdf $pdf, float $x, float $y, float $width, float $height, string $title): void
    {
        $pdf->Rect($x, $y, $width, $height);
        $pdf->SetFont($this->config->font, 'B', 7);
        $pdf->SetXY($x, $y + max(0.3, ($height - 3) / 2));
        $pdf->Cell($width, 3, $this->pdfText($title), 0, 0, 'C');
    }

    private function compactField(
        DamdfePdf $pdf,
        float $x,
        float $y,
        float $width,
        float $height,
        string $label,
        string $value,
        string $align = 'L',
        float $valueSize = 6.2,
    ): void {
        $pdf->Rect($x, $y, $width, $height);
        $pdf->SetFont($this->config->font, '', 5.2);
        $pdf->SetXY($x + 0.6, $y + 0.4);
        $pdf->Cell($width - 1.2, 2.2, $this->pdfText($label), 0, 0, $align);
        $pdf->SetFont($this->config->font, '', $valueSize);
        $pdf->SetXY($x + 0.6, $y + 3.1);
        if (str_contains($value, "\n")) {
            $pdf->MultiCell($width - 1.2, 2, $this->pdfText($value), 0, $align);

            return;
        }

        $pdf->Cell($width - 1.2, 2.6, $this->pdfText($this->truncate($value, $width)), 0, 0, $align);
    }

    private function smallText(DamdfePdf $pdf, float $x, float $y, string $text, bool $bold = false, float $size = 6): void
    {
        $pdf->SetFont($this->config->font, $bold ? 'B' : '', $size);
        $pdf->SetXY($x, $y);
        $pdf->Cell(0, 2.5, $this->pdfText($text));
    }

    private function wrappedText(DamdfePdf $pdf, float $x, float $y, float $width, float $height, string $text): void
    {
        $pdf->SetFont($this->config->font, '', 6);
        $text = str_replace(["\r\n", "\r", '&#10;', '&#13;'], "\n", $text);
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';

                continue;
            }

            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $pdf->GetStringWidth($this->pdfText($candidate)) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $lines[] = $line;
        }

        $maxLines = max(1, (int) floor($height / 3));
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = mb_strimwidth($lines[$maxLines - 1], 0, 150, '...', 'UTF-8');
        }

        foreach ($lines as $index => $line) {
            $pdf->SetXY($x, $y + ($index * 3));
            $pdf->Cell($width, 3, $this->pdfText($line));
        }
    }

    private function ideText(string $tag, string $default = ''): string
    {
        return $this->text($this->first('//*[local-name()="ide"]'), $tag) ?: $default;
    }

    private function issuerText(string $tag): string
    {
        return $this->descendantText($this->first('//*[local-name()="emit"]'), $tag);
    }

    private function roadText(string $tag): string
    {
        return $this->descendantText($this->first('//*[local-name()="infModal"]/*[local-name()="rodo"]'), $tag);
    }

    private function issuerName(): string
    {
        return $this->issuerText('xNome');
    }

    private function protocol(): string
    {
        $protocol = $this->first('//*[local-name()="protMDFe"]');
        $number = $this->descendantText($protocol, 'nProt');
        $date = $this->formatDateTime($this->descendantText($protocol, 'dhRecbto'));

        return trim($number . ' ' . $date);
    }

    private function modal(): string
    {
        return match ($this->ideText('modal')) {
            '1' => 'Rodoviário',
            '2' => 'Aéreo',
            '3' => 'Aquaviário',
            '4' => 'Ferroviário',
            default => $this->ideText('modal'),
        };
    }

    /** @return list<array{plate: string, uf: string, rntrc: string, renavam: string, cpf: string}> */
    private function vehicles(): array
    {
        $vehicles = [];
        $nodes = $this->xpath->query('//*[local-name()="infModal"]/*[local-name()="rodo"]/*[local-name()="veicTracao" or local-name()="veicReboque"]');
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $vehicles[] = [
                'plate' => $this->text($node, 'placa'),
                'uf' => $this->text($node, 'UF'),
                'rntrc' => $this->roadText('RNTRC'),
                'renavam' => $this->text($node, 'RENAVAM'),
                'cpf' => $this->formatDocument($this->text($node, 'CPF')),
            ];
        }

        return $vehicles;
    }

    /** @return list<array{cpf: string, name: string}> */
    private function drivers(): array
    {
        $drivers = [];
        $nodes = $this->xpath->query('//*[local-name()="veicTracao"]/*[local-name()="condutor"]');
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $drivers[] = [
                    'cpf' => $this->formatDocument($this->text($node, 'CPF')),
                    'name' => $this->text($node, 'xNome'),
                ];
            }
        }

        return $drivers;
    }

    /** @return list<array{supplier: string, payer: string, number: string, value: string}> */
    private function tollVouchers(): array
    {
        $vouchers = [];
        $nodes = $this->xpath->query('//*[local-name()="valePed"]/*[local-name()="disp"]');
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $vouchers[] = [
                'supplier' => $this->formatDocument($this->text($node, 'CNPJForn')),
                'payer' => $this->formatDocument($this->text($node, 'CNPJPg') ?: $this->text($node, 'CPFPg')),
                'number' => $this->text($node, 'nCompra'),
                'value' => $this->formatMoney($this->text($node, 'vValePed')),
            ];
        }

        return $vouchers;
    }

    /** @return list<string> */
    private function routeStates(): array
    {
        $states = [];
        $nodes = $this->xpath->query('//*[local-name()="ide"]/*[local-name()="infPercurso"]/*[local-name()="UFPer"]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $value = trim($node->textContent);
                if ($value !== '') {
                    $states[] = $value;
                }
            }
        }

        if ($states !== []) {
            return $states;
        }

        return array_values(array_filter([
            $this->ideText('UFIni'),
            $this->ideText('UFFim'),
        ]));
    }

    /** @return list<array{municipality: string, key: string, type: string}> */
    private function documents(): array
    {
        $documents = [];
        $municipalities = $this->xpath->query('//*[local-name()="infDoc"]/*[local-name()="infMunDescarga"]');
        if ($municipalities === false) {
            return [];
        }

        foreach ($municipalities as $municipality) {
            if (!$municipality instanceof DOMElement) {
                continue;
            }
            $name = $this->text($municipality, 'xMunDescarga');
            foreach (['infCTe' => 'chCTe', 'infNFe' => 'chNFe'] as $nodeName => $keyName) {
                foreach ($this->children($municipality, $nodeName) as $document) {
                    $documents[] = [
                        'municipality' => $name,
                        'key' => $this->text($document, $keyName),
                        'type' => $nodeName === 'infCTe' ? 'CT-e' : 'NF-e',
                    ];
                }
            }
        }

        return $documents;
    }

    /** @return list<array{name: string, document: string, policy: string, endorsements: list<string>}> */
    private function insurance(): array
    {
        $result = [];
        $nodes = $this->xpath->query('//*[local-name()="infMDFe"]/*[local-name()="seg"]');
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $insurer = $this->firstFrom($node, './*[local-name()="infSeg"]');
            $endorsements = [];
            foreach ($this->children($node, 'nAver') as $endorsement) {
                $endorsements[] = trim($endorsement->textContent);
            }
            $result[] = [
                'name' => $this->text($insurer, 'xSeg'),
                'document' => $this->formatDocument($this->documentNumber($insurer)),
                'policy' => $this->text($node, 'nApol'),
                'endorsements' => $endorsements,
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private function ciots(): array
    {
        $result = [];
        $nodes = $this->xpath->query('//*[local-name()="rodo"]/*[local-name()="infANTT"]/*[local-name()="infCIOT"]');
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $document = $this->text($node, 'CNPJ') ?: $this->text($node, 'CPF');
            $type = mb_strlen(preg_replace('/\D+/', '', $document) ?? '') === 11 ? 'CPF' : 'CNPJ';
            $result[] = sprintf('RESPONSÁVEL %s: %s e Nº CIOT: %s', $type, $this->formatDocument($document), $this->text($node, 'CIOT'));
        }

        return $result;
    }

    private function documentNumber(?DOMElement $node): string
    {
        return $this->text($node, 'CNPJ') ?: $this->text($node, 'CPF');
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

    private function descendantText(?DOMElement $node, string $tag): string
    {
        if (!$node) {
            return '';
        }

        $result = $this->xpath->query('.//*[local-name()="' . $tag . '"]', $node);

        return trim((string) ($result?->item(0)?->textContent ?? ''));
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
        $node = $this->xpath->query($query)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function firstFrom(DOMNode $context, string $query): ?DOMElement
    {
        $node = $this->xpath->query($query, $context)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function formatDateTime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y H:i:s');
        } catch (\Exception) {
            return $value;
        }
    }

    private function formatDocument(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (mb_strlen($digits) === 11) {
            return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) ?? $value;
        }
        if (mb_strlen($digits) === 14) {
            return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) ?? $value;
        }

        return $value;
    }

    private function formatCep(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $digits) ?? $value;
    }

    private function formatPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '55') && in_array(mb_strlen($digits), [12, 13], true)) {
            $digits = mb_substr($digits, 2);
        }
        if (mb_strlen($digits) === 11) {
            return preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $digits) ?? $value;
        }
        if (mb_strlen($digits) === 10) {
            return preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $digits) ?? $value;
        }

        return $value;
    }

    private function formatMoney(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }

    private function formatQuantity(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return number_format((float) $value, 4, ',', '.');
    }

    private function truncate(string $value, float $width): string
    {
        $max = max(6, (int) ($width * 1.25));

        return mb_strimwidth($value, 0, $max, '...', 'UTF-8');
    }

    private function pdfText(string $value): string
    {
        return mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
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
