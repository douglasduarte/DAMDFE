<?php

declare(strict_types=1);

namespace DAMDFE\Config;

final class DamdfeConfig
{
    public function __construct(
        public readonly ?string $logo = null,
        public readonly float $marginTop = 5.0,
        public readonly float $marginRight = 5.0,
        public readonly float $marginBottom = 5.0,
        public readonly float $marginLeft = 5.0,
        public readonly string $font = 'Times',
    ) {
    }
}
