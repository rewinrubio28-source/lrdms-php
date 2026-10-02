<?php
require_once __DIR__.'/record_reports.php';
require_once __DIR__.'/report_schema.php';

function report_schedule_next(string $frequency,string $time,DateTimeImmutable $now): DateTimeImmutable {
    if (!in_array($frequency,['daily','weekly','monthly'],true) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)) throw new InvalidArgumentException('Choose daily, weekly or monthly and a valid time.');
    $now=$now->setTimezone(new DateTimeZone('Asia/Manila'));
    [$h,$m]=array_map('intval',explode(':',$time));
    $next=match($frequency) {'daily'=>$now,'weekly'=>$now->modify('monday this week'),'monthly'=>$now->modify('first day of this month')};
    $next=$next->setTime($h,$m,0);
    return $next<=$now ? $next->modify(match($frequency){'daily'=>'+1 day','weekly'=>'+1 week','monthly'=>'+1 month'}) : $next;
}

function report_schedule_period(string $frequency,DateTimeImmutable $now): array {
    $now=$now->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0,0);
    [$start,$end]=match($frequency) {
        'daily'=>[$now->modify('-1 day'),$now->modify('-1 day')],
        'weekly'=>[$now->modify('monday this week')->modify('-1 week'),$now->modify('monday this week')->modify('-1 day')],
        'monthly'=>[$now->modify('first day of previous month'),$now->modify('first day of this month')->modify('-1 day')],
        default=>throw new InvalidArgumentException('Invalid frequency.')
    };
    return ['from'=>$start->format('Y-m-d'),'to'=>$end->format('Y-m-d')];
}

