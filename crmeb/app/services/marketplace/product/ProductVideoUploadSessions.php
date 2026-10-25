<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\Request;
use app\services\marketplace\MerchantManagementInput as Input;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use crmeb\exceptions\AuthUnavailableException;
use think\exception\HttpException;
use think\facade\Config;

/** One private, bounded platform-video staging purpose; never a commercial object writer. */
final class ProductVideoUploadSessions
{
    public const CHUNK_BYTES = 3145728; // Original uploadByPieces pieceSize=3 MiB.
    private const PURPOSE = 'product-video-upload-v1';
    private const STAT = ['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];

    public function __construct(private MerchantAttachmentStorage $storage) {}

    public static function enabled(): bool
    {
        return Config::get('marketplace.product_video_upload.enabled', false) === true;
    }

    public static function needsCollection(): bool
    {
        return self::enabled() || Config::get('marketplace.product_video_upload.private_root', '') !== '';
    }

    private static function fail(): never { throw new AuthUnavailableException(); }
    private static function invalid(): never { throw new HttpException(422, '请求参数不正确'); }

    private static function reference(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{32}\z/D', $value) !== 1) self::invalid();
        return $value;
    }

    private static function stat(string $path): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1) self::fail();
        $out = []; foreach (self::STAT as $key) $out[$key] = $stat[$key];
        return $out;
    }

    private static function sameHandle($handle, array $expected): void
    {
        $actual = fstat($handle);
        foreach (self::STAT as $key) if (!is_array($actual) || $actual[$key] !== $expected[$key]) self::fail();
    }

    private static function writeAll($handle, string $bytes): void
    {
        $offset = 0; $size = strlen($bytes);
        while ($offset < $size) {
            $written = fwrite($handle, substr($bytes, $offset, 1048576));
            if (!is_int($written) || $written < 1) self::fail();
            $offset += $written;
        }
        if (!fflush($handle) || !fsync($handle)) self::fail();
    }

    private static function read(string $path, int $maximum): string
    {
        $before = self::stat($path);
        if ($before['size'] < 0 || $before['size'] > $maximum) self::fail();
        $handle = @fopen($path, 'rb'); if (!is_resource($handle)) self::fail();
        try {
            self::sameHandle($handle, $before); $bytes = '';
            while (strlen($bytes) < $before['size']) {
                $part = fread($handle, min(1048576, $before['size'] - strlen($bytes)));
                if (!is_string($part) || $part === '') self::fail(); $bytes .= $part;
            }
            if (fread($handle, 1) !== '' || !feof($handle)) self::fail();
            self::sameHandle($handle, $before);
            if (self::stat($path) !== $before) self::fail();
            return $bytes;
        } finally { if (!fclose($handle)) self::fail(); }
    }

    /** The caller must provision this dedicated persistent root, shared by all workers and the existing timer. */
    private function locked(callable $operation, bool $collecting = false): mixed
    {
        if (!$collecting && !self::enabled()) throw new HttpException(422, '视频分片暂存未启用，请使用整文件上传');
        $configured = Config::get('marketplace.product_video_upload.private_root');
        if (!is_string($configured) || $configured === '' || str_contains($configured, "\0") || is_link($configured))
            throw new HttpException(422, '视频分片暂存目录未配置，请联系管理员');
        $root = realpath($configured); $public = realpath(app()->getRootPath() . 'public');
        $uploads = realpath(Config::get('filesystem.disks.public.root', ''));
        if ($root === false || $root !== rtrim($configured, '/\\') || !is_writable($root)
            || $public === false || $uploads === false) self::fail();
        foreach ([$public, $uploads] as $forbidden)
            if ($root === $forbidden || str_starts_with($root, $forbidden . DIRECTORY_SEPARATOR)
                || str_starts_with($forbidden, $root . DIRECTORY_SEPARATOR)) self::fail();
        $native = lstat($root);
        if (!is_array($native) || ($native['mode'] & 0170000) !== 0040000 || ($native['mode'] & 0777) !== 0700) self::fail();
        $path = $root . DIRECTORY_SEPARATOR . '.lock';
        if (is_link($path)) self::fail();
        $lock = @fopen($path, 'c+b'); if (!is_resource($lock)) self::fail();
        try {
            if (!chmod($path, 0600)) self::fail();
            self::sameHandle($lock, self::stat($path));
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new HttpException(422, '视频分片正在处理，请稍后重试');
            clearstatcache(true, $root);
            if (lstat($root)['dev'] !== $native['dev'] || lstat($root)['ino'] !== $native['ino'] || realpath($root) !== $root) self::fail();
            return $operation($root);
        } finally {
            $unlocked = flock($lock, LOCK_UN); $closed = fclose($lock);
            if (!$unlocked || !$closed) self::fail();
        }
    }

    private static function paths(string $root, string $reference): array
    {
        $base = $root . DIRECTORY_SEPARATOR . self::reference($reference);
        return [$base . '.json', $base . '.bin'];
    }

    private static function state(string $root, string $reference): array
    {
        [$path] = self::paths($root, $reference);
        if (!file_exists($path)) throw new HttpException(410, '视频分片已失效，请重新上传');
        $state = json_decode(self::read($path, 65536), true, 32, JSON_THROW_ON_ERROR);
        $keys = ['purpose','binding','actor','filename','total_size','chunk_size','total_chunks','chunks',
            'created_at','expires_at','state','sha256','result','data_dev','data_ino'];
        if (!is_array($state) || array_keys($state) !== $keys || $state['purpose'] !== self::PURPOSE
            || !is_string($state['binding']) || preg_match('/\A[0-9a-f]{64}\z/D', $state['binding']) !== 1
            || !is_string($state['actor']) || preg_match('/\A[0-9a-f]{64}\z/D', $state['actor']) !== 1
            || !is_string($state['filename']) || !is_array($state['chunks']) || !array_is_list($state['chunks'])
            || !in_array($state['state'], ['open','ready','processing','complete'], true)
            || !is_int($state['created_at']) || !is_int($state['expires_at']) || $state['created_at'] < 1
            || $state['expires_at'] <= $state['created_at'] || !is_int($state['data_dev']) || !is_int($state['data_ino'])
            || ($state['result'] !== null && (!is_string($state['result']) || $state['result'] === ''))
            || ($state['sha256'] !== null && (!is_string($state['sha256']) || preg_match('/\A[0-9a-f]{64}\z/D', $state['sha256']) !== 1))) self::fail();
        Input::integer($state['total_size'], 1, PHP_INT_MAX);
        if ($state['chunk_size'] !== self::CHUNK_BYTES || $state['total_chunks'] !== intdiv($state['total_size'] - 1, self::CHUNK_BYTES) + 1
            || count($state['chunks']) > $state['total_chunks']) self::fail();
        foreach ($state['chunks'] as $hash) if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1) self::fail();
        return $state;
    }

    private static function save(string $root, string $reference, array $state): void
    {
        [$path] = self::paths($root, $reference); $temporary = $path . '.new';
        $bytes = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($bytes) > 65536 || file_exists($temporary) || is_link($temporary)) self::fail();
        $handle = @fopen($temporary, 'x+b'); if (!is_resource($handle)) self::fail();
        $renamed = false; $temporaryNative = fstat($handle);
        try {
            if (!is_array($temporaryNative)) self::fail();
            if (!chmod($temporary, 0600)) self::fail(); $temporaryNative = self::stat($temporary); self::sameHandle($handle, $temporaryNative);
            self::writeAll($handle, $bytes);
            if (self::read($temporary, 65536) !== $bytes) self::fail();
            if (!fclose($handle)) self::fail(); $handle = null;
            if (is_link($path) || !rename($temporary, $path)) self::fail(); $renamed = true;
            if (self::read($path, 65536) !== $bytes) self::fail();
        } finally {
            if (is_resource($handle) && !fclose($handle)) self::fail();
            if (!$renamed && is_array($temporaryNative)) {
                clearstatcache(true, $temporary); $actual = @lstat($temporary);
                if (!is_array($actual) || is_link($temporary) || $actual['dev'] !== $temporaryNative['dev']
                    || $actual['ino'] !== $temporaryNative['ino'] || !unlink($temporary)) self::fail();
            }
        }
    }

    private static function data(string $root, string $reference, array $state): string
    {
        [, $path] = self::paths($root, $reference); $native = self::stat($path);
        if ($native['dev'] !== $state['data_dev'] || $native['ino'] !== $state['data_ino']) self::fail();
        return $path;
    }

    private static function entries(string $root): array
    {
        $entries = []; $directory = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
        foreach ($directory as $item) {
            $name = $item->getFilename(); if ($name === '.lock') continue;
            if (count($entries) >= 2048 || preg_match('/\A([0-9a-f]{32})\.(json|bin)\z/D', $name, $match) !== 1) self::fail();
            self::stat($item->getPathname()); $entries[$name] = $match[1];
        }
        return array_values(array_unique($entries));
    }

    private static function remove(string $root, string $reference, array $state): void
    {
        [$manifest] = self::paths($root, $reference); $data = self::data($root, $reference, $state);
        self::stat($manifest);
        if (!unlink($data) || !unlink($manifest)) self::fail();
        clearstatcache(true, $data); clearstatcache(true, $manifest);
        if (file_exists($data) || file_exists($manifest)) self::fail();
    }

    private static function collect(string $root, int $limit): array
    {
        $removed = 0; $held = 0;
        foreach (self::entries($root) as $reference) {
            $state = self::state($root, $reference);
            if ($state['state'] === 'processing') { ++$held; continue; } // Unknown SQL/physical outcomes keep their original responsibility.
            if ($state['expires_at'] > time() || $removed >= $limit) continue;
            self::remove($root, $reference, $state); ++$removed;
        }
        return ['removed' => $removed, 'held' => $held];
    }

    public function gcBatch(int $limit): array
    {
        Input::integer($limit, 1, 256);
        if (!self::needsCollection()) return ['state' => 'disabled'];
        return $this->locked(static fn(string $root): array => ['state' => 'complete'] + self::collect($root, $limit), true);
    }

    /** binding/actor come only from the actual authorized product writer, never Request body. */
    public function command(Request $request, string $binding, string $actor, array $body, callable $publish, callable $observe): array
    {
        if (app()->request !== $request || preg_match('/\A[0-9a-f]{64}\z/D', $binding) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $actor) !== 1) self::fail();
        return $this->locked(function (string $root) use ($request, $binding, $actor, $body, $publish, $observe): array {
            $rootNative = lstat($root);
            if (!is_array($rootNative)) self::fail();
            $binding = hash('sha256', self::PURPOSE . "\0" . $root . "\0" . $rootNative['dev'] . "\0" . $rootNative['ino'] . "\0" . $binding);
            $maximum = Config::get('upload.filesize'); if (!is_int($maximum) || $maximum < 1) self::fail();
            if ($body['upload_operation'] === 'issue') {
                $size = Input::integer($body['total_size'] ?? null, 1, $maximum, true);
                $name = Input::text($body['filename'] ?? null, 255, true);
                if (strpbrk($name, "/\\\0") !== false || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mp4') self::invalid();
                $maximumOpen = Input::integer(Config::get('marketplace.product_video_upload.max_open_sessions'), 1, 1024, true);
                $perActor = Input::integer(Config::get('marketplace.product_video_upload.per_actor'), 1, $maximumOpen, true);
                $ttl = Input::integer(Config::get('marketplace.product_video_upload.ttl_seconds'), 1, 86400, true);
                self::collect($root, 16); $entries = self::entries($root); $owned = 0;
                foreach ($entries as $id) if (self::state($root, $id)['actor'] === $actor) ++$owned;
                if (count($entries) >= $maximumOpen || $owned >= $perActor) throw new HttpException(422, '视频暂存容量已满，请稍后重试');
                $reference = bin2hex(random_bytes(16)); [$manifest, $path] = self::paths($root, $reference);
                if (file_exists($manifest) || is_link($manifest) || file_exists($path) || is_link($path)) self::fail();
                $handle = @fopen($path, 'x+b'); if (!is_resource($handle)) self::fail();
                $native = fstat($handle);
                try {
                    if (!is_array($native) || !chmod($path, 0600)) self::fail();
                    $native = self::stat($path); self::sameHandle($handle, $native);
                } catch (\Throwable $error) {
                    clearstatcache(true, $path); $actual = @lstat($path);
                    if (is_array($native) && is_array($actual) && !is_link($path)
                        && $actual['dev'] === $native['dev'] && $actual['ino'] === $native['ino']) {
                        if (!unlink($path)) self::fail();
                    }
                    throw $error;
                }
                finally { if (!fclose($handle)) self::fail(); }
                $now = time(); $state = ['purpose' => self::PURPOSE, 'binding' => $binding, 'actor' => $actor, 'filename' => $name,
                    'total_size' => $size, 'chunk_size' => self::CHUNK_BYTES, 'total_chunks' => intdiv($size - 1, self::CHUNK_BYTES) + 1,
                    'chunks' => [], 'created_at' => $now, 'expires_at' => $now + $ttl, 'state' => 'open', 'sha256' => null,
                    'result' => null, 'data_dev' => $native['dev'], 'data_ino' => $native['ino']];
                try { self::save($root, $reference, $state); }
                catch (\Throwable $error) {
                    // A failed initial manifest write has no issued reference and no SQL/physical publication.
                    if (!file_exists($manifest)) {
                        $actual = self::stat($path);
                        if ($actual['dev'] !== $native['dev'] || $actual['ino'] !== $native['ino'] || !unlink($path)) self::fail();
                    }
                    throw $error;
                }
                return ['upload_reference' => $reference, 'chunk_size' => self::CHUNK_BYTES, 'total_chunks' => $state['total_chunks'],
                    'expires_at' => $state['expires_at'], 'code' => 1, 'msg' => 'waiting', 'file_path' => ''];
            }
            $reference = self::reference($body['upload_reference'] ?? null); $state = self::state($root, $reference);
            if (!hash_equals($state['binding'], $binding) || !hash_equals($state['actor'], $actor)) throw new HttpException(403, '没有权限访问');
            if ($state['state'] === 'processing') throw new AuthUnavailableException();
            if ($body['upload_operation'] === 'cancel') {
                self::remove($root, $reference, $state); return ['code' => 0, 'msg' => 'closed', 'file_path' => ''];
            }
            if ($state['expires_at'] <= time()) throw new HttpException(410, '视频分片已失效，请重新上传');
            if ($state['total_size'] > $maximum) self::invalid();
            if ($body['upload_operation'] === 'chunk') {
                if (!in_array($state['state'], ['open','ready'], true)) self::invalid();
                $number = Input::integer($body['chunkNumber'] ?? null, 1, $state['total_chunks'], true);
                $uploaded = $this->storage->readTypedUploadedFile($request); $bytes = $uploaded['bytes']; unset($uploaded);
                $size = $number === $state['total_chunks'] ? $state['total_size'] - ($number - 1) * self::CHUNK_BYTES : self::CHUNK_BYTES;
                if (strlen($bytes) !== $size || $number > count($state['chunks']) + 1) self::invalid();
                $hash = hash('sha256', $bytes); $path = self::data($root, $reference, $state);
                if ($number <= count($state['chunks'])) {
                    if (!hash_equals($state['chunks'][$number - 1], $hash)) self::invalid();
                    $all = self::read($path, $maximum);
                    if (substr($all, ($number - 1) * self::CHUNK_BYTES, $size) !== $bytes) self::fail();
                } else {
                    $before = self::stat($path);
                    if ($before['size'] !== ($number - 1) * self::CHUNK_BYTES) self::fail();
                    $handle = @fopen($path, 'r+b'); if (!is_resource($handle)) self::fail();
                    try {
                        self::sameHandle($handle, $before);
                        if (fseek($handle, 0, SEEK_END) !== 0) self::fail(); self::writeAll($handle, $bytes);
                        $after = self::stat($path); self::sameHandle($handle, $after);
                        if ($after['dev'] !== $before['dev'] || $after['ino'] !== $before['ino'] || $after['size'] !== $before['size'] + $size) self::fail();
                    } finally { if (!fclose($handle)) self::fail(); }
                    $all = self::read($path, $maximum);
                    if (strlen($all) !== $before['size'] + $size || substr($all, $before['size']) !== $bytes) self::fail();
                    $state['chunks'][] = $hash;
                    if ($number === $state['total_chunks']) {
                        if (strlen($all) !== $state['total_size']) self::fail();
                        foreach ($state['chunks'] as $index => $expected)
                            if (!hash_equals($expected, hash('sha256', substr($all, $index * self::CHUNK_BYTES, self::CHUNK_BYTES)))) self::fail();
                        $state['sha256'] = hash('sha256', $all); $state['state'] = 'ready';
                    }
                    self::save($root, $reference, $state);
                }
                return ['code' => $state['state'] === 'ready' ? 2 : 1, 'msg' => $state['state'] === 'ready' ? 'success' : 'waiting',
                    'file_path' => $state['state'] === 'ready' ? $reference : ''];
            }
            if ($body['upload_operation'] !== 'finalize') self::invalid();
            if ($state['state'] === 'complete') { $observe($state['result']); return ['src' => $state['result']]; }
            if ($state['state'] !== 'ready') self::invalid();
            $bytes = self::read(self::data($root, $reference, $state), $maximum);
            if (strlen($bytes) !== $state['total_size'] || !hash_equals($state['sha256'], hash('sha256', $bytes))) self::fail();
            $this->storage->qualifyTypedUploadBytes($bytes, $state['filename'], 'video');
            $state['state'] = 'processing'; self::save($root, $reference, $state);
            // No retry after this boundary unless the original publisher returned its actual committed result.
            $src = $publish(['bytes' => $bytes, 'display_name' => $state['filename']]);
            if (!is_string($src) || $src === '') self::fail();
            $state['state'] = 'complete'; $state['result'] = $src; self::save($root, $reference, $state);
            return ['src' => $src];
        });
    }
}
