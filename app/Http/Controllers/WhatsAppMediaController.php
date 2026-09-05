<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WhatsAppMediaController extends Controller
{
    public function show(string $encodedPath): BinaryFileResponse
    {
        $encodedPath .= str_repeat('=', (4 - strlen($encodedPath) % 4) % 4);
        $path = base64_decode(strtr($encodedPath, '-_', '+/'), true);

        abort_unless(
            is_string($path) && str_starts_with($path, 'whatsapp-queue/') && !str_contains($path, '..') && Storage::exists($path),
            404,
        );

        return response()->file(Storage::path($path), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
