<?php
function session_stage_position(string $stage): int {
    return [''=>0,'agenda_pending'=>0,'agenda_sent'=>0,'first_session'=>1,'second_session'=>2,'amendment'=>2,'amendment_received'=>2,'third_session'=>3,'signed_pending'=>4,'signed'=>4][$stage] ?? 0;
}
function session_stage_tone(string $stage): string {
    return $stage==='signed' ? 'success' : (in_array($stage,['amendment','signed_pending','agenda_pending'],true) ? 'warning' : 'primary');
}
function session_stage_hint(string $stage): string {
    return [
        ''=>'Check the document, then prepare its handoff to Agenda.',
        'agenda_pending'=>'Record the delivery reference once you have sent the document manually.',
        'agenda_sent'=>'Awaiting documents from the 1st session. Record receipt when they return.',
        'first_session'=>'Record the session outcome: request amendments or continue to the 2nd session.',
        'second_session'=>'Request amendments if needed, or continue when the 2nd session is complete.',
        'amendment'=>'Awaiting the committee submission. Record a follow-up or receive their amended document below.',
        'amendment_received'=>'Review the received amendment. Continue when no further changes are needed.',
        'third_session'=>'Prepare the final PDF and print the hard copy for the mayor’s signature.',
        'signed_pending'=>'Open the signed PDF and check the mayor’s signature before confirming it is on file.',
        'signed'=>'The checked signed copy is on file. Both the final PDF and signed copy remain available.'
    ][$stage] ?? '';
}
