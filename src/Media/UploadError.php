<?php

declare(strict_types=1);

namespace Nimbus\Media;

use RuntimeException;

/**
 * An upload was rejected. The message is safe to show a user — it explains what
 * to fix (too big, wrong type) without leaking paths or internals.
 */
final class UploadError extends RuntimeException
{
}
