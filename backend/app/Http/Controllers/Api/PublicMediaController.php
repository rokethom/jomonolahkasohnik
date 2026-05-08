<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicMediaController extends Controller
{
    public function __invoke(Request $request, string $path): StreamedResponse
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        abort_if($path === '' || str_contains($path, '..') || ! Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
