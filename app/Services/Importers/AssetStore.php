<?php

namespace App\Services\Importers;

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;

/**
 * Server-side asset storage helper for the import pipeline.
 * Mirrors the dedup logic in AssetController without needing an HTTP round-trip.
 */
class AssetStore
{
    private const DISK = 'public';

    // SVG is intentionally absent — it can carry <script> and would be a
    // stored-XSS vector served from the public disk (matches AssetController).
    private const MIME_EXT = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
    ];

    /**
     * Store raw image bytes and return the public URL.
     * Deduplicates by SHA-256, same as the upload endpoint.
     */
    public function store(string $content, string $mime, ?int $uploadedById): string
    {
        $checksum = hash('sha256', $content);

        $existing = Asset::where('checksum', $checksum)->first();
        if ($existing) {
            return Storage::disk(self::DISK)->url($existing->path);
        }

        $ext  = self::MIME_EXT[$mime] ?? 'bin';
        $path = "assets/{$checksum}.{$ext}";

        Storage::disk(self::DISK)->put($path, $content);

        Asset::create([
            'path'           => $path,
            'disk'           => self::DISK,
            'mime'           => $mime,
            'size'           => strlen($content),
            'checksum'       => $checksum,
            'uploaded_by_id' => $uploadedById,
        ]);

        return Storage::disk(self::DISK)->url($path);
    }

    /**
     * Decode a base64 data: URI and store it. Returns public URL or null if invalid.
     */
    public function storeDataUri(string $dataUri, ?int $uploadedById): ?string
    {
        if (!preg_match('/^data:(image\/[a-z+\-]+);base64,(.+)$/i', $dataUri, $m)) {
            return null;
        }

        $mime    = strtolower($m[1]);
        $content = base64_decode($m[2], true);

        if ($content === false || !isset(self::MIME_EXT[$mime])) {
            return null;
        }

        return $this->store($content, $mime, $uploadedById);
    }
}
