<?php

declare(strict_types=1);

namespace DAMDFE\Barcode;

interface BarcodeGeneratorInterface
{
    /** @return string Binary PNG contents. */
    public function code128(string $value): string;

    /** @return string Binary PNG contents. */
    public function qrCode(string $value): string;
}
