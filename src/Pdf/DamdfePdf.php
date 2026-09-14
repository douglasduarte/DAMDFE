<?php

declare(strict_types=1);

namespace DAMDFE\Pdf;

use FPDF;

final class DamdfePdf extends FPDF
{
    private float $angle = 0.0;

    public function Rotate(float $angle, float $x = -1, float $y = -1): void
    {
        if ($x === -1.0) {
            $x = $this->x;
        }

        if ($y === -1.0) {
            $y = $this->y;
        }

        if ($this->angle !== 0.0) {
            $this->_out('Q');
        }

        $this->angle = $angle;

        if ($angle !== 0.0) {
            $angle *= M_PI / 180;
            $cos = cos($angle);
            $sin = sin($angle);
            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;
            $this->_out(sprintf(
                'q %.5F %.5F %.5F %.5F %.5F %.5F cm 1 0 0 1 %.5F %.5F cm',
                $cos,
                $sin,
                -$sin,
                $cos,
                $cx,
                $cy,
                -$cx,
                -$cy,
            ));
        }
    }

    public function RotatedText(float $x, float $y, string $text, float $angle): void
    {
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $text);
        $this->Rotate(0);
    }

    protected function _endpage(): void
    {
        if ($this->angle !== 0.0) {
            $this->angle = 0.0;
            $this->_out('Q');
        }

        parent::_endpage();
    }
}
