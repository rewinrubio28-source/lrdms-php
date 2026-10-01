<?php
// Read-only readiness check. Never prints configured URLs, keys or credentials.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../config/env.php';
load_env_file();
$failed=false;
foreach (['BERT_SERVICE_URL'=>['http://localhost:5000','/search'],'OCR_SERVICE_URL'=>['http://ocr_service/ocr','/ocr']] as $name=>[$default,$suffix]) {
    $url=rtrim((string)env_optional($name,$default),'/');
    if (str_ends_with($url,$suffix)) $url=substr($url,0,-strlen($suffix));
    if (!function_exists('curl_init')) {
        echo "$name: FAIL (PHP cURL is unavailable)\n"; $failed=true; continue;
    }
    $ch=curl_init($url.'/health');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10]);
    $started=microtime(true);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $data=is_string($body)?json_decode($body,true):null;
    $ok=$code===200 && is_array($data) && ($data['status']??null)==='ok';
    printf("%s: %s (HTTP %d, %.0f ms)\n",$name,$ok?'PASS':'FAIL',$code,(microtime(true)-$started)*1000);
    $failed=$failed||!$ok;
}
echo "Health checks do not establish AI accuracy, OCR processing time, or worker availability.\n";
exit($failed?1:0);
