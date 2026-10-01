<?php
/** Proposed access model based on the supplied draft chart, not official job descriptions. */
function records_role_definitions(): array {
    $read = ['repository.view_all', 'search.run'];
    $encode = [...$read, 'encoding.create', 'repository.edit_metadata'];
    $validate = [...$read, 'encoding.create', 'encoding.register_record', 'repository.print_record'];
    $custody = [...$read, 'encoding.create', 'repository.edit_metadata', 'repository.manage_visibility', 'repository.print_record', 'repository.download', 'repository.review_copy_requests', 'version.amend'];
    return [
        'Administrator' => ['Designated system administrator: accounts and organizational assignments; no records validation or publication.', ['access.manage_users', 'access.reset_password', 'access.force_logout', 'access.view_user_activity', 'access.manage_organization', 'audit.view', 'audit.export']],
        'Division Viewer' => ['Chief / Supervising Administrative Officer: read records and audit reports; no record changes.', [...$read, 'audit.view', 'audit.export', 'repository.print_record']],
        'Records Supervisor' => ['RMPS section lead: validate, supervise records, manage copies and publication, and review audit activity.', array_values(array_unique([...$validate, ...$custody, 'audit.view', 'audit.export']))],
        'Records Validator' => ['RMPS senior processing staff: validate and register incoming records; no public release or user administration.', $validate],
        'Records Encoder' => ['RMPS encoding staff: prepare metadata, upload corrections and run OCR; no registration or public release.', $encode],
        'Records Assistant' => ['RMPS filing and retrieval support: search and view registered records; no changes or direct downloads.', $read],
        'Records Custodian' => ['Maintains registered records, versions, copy requests and publication; no intake registration.', $custody],
        'Records Viewer' => ['External / office reader: public and assigned-office registered records only.', ['repository.view_public', 'repository.view_office', 'search.run']],
        'Auditor' => ['Reviews audit activity and exports audit reports; no records or account changes.', ['audit.view', 'audit.export']],
        'Records Officer' => ['Existing records operations role: supervisor access retained for current accounts.', array_values(array_unique([...$validate, ...$custody, 'audit.view', 'audit.export']))],
        'Legislative Staff' => ['Originating-office staff: own and public records with intake corrections; no registration.', ['repository.view_own', 'repository.view_public', 'encoding.create', 'repository.edit_metadata', 'search.run']],
        'Committee Secretary' => ['Committee staff: committee and public records; no records registration or account administration.', ['repository.view_committee', 'repository.view_public', 'search.run']],
    ];
}

function records_position_guidance(): array {
    return [
        ['Division leadership', 'Chief Administrative Officer / Supervising Administrative Officer', 'Division Viewer'],
        ['RMPS', 'Administrative Officer V', 'Records Supervisor'],
        ['RMPS', 'Senior Administrative Assistant IV / Administrative Officer III', 'Records Validator'],
        ['RMPS', 'Administrative Assistant II / Administrative Aide VI', 'Records Encoder'],
        ['RMPS', 'Administrative Aide IV', 'Records Assistant'],
        ['RMPS', 'Administrative Aide II', 'No account unless assigned a digital records task'],
        ['ITTS', 'Designated system support personnel', 'Administrator (explicit assignment only)'],
        ['ITTS', 'Computer operators, media/equipment staff and aides', 'No automatic account; select access for the assigned task'],
    ];
}
