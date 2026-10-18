<?php
declare(strict_types=1);

/** Fixed private-file metadata only; importing this file does not read the handoff. */
function m4ui_credential_meta(): array
{
    $expect = static function (bool $ok): void {
        if (!$ok) throw new \RuntimeException('M4UI_CREDENTIAL_META_UNPROVED');
    };
    $stable = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
    $select = static function (array $stat) use ($expect, $stable): array {
        $out = [];
        foreach ($stable as $field) {
            $expect(array_key_exists($field, $stat) && is_int($stat[$field]));
            $out[$field] = $stat[$field];
        }
        return $out;
    };
    $directory = static function (string $path) use ($expect, $select): array {
        clearstatcache(true, $path); $stat = @lstat($path);
        $expect(is_array($stat) && isset($stat['mode']) && is_int($stat['mode'])
            && ($stat['mode'] & 0170000) === 0040000 && !is_link($path) && realpath($path) === $path);
        return $select($stat);
    };
    $root = '/run/audit-fixtures'; $parent = '/run/audit-fixtures/ms4-ui';
    $path = '/run/audit-fixtures/ms4-ui/credentials.json';
    $stream = null; $raw = null; $extra = null;
    try {
        $expect(PHP_SAPI === 'cli' && PHP_INT_SIZE >= 8 && DIRECTORY_SEPARATOR === '/'
            && function_exists('posix_geteuid'));
        $uid = posix_geteuid(); $expect(is_int($uid) && $uid >= 0);
        $directory('/'); $directory('/run');
        $rootBefore = $directory($root); $parentBefore = $directory($parent);
        foreach ([$rootBefore, $parentBefore] as $dir) $expect(($dir['mode'] & 07777) === 0700
            && $dir['uid'] === $uid && $dir['dev'] > 0 && $dir['ino'] > 0);
        $expect($rootBefore['dev'] === $parentBefore['dev']);
        $directories = static function () use ($expect, $directory, $root, $parent, $rootBefore, $parentBefore): void {
            $expect($directory($root) === $rootBefore && $directory($parent) === $parentBefore);
            $expect(@scandir($parent) === ['.', '..', 'credentials.json']);
        };
        $file = static function () use ($expect, $select, $path, $uid, $parentBefore): array {
            clearstatcache(true, $path); $stat = @lstat($path);
            $expect(is_array($stat) && !is_link($path) && realpath($path) === $path);
            $stat = $select($stat);
            $expect(($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 07777) === 0600
                && $stat['uid'] === $uid && $stat['dev'] === $parentBefore['dev']
                && $stat['dev'] > 0 && $stat['ino'] > 0 && $stat['nlink'] === 1
                && $stat['size'] > 0 && $stat['size'] <= 16384);
            return $stat;
        };
        $directories(); $before = $file();
        $stream = @fopen($path, 'rb'); $expect(is_resource($stream));
        $opened = @fstat($stream); $expect(is_array($opened) && $select($opened) === $before);
        $expect($file() === $before); $directories();
        $raw = @stream_get_contents($stream, $before['size'] + 1);
        $extra = @fgetc($stream);
        $expect(is_string($raw) && strlen($raw) === $before['size'] && $extra === false && feof($stream));
        $read = @fstat($stream); $expect(is_array($read) && $select($read) === $before);
        $expect($file() === $before); $directories();
        $closing = $stream; $stream = null; $expect(@fclose($closing)); unset($closing);
        $after = $file(); $expect($after === $before); $directories();
        return ['path' => $path, 'bytes' => $after['size'], 'sha256' => hash('sha256', $raw),
            'mode' => sprintf('%04o', $after['mode'] & 07777), 'uid' => $after['uid'], 'gid' => $after['gid'],
            'dev' => $after['dev'], 'ino' => $after['ino'], 'nlink' => $after['nlink'],
            'directory_mode' => sprintf('%04o', $parentBefore['mode'] & 07777)];
    } catch (\Throwable $error) {
        // Do not chain native errors, paths, arguments or private bytes.
        throw new \RuntimeException('M4UI_CREDENTIAL_META_UNPROVED');
    } finally {
        try {
            if (is_resource($stream)) {
                $closing = $stream; $stream = null;
                $expect(@fclose($closing)); unset($closing);
            }
        } finally {
            if (is_string($raw)) {
                for ($i = 0, $length = strlen($raw); $i < $length; ++$i) $raw[$i] = "\0";
            }
            if (is_string($extra)) {
                for ($i = 0, $length = strlen($extra); $i < $length; ++$i) $extra[$i] = "\0";
            }
            unset($raw, $extra);
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $exitCode = 1;
    try {
        if (PHP_SAPI !== 'cli' || !isset($_SERVER['argv']) || !is_array($_SERVER['argv'])
            || count($_SERVER['argv']) !== 2 || $_SERVER['argv'][1] !== 'metadata') {
            throw new \RuntimeException('M4UI_CREDENTIAL_META_UNPROVED');
        }
        $metadata = m4ui_credential_meta();
        $output = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@fwrite(STDOUT, $output) !== strlen($output) || !@fflush(STDOUT)) {
            throw new \RuntimeException('M4UI_CREDENTIAL_META_UNPROVED');
        }
        $exitCode = 0;
    } catch (\Throwable $error) {
        @fwrite(STDERR, "M4UI_CREDENTIAL_META_UNPROVED\n");
    } finally {
        unset($metadata, $output, $error);
    }
    exit($exitCode);
}
