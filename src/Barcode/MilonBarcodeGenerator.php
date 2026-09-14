<?php

declare(strict_types=1);

namespace DAMDFE\Barcode;

use Milon\Barcode\DNS1D;
use Milon\Barcode\DNS2D;

final class MilonBarcodeGenerator implements BarcodeGeneratorInterface
{
    public function __construct(
        private readonly DNS1D $oneDimensional = new DNS1D(),
        private readonly DNS2D $twoDimensional = new DNS2D(),
    ) {
    }

    public function code128(string $value): string
    {
        return base64_decode($this->oneDimensional->getBarcodePNG($value, 'C128', 2, 35), true) ?: '';
    }

    public function qrCode(string $value): string
    {
        return base64_decode($this->twoDimensional->getBarcodePNG($value, 'QRCODE', 4, 4), true) ?: '';
    }
}
