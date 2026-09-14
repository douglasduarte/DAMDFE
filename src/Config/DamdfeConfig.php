<?php

declare(strict_types=1);

namespace DAMDFE\Config;

final class DamdfeConfig
{
    public function __construct(
        public readonly ?string $logo = null,
        public readonly float $marginTop = 2.0,
        public readonly float $marginRight = 2.0,
        public readonly float $marginBottom = 2.0,
        public readonly float $marginLeft = 2.0,
        public readonly string $font = 'Times',
    ) {
    }
}
