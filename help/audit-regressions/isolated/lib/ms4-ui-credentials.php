<?php
declare(strict_types=1);

/** Private UI handoff only. Loading this library performs no application or filesystem action. */
function m4ui_credentials_write(#[\SensitiveParameter] array $credentials): array
{
    $expect = static function (bool $condition, string $code): void {
        if (!$condition) throw new \RuntimeException('M4UI_CREDENTIALS_' . $code);
    };
    $keys = static function (#[\SensitiveParameter] array $value, array $expected) use ($expect): void {
        $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        $expect($actual === $expected, 'EXACT_FIELDS');
    };
    $select = static function (array $stat, array $fields) use ($expect): array {
        $out = [];
        foreach ($fields as $field) {
            $expect(array_key_exists($field, $stat) && is_int($stat[$field]), 'NATIVE_STAT_FIELDS');
            $out[$field] = $stat[$field];
        }
        return $out;
    };
    $directory = static function (string $path) use ($expect): array {
        clearstatcache(true, $path); $stat = lstat($path);
        $expect(is_array($stat) && isset($stat['mode']) && is_int($stat['mode'])
            && ($stat['mode'] & 0170000) === 0040000 && !is_link($path)
            && realpath($path) === $path, 'REAL_DIRECTORY');
        return $stat;
    };
    $file = static function (array $stat, int $uid, int $dev) use ($expect): void {
        $expect(isset($stat['mode'], $stat['uid'], $stat['dev'], $stat['ino'], $stat['nlink'], $stat['size'])
            && ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 07777) === 0600
            && $stat['uid'] === $uid && $stat['dev'] === $dev && $stat['ino'] > 0
            && $stat['nlink'] === 1 && $stat['size'] >= 0, 'PRIVATE_REGULAR_FILE');
    };
    try {
        $expect(PHP_SAPI === 'cli' && PHP_INT_SIZE >= 8 && DIRECTORY_SEPARATOR === '/'
            && function_exists('posix_geteuid'), 'LINUX_PRIVATE_WRITER_ONLY');
        $keys($credentials, ['customer', 'merchant', 'platform']);
        $spec = ['customer' => ['uid' => 2147483647],
            'merchant' => ['id' => 2147483647, 'mer_id' => 4294967295],
            'platform' => ['id' => 2147483647]];
        foreach ($spec as $kind => $ids) {
            $expect(is_array($credentials[$kind]), 'ACTOR_ROW');
            $row = $credentials[$kind]; $keys($row, array_merge(['account', 'password'], array_keys($ids)));
            $expect(is_string($row['account']) && strlen($row['account']) > 0 && strlen($row['account']) <= 191
                && !str_contains($row['account'], "\0") && is_string($row['password'])
                && strlen($row['password']) > 0 && strlen($row['password']) <= 72
                && !str_contains($row['password'], "\0"), 'BOUNDED_ORIGINAL_CREDENTIAL_BYTES');
            foreach ($ids as $key => $maximum) $expect(is_int($row[$key]) && $row[$key] > 0
                && $row[$key] <= $maximum, 'ACTUAL_POSITIVE_ID_TYPE');
        }
        unset($row);
        $raw = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $length = strlen($raw); $expect($length > 0 && $length <= 16384, 'BOUNDED_PRIVATE_JSON');
        $root = '/run/audit-fixtures'; $parent = '/run/audit-fixtures/ms4-ui';
        $path = '/run/audit-fixtures/ms4-ui/credentials.json';
        $uid = posix_geteuid(); $expect(is_int($uid) && $uid >= 0, 'NATIVE_EUID');
        $directory('/'); $directory('/run'); $rootBefore = $directory($root);
        $expect(($rootBefore['mode'] & 07777) === 0700 && $rootBefore['uid'] === $uid, 'OWNED_PRIVATE_ROOT');
        $identity = ['dev', 'ino', 'mode', 'uid', 'gid'];
        $stable = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
        $rootIdentity = $select($rootBefore, $identity);
        clearstatcache(true, $parent);
        $expect(!file_exists($parent) && !is_link($parent), 'FRESH_PRIVATE_DIRECTORY');
        $stream = null; $previousMask = umask(0077);
        try {
            $expect(mkdir($parent, 0700), 'CREATE_PRIVATE_DIRECTORY');
            $parentBefore = $directory($parent);
            $expect(($parentBefore['mode'] & 07777) === 0700 && $parentBefore['uid'] === $uid
                && $parentBefore['dev'] === $rootBefore['dev'], 'OWNED_PRIVATE_DIRECTORY');
            $parentIdentity = $select($parentBefore, $identity);
            $expect($select($directory($root), $identity) === $rootIdentity, 'ROOT_IDENTITY_AFTER_DIRECTORY');
            $names = scandir($parent); $expect($names === ['.', '..'], 'FRESH_EMPTY_PRIVATE_DIRECTORY');
            clearstatcache(true, $path);
            $expect(!file_exists($path) && !is_link($path), 'NO_EXISTING_CREDENTIAL_FILE');
            $stream = fopen($path, 'x+b'); $expect(is_resource($stream), 'EXCLUSIVE_PRIVATE_OPEN');
            $opened = fstat($stream); $expect(is_array($opened), 'OPEN_HANDLE_STAT');
            $file($opened, $uid, $parentBefore['dev']); $expect($opened['size'] === 0, 'NEW_EMPTY_FILE');
            clearstatcache(true, $path); $named = lstat($path);
            $expect(is_array($named), 'OPEN_PATH_STAT'); $file($named, $uid, $parentBefore['dev']);
            $expect($select($named, $stable) === $select($opened, $stable), 'SAME_OPEN_HANDLE_AND_PATH');
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($stream, substr($raw, $offset));
                $expect(is_int($written) && $written > 0 && $written <= $length - $offset, 'COMPLETE_NATIVE_WRITE');
                $offset += $written;
            }
            $expect($offset === $length && ftell($stream) === $length && fflush($stream), 'FLUSH_COMPLETE_BYTES');
            $writtenStat = fstat($stream); $expect(is_array($writtenStat), 'WRITTEN_HANDLE_STAT');
            $file($writtenStat, $uid, $parentBefore['dev']);
            $expect($writtenStat['size'] === $length
                && $select($writtenStat, $identity) === $select($opened, $identity), 'WRITTEN_SAME_FILE');
            $accepted = $select($writtenStat, $stable);
            clearstatcache(true, $path); $named = lstat($path);
            $expect(is_array($named) && $select($named, $stable) === $accepted, 'WRITTEN_PATH_STABLE');
            $expect(fseek($stream, 0, SEEK_SET) === 0, 'REWIND_WRITTEN_HANDLE');
            $read = stream_get_contents($stream, $length + 1);
            $expect(is_string($read) && strlen($read) === $length && $read === $raw
                && fgetc($stream) === false && feof($stream), 'COMPLETE_SAME_HANDLE_READBACK_EOF');
            $readStat = fstat($stream); $expect(is_array($readStat)
                && $select($readStat, $stable) === $accepted, 'READ_HANDLE_STABLE');
            clearstatcache(true, $path); $named = lstat($path);
            $expect(is_array($named) && $select($named, $stable) === $accepted, 'READ_PATH_STABLE');
            $closing = $stream; $stream = null; $expect(fclose($closing), 'NATIVE_CLOSE');
            clearstatcache(true, $path); $closed = lstat($path);
            $expect(is_array($closed) && $select($closed, $stable) === $accepted, 'CLOSED_PATH_STABLE');
            $file($closed, $uid, $parentBefore['dev']);
            $parentAfter = $directory($parent);
            $expect($select($parentAfter, $identity) === $parentIdentity
                && $select($directory($root), $identity) === $rootIdentity, 'FINAL_DIRECTORY_IDENTITIES');
            $names = scandir($parent); $expect($names === ['.', '..', 'credentials.json'], 'ONLY_PRIVATE_HANDOFF_FILE');
            $proof = ['path' => $path, 'bytes' => $closed['size'], 'sha256' => hash('sha256', $read),
                'mode' => sprintf('%04o', $closed['mode'] & 07777), 'uid' => $closed['uid'], 'gid' => $closed['gid'],
                'dev' => $closed['dev'], 'ino' => $closed['ino'], 'nlink' => $closed['nlink'],
                'directory_mode' => sprintf('%04o', $parentAfter['mode'] & 07777)];
            unset($credentials, $raw, $read);
            return $proof;
        } finally {
            try {
                if (is_resource($stream)) {
                    $closing = $stream; $stream = null; $expect(fclose($closing), 'FAILURE_PATH_NATIVE_CLOSE');
                }
            } finally { umask($previousMask); }
        }
    } catch (\Throwable $error) {
        // Never chain native messages, arguments, credential values or private JSON into the public exception.
        throw new \RuntimeException('M4UI_CREDENTIALS_PRIVATE_WRITE_UNPROVED');
    }
}
