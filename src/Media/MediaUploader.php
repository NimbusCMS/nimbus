<?php

declare(strict_types=1);

namespace Nimbus\Media;

/**
 * Validates and stores an uploaded file, then records it.
 *
 * The security-critical part of media. Every decision here assumes the client
 * is hostile:
 *
 * - The type is decided by sniffing the file's actual content (finfo), never by
 *   the browser-supplied Content-Type, which is trivially forged.
 * - Only an allowlist of types is accepted; SVG is excluded on purpose because
 *   an SVG can carry script and would run in a victim's browser.
 * - The stored filename is random and its extension is derived from the
 *   *validated* type — so a file claiming to be "shell.php" can never be written
 *   as executable, and there is no path-traversal surface from the client name.
 * - The client's filename is kept only as display metadata, sanitized.
 * - move_uploaded_file (guarded by is_uploaded_file) is the only way a real
 *   request writes a file, so tmp_name cannot be pointed at an arbitrary path.
 *   Tests inject a plain-file mover instead.
 */
final class MediaUploader
{
    /** Accepted content types => the extension we store them under. */
    private const ALLOWED = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @var callable(string,string):bool */
    private $mover;

    /**
     * @param callable(string,string):bool|null $mover moves a validated temp
     *        file to its destination. Defaults to the is_uploaded_file-guarded
     *        move_uploaded_file; tests pass a copy-based mover for fixtures.
     */
    public function __construct(
        private MediaRepository $media,
        private string $baseDir,
        private string $urlPrefix,
        private int $maxBytes,
        ?callable $mover = null,
    ) {
        $this->mover = $mover ?? static function (string $from, string $to): bool {
            return is_uploaded_file($from) && move_uploaded_file($from, $to);
        };
    }

    /**
     * Store one uploaded file and return its media id.
     *
     * @param array<string,mixed> $file a single $_FILES entry
     * @throws UploadError when the file is missing, too big, or not an allowed type
     */
    public function store(array $file, ?int $authorId = null, ?string $alt = null): int
    {
        $this->assertUploadOk($file);

        $tmp  = (string) $file['tmp_name'];
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new UploadError('The file is empty.');
        }
        if ($size > $this->maxBytes) {
            throw new UploadError('The file is larger than ' . $this->humanBytes($this->maxBytes) . '.');
        }

        $mime = $this->detectMime($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw new UploadError('That file type is not allowed. Accepted: JPEG, PNG, GIF, WebP, PDF.');
        }
        // In the public demo, PDFs are refused: they are the realistic
        // malware-carrier for abusing our domain as a file host (images are far
        // lower-risk and keep the showcase intact).
        if ($mime === 'application/pdf' && \Nimbus\Support\Config::demo()) {
            throw new UploadError('PDF uploads are disabled in the live demo.');
        }
        $ext = self::ALLOWED[$mime];

        [$relDir, $absDir] = $this->targetDir();
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $absPath    = $absDir . '/' . $storedName;
        $relPath    = $relDir . '/' . $storedName;

        if (!($this->mover)($tmp, $absPath)) {
            throw new UploadError('The upload could not be saved. Please try again.');
        }

        [$width, $height] = $this->dimensions($absPath, $mime);

        return $this->media->create([
            'filename' => $this->displayName((string) ($file['name'] ?? $storedName)),
            'path'     => $relPath,
            'url'      => rtrim($this->urlPrefix, '/') . '/' . $this->datePath() . '/' . $storedName,
            'mime'     => $mime,
            'size'     => $size,
            'width'    => $width,
            'height'   => $height,
            'alt'      => $alt !== null && trim($alt) !== '' ? trim($alt) : null,
        ], $authorId);
    }

    /** @param array<string,mixed> $file */
    private function assertUploadOk(array $file): void
    {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_OK && ($file['tmp_name'] ?? '') !== '') {
            return;
        }
        throw new UploadError(match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_NO_FILE                        => 'No file was selected.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
            default                                   => 'The file could not be uploaded.',
        });
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function dimensions(string $path, string $mime): array
    {
        if (!str_starts_with($mime, 'image/')) {
            return [null, null];
        }
        $info = @getimagesize($path);
        return $info !== false ? [(int) $info[0], (int) $info[1]] : [null, null];
    }

    /**
     * The date-sharded directory to write into, created if needed.
     *
     * @return array{0:string,1:string} [relativeToBaseDir, absolute]
     */
    private function targetDir(): array
    {
        $rel = $this->datePath();
        $abs = rtrim($this->baseDir, '/') . '/' . $rel;
        if (!is_dir($abs) && !mkdir($abs, 0o755, true) && !is_dir($abs)) {
            throw new UploadError('The upload directory could not be created.');
        }
        return [$rel, $abs];
    }

    private function datePath(): string
    {
        return date('Y/m');
    }

    /** Keep only a readable basename for display — never used as a filesystem path. */
    private function displayName(string $name): string
    {
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = trim($name);
        return $name !== '' ? mb_substr($name, 0, 255) : 'file';
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
