<?php

use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// 1×1 transparent PNG — no GD required.
function fakePng(string $name = 'photo.png'): UploadedFile
{
    $bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    $tmp = tempnam(sys_get_temp_dir(), 'png_');
    file_put_contents($tmp, $bytes);

    return new UploadedFile($tmp, $name, 'image/png', null, true);
}

beforeEach(function () {
    Storage::fake('public');
});

it('authenticated user can upload an image', function () {
    $user = login();
    $file = fakePng();

    $response = $this->actingAs($user)
        ->postJson('/assets', ['file' => $file]);

    $response->assertStatus(201)
        ->assertJsonStructure(['id', 'url']);

    expect(Asset::count())->toBe(1);
    $asset = Asset::first();
    expect($asset->mime)->toBe('image/png')
        ->and($asset->disk)->toBe('public')
        ->and($asset->checksum)->not->toBeNull();

    Storage::disk('public')->assertExists($asset->path);
});

it('deduplicates uploads by sha-256 checksum', function () {
    $user = login();

    $r1 = $this->actingAs($user)->postJson('/assets', ['file' => fakePng()]);
    $r2 = $this->actingAs($user)->postJson('/assets', ['file' => fakePng()]);

    $r1->assertStatus(201);
    $r2->assertStatus(200);

    expect(Asset::count())->toBe(1)
        ->and($r1->json('id'))->toBe($r2->json('id'));
});

it('rejects non-image uploads', function () {
    $user = login();
    $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

    $this->actingAs($user)
        ->postJson('/assets', ['file' => $file])
        ->assertStatus(422);

    expect(Asset::count())->toBe(0);
});

it('rejects files over 10 MB', function () {
    $user = login();
    $file = UploadedFile::fake()->create('huge.jpg', 11_001, 'image/jpeg'); // 11 MB in KB

    $this->actingAs($user)
        ->postJson('/assets', ['file' => $file])
        ->assertStatus(422);
});

it('guests cannot upload assets', function () {
    $this->postJson('/assets', ['file' => fakePng()])
        ->assertStatus(401);
});

it('rejects svg uploads (stored-xss vector)', function () {
    $user = login();
    $file = UploadedFile::fake()->create('vector.svg', 1, 'image/svg+xml');

    $this->actingAs($user)
        ->postJson('/assets', ['file' => $file])
        ->assertStatus(422);

    expect(Asset::count())->toBe(0);
});

it('rehost refuses private and link-local hosts (ssrf guard)', function () {
    $user = login();
    Http::fake(); // would record a request if the guard let one through

    foreach ([
        'http://127.0.0.1/x.png',
        'http://169.254.169.254/latest/meta-data',
        'http://192.168.1.10/x.png',
        'http://10.0.0.5/x.png',
        'http://[::1]/x.png',
    ] as $url) {
        $this->actingAs($user)
            ->postJson('/assets/rehost', ['url' => $url])
            ->assertStatus(422);
    }

    Http::assertNothingSent();
    expect(Asset::count())->toBe(0);
});

it('rehost refuses non-http schemes', function () {
    $user = login();
    Http::fake();

    $this->actingAs($user)
        ->postJson('/assets/rehost', ['url' => 'file:///etc/passwd'])
        ->assertStatus(422);

    Http::assertNothingSent();
});

it('rehost does not follow a redirect (which could point at an internal host)', function () {
    $user = login();

    // A public host that 302s toward cloud-metadata. With redirects disabled the
    // 30x comes back as-is (not successful), so nothing is fetched or stored.
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

    $this->actingAs($user)
        ->postJson('/assets/rehost', ['url' => 'http://93.184.216.34/logo.png'])
        ->assertStatus(422);

    expect(Asset::count())->toBe(0);
});

it('rehost downloads and stores an image from a public host', function () {
    $user = login();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    // Literal public IP host so no real DNS lookup happens in the test.
    Http::fake(['*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    $this->actingAs($user)
        ->postJson('/assets/rehost', ['url' => 'http://93.184.216.34/logo.png'])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'url']);

    expect(Asset::count())->toBe(1)
        ->and(Asset::first()->mime)->toBe('image/png');
});

it('rehost rejects an image whose declared size exceeds the limit', function () {
    $user = login();

    // A hostile host that announces an 11 MB body: reject on the declared length
    // before reading it, rather than buffering the whole payload into memory.
    Http::fake(['*' => Http::response('tiny', 200, [
        'Content-Type'   => 'image/png',
        'Content-Length' => (string) (11 * 1024 * 1024),
    ])]);

    $this->actingAs($user)
        ->postJson('/assets/rehost', ['url' => 'http://93.184.216.34/huge.png'])
        ->assertStatus(422);

    expect(Asset::count())->toBe(0);
});
