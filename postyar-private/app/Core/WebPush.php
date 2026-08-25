<?php
namespace WHCM\Core;

/** Secure Web Push transport with strict SSRF protection. */
final class WebPush {
    public static function send(string $endpoint,string $userPublicKey,string $userAuthToken,string $payload,array $vapid,int $ttl=2419200): array {
        $target=self::validateEndpoint($endpoint);$ttl=max(0,min($ttl,2419200));
        $headers=['TTL: '.$ttl,'Content-Type: application/octet-stream','Content-Encoding: aes128gcm'];
        if(!empty($vapid['subject'])&&!empty($vapid['publicKey'])&&!empty($vapid['privateKey'])){
            $headers[]='Authorization: '.self::createVapidAuthorization($target['audience'],$vapid['subject'],$vapid['publicKey'],$vapid['privateKey']);
        }
        $encrypted=self::encryptPayload($payload,$userPublicKey,$userAuthToken);$headers[]='Content-Length: '.strlen($encrypted);
        $ch=curl_init($endpoint);if($ch===false)throw new \RuntimeException('Web Push transport initialization failed.');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$encrypted,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>$target['resolve']]);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        return ['success'=>$status>=200&&$status<300,'status'=>$status,'error'=>$error?:null];
    }
    public static function sendBatch(array $subscriptions,string $payload,array $vapid): array {$results=[];foreach($subscriptions as $sub){try{$results[]=self::send((string)$sub['endpoint'],(string)$sub['keys_p256dh'],(string)$sub['keys_auth'],$payload,$vapid);}catch(\Throwable $e){$results[]=['success'=>false,'status'=>0,'error'=>$e->getMessage()];}}return $results;}
    private static function validateEndpoint(string $endpoint): array {
        $p=parse_url(trim($endpoint));if(!$p||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host']))throw new \InvalidArgumentException('Push endpoint must use HTTPS.');
        if(isset($p['user'])||isset($p['pass']))throw new \InvalidArgumentException('Push endpoint credentials are not allowed.');
        $host=strtolower(rtrim((string)$p['host'],'.'));$port=(int)($p['port']??443);if($port!==443)throw new \InvalidArgumentException('Push endpoint port is not allowed.');
        if($host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local'))throw new \InvalidArgumentException('Private push endpoint is not allowed.');
        $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:self::resolveHost($host);$ips=array_values(array_unique($ips));if(!$ips)throw new \RuntimeException('Push endpoint DNS resolution failed.');
        foreach($ips as $ip)if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)throw new \InvalidArgumentException('Push endpoint resolves to a private or reserved address.');
        $resolve=[];foreach($ips as $ip)$resolve[]=str_contains($ip,':')?$host.':443:['.$ip.']':$host.':443:'.$ip;
        return ['audience'=>'https://'.$host,'resolve'=>$resolve];
    }
    private static function resolveHost(string $host): array {$out=[];foreach(array_merge(@dns_get_record($host,DNS_A)?:[],@dns_get_record($host,DNS_AAAA)?:[]) as $r){if(!empty($r['ip']))$out[]=$r['ip'];if(!empty($r['ipv6']))$out[]=$r['ipv6'];}return array_values(array_unique($out));}
    private static function createVapidAuthorization(string $audience,string $subject,string $publicKey,string $privateKeyPem): string{return 'vapid t='.self::buildVapidJwt($audience,$subject,$privateKeyPem).', k='.$publicKey;}
    private static function buildVapidJwt(string $audience,string $subject,string $privateKeyPem): string{$h=self::b64UrlEncode(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_UNESCAPED_SLASHES));$b=self::b64UrlEncode(json_encode(['aud'=>$audience,'exp'=>time()+43200,'sub'=>$subject],JSON_UNESCAPED_SLASHES));$u=$h.'.'.$b;$key=openssl_pkey_get_private($privateKeyPem);if(!$key||!openssl_sign($u,$sig,$key,OPENSSL_ALGO_SHA256))throw new \RuntimeException('VAPID signing failed.');return $u.'.'.self::b64UrlEncode($sig);}
    private static function encryptPayload(string $payload,string $userPubKeyB64,string $userAuthB64): string{$pub=self::b64UrlDecode($userPubKeyB64);$auth=self::b64UrlDecode($userAuthB64);if(strlen($pub)!==65||strlen($auth)<16)throw new \InvalidArgumentException('Invalid Web Push subscription keys.');$key=openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]);if(!$key)throw new \RuntimeException('Ephemeral key generation failed.');$details=openssl_pkey_get_details($key);$raw=self::extractRawPoint($details);$shared=self::ecdh($key,$pub);$prk=hash_hmac('sha256',$shared,$auth,true);$info="Content-Encoding: aes128gcm\x00P-256\x00".self::u16(strlen($raw)).$raw.self::u16(strlen($pub)).$pub;$cek=substr(hash_hmac('sha256',$info."\x01",$prk,true),0,16);$nonce=substr(hash_hmac('sha256',$info."\x02",$prk,true),0,12);$tag='';$cipher=openssl_encrypt($payload,'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);if($cipher===false)throw new \RuntimeException('Push encryption failed.');return $raw."\x00\x00".$cipher.$tag;}
    private static function ecdh($localKey,string $remotePubRaw): string{$pem=self::rawPointToSpkiPem($remotePubRaw);$remote=openssl_pkey_get_public($pem);if(!$remote)throw new \RuntimeException('Remote public key error.');$result=openssl_dh_compute_key($remote,$localKey);if($result===false)throw new \RuntimeException('ECDH compute failed.');return str_pad($result,32,"\x00",STR_PAD_LEFT);}
    private static function b64UrlEncode(string $data): string{return rtrim(strtr(base64_encode($data),'+/','-_'),'=');}
    private static function b64UrlDecode(string $data): string{$data=strtr($data,'-_','+/');return base64_decode($data.str_repeat('=',(4-strlen($data)%4)%4),true)?:'';}
    private static function u16(int $n): string{return chr(($n>>8)&255).chr($n&255);}
    private static function extractRawPoint(array $details): string{$der=base64_decode(preg_replace('/-----.*?-----/','',$details['key']));$pos=strrpos($der,"\x04");if($pos===false||$pos+65>strlen($der))throw new \RuntimeException('Cannot extract EC public point.');return substr($der,$pos,65);}
    private static function rawPointToSpkiPem(string $point): string{$oid=hex2bin('06082a8648ce3d030107');$bit="\x03".chr(1+strlen($point))."\x00".$point;$content=$oid.$bit;$len=strlen($content);$seq="\x30".($len<128?chr($len):chr(0x81).chr($len)).$content;return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($seq),64)."-----END PUBLIC KEY-----";}
}
