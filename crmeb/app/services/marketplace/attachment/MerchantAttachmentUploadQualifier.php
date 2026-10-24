<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

/** New-upload policy over complete content. No owner, locator, transport or SQL authority. */
final class MerchantAttachmentUploadQualifier
{
    public static function qualify(string $bytes,string $displayName,string $expectedKind,int $maximum,array $extensions,array $mimes): array
    {
        if(!in_array($expectedKind,['image','video','audio'],true))MerchantAttachmentInput::unavailable();
        if(PHP_INT_SIZE<8||$maximum<1)MerchantAttachmentInput::unavailable();
        foreach($extensions as$value)if(!is_string($value))MerchantAttachmentInput::unavailable();
        foreach($mimes as$value)if(!is_string($value))MerchantAttachmentInput::unavailable();
        \app\services\marketplace\MerchantManagementInput::text($displayName,255,true);
        $size=strlen($bytes);
        if($size<1||$size>$maximum)MerchantAttachmentInput::invalid();
        MerchantAttachmentReferenceBytes::room($size);
        $facts=MerchantAttachmentReferenceFormat::qualify($bytes);
        if($facts['resolved_kind']!==$expectedKind||$facts['bytes']!==$size
            ||$facts['sha256']!==hash('sha256',$bytes))MerchantAttachmentInput::invalid();
        $format=$facts['format']['format']??null;
        $allowed=match($format){
            'jpg'=>['jpg','jpeg'], 'png'=>['png'], 'gif'=>['gif'], 'webp'=>['webp'], 'ico'=>['ico'],
            'mp4'=>['mp4'], 'mpeg-audio'=>['mp3'], 'asf-wma'=>['wma'], 'wave'=>['wav'], 'amr','amr-wb'=>['amr'],
            default=>[],
        };
        $extension=strtolower(pathinfo($displayName,PATHINFO_EXTENSION));
        if(!in_array($extension,$allowed,true)||!in_array($extension,$extensions,true))MerchantAttachmentInput::invalid();
        // Only the finite format-proved MIME family is interchangeable. Config's
        // generic octet-stream entry never grants a media kind or format.
        if(!is_array($facts['mime_aliases'])||!in_array($facts['mime'],$facts['mime_aliases'],true)
            ||array_intersect($facts['mime_aliases'],$mimes)===[])MerchantAttachmentInput::invalid();
        return ['resolved_kind'=>$facts['resolved_kind'],'extension'=>$extension,'mime'=>$facts['mime'],
            'size'=>$size,'sha256'=>$facts['sha256'],'format'=>$facts['format'],'type'=>$expectedKind==='video'?1:0];
    }
}
