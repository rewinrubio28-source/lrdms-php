<?php
function session_stage_position(string $stage): int {
    return [''=>0,'agenda_pending'=>0,'agenda_sent'=>0,'first_session'=>1,'second_session'=>2,'amendment'=>2,'amendment_received'=>2,'third_session'=>3,'signed_pending'=>3,'signed'=>3][$stage] ?? 0;
}
// Tracking is completed at the 3rd Session once the printable Final PDF is
// on file. Legacy signed-copy rows keep their completed status.
function session_stage_completed(?array $sessionState): bool {
    if (!$sessionState) return false;
    if (in_array($sessionState['stage'], ['signed_pending','signed'], true)) return true;
    return $sessionState['stage']==='third_session' && !empty($sessionState['final_attachment_id']);
}
function session_stage_tone(string $stage, ?array $sessionState=null): string {
    if ($sessionState !== null) return session_stage_completed($sessionState) ? 'success' : (in_array($stage,['amendment','agenda_pending'],true) ? 'warning' : 'primary');
    return in_array($stage,['signed','signed_pending'],true) ? 'success' : (in_array($stage,['amendment','agenda_pending'],true) ? 'warning' : 'primary');
}
function session_stage_hint(string $stage): string {
    return [
        ''=>'Check the document, then prepare its handoff to Agenda within 24 hours of receipt.',
        'agenda_pending'=>'Record the delivery reference once you have sent the document manually.',
        'agenda_sent'=>'Awaiting documents from the 1st session. Record receipt when they return.',
        'first_session'=>'Record the session outcome: request amendments or continue to the 2nd session.',
        'second_session'=>'Request amendments if needed, or continue when the 2nd session is complete.',
        'amendment'=>'Awaiting the department submission. Record a follow-up or receive their amended document below.',
        'amendment_received'=>'Review the received amendment. Continue when no further changes are needed.',
        'third_session'=>'Upload the official final PDF below, then download and print it as the hard copy. That ends the tracking.',
        'signed_pending'=>'Legacy signed-copy check from before the 3rd-Session final.',
        'signed'=>'Legacy checked signed copy. The final PDF remains available for printing.'
    ][$stage] ?? '';
}
