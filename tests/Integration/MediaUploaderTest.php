<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Media\MediaRepository;
use Nimbus\Media\MediaUploader;
use Nimbus\Media\UploadError;

/**
 * The security-critical part of media. A copy-based mover stands in for
 * move_uploaded_file so real fixture files can be driven through — everything
 * else is the production code, including finfo type detection.
 */
final class MediaUploaderTest extends IntegrationTestCase
{
    private string $storeDir;
    private string $tmpDir;
    private MediaRepository $media;
    private MediaUploader $uploader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeDir = sys_get_temp_dir() . '/nb-media-' . bin2hex(random_bytes(4));
        $this->tmpDir   = sys_get_temp_dir() . '/nb-tmp-' . bin2hex(random_bytes(4));
        mkdir($this->storeDir, 0o755, true);
        mkdir($this->tmpDir, 0o755, true);

        $this->media    = new MediaRepository($this->db);
        $this->uploader = new MediaUploader(
            $this->media,
            $this->storeDir,
            '/uploads',
            1_048_576, // 1 MB cap for the test
            static fn (string $from, string $to): bool => copy($from, $to),
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->storeDir, $this->tmpDir] as $dir) {
            if (is_dir($dir)) {
                $this->rmrf($dir);
            }
        }
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = "{$dir}/{$f}";
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** A 1×1 PNG written to a temp file, returned as a $_FILES-shaped array. */
    /** @return array<string,mixed> */
    private function pngUpload(string $clientName = 'photo.png'): array
    {
        $png  = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $png);
        return ['name' => $clientName, 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)];
    }

    /**
     * A file whose bytes are plain text, returned with a lying image content-type.
     *
     * @return array<string,mixed>
     */
    private function fakeImageUpload(): array
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, "<?php echo 'gotcha';");
        return ['name' => 'evil.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 20];
    }

    // ------------------------------------------------------------- happy path

    public function test_a_png_is_stored_and_recorded(): void
    {
        $id   = $this->uploader->store($this->pngUpload(), authorId: null, alt: 'A dot');
        $item = $this->media->find($id);

        self::assertNotNull($item);
        self::assertSame('image/png', $item->mime);
        self::assertSame('A dot', $item->alt);
        self::assertSame(1, $item->width);
        self::assertSame(1, $item->height);
        // path is relative to the store dir; the file really landed there.
        self::assertFileExists($this->storeDir . '/' . $item->path);
    }

    public function test_the_stored_name_is_random_and_never_the_client_name(): void
    {
        $item = $this->media->find($this->uploader->store($this->pngUpload('../../etc/passwd.png')));

        self::assertNotNull($item);
        // The client name survives only as display metadata, basename-stripped.
        self::assertSame('passwd.png', $item->filename);
        // The stored path is random and ends in the validated extension.
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}\.png$#', $item->path);
        self::assertStringNotContainsString('passwd', $item->path);
        self::assertStringNotContainsString('..', $item->path);
    }

    // ----------------------------------------------------------- rejections

    public function test_type_is_decided_by_content_not_the_client_header(): void
    {
        // Claims image/png, but the bytes are PHP — finfo sees text/x-php.
        $this->expectException(UploadError::class);
        $this->expectExceptionMessageMatches('/not allowed/');
        $this->uploader->store($this->fakeImageUpload());
    }

    public function test_a_file_over_the_cap_is_rejected(): void
    {
        $file         = $this->pngUpload();
        $file['size'] = 2_000_000; // over the 1 MB cap

        $this->expectException(UploadError::class);
        $this->expectExceptionMessageMatches('/larger than/');
        $this->uploader->store($file);
    }

    public function test_an_empty_upload_is_rejected(): void
    {
        $file         = $this->pngUpload();
        $file['size'] = 0;

        $this->expectException(UploadError::class);
        $this->uploader->store($file);
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->expectException(UploadError::class);
        $this->uploader->store(['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0, 'name' => '']);
    }

    public function test_the_server_size_error_is_reported_clearly(): void
    {
        $this->expectException(UploadError::class);
        $this->expectExceptionMessageMatches('/larger than the server allows/');
        $this->uploader->store(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0, 'name' => 'big.png']);
    }

    public function test_nothing_is_recorded_when_a_type_is_rejected(): void
    {
        try {
            $this->uploader->store($this->fakeImageUpload());
        } catch (UploadError) {
            // expected
        }
        self::assertSame([], $this->media->all(), 'a rejected upload leaves no metadata row');
    }
}
