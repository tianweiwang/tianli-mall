<?php
declare(strict_types=1);

namespace crmeb\compat;

/** Temporary scope for the installed AWS SDK's PHP engine compatibility notices. */
final class AwsErrorCompatibility
{
    public static function run(#[\SensitiveParameter] callable $operation): mixed
    {
        $sourcePrefix=self::sourcePrefix();
        $previous=null;
        $previous=set_error_handler(static function(int $type,string $message,string $file='',int $line=0)use($sourcePrefix,&$previous){
            if($type===E_DEPRECATED){
                $actual=realpath($file);
                if(is_string($actual)&&str_starts_with(self::normalize($actual),$sourcePrefix))return true;
            }
            return is_callable($previous)?$previous($type,$message,$file,$line):false;
        });
        try{
            return $operation();
        }finally{
            restore_error_handler();
        }
    }

    /** Resolve without first autoloading an incompatible AWS interface implementation. */
    private static function sourcePrefix(): string
    {
        $class='Aws\\S3\\S3Client';$file=false;
        if(class_exists($class,false)){
            $file=(new \ReflectionClass($class))->getFileName();
        }else{
            foreach(spl_autoload_functions()as$callback){
                if(!is_array($callback)||!isset($callback[0],$callback[1])
                    ||!$callback[0] instanceof \Composer\Autoload\ClassLoader||$callback[1]!=='loadClass')continue;
                $found=$callback[0]->findFile($class);
                if(is_string($found)){$file=$found;break;}
            }
        }
        $actual=is_string($file)?realpath($file):false;
        if(!is_string($actual))throw new \RuntimeException('Unable to locate the installed AWS SDK source');
        $path=self::normalize($actual);
        if(!str_ends_with($path,self::normalize('/src/S3/S3Client.php')))
            throw new \RuntimeException('Unexpected installed AWS SDK source path');
        return substr($path,0,-strlen('S3/S3Client.php'));
    }

    private static function normalize(string $path): string
    {
        $path=str_replace('\\','/',$path);
        return DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
    }
}
