<?php
namespace WHCM\Core;

/** Central outbound HTTP client with strict public-HTTPS egress controls. */
class HttpClient {
    public static function get(string $url,array $headers=[],int $timeout=15): array{return self::request('GET',$url,[],$headers,$timeout);}
    public static function post(string $url,$body=[],array $headers=[],int $timeout=15): array{return self::request('POST',$url,$body,$headers,$timeout);}
    public static function request(string $method,string $url,$body=[],array $headers=[],int $timeout=15): array{
        $method=strtoupper($method);
        if(!self::validateOutboundUrl($url))return ['success'=>false,'code'=>0,'body'=>'','error'=>'نشانی مقصد خارجی مجاز نیست.'];
        if(is_array($body)){
            if($method==='GET'){$url.=(strpos($url,'?')===false?'?':'&').http_build_query($body);$body_str='';}
            else{$json=false;foreach($headers as $h){if(stripos($h,'application/json')!==false){$json=true;break;}}$body_str=$json?json_encode($body,JSON_UNESCAPED_UNICODE):http_build_query($body);}
        }else{$body_str=(string)$body;}
        if(function_exists('curl_init'))return self::runCurl($method,$url,$body_str,$headers,$timeout);
        return self::runStream($method,$url,$body_str,$headers,$timeout);
    }
    private static function runCurl(string $method,string $url,string $body,array $headers,int $timeout): array{
        $ch=curl_init();curl_setopt($ch,CURLOPT_URL,$url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_TIMEOUT,$timeout);curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);curl_setopt($ch,CURLOPT_MAXREDIRS,0);curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);curl_setopt($ch,CURLOPT_REDIR_PROTOCOLS,CURLPROTO_HTTPS);
        $pin=self::dnsResolvePublic($url);if($pin)curl_setopt($ch,CURLOPT_RESOLVE,$pin);
        if($method!=='GET'&&!empty($body))curl_setopt($ch,CURLOPT_POSTFIELDS,$body);if($headers)curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
        $response=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($response===false)return ['success'=>false,'code'=>0,'body'=>'','error'=>$err?:'خطای ناشناخته در cURL'];
        return ['success'=>$code>=200&&$code<300,'code'=>$code,'body'=>$response,'error'=>''];
    }
    private static function runStream(string $method,string $url,string $body,array $headers,int $timeout): array{
        $opts=['http'=>['method'=>$method,'timeout'=>$timeout,'ignore_errors'=>true,'follow_location'=>false,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'verify_depth'=>5]];
        if($method!=='GET'&&!empty($body)){$opts['http']['content']=$body;$has=false;foreach($headers as $h){if(stripos($h,'Content-Type')!==false){$has=true;break;}}if(!$has)$headers[]='Content-Type: application/x-www-form-urlencoded';}
        if($headers)$opts['http']['header']=implode("\r\n",$headers);
        $context=stream_context_create($opts);$response=@file_get_contents($url,false,$context);if($response===false)return ['success'=>false,'code'=>0,'body'=>'','error'=>'ارتباط با سرور برقرار نشد.'];
        $code=200;if(isset($http_response_header)&&is_array($http_response_header)){preg_match('{HTTP\\/\\S*\\s(\\d{3})}',$http_response_header[0],$m);$code=isset($m[1])?(int)$m[1]:200;}
        return ['success'=>$code>=200&&$code<300,'code'=>$code,'body'=>$response,'error'=>''];
    }
    private static function validateOutboundUrl(string $url): bool{
        $p=parse_url(trim($url));if(!$p||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))return false;
        if((int)($p['port']??443)!==443)return false;$host=strtolower(rtrim((string)$p['host'],'.'));if($host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local'))return false;
        $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:self::resolveHost($host);if(!$ips)return false;foreach($ips as $ip)if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return false;return true;
    }
    private static function dnsResolvePublic(string $url): array{$p=parse_url($url);if(!$p||empty($p['host']))return []; $host=strtolower(rtrim($p['host'],'.'));$port=(int)($p['port']??443);$out=[];foreach(self::resolveHost($host) as $ip)$out[]=str_contains($ip,':')?$host.':'.$port.':['.$ip.']':$host.':'.$port.':'.$ip;return $out;}
    private static function resolveHost(string $host): array{if(filter_var($host,FILTER_VALIDATE_IP))return[$host];$out=[];foreach(array_merge(@dns_get_record($host,DNS_A)?:[],@dns_get_record($host,DNS_AAAA)?:[]) as $r){if(!empty($r['ip']))$out[]=$r['ip'];if(!empty($r['ipv6']))$out[]=$r['ipv6'];}return array_values(array_unique($out));}
}
