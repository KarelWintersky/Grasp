<?php

namespace App\Units;

use Arris\Entity\Result;
use Arris\Path;

class CronHelper
{

    /**
     * @param string $subdir
     * @return void
     */
    public static function createStorageSubdir(string $subdir): string
    {
        $dir
            = Path::create(config('PATH.STORAGE'))
            ->join($subdir)
            ->toString(true);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }



}