function report_schedule_create(PDO $pdo,array $user,array $input): int {
    if (!record_report_allowed($user) || !record_report_allowed($user,'export')) throw new RuntimeException('Repository download permission is required for scheduling.');
    $options=record_report_options($input);
    $frequency=$input['frequency']??'daily'; $time=$input['run_time']??'08:00';
    if (!is_string($frequency) || !is_string($time)) throw new InvalidArgumentException('Invalid schedule.');
    $next=report_schedule_next($frequency,$time,new DateTimeImmutable());
    $pdo->beginTransaction();
    try {
        // Serialize quota checks for simultaneous requests by the same user.
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $lock->execute([$user['id']]);
        $count=$pdo->prepare('SELECT COUNT(*) FROM report_schedules WHERE user_id=?'); $count->execute([$user['id']]);
        if ((int)$count->fetchColumn()>=10) throw new InvalidArgumentException('Limit of 10 schedules reached. Remove an old schedule first.');
        $pdo->prepare('INSERT INTO report_schedules (user_id,title,options_json,frequency,run_time,next_run_at) VALUES (?,?,?,?,?,?)')->execute([$user['id'],$options['title'],json_encode($options,JSON_THROW_ON_ERROR),$frequency,$time,$next->format('Y-m-d H:i:s')]);
        $id=(int)$pdo->lastInsertId();
        log_action('reports','schedule_created','Schedule '.$id.'; '.$frequency.' '.$time.' Asia/Manila');
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function report_schedule_change(PDO $pdo,array $user,int $id,string $action): void {
    if (!record_report_allowed($user,'export')) throw new RuntimeException('Repository download permission is required.');
    if (!in_array($action,['pause','resume','remove'],true)) throw new InvalidArgumentException('Invalid schedule action.');
    $pdo->beginTransaction();
    try {
        $query=$pdo->prepare('SELECT * FROM report_schedules WHERE id=? AND user_id=? FOR UPDATE'); $query->execute([$id,$user['id']]); $schedule=$query->fetch();
        if (!$schedule) throw new InvalidArgumentException('Schedule unavailable.');
        if ($action==='remove') $pdo->prepare('DELETE FROM report_schedules WHERE id=? AND user_id=?')->execute([$id,$user['id']]);
        else {
            $next=report_schedule_next($schedule['frequency'],$schedule['run_time'],new DateTimeImmutable());
            $pdo->prepare('UPDATE report_schedules SET enabled=?,next_run_at=? WHERE id=? AND user_id=?')->execute([$action==='resume'?1:0,$next->format('Y-m-d H:i:s'),$id,$user['id']]);
        }
        log_action('reports','schedule_'.$action,'Schedule '.$id); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** One due schedule per pass; database lock prevents overlapping workers. */
function report_schedule_tick(PDO $pdo,?DateTimeImmutable $now=null,?callable $render=null): ?int {
    $now=($now??new DateTimeImmutable())->setTimezone(new DateTimeZone('Asia/Manila'));
    $lockName='lrdms-reports-'.substr(hash('sha256',DB_NAME),0,32);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn()!==1) return null;
    try {
        $pdo->prepare('DELETE FROM report_runs WHERE created_at < ?')->execute([$now->modify('-30 days')->format('Y-m-d H:i:s')]);
        $query=$pdo->prepare('SELECT * FROM report_schedules WHERE enabled=1 AND next_run_at<=? ORDER BY next_run_at,id LIMIT 1'); $query->execute([$now->format('Y-m-d H:i:s')]); $schedule=$query->fetch();
        if (!$schedule) return null;
        $pdo->prepare('INSERT IGNORE INTO report_runs (schedule_id,user_id,scheduled_for,created_at) VALUES (?,?,?,?)')->execute([$schedule['id'],$schedule['user_id'],$schedule['next_run_at'],$now->format('Y-m-d H:i:s')]);
        $query=$pdo->prepare('SELECT id,status,attempts FROM report_runs WHERE schedule_id=? AND scheduled_for=?'); $query->execute([$schedule['id'],$schedule['next_run_at']]); $run=$query->fetch();
        if (!$run) return null; // Owner may have removed this schedule during claiming.
        // A crashed worker can safely regenerate an unfinished slot; a completed slot is not repeated.
        if ($run['status']==='Processing') {
            try {
                if ((int)$run['attempts']>=3) throw new RuntimeException('Worker interrupted repeatedly; skipping this scheduled slot.');
                $pdo->prepare('UPDATE report_runs SET attempts=attempts+1 WHERE id=?')->execute([$run['id']]);
                $query=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1 AND must_change_password=0'); $query->execute([$schedule['user_id']]); $user=$query->fetch();
                if (!$user || !record_report_allowed($user) || !record_report_allowed($user,'export')) {
                    $pdo->prepare("UPDATE report_runs SET status='Skipped',error_message='Account inactive or current reporting permissions are unavailable.' WHERE id=?")->execute([$run['id']]);
                } else {
                    $options=json_decode($schedule['options_json'],true,32,JSON_THROW_ON_ERROR);
                    $options=array_replace($options,report_schedule_period($schedule['frequency'],$now));
                    $report=record_report_build($pdo,$user,$options);
                    $pdf=$render ? $render($report) : record_report_bytes($report,'pdf');
                    if (!is_string($pdf) || !str_starts_with($pdf,'%PDF-') || strlen($pdf)>8388608) throw new RuntimeException('Generated PDF is invalid or exceeds 8 MB.');
                    $pdo->prepare("UPDATE report_runs SET status='Ready',generated_at=?,snapshot_json=?,pdf_bytes=?,error_message=NULL WHERE id=?")->execute([$report['generated_at'],json_encode($report,JSON_THROW_ON_ERROR),$pdf,$run['id']]);
                }
            } catch (Throwable $e) {
                error_log('Scheduled report run '.$run['id'].': '.$e->getMessage());
                $message=$e instanceof InvalidArgumentException?$e->getMessage():'Generation failed. Contact the administrator or retry on the next scheduled run.';
                $pdo->prepare("UPDATE report_runs SET status='Failed',error_message=?,snapshot_json=NULL,pdf_bytes=NULL WHERE id=?")->execute([mb_substr($message,0,250),$run['id']]);
            }
        }
        $next=report_schedule_next($schedule['frequency'],$schedule['run_time'],$now);
        $pdo->prepare('UPDATE report_schedules SET next_run_at=? WHERE id=? AND next_run_at=?')->execute([$next->format('Y-m-d H:i:s'),$schedule['id'],$schedule['next_run_at']]);
        return (int)$run['id'];
    } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
}
