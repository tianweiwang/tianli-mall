<?php
declare(strict_types=1);

namespace app\services\marketplace\assets;

use crmeb\exceptions\AuthUnavailableException;
use think\facade\Config;

/** Fixed legacy-refund private bytes; named service owns actual identity/family/refund/PDO/full34 and commit. */
final class CustomerLegacyRefundPrivateStorage
{
    private const PHYSICAL = ['uid','object_key','media_type','byte_size','width','height','content_sha256'];
    private const RECEIPT = ['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete'];
    private const MIME_EXTENSIONS = ['image/jpeg'=>['jpg','jpeg'],'image/jpg'=>['jpg','jpeg'],
        'image/png'=>['png'],'image/gif'=>['gif'],'image/webp'=>['webp']];
    private string $root;
    private int $owner;
    private int $device;
    private array $rootStat;
    private array $fresh = [];

    /** Never creates/chmods a deployment root or falls back to public/runtime/cloud storage. */
    public function __construct(private CustomerAssetStorage $images)
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE < 8 || !function_exists('posix_geteuid')
            || !function_exists('fsync') || !class_exists(\finfo::class)
            || !function_exists('getimagesizefromstring') || !function_exists('imagecreatefromstring')) self::unavailable();
        $privateRoot = Config::get('marketplace.assets.customer_refund_private_root');
        $owner = posix_geteuid();
        if (!is_string($privateRoot) || $privateRoot === '' || $privateRoot === '/' || $privateRoot[0] !== '/'
            || str_ends_with($privateRoot,'/') || str_contains($privateRoot,"\0") || str_contains($privateRoot,'\\')
            || !is_int($owner) || $owner < 0) self::unavailable();
        $cursor='';
        foreach (explode('/',substr($privateRoot,1)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') self::unavailable();
            $cursor.='/'.$part; clearstatcache(true,$cursor); $stat=@lstat($cursor);
            if (!is_array($stat) || ($stat['mode']&0170000)!==0040000
                || !in_array($stat['uid'],[0,$owner],true) || ($stat['mode']&0022)!==0) self::unavailable();
        }
        $real=realpath($privateRoot); $application=realpath(dirname(__DIR__,4));
        if ($real !== $privateRoot || !is_string($application) || $real === $application
            || str_starts_with($real.'/',$application.'/')) self::unavailable();
        $this->root=$real; $this->owner=$owner;
        $this->rootStat=$this->directory($real,null); $this->device=$this->rootStat['dev'];
        $this->outsidePublic();
    }

    /** Existing complete-byte/GD/memory/config intersection, including permitted GIF, without a fake review context. */
    public function inspectUpload(string $bytes, string $originalName): array
    {
        $q=$this->images->qualify($bytes,$originalName);
        if (array_keys($q)!==['extension','mime','size','width','height','sha256']
            || !is_string($q['extension']) || !is_string($q['mime'])
            || !isset(self::MIME_EXTENSIONS[$q['mime']]) || !in_array($q['extension'],self::MIME_EXTENSIONS[$q['mime']],true)
            || !is_int($q['size']) || $q['size']!==strlen($bytes) || $q['size']<1
            || !is_int($q['width']) || $q['width']<1 || !is_int($q['height']) || $q['height']<1
            || !is_string($q['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D',$q['sha256'])!==1
            || $q['sha256']!==hash('sha256',$bytes)) self::unavailable();
        $digest=hex2bin($q['sha256']); if (!is_string($digest) || strlen($digest)!==32) self::unavailable();
        return ['media_type'=>$q['mime'],'byte_size'=>$q['size'],'width'=>$q['width'],
            'height'=>$q['height'],'content_sha256'=>$digest,'extension'=>$q['extension']];
    }

    /** Caller has authorized the actual customer/order/refund and locked its real parents on the same PDO. */
    public function writeNew(int $actualUid, string $purpose, int $rootId, ?int $refundId, string $bytes,
        string $originalName, array &$object, array &$receipt): array
    {
        $this->receipt($receipt);
        if ($object!==[] || $receipt['complete']!==false || $receipt['phase']!=='preflight'
            || $receipt['physical_changed']!==false || $receipt['physical_complete']!==false
            || $receipt['database_committed']!==false || $receipt['cleanup_complete']!==null) self::unavailable();
        $this->uid($actualUid);
        \app\dao\marketplace\assets\CustomerLegacyRefundAssetDao::purpose($purpose);
        if ($rootId<1 || $rootId>2147483647 || ($purpose==='legacy_refund_evidence' ? $refundId!==null
            : ($refundId===null || $refundId<1 || $refundId>2147483647))) self::unavailable();
        $facts=$this->inspectUpload($bytes,$originalName); $this->rootGate();
        $parts=['legacy-refund','v1',$purpose,(string)$actualUid,(string)$rootId,(string)($refundId??0),bin2hex(random_bytes(32))];
        $leaf='content.'.$facts['extension']; $key=implode('/',$parts).'/'.$leaf;
        $metadata=['uid'=>$actualUid,'object_key'=>$key,'media_type'=>$facts['media_type'],
            'byte_size'=>$facts['byte_size'],'width'=>$facts['width'],'height'=>$facts['height'],
            'content_sha256'=>$facts['content_sha256']];
        $this->physical($metadata);
        $receipt['phase']='physical_write'; $receipt['physical_changed']=null;
        $cursor=$this->root; $directories=[];
        foreach ($parts as $index=>$part) {
            $cursor.='/'.$part; clearstatcache(true,$cursor); $stat=@lstat($cursor);
            if ($index===count($parts)-1) {
                if ($stat!==false || !@mkdir($cursor,0700)) self::unavailable();
            } elseif ($stat===false && !@mkdir($cursor,0700)) self::unavailable();
            $directories[$cursor]=$this->directory($cursor,$this->device);
        }
        $receipt['physical_changed']=true;
        $directory=$cursor; $temporary=$directory.'/.upload'; $final=$directory.'/'.$leaf;
        $handle=@fopen($temporary,'x+b'); if (!is_resource($handle)) self::unavailable();
        try {
            if (!@chmod($temporary,0600)) self::unavailable();
            $this->regular(fstat($handle),0); $offset=0; $length=strlen($bytes);
            while ($offset<$length) {
                $written=@fwrite($handle,substr($bytes,$offset,65536));
                if (!is_int($written) || $written<1) self::unavailable(); $offset+=$written;
            }
            if (!@fflush($handle) || !@fsync($handle) || !rewind($handle)) self::unavailable();
            $before=$this->regular(fstat($handle),$length); $observed=$this->readAll($handle,$length);
            $this->sameFile($before,$this->regular(fstat($handle),$length)); $this->pathFile($temporary,$before);
            if ($observed!==$bytes || !hash_equals($facts['content_sha256'],hash('sha256',$observed,true))) self::unavailable();
            unset($observed); $this->rootGate();
            foreach ($directories as $path=>$held) $this->sameDirectory($held,$this->directory($path,$this->device));
            clearstatcache(true,$final);
            if (@lstat($final)!==false || !@rename($temporary,$final)) self::unavailable();
            $after=$this->regular(fstat($handle),$length);
            if ($before['dev']!==$after['dev'] || $before['ino']!==$after['ino']) self::unavailable();
            $this->pathFile($final,$after);
        } finally { if (!@fclose($handle)) self::unavailable(); }
        // Only this confirmed new final object may later qualify for narrow pre-commit cleanup.
        if ($this->readObject($metadata)!==$bytes) self::unavailable();
        clearstatcache(true,$final); $file=$this->regular(@lstat($final),$facts['byte_size']);
        foreach ($directories as $path=>$held) $this->sameDirectory($held,$this->directory($path,$this->device));
        $object=['physical7'=>$metadata,'directories'=>$directories,'file_stat'=>$file];
        $this->fresh[$key]=$object; $receipt['physical_complete']=true;
        return $metadata;
    }

    /** Authorized physical7 only. A stored BOUND object is not re-authorized by upload policy or an old cache TTL. */
    public function readObject(array $physical7): string
    {
        [$parts,$leaf]=$this->physical($physical7); $this->rootGate(); $cursor=$this->root; $directories=[];
        foreach ($parts as $part) { $cursor.='/'.$part; $directories[$cursor]=$this->directory($cursor,$this->device); }
        $file=$cursor.'/'.$leaf; clearstatcache(true,$file);
        $before=$this->regular(@lstat($file),$physical7['byte_size']); $handle=@fopen($file,'rb');
        if (!is_resource($handle)) self::unavailable();
        try {
            $this->sameFile($before,$this->regular(fstat($handle),$physical7['byte_size']));
            $bytes=$this->readAll($handle,$physical7['byte_size']);
            $this->sameFile($before,$this->regular(fstat($handle),$physical7['byte_size'])); $this->pathFile($file,$before);
            $this->rootGate();
            foreach ($directories as $path=>$held) $this->sameDirectory($held,$this->directory($path,$this->device));
            if (!hash_equals($physical7['content_sha256'],hash('sha256',$bytes,true))) self::unavailable();
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes); $size=@getimagesizefromstring($bytes);
            if ($mime!==$physical7['media_type'] || !is_array($size) || ($size[0]??null)!==$physical7['width']
                || ($size[1]??null)!==$physical7['height'] || !is_string($size['mime']??null)
                || str_replace('image/jpg','image/jpeg',$size['mime'])!==str_replace('image/jpg','image/jpeg',$mime)) self::unavailable();
            return $bytes;
        } finally { if (!@fclose($handle)) self::unavailable(); }
    }

    /** No commit/physical unknown, old object, shared parent, recursive removal or retry may enter this leaf. */
    public function cleanupNew(array $object, array &$receipt): void
    {
        $this->receipt($receipt);
        if ($receipt['database_committed']!==false || $receipt['complete']!==false
            || $receipt['physical_changed']!==true || $receipt['physical_complete']!==true
            || $receipt['cleanup_complete']!==null || array_keys($object)!==['physical7','directories','file_stat']) self::unavailable();
        $metadata=$object['physical7'];
        if (!is_array($metadata) || !is_string($metadata['object_key']??null)
            || !isset($this->fresh[$metadata['object_key']]) || $this->fresh[$metadata['object_key']]!==$object) self::unavailable();
        [$parts,$leaf]=$this->physical($metadata); $this->readObject($metadata); $this->rootGate();
        $directory=$this->root.'/'.implode('/',$parts); $file=$directory.'/'.$leaf;
        foreach ($object['directories'] as $path=>$held) $this->sameDirectory($held,$this->directory($path,$this->device));
        $this->pathFile($file,$object['file_stat']);
        $members=@scandir($directory); if ($members!==['.','..',$leaf]) self::unavailable();
        // The attempt is single-use even if any native outcome below is unknown.
        unset($this->fresh[$metadata['object_key']]); $receipt['phase']='physical_cleanup'; $receipt['cleanup_complete']=false;
        if (!@unlink($file)) self::unavailable(); clearstatcache(true,$file);
        if (@lstat($file)!==false || @scandir($directory)!==['.','..'] || !@rmdir($directory)) self::unavailable();
        clearstatcache(true,$directory); if (@lstat($directory)!==false) self::unavailable();
        $this->rootGate(); $receipt['cleanup_complete']=true;
    }

    /** Complete canonical legacy-refund key; never an arbitrary filesystem path or public locator. */
    private function physical(array $row): array
    {
        if (array_keys($row)!==self::PHYSICAL || !is_int($row['uid'])) self::unavailable(); $this->uid($row['uid']);
        foreach (['byte_size','width','height'] as $key) if (!is_int($row[$key]) || $row[$key]<1) self::unavailable();
        if (!is_string($row['media_type']) || !isset(self::MIME_EXTENSIONS[$row['media_type']])
            || !is_string($row['content_sha256']) || strlen($row['content_sha256'])!==32 || !is_string($row['object_key'])
            || preg_match('/\Alegacy-refund\/v1\/(legacy_refund_evidence|refund_shipping_proof)\/([1-9][0-9]*)\/([1-9][0-9]{0,9})\/(0|[1-9][0-9]{0,9})\/([a-f0-9]{64})\/content\.(jpg|jpeg|png|gif|webp)\z/D',$row['object_key'],$match)!==1
            || $match[2] !== (string)$row['uid'] || !in_array($match[6],self::MIME_EXTENSIONS[$row['media_type']],true)) self::unavailable();
        foreach ([3,4] as $index) if (strlen($match[$index])===10 && strcmp($match[$index],'2147483647')>0) self::unavailable();
        if ($match[1]==='legacy_refund_evidence' ? $match[4]!=='0' : $match[4]==='0') self::unavailable();
        $parts=explode('/',$row['object_key']); $leaf=array_pop($parts); return [$parts,$leaf];
    }
    private function uid(int $uid): void { if ($uid<1 || $uid>4294967295) self::unavailable(); }
    private function receipt(array $receipt): void
    {
        $keys=array_keys($receipt); sort($keys,SORT_STRING);
        if ($keys!==self::RECEIPT || !is_bool($receipt['complete']) || !is_string($receipt['phase'])
            || !in_array($receipt['physical_changed'],[null,false,true],true) || !is_bool($receipt['physical_complete'])
            || !in_array($receipt['database_committed'],[null,false,true],true)
            || !in_array($receipt['cleanup_complete'],[null,false,true],true)) self::unavailable();
    }
    private function rootGate(): void
    {
        if (posix_geteuid()!==$this->owner || Config::get('marketplace.assets.customer_refund_private_root')!==$this->root) self::unavailable();
        $this->sameDirectory($this->rootStat,$this->directory($this->root,$this->device));
        $this->outsidePublic();
    }

    private function outsidePublic(): void
    {
        $configured=Config::get('filesystem.disks.public.root');
        $public=is_string($configured) && $configured!=='' ? realpath($configured) : false;
        if (!is_string($public) || $this->root===$public
            || str_starts_with($this->root.'/',rtrim($public,'/').'/')) self::unavailable();
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

    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
