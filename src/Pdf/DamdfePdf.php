<?php

declare(strict_types=1);

namespace DAMDFE\Pdf;

use FPDF;

final class DamdfePdf extends FPDF
{
    private float $angle = 0.0;

    public function RoundedRect(
        float $x,
        float $y,
        float $width,
        float $height,
        float $radius = 0.8,
        string $style = 'D',
    ): void {
        $operator = match ($style) {
            'F' => 'f',
            'FD', 'DF' => 'B',
            default => 'S',
        };
        $arc = (4 / 3) * (sqrt(2) - 1);
        $right = $x + $width;
        $bottom = $y + $height;

        $this->_out(sprintf('%.2F %.2F m', ($x + $radius) * $this->k, ($this->h - $y) * $this->k));
        $this->_out(sprintf('%.2F %.2F l', ($right - $radius) * $this->k, ($this->h - $y) * $this->k));
        $this->arc($right - $radius + ($radius * $arc), $y, $right, $y + $radius - ($radius * $arc), $right, $y + $radius);
        $this->_out(sprintf('%.2F %.2F l', $right * $this->k, ($this->h - ($bottom - $radius)) * $this->k));
        $this->arc($right, $bottom - $radius + ($radius * $arc), $right - $radius + ($radius * $arc), $bottom, $right - $radius, $bottom);
        $this->_out(sprintf('%.2F %.2F l', ($x + $radius) * $this->k, ($this->h - $bottom) * $this->k));
        $this->arc($x + $radius - ($radius * $arc), $bottom, $x, $bottom - $radius + ($radius * $arc), $x, $bottom - $radius);
        $this->_out(sprintf('%.2F %.2F l', $x * $this->k, ($this->h - ($y + $radius)) * $this->k));
        $this->arc($x, $y + $radius - ($radius * $arc), $x + $radius - ($radius * $arc), $y, $x + $radius, $y);
        $this->_out($operator);
    }

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

    private function arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k,
            ($this->h - $y1) * $this->k,
            $x2 * $this->k,
            ($this->h - $y2) * $this->k,
            $x3 * $this->k,
            ($this->h - $y3) * $this->k,
        ));
    }
}
