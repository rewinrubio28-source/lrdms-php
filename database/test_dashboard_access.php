<?php
if (PHP_SAPI!=='cli') exit;
foreach (['dashboard.php','export_dashboard.php'] as $endpoint) {
    foreach (['GET'=>401,'POST'=>405] as $method=>$expected) {
        $code='session_start(["save_path"=>sys_get_temp_dir()]); $_SESSION=[]; $_SERVER["REQUEST_METHOD"]='.var_export($method,true).';'
            .'register_shutdown_function(function(){fwrite(STDERR,"STATUS=".http_response_code());}); require '.var_export(__DIR__.'/../api/'.$endpoint,true).';';
        $process=proc_open([PHP_BINARY,'-d','display_errors=0','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start endpoint test.');
        fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
        if ($exit!==0 || !str_contains($err,'STATUS='.$expected) || str_starts_with($out,'%PDF-') || str_contains($out,'stat-tile')) throw new RuntimeException('Endpoint access check failed: '.$endpoint);
        echo "PASS: $method $endpoint returns $expected without dashboard/report data.\n";
    }
}
