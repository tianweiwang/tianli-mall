<?php
declare(strict_types=1);

namespace app\services\marketplace\attachment;

use crmeb\exceptions\AuthUnavailableException;
use Throwable;

/** Private local bytes only. Current authentication, root/refund ownership and commit belong to the caller. */
final class MarketplaceAftersaleFileStorage
{
    private const MAX_BYTES = 5242880;
    private const MAX_SIDE = 4096;
    private const MIME_EXTENSION = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const METADATA_COLUMNS = ['id', 'uid', 'root_order_id', 'mer_id', 'refund_id', 'image_position', 'object_key',
        'media_type', 'byte_size', 'width', 'height', 'content_sha256', 'upload_key', 'state', 'created_at',
        'claim_expires_at', 'claimed_at', 'purged_at'];
    private const OBJECT_COLUMNS = ['root_order_id', 'object_key', 'media_type', 'byte_size', 'width', 'height', 'content_sha256'];
    private string $root;
    private int $owner;
    private int $device;
    private array $rootStat;

    /** The dedicated persistent root must already exist, outside this application's public/runtime tree. */
    public function __construct(string $privateRoot)
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE < 8 || !function_exists('posix_geteuid')
            || !function_exists('fsync') || !class_exists(\finfo::class)
            || !function_exists('imagecreatefromstring') || !function_exists('getimagesizefromstring')) self::unavailable();
        $owner = posix_geteuid();
        if (!is_int($owner) || $owner < 0 || $privateRoot === '/' || $privateRoot === ''
            || $privateRoot[0] !== '/' || str_contains($privateRoot, "\0")
            || str_contains($privateRoot, '\\') || str_ends_with($privateRoot, '/')) self::unavailable();
        $parts = explode('/', substr($privateRoot, 1));
        $cursor = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') self::unavailable();
            $cursor .= '/' . $part;
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
                || !in_array($stat['uid'], [0, $owner], true) || ($stat['mode'] & 0022) !== 0) self::unavailable();
        }
        $real = realpath($privateRoot);
        $application = realpath(dirname(__DIR__, 4));
        if ($real !== $privateRoot || !is_string($application) || $real === $application
            || str_starts_with($real . '/', $application . '/')) self::unavailable();
        $this->root = $real;
        $this->owner = $owner;
        $this->rootStat = $this->directory($real, null);
        $this->device = $this->rootStat['dev'];
    }

    /** Pure full-byte validation; original filename is consulted only for its extension, never for a path. */
    public function inspectUpload(string $bytes, string $originalName): array
    {
        $length = strlen($bytes);
        if ($length < 1 || $length > self::MAX_BYTES || $originalName === '' || strlen($originalName) > 255
            || str_contains($originalName, "\0")) self::unavailable();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) self::unavailable();
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $size = @getimagesizefromstring($bytes);
            if (!is_string($mime) || !isset(self::MIME_EXTENSION[$mime]) || !is_array($size)
                || !isset($size[0], $size[1], $size[2], $size['mime']) || $size['mime'] !== $mime
                || !is_int($size[0]) || !is_int($size[1]) || $size[0] < 1 || $size[1] < 1
                || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE
                || ($mime === 'image/jpeg' ? !in_array($extension, ['jpg', 'jpeg'], true)
                    : $extension !== self::MIME_EXTENSION[$mime])) self::unavailable();
            $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
            if ($size[2] !== $types[$mime]) self::unavailable();
            $image = @imagecreatefromstring($bytes);
            if (!$image instanceof \GdImage) self::unavailable();
            try {
                if (imagesx($image) !== $size[0] || imagesy($image) !== $size[1]) self::unavailable();
            } finally { imagedestroy($image); }
            return ['media_type' => $mime, 'byte_size' => $length, 'width' => $size[0],
                'height' => $size[1], 'content_sha256' => hash('sha256', $bytes, true)];
        } catch (Throwable $error) { self::unavailable(); }
    }

    /** Caller first authenticates and locks the real owner root. Failure preserves any new orphan; never implies DB success. */
    public function writeNew(int $rootOrderId, string $bytes, string $originalName): array
    {
        self::rootId($rootOrderId);
        $facts = $this->inspectUpload($bytes, $originalName);
        $this->rootGate();
        $rootDirectory = $this->root . '/' . $rootOrderId;
        clearstatcache(true, $rootDirectory);
        if (@lstat($rootDirectory) === false) {
            if (!@mkdir($rootDirectory, 0700)) self::unavailable();
        }
        $rootStat = $this->directory($rootDirectory, $this->device);
        $key = bin2hex(random_bytes(32));
        $directory = $rootDirectory . '/' . $key;
        if (!@mkdir($directory, 0700)) self::unavailable();
        $objectStat = $this->directory($directory, $this->device);
        $temporary = $directory . '/.upload';
        $final = $directory . '/content';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) self::unavailable();
        try {
            if (!@chmod($temporary, 0600)) self::unavailable();
            $this->regular(fstat($handle), 0);
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = @fwrite($handle, substr($bytes, $offset, 65536));
                if (!is_int($written) || $written < 1) self::unavailable();
                $offset += $written;
            }
            if (!@fflush($handle) || !@fsync($handle) || !rewind($handle)) self::unavailable();
            $before = $this->regular(fstat($handle), strlen($bytes));
            $observed = $this->readAll($handle, strlen($bytes));
            $this->sameFile($before, $this->regular(fstat($handle), strlen($bytes)));
            $this->pathFile($temporary, $before);
            if (!hash_equals($facts['content_sha256'], hash('sha256', $observed, true)) || $observed !== $bytes) self::unavailable();
            $this->rootGate();
            $this->sameDirectory($rootStat, $this->directory($rootDirectory, $this->device));
            $this->sameDirectory($objectStat, $this->directory($directory, $this->device));
            clearstatcache(true, $final);
            if (@lstat($final) !== false || !@rename($temporary, $final)) self::unavailable();
            $after = $this->regular(fstat($handle), strlen($bytes));
            if ($before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']) self::unavailable();
            $this->pathFile($final, $after);
        } finally {
            if (!@fclose($handle)) self::unavailable();
        }
        $metadata = ['root_order_id' => $rootOrderId, 'object_key' => $key] + $facts;
        // A fresh open observes the renamed final object and its complete original bytes.
        if ($this->readObject($metadata) !== $bytes) self::unavailable();
        return $metadata;
    }

    /** Only a real authorized metadata row (or this write's seven-key internal observation) may reach this low-level seam. */
    public function readObject(array $metadata): string
    {
        $keys = array_keys($metadata); $objectKeys = self::OBJECT_COLUMNS; $metadataKeys = self::METADATA_COLUMNS;
        sort($keys, SORT_STRING); sort($objectKeys, SORT_STRING); sort($metadataKeys, SORT_STRING);
        if ($keys !== $objectKeys && $keys !== $metadataKeys) self::unavailable();
        foreach ($metadata as $value) if ($value !== null && !is_int($value) && !is_string($value)) self::unavailable();
        foreach (['root_order_id', 'object_key', 'media_type', 'byte_size', 'width', 'height', 'content_sha256'] as $key)
            if (!array_key_exists($key, $metadata)) self::unavailable();
        $rootId = self::storedInteger($metadata['root_order_id'], 1, 2147483647);
        $size = self::storedInteger($metadata['byte_size'], 1, self::MAX_BYTES);
        $width = self::storedInteger($metadata['width'], 1, self::MAX_SIDE);
        $height = self::storedInteger($metadata['height'], 1, self::MAX_SIDE);
        $key = $metadata['object_key']; $mime = $metadata['media_type']; $digest = $metadata['content_sha256'];
        if (!is_string($key) || preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1 || !is_string($mime)
            || !isset(self::MIME_EXTENSION[$mime]) || !is_string($digest) || strlen($digest) !== 32) self::unavailable();
        $this->rootGate();
        $rootDirectory = $this->root . '/' . $rootId;
        $directory = $rootDirectory . '/' . $key;
        $rootStat = $this->directory($rootDirectory, $this->device);
        $objectStat = $this->directory($directory, $this->device);
        $file = $directory . '/content';
        clearstatcache(true, $file);
        $before = $this->regular(@lstat($file), $size);
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) self::unavailable();
        try {
            $this->sameFile($before, $this->regular(fstat($handle), $size));
            $bytes = $this->readAll($handle, $size);
            $this->sameFile($before, $this->regular(fstat($handle), $size));
            $this->pathFile($file, $before);
            $this->rootGate();
            $this->sameDirectory($rootStat, $this->directory($rootDirectory, $this->device));
            $this->sameDirectory($objectStat, $this->directory($directory, $this->device));
            if (!hash_equals($digest, hash('sha256', $bytes, true))) self::unavailable();
            $actual = $this->inspectUpload($bytes, 'evidence.' . self::MIME_EXTENSION[$mime]);
            if ($actual !== ['media_type' => $mime, 'byte_size' => $size, 'width' => $width,
                'height' => $height, 'content_sha256' => $digest]) self::unavailable();
            return $bytes;
        } finally {
            if (!@fclose($handle)) self::unavailable();
        }
    }

    private function rootGate(): void
    {
        if (posix_geteuid() !== $this->owner) self::unavailable();
        $this->sameDirectory($this->rootStat, $this->directory($this->root, $this->device));
    }

    private function directory(string $directory, ?int $device): array
    {
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 07777) !== 0700
            || $stat['uid'] !== $this->owner || ($device !== null && $stat['dev'] !== $device)
            || realpath($directory) !== $directory) self::unavailable();
        return $stat;
    }

    private function regular($stat, int $size): array
    {
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
            || $stat['uid'] !== $this->owner || $stat['nlink'] !== 1 || $stat['dev'] !== $this->device
            || $stat['size'] !== $size) self::unavailable();
        return $stat;
    }

    private function pathFile(string $file, array $descriptor): void
    {
        clearstatcache(true, $file);
        $this->sameFile($descriptor, $this->regular(@lstat($file), $descriptor['size']));
        if (realpath($file) !== $file) self::unavailable();
    }

    private function sameDirectory(array $before, array $after): void
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid'] as $key) if ($before[$key] !== $after[$key]) self::unavailable();
    }

    private function sameFile(array $before, array $after): void
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $key)
            if ($before[$key] !== $after[$key]) self::unavailable();
    }

    private function readAll($handle, int $size): string
    {
        $bytes = '';
        while (strlen($bytes) < $size) {
            $part = @fread($handle, min(65536, $size - strlen($bytes)));
            if (!is_string($part) || $part === '') self::unavailable();
            $bytes .= $part;
        }
        $tail = @fread($handle, 1);
        if ($tail !== '' || !feof($handle)) self::unavailable();
        return $bytes;
    }

    private static function storedInteger($value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && strlen($value) <= 10 && (string)(int)$value === $value) $value = (int)$value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) self::unavailable();
        return $value;
    }
    private static function rootId(int $id): void { self::storedInteger($id, 1, 2147483647); }
    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
