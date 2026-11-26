<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

class AppRouter
{
    public static function load(string $baseDir, string $prefix = '')
    {
        $directories = File::directories($baseDir);
        $files = File::files($baseDir);

        foreach ($directories as $directory) {
            self::load($directory, $prefix . '/' . basename($directory));
        }

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $routePrefix = trim($prefix . '/' . $file->getFilenameWithoutExtension(), '/');

            Route::prefix($routePrefix)
                ->middleware([
                    'api',
                    'auth:sanctum',
                    \App\Http\Middleware\ResolveTenant::class,
                ])
                ->group(function () use ($file) {
                    include $file->getPathname();
                })
            ;
        }
    }
}
