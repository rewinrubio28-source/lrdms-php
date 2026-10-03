<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/request_security.php';
require_once __DIR__.'/../includes/upload_validation.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local test only.');
$pdo=get_db();
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',security_rate_schema()));
function check_security(bool $ok,string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS: $name\n"; }
check_security(security_rate_take($pdo,'login','test',2,60,120)===0,'First request accepted');
check_security(security_rate_take($pdo,'login','test',2,60,121)===0,'Request at limit accepted');
check_security(security_rate_take($pdo,'login','test',2,60,122)===58,'Excess request rejected with remaining window');
check_security(security_rate_take($pdo,'login','other',2,60,122)===0,'Independent subject unaffected');
check_security(security_rate_take($pdo,'reset','test',2,60,122)===0,'Independent operation unaffected');
check_security(security_rate_take($pdo,'login','test',2,60,180)===0,'New window permits retry');
check_security($pdo->query('SELECT MIN(LENGTH(bucket)) FROM security_rate_limits')->fetchColumn()==64,'Only hashed bucket identifiers stored');
$now=time();
check_security(!security_session_expired(['session_created_at'=>date('Y-m-d H:i:s',$now-600),'session_last_seen'=>date('Y-m-d H:i:s',$now-60)],$now),'Recent session remains valid');
check_security(security_session_expired(['session_created_at'=>date('Y-m-d H:i:s',$now-3600),'session_last_seen'=>date('Y-m-d H:i:s',$now-1800)],$now),'Inactive session expires at 30 minutes');
check_security(security_session_expired(['session_created_at'=>date('Y-m-d H:i:s',$now-43200),'session_last_seen'=>date('Y-m-d H:i:s',$now)],$now),'Absolute 12-hour expiry survives polling');
$_SERVER['REMOTE_ADDR']='192.0.2.20'; $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.99';
check_security(security_client_ip()==='192.0.2.20','Untrusted forwarded IP cannot bypass limits');
$file=tempnam(sys_get_temp_dir(),'lrdms-safe-');
try {
    file_put_contents($file,'<?php echo "not an image";');
    check_security(document_upload_error($file,'fake.png')!==null,'Executable disguised as image rejected');
    check_security(document_upload_error($file,'payload.php')!==null,'Executable extension rejected');
    file_put_contents($file,'Ordinary document text.');
    check_security(document_upload_error($file,'sample.txt')===null,'Plain-text document allowed');
    check_security(document_upload_error($file,'fake.pdf')!==null,'Text disguised as PDF rejected');
    check_security(document_upload_error($file,'sample.txt',true)!==null,'OCR endpoint rejects non-image/non-PDF');
    file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5iUAAAAASUVORK5CYII='));
    check_security(document_upload_error($file,'sample.png',true)===null,'Valid PNG accepted for OCR');
    file_put_contents($file,'');
    check_security(document_upload_error($file,'empty.txt')!==null,'Empty file rejected');
    $fp=fopen($file,'wb'); ftruncate($fp,25*1024*1024+1); fclose($fp); clearstatcache(true,$file);
    check_security(document_upload_error($file,'large.txt')!==null,'Oversized file rejected');
    if (!class_exists('ZipArchive')) throw new RuntimeException('Enable the zip extension to test Word archive checks.');
    $zip=new ZipArchive(); $zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml','<Types/>'); $zip->addFromString('word/document.xml','<document/>'); $zip->close(); clearstatcache(true,$file);
    check_security(document_upload_error($file,'sample.docx')===null,'Word archive with expected entries accepted');
    $zip->open($file); $zip->addFromString('word/vbaProject.bin','macro'); $zip->close(); clearstatcache(true,$file);
    check_security(document_upload_error($file,'sample.docx')!==null,'Macro-bearing Word archive rejected');
    $zip->open($file); $zip->deleteName('word/vbaProject.bin'); $zip->addFromString('../escape','bad'); $zip->close(); clearstatcache(true,$file);
    check_security(document_upload_error($file,'sample.docx')!==null,'Traversal entry in Word archive rejected');
} finally { unlink($file); }
echo "No real accounts or records modified.\n";
