<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

/** Finite content/container qualification; not a playback/codec decoder. */
final class MerchantAttachmentReferenceFormat
{
    public static function qualify(string $bytes): array
    {
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!is_string($mime)||$bytes==='')self::bad();
        $kind='';$mimes=[];$format=[];
        if(in_array($mime,['image/jpeg','image/jpg','image/png','image/gif','image/webp'],true)){
            $dimensions=@getimagesizefromstring($bytes);$types=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_GIF=>'gif',IMAGETYPE_WEBP=>'webp'];
            if(!is_array($dimensions)||!isset($types[$dimensions[2]??0])||!is_int($dimensions[0]??null)||!is_int($dimensions[1]??null))self::bad();
            $extension=$types[$dimensions[2]];
            $expectedMime=match($extension){'jpg'=>['image/jpeg','image/jpg'],'png'=>['image/png'],'gif'=>['image/gif'],'webp'=>['image/webp']};
            if(!in_array($mime,$expectedMime,true))self::bad();
            self::image($bytes,$dimensions[0],$dimensions[1]);$kind='image';
            $mimes=in_array($mime,['image/jpeg','image/jpg'],true)?['image/jpeg','image/jpg']:[$mime];
            $format=['format'=>$extension,'width'=>$dimensions[0],'height'=>$dimensions[1],'decoded'=>true];
        }elseif(in_array($mime,['image/x-icon','image/vnd.microsoft.icon'],true)){
            $kind='image';$mimes=['image/x-icon','image/vnd.microsoft.icon'];$format=self::icon($bytes);
        }elseif(in_array($mime,['audio/wav','audio/wave','audio/x-wav','audio/vnd.wave'],true)){
            $kind='audio';$mimes=['audio/wav','audio/wave','audio/x-wav','audio/vnd.wave'];$format=self::wave($bytes);
        }elseif(in_array($mime,['audio/mpeg','audio/mp3','audio/x-mp3'],true)){
            $kind='audio';$mimes=['audio/mpeg','audio/mp3','audio/x-mp3'];$format=self::mpeg($bytes);
        }elseif(in_array($mime,['audio/amr','audio/amr-wb'],true)){
            $kind='audio';$format=self::amr($bytes,$mime);$mimes=$format['format']==='amr-wb'?['audio/amr','audio/amr-wb']:['audio/amr'];
        }elseif(in_array($mime,['audio/x-ms-wma','audio/x-ms-asf','video/x-ms-asf','application/vnd.ms-asf'],true)){
            // ASF is a container MIME. Only actual audio Stream Properties may
            // resolve it to WMA; a video/mixed ASF is never inferred as audio.
            $kind='audio';$mimes=['audio/x-ms-wma','audio/x-ms-asf','video/x-ms-asf','application/vnd.ms-asf'];$format=self::asf($bytes);
        }elseif(in_array($mime,['video/mp4','application/mp4'],true)){
            $kind='video';$mimes=['video/mp4','application/mp4'];$format=self::mp4($bytes);
        }else self::bad();
        // Persisted-object reference qualification is deliberately independent
        // of an administrator's later new-upload Config size/extension policy.
        return ['resolved_kind'=>$kind,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),
            'mime'=>$mime,'mime_aliases'=>$mimes,'format'=>$format];
    }

    private static function image(string $bytes,int $width,int $height): void
    {
        if($width<1||$height<1||$width>intdiv(PHP_INT_MAX,$height))self::bad();
        MerchantAttachmentReferenceBytes::room(strlen($bytes),$width*$height);$image=null;
        try{
            $image=@imagecreatefromstring($bytes);
            if(!$image instanceof \GdImage||imagesx($image)!==$width||imagesy($image)!==$height)self::bad();
        }finally{if($image instanceof \GdImage)imagedestroy($image);}
    }

    private static function icon(string $bytes): array
    {
        if(substr($bytes,0,4)!=="\0\0\1\0")self::bad();$count=self::u16($bytes,4);
        if($count<1||$count>intdiv(strlen($bytes)-6,16))self::bad();$width=0;$height=0;
        for($i=0;$i<$count;$i++){
            $at=6+$i*16;$w=ord($bytes[$at])?:256;$h=ord($bytes[$at+1])?:256;
            $size=self::u32($bytes,$at+8);$offset=self::u32($bytes,$at+12);
            if($bytes[$at+3]!=="\0"||$offset<6+$count*16||$size<1||$offset>strlen($bytes)-$size)self::bad();
            $payload=substr($bytes,$offset,$size);
            if(str_starts_with($payload,"\x89PNG\r\n\x1a\n")){
                $dimensions=@getimagesizefromstring($payload);
                if(!is_array($dimensions)||$dimensions[2]!==IMAGETYPE_PNG||$dimensions[0]!==$w||$dimensions[1]!==$h)self::bad();
                self::image($payload,$w,$h);
            }elseif(self::u32($payload,0)===12){
                // OS/2 BITMAPCOREHEADER uses a three-byte palette. Convert only
                // this in-memory DIB representation for the same GD pixel check.
                $bits=self::u16($payload,10);
                if(self::u16($payload,4)!==$w||self::u16($payload,6)!==$h*2||self::u16($payload,8)!==1
                    ||!in_array($bits,[1,4,8,24],true))self::bad();
                $colors=$bits<=8?1<<$bits:0;$pixels=12+$colors*3;
                $xor=intdiv($w*$bits+31,32)*4*$h;$mask=intdiv($w+31,32)*4*$h;
                if(strlen($payload)!==$pixels+$xor+$mask)self::bad();$palette='';
                for($color=0;$color<$colors;$color++)$palette.=substr($payload,12+$color*3,3)."\0";
                $bitmap=pack('VVVvvVVVVVV',40,$w,$h,1,$bits,0,$xor,0,0,$colors,0).$palette.substr($payload,$pixels,$xor);
                self::image('BM'.pack('VvvV',14+strlen($bitmap),0,0,54+strlen($palette)).$bitmap,$w,$h);
            }else{
                $header=self::u32($payload,0);$bits=self::u16($payload,14);$compression=self::u32($payload,16);
                if(!in_array($header,[40,52,56,108,124],true)||strlen($payload)<$header||self::u32($payload,4)!==$w
                    ||self::u32($payload,8)!==$h*2||self::u16($payload,12)!==1
                    ||!in_array($bits,[1,4,8,16,24,32],true)||!in_array($compression,[0,3],true)
                    ||($compression===3&&!in_array($bits,[16,32],true)))self::bad();
                $colors=self::u32($payload,32);if($bits<=8){if($colors===0)$colors=1<<$bits;if($colors>(1<<$bits))self::bad();}
                elseif($colors>intdiv(strlen($payload)-$header,4))self::bad();
                $pixels=$header+($compression===3&&$header===40?12:0)+$colors*4;
                $xor=intdiv($w*$bits+31,32)*4*$h;$mask=intdiv($w+31,32)*4*$h;
                if(strlen($payload)!==$pixels+$xor+$mask&&!(($bits===32)&&strlen($payload)===$pixels+$xor))self::bad();
                if($header===124&&(self::u32($payload,112)!==0||self::u32($payload,116)!==0))self::bad();
                $bitmap=substr($payload,0,$pixels+$xor);
                $bitmap=substr_replace($bitmap,pack('V',$h),8,4);$bitmap=substr_replace($bitmap,pack('V',$xor),20,4);
                self::image('BM'.pack('VvvV',14+strlen($bitmap),0,0,14+$pixels).$bitmap,$w,$h);
            }
            $width=max($width,$w);$height=max($height,$h);unset($payload,$bitmap);
        }
        return ['format'=>'ico','images'=>$count,'width'=>$width,'height'=>$height,'decoded'=>true];
    }

    private static function wave(string $bytes): array
    {
        if(substr($bytes,0,4)!=='RIFF'||substr($bytes,8,4)!=='WAVE'||self::u32($bytes,4)!==strlen($bytes)-8)self::bad();
        $at=12;$fmt=null;$data=0;$chunks=0;
        while($at<strlen($bytes)){
            self::range($bytes,$at,8);$tag=substr($bytes,$at,4);$size=self::u32($bytes,$at+4);$start=$at+8;
            self::range($bytes,$start,$size);$end=$start+$size+($size&1);if($end>strlen($bytes))self::bad();
            if($tag==='fmt '){if($fmt!==null||$size<16)self::bad();
                $code=self::u16($bytes,$start);$channels=self::u16($bytes,$start+2);$rate=self::u32($bytes,$start+4);
                $align=self::u16($bytes,$start+12);$bits=self::u16($bytes,$start+14);
                if($code<1||$channels<1||$rate<1||$align<1)self::bad();
                if($size>16&&($size<18||self::u16($bytes,$start+16)>$size-18))self::bad();
                if($code===65534){
                    if($size<40||self::u16($bytes,$start+16)<22||substr($bytes,$start+28,12)!==hex2bin('00001000800000aa00389b71'))self::bad();
                    $code=self::u32($bytes,$start+24);
                }
                if(in_array($code,[1,3],true)){
                    if($bits<1||$bits%8!==0||intdiv($channels*$bits,8)!==$align||$rate>intdiv(4294967295,$align)||self::u32($bytes,$start+8)!==$rate*$align)self::bad();
                }elseif($size<18||self::u32($bytes,$start+8)<1)self::bad();
                $fmt=['format'=>'wave','format_tag'=>$code,'channels'=>$channels,'sample_rate'=>$rate,'block_align'=>$align,'bits_per_sample'=>$bits];
            }elseif($tag==='data'){$data+=$size;}
            $chunks++;$at=$end;
        }
        if($fmt===null||$data<1||$at!==strlen($bytes)||(in_array($fmt['format_tag'],[1,3],true)&&$data%$fmt['block_align']!==0))self::bad();
        return $fmt+['data_bytes'=>$data,'chunks'=>$chunks,'qualification'=>'COMPLETE_RIFF_WAVE_CONTAINER_NOT_PLAYBACK'];
    }

    private static function mpeg(string $bytes): array
    {
        $end=strlen($bytes);$at=0;
        if(substr($bytes,0,3)==='ID3'){
            self::range($bytes,0,10);$version=ord($bytes[3]);if(!in_array($version,[2,3,4],true)||ord($bytes[4])===255)self::bad();$size=0;
            for($i=6;$i<10;$i++){$v=ord($bytes[$i]);if($v>127)self::bad();$size=($size<<7)|$v;}
            $at=10+$size;if($version===4&&(ord($bytes[5])&16)!==0){self::range($bytes,$at,10);if(substr($bytes,$at,3)!=='3DI')self::bad();$at+=10;}
            if($at>$end)self::bad();
        }
        if($end-$at>=128&&substr($bytes,$end-128,3)==='TAG')$end-=128;
        if($end-$at>=20&&substr($bytes,$end-10,3)==='3DI'){
            $footer=$end-10;$size=0;
            for($i=$footer+6;$i<$footer+10;$i++){$value=ord($bytes[$i]);if($value>127)self::bad();$size=($size<<7)|$value;}
            $tag=$end-20-$size;
            if($tag<$at||substr($bytes,$tag,3)!=='ID3'||substr($bytes,$tag+3,7)!==substr($bytes,$footer+3,7))self::bad();$end=$tag;
        }
        if($end-$at>=32&&substr($bytes,$end-32,8)==='APETAGEX'){
            $length=self::u32($bytes,$end-20);if($length<32||$length>$end-$at)self::bad();$end-=$length;
            if($end-$at>=32&&substr($bytes,$end-32,8)==='APETAGEX')$end-=32;
        }
        $frames=0;$rates=[];$freeLength=null;
        while($at<$end){
            $head=self::mpegHeader($bytes,$at);$length=$head['length'];
            if($length===null){
                if($freeLength===null){
                    // Infer free-format spacing from actual repeated headers;
                    // no new bitrate/size ceiling is imposed.
                    for($next=$at+4;$next+4<=$end;$next++){
                        if(ord($bytes[$next])!==255)continue;
                        try{$h=self::mpegHeader($bytes,$next);}catch(\Throwable $error){continue;}
                        if($h['identity']!==$head['identity']||$h['length']!==null)continue;
                        $base=$next-$at-$head['padding'];$third=$next+$base+$h['padding'];
                        $confirmed=$third===$end;
                        if(!$confirmed&&$third+4<=$end){
                            try{$confirmed=self::mpegHeader($bytes,$third)['identity']===$head['identity'];}
                            catch(\Throwable $error){$confirmed=false;}
                        }
                        if($base>3&&$confirmed){$freeLength=$base;break;}
                    }
                    if($freeLength===null)$freeLength=$end-$at-$head['padding'];
                }
                $length=$freeLength+$head['padding'];
            }
            if($length<4||$length>$end-$at)self::bad();$at+=$length;$frames++;$rates[$head['rate']]=true;
        }
        if($at!==$end||$frames<1)self::bad();$sampleRates=array_keys($rates);sort($sampleRates,SORT_NUMERIC);
        return ['format'=>'mpeg-audio','frames'=>$frames,'sample_rates'=>$sampleRates,'qualification'=>'COMPLETE_MPEG_AUDIO_FRAME_BOUNDARIES_NOT_PLAYBACK'];
    }
    private static function mpegHeader(string $bytes,int $at): array
    {
        $h=self::be32($bytes,$at);$version=($h>>19)&3;$layer=($h>>17)&3;$index=($h>>12)&15;$frequency=($h>>10)&3;
        if(($h&0xffe00000)!==0xffe00000||$version===1||$layer===0||$index===15||$frequency===3||($h&3)===2)self::bad();
        $rate=[44100,48000,32000][$frequency];if($version===2)$rate=intdiv($rate,2);elseif($version===0)$rate=intdiv($rate,4);
        $pad=(($h>>9)&1)*($layer===3?4:1);$length=null;
        if($index>0){
            $tables=$version===3?[
                3=>[32,64,96,128,160,192,224,256,288,320,352,384,416,448],
                2=>[32,48,56,64,80,96,112,128,160,192,224,256,320,384],
                1=>[32,40,48,56,64,80,96,112,128,160,192,224,256,320]]:[
                3=>[32,48,56,64,80,96,112,128,144,160,176,192,224,256],
                2=>[8,16,24,32,40,48,56,64,80,96,112,128,144,160],
                1=>[8,16,24,32,40,48,56,64,80,96,112,128,144,160]];
            $bitrate=$tables[$layer][$index-1]*1000;
            $length=$layer===3?intdiv(12*$bitrate,$rate)*4+$pad:intdiv(($layer===1&&$version!==3?72:144)*$bitrate,$rate)+$pad;
        }
        return ['identity'=>[$version,$layer,$frequency],'rate'=>$rate,'length'=>$length,'padding'=>$pad];
    }

    private static function amr(string $bytes,string $mime): array
    {
        $wide=str_starts_with($bytes,"#!AMR-WB\n");$header=$wide?"#!AMR-WB\n":"#!AMR\n";
        if(!str_starts_with($bytes,$header)||($wide? !in_array($mime,['audio/amr','audio/amr-wb'],true):$mime!=='audio/amr'))self::bad();
        $sizes=$wide?[17,23,32,36,40,46,50,58,60,5,0,0,0,0,0,0]:[12,13,15,17,19,20,26,31,5,0,0,0,0,0,0,0];
        $at=strlen($header);$frames=0;
        while($at<strlen($bytes)){
            $toc=ord($bytes[$at++]);$type=($toc>>3)&15;
            if(($toc&0x83)!==0||(!$wide&&$type>=9&&$type<=14)||($wide&&$type>=10&&$type<=13))self::bad();
            self::range($bytes,$at,$sizes[$type]);$at+=$sizes[$type];$frames++;
        }
        if($frames<1)self::bad();return ['format'=>$wide?'amr-wb':'amr','frames'=>$frames,'qualification'=>'COMPLETE_STORAGE_FRAMES_NOT_PLAYBACK'];
    }

    private static function asf(string $bytes): array
    {
        $header=hex2bin('3026b2758e66cf11a6d900aa0062ce6c');$file=hex2bin('a1dcab8c47a9cf118ee400c00c205365');
        $stream=hex2bin('9107dcb7b7a9cf118ee600c00c205365');$audio=hex2bin('409e69f84d5bcf11a8fd00805f5c442b');
        $dataGuid=hex2bin('3626b2758e66cf11a6d900aa0062ce6c');
        if(substr($bytes,0,16)!==$header)self::bad();$headerSize=self::u64($bytes,16);$count=self::u32($bytes,24);
        if($headerSize<30||$headerSize>strlen($bytes)||$count>intdiv($headerSize-30,24))self::bad();
        $at=30;$streams=0;$fileId=null;$packets=null;
        for($i=0;$i<$count;$i++){
            self::range($bytes,$at,24);$guid=substr($bytes,$at,16);$size=self::u64($bytes,$at+16);
            if($size<24||$size>$headerSize-$at)self::bad();
            if($guid===$file){
                if($fileId!==null||$size<104)self::bad();$declared=self::u64($bytes,$at+40);
                if($declared!==strlen($bytes)&&!($declared===0&&(self::u32($bytes,$at+88)&1)!==0))self::bad();
                $fileId=substr($bytes,$at+24,16);$packets=self::u64($bytes,$at+56);
            }elseif($guid===$stream){
                if($size<78||substr($bytes,$at+24,16)!==$audio)self::bad();
                $typeSize=self::u32($bytes,$at+64);$errorSize=self::u32($bytes,$at+68);
                if($typeSize<18||$typeSize>$size-78||$errorSize>$size-78-$typeSize)self::bad();
                $codec=self::u16($bytes,$at+78);
                if(!in_array($codec,[0x000a,0x0160,0x0161,0x0162,0x0163,0x0164],true)||self::u16($bytes,$at+80)<1
                    ||self::u32($bytes,$at+82)<1||self::u16($bytes,$at+90)<1)self::bad();$streams++;
            }
            $at+=$size;
        }
        if($at!==$headerSize||$fileId===null||$streams<1||$packets===null)self::bad();$data=false;
        while($at<strlen($bytes)){
            self::range($bytes,$at,24);$guid=substr($bytes,$at,16);$size=self::u64($bytes,$at+16);
            if($size<24||$size>strlen($bytes)-$at)self::bad();
            if($guid===$dataGuid){
                if($data||$at!==$headerSize||$size<=50||substr($bytes,$at+24,16)!==$fileId||self::u64($bytes,$at+40)!==$packets)self::bad();$data=true;
            }
            $at+=$size;
        }
        if(!$data||$at!==strlen($bytes))self::bad();
        return ['format'=>'asf-wma','audio_streams'=>$streams,'data_packets'=>$packets,'qualification'=>'COMPLETE_ASF_AUDIO_CONTAINER_NOT_PLAYBACK'];
    }

    private static function mp4(string $bytes): array
    {
        $boxes=self::boxes($bytes,0,strlen($bytes));$ftyp=false;$media=false;$movie=false;$video=false;
        foreach($boxes as[$kind,$start,$end]){
            if($kind==='ftyp'){if($end-$start<8||($end-$start)%4!==0)self::bad();$ftyp=true;}
            elseif($kind==='mdat'&&$end>$start)$media=true;
            elseif($kind==='moov'){
                $movie=true;
                foreach(self::boxes($bytes,$start,$end)as[$tag,$a,$b])if($tag==='trak'){
                    foreach(self::boxes($bytes,$a,$b)as[$inner,$x,$y])if($inner==='mdia'){
                        foreach(self::boxes($bytes,$x,$y)as[$leaf,$u,$v])if($leaf==='hdlr'){
                            if($v-$u<12)self::bad();if(substr($bytes,$u+8,4)==='vide')$video=true;
                        }
                    }
                }
            }
        }
        if(!$ftyp||!$media||!$movie||!$video)self::bad();
        return ['format'=>'mp4','video_track'=>true,'qualification'=>'COMPLETE_ISOBMFF_CONTAINER_NOT_PLAYBACK'];
    }
    private static function boxes(string $bytes,int $start,int $end): \Generator
    {
        $at=$start;
        while($at<$end){
            self::range($bytes,$at,8);$size=self::be32($bytes,$at);$header=8;$kind=substr($bytes,$at+4,4);
            if($size===1){$size=self::be64($bytes,$at+8);$header=16;}elseif($size===0)$size=$end-$at;
            if($size<$header||$size>$end-$at)self::bad();yield [$kind,$at+$header,$at+$size];$at+=$size;
        }
        if($at!==$end)self::bad();
    }
    private static function range(string $bytes,int $at,int $size): void
    { if($at<0||$size<0||$at>strlen($bytes)||$size>strlen($bytes)-$at)self::bad(); }
    private static function u16(string $bytes,int $at): int
    { self::range($bytes,$at,2);return unpack('v',substr($bytes,$at,2))[1]; }
    private static function u32(string $bytes,int $at): int
    { self::range($bytes,$at,4);return unpack('V',substr($bytes,$at,4))[1]; }
    private static function be32(string $bytes,int $at): int
    { self::range($bytes,$at,4);return unpack('N',substr($bytes,$at,4))[1]; }
    private static function u64(string $bytes,int $at): int
    { $low=self::u32($bytes,$at);$high=self::u32($bytes,$at+4);if($high>intdiv(PHP_INT_MAX,4294967296))self::bad();return $high*4294967296+$low; }
    private static function be64(string $bytes,int $at): int
    { $high=self::be32($bytes,$at);$low=self::be32($bytes,$at+4);if($high>intdiv(PHP_INT_MAX,4294967296))self::bad();return $high*4294967296+$low; }
    private static function bad(): never { MerchantAttachmentInput::invalid(); }
}
