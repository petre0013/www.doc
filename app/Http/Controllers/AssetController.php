<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Support\Ssrf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    private const DISK = 'public';

    // SVG is deliberately excluded: it can carry <script> and, served from the
    // public disk, would be a stored-XSS vector. Only raster formats are allowed.
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ];

    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    /** Upload a file. Returns {id, url}. Dedupes by SHA-256 checksum. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp'],
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());
        $checksum = hash('sha256', $content);

        $existing = Asset::where('checksum', $checksum)->first();
        if ($existing) {
            return response()->json([
                'id'  => $existing->id,
                'url' => Storage::disk(self::DISK)->url($existing->path),
            ]);
        }

        $ext  = $file->guessExtension() ?? 'bin';
        $path = "assets/{$checksum}.{$ext}";

        Storage::disk(self::DISK)->put($path, $content);

        $asset = Asset::create([
            'path'           => $path,
            'disk'           => self::DISK,
            'mime'           => $file->getMimeType(),
            'size'           => strlen($content),
            'checksum'       => $checksum,
            'uploaded_by_id' => Auth::id(),
        ]);

        return response()->json([
            'id'  => $asset->id,
            'url' => Storage::disk(self::DISK)->url($path),
        ], 201);
    }

    /**
     * Download an external image URL and re-host it as an Asset.
     * Used by the editor paste pipeline for external <img src> rehosting.
     */
    public function rehost(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);

        $url = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ])['url'];

        // SSRF guard: only fetch public http(s) hosts. Without this an editor
        // could make the server hit internal targets it can reach but they
        // can't — cloud metadata (169.254.169.254), localhost Redis/Postgres,
        // other services on the Docker network. fetchOptions() refuses
        // redirects and pins the connection to the validated IPs (rebinding).
        $ips = Ssrf::assertPublicUrl($url);

        // Stream the response so the size limit is enforced as we read, not after
        // the whole body is already in memory: a hostile host could otherwise
        // return (or lie about) a huge payload and exhaust the worker's memory.
        $response = Http::timeout(10)
            ->withOptions(Ssrf::fetchOptions($url, $ips) + ['stream' => true])
            ->get($url);

        abort_unless($response->successful(), 422, 'Failed to download image.');

        $mime = strtolower(explode(';', $response->header('Content-Type') ?? '')[0]);
        abort_unless(in_array($mime, self::ALLOWED_MIMES), 422, 'URL does not point to a supported image.');

        // Reject up front when the server honestly declares an oversized length…
        $declared = (int) ($response->header('Content-Length') ?: 0);
        abort_if($declared > self::MAX_BYTES, 422, 'Image exceeds 10 MB limit.');

        // …then cap the actual read so a missing or lying Content-Length can't
        // stream us past the limit anyway.
        $body    = $response->toPsrResponse()->getBody();
        $content = '';
        while (! $body->eof()) {
            $content .= $body->read(8192);
            abort_if(strlen($content) > self::MAX_BYTES, 422, 'Image exceeds 10 MB limit.');
        }

        $checksum = hash('sha256', $content);

        $existing = Asset::where('checksum', $checksum)->first();
        if ($existing) {
            return response()->json([
                'id'  => $existing->id,
                'url' => Storage::disk(self::DISK)->url($existing->path),
            ]);
        }

        // Only the allow-listed raster mimes can reach here (SVG is rejected
        // above), so there's deliberately no svg branch.
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'bin',
        };

        $path = "assets/{$checksum}.{$ext}";
        Storage::disk(self::DISK)->put($path, $content);

        $asset = Asset::create([
            'path'           => $path,
            'disk'           => self::DISK,
            'mime'           => $mime,
            'size'           => strlen($content),
            'checksum'       => $checksum,
            'uploaded_by_id' => Auth::id(),
        ]);

        return response()->json([
            'id'  => $asset->id,
            'url' => Storage::disk(self::DISK)->url($path),
        ], 201);
    }

}
