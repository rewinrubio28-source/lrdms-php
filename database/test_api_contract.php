<?php
// CLI-only: invalid requests exit before accessing application records.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/external_api.php';
function contract_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
foreach (['{', '[]', 'null', '"text"'] as $body) {
    try { external_api_json_payload($body); throw new RuntimeException('Invalid JSON accepted'); }
    catch (InvalidArgumentException $e) { }
}
contract_check(external_api_json_payload('{"title":"Sample"}')['title'] === 'Sample', 'JSON object contract and malformed payload rejection');
foreach ([[], ['query'=>[]], ['query'=>'x','mode'=>[]], ['query'=>'x','mode'=>'invalid'], ['query'=>str_repeat('a',501)]] as $input) {
    try { external_api_search_parameters($input); throw new RuntimeException('Invalid query accepted'); }
    catch (InvalidArgumentException $e) { }
}
contract_check(external_api_search_parameters(['query'=>' sample ']) === ['sample','keyword'], 'Search parameter types, limits and default mode');
$cases = [
    ['search.php','POST',[],true,'application/json',405],
    ['upload_document.php','GET',[],true,'application/json',405],
    ['search.php','GET',['query'=>'sample'],false,'application/json',401],
    ['upload_document.php','POST',[],false,'application/json',401],
    ['search.php','GET',['query'=>[]],true,'application/json',422],
    ['search.php','GET',['query'=>'x','mode'=>'invalid'],true,'application/json',422],
    ['upload_document.php','POST',[],true,'text/plain',415],
    ['upload_document.php','POST',[],true,'application/json',422],
];
foreach ($cases as [$file,$method,$query,$authorized,$contentType,$expected]) {
    $code = 'putenv("API_SHARED_KEY=contract-test-only");'
        . '$_SERVER=' . var_export(['REQUEST_METHOD'=>$method,'CONTENT_TYPE'=>$contentType,'HTTP_X_API_KEY'=>$authorized?'contract-test-only':'wrong'],true) . ';'
        . '$_GET=' . var_export($query,true) . ';'
        . 'register_shutdown_function(function(){fwrite(STDERR,"STATUS=".http_response_code());});'
        . 'require ' . var_export(__DIR__ . '/../api/' . $file,true) . ';';
    $process = proc_open([PHP_BINARY,'-d','display_errors=0','-r',$code], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start PHP');
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit=proc_close($process);
    contract_check($exit===0 && str_contains($err,'STATUS='.$expected) && isset(json_decode($out,true)['error']), "$method $file rejects invalid request with JSON/$expected");
}
