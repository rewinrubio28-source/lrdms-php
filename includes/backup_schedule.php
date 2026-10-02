<?php
require_once __DIR__.'/backup_tools.php';

function backup_schedule_slot(DateTimeImmutable $now,string $time): ?string {
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)) throw new RuntimeException('BACKUP_TIME must be HH:MM.');
    $now=$now->setTimezone(new DateTimeZone('Asia/Manila')); [$h,$m]=array_map('intval',explode(':',$time));
    $slot=$now->setTime($h,$m,0); if ($slot>$now) $slot=$slot->modify('-1 day');
    return $now<$slot->modify('+1 hour')?$slot->format('Y-m-d'):null;
}

/** At most once per daily slot. Failed/busy attempts retry within the one-hour window. */
function backup_schedule_tick(string $directory,DateTimeImmutable $now,string $time,callable $create,?string $sourceDatabase=null): array {
    $slot=backup_schedule_slot($now,$time); if ($slot===null) return ['status'=>'idle'];
    $handle=fopen($directory.'/.scheduler.lock','c');
    if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) fclose($handle); return ['status'=>'busy']; }
    try {
        $stateFile=$directory.'/.scheduler-state.json';
        try { $state=is_file($stateFile)?json_decode(file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR):[]; }
        catch (JsonException $e) { $state=[]; }
        if (($state['completed_slot']??'')===$slot && ($sourceDatabase===null || ($state['source_database']??'')===$sourceDatabase) && preg_match('/^records-[A-Za-z0-9-]+$/D',$state['set']??'') && is_file($directory.'/'.$state['set'].'/manifest.json')) return ['status'=>'already-complete'];
        // Also recover completion if the process stopped after finalizing a backup but before saving state.
        foreach (glob($directory.'/records-*/manifest.json')?:[] as $manifestPath) {
            $set=dirname($manifestPath); if (str_ends_with($set,'.partial')) continue;
            try {
                $manifest=json_decode(file_get_contents($manifestPath),true,32,JSON_THROW_ON_ERROR);
                if (($manifest['format']??'')!=='lrdms-local-v1' || ($sourceDatabase!==null && ($manifest['source_database']??'')!==$sourceDatabase)) continue;
                $created=new DateTimeImmutable($manifest['created_utc']);
                if (backup_schedule_slot($created,$time)===$slot) {
                    backup_verify($set); $state=['completed_slot'=>$slot,'set'=>basename($set),'source_database'=>$manifest['source_database'],'recovered'=>true];
                    backup_json($stateFile,$state); return ['status'=>'already-complete'];
                }
            } catch (Throwable $e) { error_log('Ignoring an unverifiable prior backup set while checking this schedule.'); }
        }
        $name=$create(); $manifest=backup_verify($directory.'/'.$name);
        if ($sourceDatabase!==null && $manifest['source_database']!==$sourceDatabase) throw new RuntimeException('Scheduled backup source does not match the configured database.');
        $state=['completed_slot'=>$slot,'set'=>$name,'source_database'=>$manifest['source_database'],'completed_at'=>$now->format(DATE_ATOM)];
        $pending=$stateFile.'.tmp'; backup_json($pending,$state);
        if (is_file($stateFile) && PHP_OS_FAMILY==='Windows') unlink($stateFile);
        if (!rename($pending,$stateFile)) throw new RuntimeException('Could not record backup schedule completion.');
        return ['status'=>'complete','set'=>$name,'slot'=>$slot];
    } finally { flock($handle,LOCK_UN); fclose($handle); }
}
