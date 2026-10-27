<?php

declare(strict_types=1);

namespace DAMDFE\Pdf;

use DOMElement;

/** Identificação do emitente compartilhada pelo DACTE e DAMDFE. */
final class IssuerHeader
{
    public function render(
        object $pdf,
        DOMElement $issuer,
        float $x,
        float $y,
        float $width,
        ?string $logo = null,
        string $font = 'Times',
    ): void {
        $pdf->Rect($x, $y, $width, 36);
        $address = $issuer->getElementsByTagName('enderEmit')->item(0);
        $value = static fn(string $tag): string => trim($issuer->getElementsByTagName($tag)->item(0)?->textContent ?? '');
        $addressValue = static fn(string $tag): string => trim($address?->getElementsByTagName($tag)->item(0)?->textContent ?? '');
        $document = $value('CNPJ') ?: $value('CPF');
        $document = match (mb_strlen($document)) {
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $document),
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $document),
            default => $document,
        };
        $cep = preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $addressValue('CEP'));
        $phone = $addressValue('fone');
        if (mb_strlen($phone) === 10 || mb_strlen($phone) === 11) {
            $phone = sprintf('(%s) %s-%s', mb_substr($phone, 0, 2), mb_substr($phone, 2, -4), mb_substr($phone, -4));
        }

        $rows = [
            [$value('xNome'), 4.0, 9.0, 'B'],
            [implode(', ', array_filter([$addressValue('xLgr'), $addressValue('nro'), $addressValue('xCpl')], static fn(string $part): bool => $part !== '')), 2.7, 7.0, ''],
            [implode(' - ', array_filter([$addressValue('xBairro'), $cep, $addressValue('xMun'), $addressValue('UF')])), 2.7, 7.0, ''],
            ['Fone/Fax: ' . $phone, 2.7, 7.0, ''],
            ['CNPJ/CPF: ' . $document . '    Insc.Estadual: ' . $value('IE'), 2.7, 7.0, ''],
        ];

        $dimensions = $logo && (is_file($logo) || str_starts_with($logo, 'data://text/plain;base64,'))
            ? @getimagesize($logo) : false;
        $logoHeight = 0.0;
        $logoWidth = 0.0;
        if ($dimensions !== false) {
            $scale = min(($width - 4) / $dimensions[0], 11 / $dimensions[1], 44 / $dimensions[0]);
            $logoWidth = $dimensions[0] * $scale;
            $logoHeight = $dimensions[1] * $scale;
        }

        $height = array_sum(array_column($rows, 1)) + 1 + ($logoHeight > 0 ? $logoHeight + 1 : 0);
        $cursor = $y + (36 - $height) / 2;
        if ($logoHeight > 0) {
            $pdf->Image($logo, $x + ($width - $logoWidth) / 2, $cursor, $logoWidth, $logoHeight, image_type_to_extension($dimensions[2], false));
            $cursor += $logoHeight + 1;
        }

        foreach ($rows as $index => [$text, $rowHeight, $fontSize, $style]) {
            if ($index === 4) {
                $cursor += 1;
            }
            $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: '';
            $pdf->SetFont($font, $style, $fontSize);
            while ($pdf->GetStringWidth($encoded) > $width - 4 && $fontSize > 1) {
                $fontSize -= 0.25;
                $pdf->SetFont($font, $style, $fontSize);
            }
            $pdf->SetXY($x + 2, $cursor);
            $pdf->Cell($width - 4, $rowHeight, $encoded, 0, 0, 'C');
            $cursor += $rowHeight;
        }
    }
}
