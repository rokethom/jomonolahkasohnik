<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    public function optimize(UploadedFile $file, string $directory = 'cms'): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $baseName = Str::uuid()->toString();

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return $file->storeAs($directory, "{$baseName}.{$extension}", 'public');
        }

        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);

        if (! $source) {
            return $file->storeAs($directory, "{$baseName}.{$extension}", 'public');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1280 / max(1, $width), 1280 / max(1, $height), 1);
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $tmp = tempnam(sys_get_temp_dir(), 'jojo-cms-');
        imagewebp($target, $tmp, 75);

        $path = "{$directory}/{$baseName}.webp";
        Storage::disk('public')->put($path, file_get_contents($tmp) ?: '');

        @unlink($tmp);
        imagedestroy($source);
        imagedestroy($target);

        return $path;
    }
}
