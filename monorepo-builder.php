<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;

return static function (MBConfig $mbConfig): void {
    $mbConfig->packageDirectories([
        __DIR__ . '/packages',
    ]);

    $mbConfig->packageDirectoriesExcludes([
        __DIR__ . '/packages/core',
    ]);

    $mbConfig->packageAliasFormat('<major>.<minor>.x-dev');
};
