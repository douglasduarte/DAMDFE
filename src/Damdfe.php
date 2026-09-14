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
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.1);

        $y = $this->drawHeader($pdf) + 1;
        $y = $this->drawTollVoucher($pdf, $y);
        $y += 1;
        $y = $this->drawRoute($pdf, $y);
        $y += 1;
        $y = $this->drawDocuments($pdf, $y);
        $y += 1;
        $y = $this->drawInsurance($pdf, $y);
        $y += 1;
        $y = $this->drawCiot($pdf, $y);
        $y += 1;
        $this->drawAdditionalInformation($pdf, $y);
        $this->drawWatermarks($pdf);
    }

    private function drawHeader(DamdfePdf $pdf): float
    {
        $x = $this->config->marginLeft;
        $y = $this->config->marginTop;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $issuerWidth = 66.0;
        $qrWidth = 46.0;
        $controlWidth = $width - $issuerWidth - $qrWidth;
        $controlX = $x + $issuerWidth;
        $qrX = $controlX + $controlWidth;
        $mainY = $y + 23;

        $this->drawReceipt($pdf, $x, $y, $width);
        $this->drawIssuer($pdf, $x, $mainY, $issuerWidth);

        $emitter = match ($this->ideText('tpEmit')) {
            '1' => 'PRESTADOR DE SERVIÇO DE TRANSPORTE',
            '2' => 'TRANSPORTADOR DE CARGA PRÓPRIA',
            '3' => "PRESTADOR DE SERVIÇO DE TRANSPORTE\n(CT-e GLOBALIZADO)",
            default => $this->ideText('tpEmit'),
        };
        $emission = $this->ideText('tpEmis') === '1' ? 'NORMAL' : 'CONTINGÊNCIA';
        $environment = $this->ideText('tpAmb') === '1' ? 'PRODUÇÃO' : 'HOMOLOGAÇÃO';
        $laterLoading = match ($this->ideText('indCarregaPosterior')) {
            '0' => 'NÃO',
            '1' => 'SIM',
            default => '',
        };
        $this->compactField($pdf, $x, $mainY + 36, $issuerWidth / 2, 12, 'TIPO DO EMITENTE', $emitter, 'C', 6);
        $this->compactField($pdf, $x + ($issuerWidth / 2), $mainY + 36, $issuerWidth / 2, 12, 'FORMA DE EMISSÃO', $emission, 'C');
        $this->compactField($pdf, $x, $mainY + 48, $issuerWidth / 2, 12, 'TIPO DO AMBIENTE', $environment, 'C');
        $this->compactField($pdf, $x + ($issuerWidth / 2), $mainY + 48, $issuerWidth / 2, 12, 'CARGA POSTERIOR', $laterLoading, 'C');

        $pdf->RoundedRect($controlX, $mainY, $controlWidth, 12);
        $this->fittedCell($pdf, $controlX + 1, $mainY + 1.7, $controlWidth - 2, 4, 'DAMDFE', 10, 'B', 'C', 8);
        $this->fittedCell(
            $pdf,
            $controlX + 1,
            $mainY + 6,
            $controlWidth - 2,
            4,
            'Documento Auxiliar do Manifesto de Documentos Fiscais Eletrônicos',
            8.5,
            '',
            'C',
            6,
        );
        $this->drawIdentification($pdf, $controlX, $mainY + 12, $controlWidth);
        $this->drawFiscalControl($pdf, $controlX, $mainY + 22, $controlWidth);

        $this->compactField($pdf, $qrX, $mainY, $qrWidth, 12, 'MODAL', mb_strtoupper($this->modal(), 'UTF-8'), 'C', 10);
        $this->drawQrCode($pdf, $qrX, $mainY + 12, $qrWidth, 48);

        $journeyY = $mainY + 60;
        $loadingMunicipality = $this->text(
            $this->first('//*[local-name()="ide"]/*[local-name()="infMunCarrega"]'),
            'xMunCarrega',
        );
        $unloadingMunicipality = $this->text(
            $this->first('//*[local-name()="infDoc"]/*[local-name()="infMunDescarga"]'),
            'xMunDescarga',
        );
        $this->compactField(
            $pdf,
            $x,
            $journeyY,
            $width / 2,
            8,
            'INÍCIO DO PERCURSO',
            trim($loadingMunicipality . ' - ' . $this->ideText('UFIni'), ' -'),
        );
        $this->compactField(
            $pdf,
            $x + ($width / 2),
            $journeyY,
            $width / 2,
            8,
            'TÉRMINO DO PERCURSO',
            trim($unloadingMunicipality . ' - ' . $this->ideText('UFFim'), ' -'),
        );

        $summaryY = $journeyY + 8;
        $this->drawRoadSummary($pdf, $x, $summaryY, $width);

        return $summaryY + 33;
    }

    private function drawReceipt(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $statementHeight = 3.5;
        $contentY = $y + $statementHeight;
        $contentHeight = 16.5;
        $nameWidth = $width * 0.25;
        $signatureWidth = $width * 0.25;
        $dateWidth = $width * 0.35;
        $documentWidth = $width - $nameWidth - $signatureWidth - $dateWidth;

        $pdf->RoundedRect($x, $y, $width, $statementHeight);
        $this->fittedCell(
            $pdf,
            $x + 1,
            $y + 0.2,
            $width - 2,
            3,
            'DECLARO QUE RECEBI OS DOCUMENTOS FISCAIS E A CARGA RELACIONADOS NESTE MANIFESTO EM PERFEITO ESTADO',
            6.2,
            '',
            'C',
            5,
        );

        $pdf->RoundedRect($x, $contentY, $nameWidth, 8);
        $pdf->RoundedRect($x, $contentY + 8, $nameWidth, $contentHeight - 8);
        $pdf->RoundedRect($x + $nameWidth, $contentY, $signatureWidth, $contentHeight);
        $pdf->RoundedRect($x + $nameWidth + $signatureWidth, $contentY, $dateWidth, $contentHeight);
        $pdf->RoundedRect($x + $nameWidth + $signatureWidth + $dateWidth, $contentY, $documentWidth, $contentHeight);

        $this->smallText($pdf, $x + 0.8, $contentY + 0.5, 'NOME', false, 6);
        $this->smallText($pdf, $x + 0.8, $contentY + 8.5, 'RG', false, 6);
        $this->fittedCell(
            $pdf,
            $x + $nameWidth + 1,
            $contentY + 13,
            $signatureWidth - 2,
            3,
            'ASSINATURA / CARIMBO',
            6,
            '',
            'C',
            5,
        );

        $dateX = $x + $nameWidth + $signatureWidth;
        $this->fittedCell($pdf, $dateX + 1, $contentY + 0.8, $dateWidth - 2, 3, 'TÉRMINO DA VIAGEM - DATA/HORA', 6, '', 'C', 5);
        $this->fittedCell($pdf, $dateX + 1, $contentY + 8, $dateWidth - 2, 3, 'INÍCIO DA VIAGEM - DATA/HORA', 6, '', 'C', 5);
        $this->fittedCell(
            $pdf,
            $dateX + 1,
            $contentY + 11,
            $dateWidth - 2,
            3,
            $this->formatDateTime($this->ideText('dhIniViagem')),
            6.5,
            'B',
            'C',
            5,
        );

        $documentX = $dateX + $dateWidth;
        $documentNumber = implode('.', str_split(str_pad($this->ideText('nMDF'), 9, '0', STR_PAD_LEFT), 3));
        $series = str_pad($this->ideText('serie'), 3, '0', STR_PAD_LEFT);
        $this->fittedCell($pdf, $documentX + 1, $contentY + 0.8, $documentWidth - 2, 3, 'MDF-e', 7, 'B', 'C', 5);
        $pdf->SetFont($this->config->font, '', 10);
        $pdf->SetXY($documentX + 1, $contentY + 5);
        $pdf->MultiCell($documentWidth - 2, 4.2, $this->pdfText("Nº. {$documentNumber}\nSérie {$series}"), 0, 'C');

        for ($dashX = $x; $dashX < $x + $width; $dashX += 3) {
            $pdf->Line($dashX, $y + 21.5, min($dashX + 2, $x + $width), $y + 21.5);
        }
    }

    private function drawIssuer(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $pdf->RoundedRect($x, $y, $width, 36);

        $textX = $x + 1;
        $textWidth = $width - 2;

        if ($this->config->logo && is_file($this->config->logo)) {
            $pdf->Image($this->config->logo, $x + 2, $y + 7, 21, 20);
            $textX = $x + 25;
            $textWidth = $width - 26;
        }

        $issuer = $this->first('//*[local-name()="emit"]');
        $address = $this->first('//*[local-name()="emit"]/*[local-name()="enderEmit"]');
        $street = trim($this->text($address, 'xLgr') . ', ' . $this->text($address, 'nro'), ' ,');
        $location = implode(' - ', array_filter([
            $this->text($address, 'xBairro'),
            $this->formatCep($this->text($address, 'CEP')),
            trim($this->text($address, 'xMun') . ' - ' . $this->text($address, 'UF'), ' -'),
        ]));

        $this->fittedCell($pdf, $textX, $y + 10, $textWidth, 4, $this->text($issuer, 'xNome'), 9, 'B', 'C', 6);
        $this->fittedCell($pdf, $textX, $y + 15, $textWidth, 3, $street, 7, '', 'C', 5.5);
        $this->fittedCell($pdf, $textX, $y + 18, $textWidth, 3, $location, 7, '', 'C', 5.5);
        $this->fittedCell($pdf, $textX, $y + 21, $textWidth, 3, 'Fone/Fax: ' . $this->formatPhone($this->text($address, 'fone')), 7, '', 'C', 5.5);
        $this->fittedCell(
            $pdf,
            $textX,
            $y + 26,
            $textWidth,
            3,
            'CNPJ/CPF: ' . $this->formatDocument($this->documentNumber($issuer)) . '    Insc.Estadual: ' . $this->text($issuer, 'IE'),
            7,
            '',
            'C',
            5.5,
        );
        $this->fittedCell($pdf, $textX, $y + 30, $textWidth, 3, 'RNTRC: ' . $this->roadText('RNTRC'), 6.5, '', 'C', 5.5);
    }

    private function drawQrCode(DamdfePdf $pdf, float $x, float $y, float $width, float $height): void
    {
        $pdf->RoundedRect($x, $y, $width, $height);

        $qr = $this->descendantText($this->first('//*[local-name()="infMDFeSupl"]'), 'qrCodMDFe');
        if ($qr === '') {
            $qr = sprintf(
                'https://dfe-portal.svrs.rs.gov.br/mdfe/qrCode?chMDFe=%s&tpAmb=%s',
                $this->key,
                $this->ideText('tpAmb', '2'),
            );
        }

        $path = $this->temporaryImage($this->barcodeGenerator()->qrCode($qr), 'qrcode');
        $size = min($width - 6, $height - 6);
        $pdf->Image($path, $x + (($width - $size) / 2), $y + (($height - $size) / 2), $size, $size, 'PNG');
    }

    private function drawIdentification(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $rowOne = [12.0, 10.0, 14.0, 6.0, 28.0, 12.0, $width - 82.0];
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
            $this->compactField($pdf, $cursor, $y, $cellWidth, 10, $labels[$index], $values[$index], 'C');
            $cursor += $cellWidth;
        }
    }

    private function drawFiscalControl(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $pdf->RoundedRect($x, $y, $width, 14);
        $barcode = $this->temporaryImage($this->barcodeGenerator()->code128($this->key), 'barcode');
        $pdf->Image($barcode, $x + 4, $y + 2, $width - 8, 10, 'PNG');

        $pdf->RoundedRect($x, $y + 14, $width, 8);
        $this->smallText($pdf, $x + 0.8, $y + 14.4, 'CHAVE DE ACESSO', false, 5.8);
        $this->fittedCell($pdf, $x + 1, $y + 17.2, $width - 2, 3, implode(' ', str_split($this->key, 4)), 7, 'B', 'C', 5.5);

        $pdf->RoundedRect($x, $y + 22, $width, 8);
        $this->fittedCell(
            $pdf,
            $x + 1,
            $y + 24.5,
            $width - 2,
            3,
            'Consulta em https://dfe-portal.svrs.rs.gov.br/MDFE/Consulta',
            7,
            '',
            'C',
            5.5,
        );

        $pdf->RoundedRect($x, $y + 30, $width, 8);
        $this->smallText($pdf, $x + 0.8, $y + 30.4, 'PROTOCOLO DE AUTORIZAÇÃO DE USO', false, 5.8);
        $this->fittedCell($pdf, $x + 1, $y + 33.2, $width - 2, 3, $this->protocol(), 7, 'B', 'C', 5.5);
    }

    private function drawRoadSummary(DamdfePdf $pdf, float $x, float $y, float $width): void
    {
        $half = $width / 2;
        $this->titleRow($pdf, $x, $y, $width, 5, 'INFORMAÇÕES PARA ANTT');

        $totals = $this->first('//*[local-name()="tot"]');
        $cells = [
            ['QTD. CT-e', $this->text($totals, 'qCTe')],
            ['QTD. NF-e', $this->text($totals, 'qNFe')],
            ['PESO TOTAL', $this->formatQuantity($this->text($totals, 'qCarga') ?: $this->text($totals, 'qTotPeso'))],
            ['VALOR TOTAL', $this->formatMoney($this->text($totals, 'vCarga'))],
        ];
        foreach ($cells as $index => [$label, $value]) {
            $this->compactField($pdf, $x + ($index * ($width / 4)), $y + 5, $width / 4, 7, $label, $value);
        }

        $this->titleRow($pdf, $x, $y + 12, $half, 4, 'VEÍCULOS');
        $this->titleRow($pdf, $x + $half, $y + 12, $half, 4, 'CONDUTORES');
        $this->drawVehiclesAndDrivers($pdf, $x, $y + 16, $width, 17);
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
            $pdf->RoundedRect($cursor, $y, $cellWidth, $height);
            $this->smallText($pdf, $cursor + 1, $y + 1, $vehicleLabels[$index], false, 6.3);
            $cursor += $cellWidth;
        }

        $lineY = $y + 4;
        foreach (array_slice($vehicles, 0, 4) as $vehicle) {
            $cursor = $x;
            foreach (array_values($vehicle) as $index => $value) {
                $this->smallText($pdf, $cursor + 1, $lineY, $value, true, 6.5);
                $cursor += $vehicleWidths[$index];
            }
            $lineY += 3;
        }

        $driverX = $x + $half;
        $cpfWidth = 30.0;
        $pdf->RoundedRect($driverX, $y, $cpfWidth, $height);
        $pdf->RoundedRect($driverX + $cpfWidth, $y, $half - $cpfWidth, $height);
        $this->smallText($pdf, $driverX + 1, $y + 1, 'CPF', false, 6.3);
        $this->smallText($pdf, $driverX + $cpfWidth + 1, $y + 1, 'CONDUTORES', false, 6.3);
        $lineY = $y + 4;
        foreach (array_slice($drivers, 0, 4) as $driver) {
            $this->smallText($pdf, $driverX + 1, $lineY, $driver['cpf'], true, 6.5);
            $this->smallText($pdf, $driverX + $cpfWidth + 1, $lineY, $driver['name'], true, 6.5);
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
        $pdf->RoundedRect($x, $y + 4, $width, 5);
        $this->smallText($pdf, $x + 1, $y + 5, implode(' / ', $this->routeStates()), true, 7);

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
            $pdf->RoundedRect($x + $offset, $headerY, $municipalityWidth, 4);
            $pdf->RoundedRect($x + $offset + $municipalityWidth, $headerY, $keyWidth, 4);
            $this->smallText($pdf, $x + $offset + 1, $headerY + 1, 'MUNICÍPIO', false, 6);
            $this->smallText($pdf, $x + $offset + $municipalityWidth + 1, $headerY + 1, 'INFORMAÇÕES DOS DOCS. FISCAIS VINCULADOS AO MANIFESTO', false, 5.3);
        }

        $contentY = $headerY + 4;
        $contentHeight = $rows * 4.0;
        foreach ([0.0, $width / 2] as $offset) {
            $pdf->RoundedRect($x + $offset, $contentY, $municipalityWidth, $contentHeight);
            $pdf->RoundedRect($x + $offset + $municipalityWidth, $contentY, $keyWidth, $contentHeight);
        }

        foreach (array_slice($documents, 0, $rows * 2) as $index => $document) {
            $column = $index % 2;
            $row = intdiv($index, 2);
            $cellX = $x + ($column * ($width / 2));
            $lineY = $contentY + ($row * 4) + 0.8;
            $this->smallText($pdf, $cellX + 1, $lineY, $document['municipality'], true, 6);
            $this->smallText($pdf, $cellX + $municipalityWidth + 1, $lineY, $document['key'], false, 5.8);
        }

        return $contentY + $contentHeight;
    }

    private function drawInsurance(DamdfePdf $pdf, float $y): float
    {
        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $height = 36.0;
        $this->titleRow($pdf, $x, $y, $width, 5, 'INFORMAÇÕES SOBRE OS SEGUROS');
        $pdf->RoundedRect($x, $y + 5, $width, $height - 5);
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
                $this->smallText($pdf, $x + 1, $lineY, $this->truncate($line, $width - 2), false, 6.5);
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
        $pdf->RoundedRect($x, $y + 5, $width, $height - 5);
        foreach (array_slice($ciots, 0, 3) as $index => $ciot) {
            $this->smallText($pdf, $x + 1, $y + 6 + ($index * 3.5), $ciot, true, 6.5);
        }

        return $y + $height;
    }

    private function drawAdditionalInformation(DamdfePdf $pdf, float $y): void
    {
        $x = $this->config->marginLeft;
        $width = 210.0 - $this->config->marginLeft - $this->config->marginRight;
        $bottom = 297.0 - $this->config->marginBottom;
        $available = max(30.0, $bottom - $y);
        $contributorHeight = $available * 0.52;
        $fiscalHeight = $available - $contributorHeight;
        $additional = $this->first('//*[local-name()="infAdic"]');

        $this->titleRow($pdf, $x, $y, $width, 5, 'INFORMAÇÕES COMPLEMENTARES DE INTERESSE DO CONTRIBUINTE');
        $pdf->RoundedRect($x, $y + 5, $width, $contributorHeight - 5);
        $this->wrappedText($pdf, $x + 1, $y + 6, $width - 2, $contributorHeight - 7, $this->text($additional, 'infCpl'));

        $fiscalY = $y + $contributorHeight;
        $this->titleRow($pdf, $x, $fiscalY, $width, 5, 'INFORMAÇÕES ADICIONAIS DE INTERESSE DO FISCO');
        $pdf->RoundedRect($x, $fiscalY + 5, $width, $fiscalHeight - 5);
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
        $pdf->RoundedRect($x, $y, $width, $height);
        $this->fittedCell($pdf, $x + 0.5, $y + max(0.3, ($height - 3) / 2), $width - 1, 3, $title, 8, 'B', 'C', 5.5);
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
        float $valueSize = 7,
    ): void {
        $pdf->RoundedRect($x, $y, $width, $height);
        $this->fittedCell($pdf, $x + 0.8, $y + 0.5, $width - 1.6, 2.5, $label, 6.2, '', $align, 4.5);
        if (str_contains($value, "\n")) {
            $pdf->SetFont($this->config->font, 'B', $valueSize);
            $pdf->SetXY($x + 0.8, $y + 2.9);
            $pdf->MultiCell($width - 1.6, 1.7, $this->pdfText($value), 0, $align);

            return;
        }

        $this->fittedCell($pdf, $x + 0.8, $y + 3.1, $width - 1.6, 2.8, $value, $valueSize, 'B', $align, 4.5);
    }

    private function fittedCell(
        DamdfePdf $pdf,
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        float $size,
        string $style = '',
        string $align = 'L',
        float $minimumSize = 5,
    ): void {
        $encoded = $this->pdfText($text);
        $fontSize = $size;
        $pdf->SetFont($this->config->font, $style, $fontSize);

        while ($fontSize > $minimumSize && $pdf->GetStringWidth($encoded) > $width) {
            $fontSize -= 0.25;
            $pdf->SetFont($this->config->font, $style, $fontSize);
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $height, $encoded, 0, 0, $align);
    }

    private function smallText(DamdfePdf $pdf, float $x, float $y, string $text, bool $bold = false, float $size = 6): void
    {
        $pdf->SetFont($this->config->font, $bold ? 'B' : '', $size);
        $pdf->SetXY($x, $y);
        $pdf->Cell(0, 2.5, $this->pdfText($text));
    }

    private function wrappedText(DamdfePdf $pdf, float $x, float $y, float $width, float $height, string $text): void
    {
        $pdf->SetFont($this->config->font, '', 6.5);
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

        $lineHeight = 3.2;
        $maxLines = max(1, (int) floor($height / $lineHeight));
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = mb_strimwidth($lines[$maxLines - 1], 0, 150, '...', 'UTF-8');
        }

        foreach ($lines as $index => $line) {
            $pdf->SetXY($x, $y + ($index * $lineHeight));
            $pdf->Cell($width, $lineHeight, $this->pdfText($line));
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
