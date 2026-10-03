"""Generate schema-only panel references from inspect_database.php JSON output."""
import json
import sys
from pathlib import Path

TABLES = {
    'document_source_history':'Append-only manually recorded source events, with explicit demo/source basis and LRDMS recorder attribution.',
    'security_rate_limits':'Expiring hashed request-rate buckets shared by application instances; no raw secrets or account identifiers.',
    'access_request_reviews':'Review events for restricted-document access requests.',
    'access_requests':'Requests for access to a restricted document, including request-letter reference and decision.',
    'application_migrations':'One-time migration markers; not business records.',
    'archive_transfers':'Preparation and tracking of record transfers to another archive system.',
    'audit_log':'Append-only application action history with an actor-name snapshot.',
    'committees':'Committee reference list.', 'divisions':'Divisions under an organizational office.',
    'document_attachments':'Multiple stored files associated with one document.',
    'document_change_notes':'Notes explaining changes to document metadata or versions.',
    'document_copy_requests':'Requests for an authorized document copy and their review outcome.',
    'document_relationships':'Typed links between related legislative records.',
    'documents':'Legislative record metadata, content, intake state, provenance and revision links.',
    'integration_receipts':'Receipt and processing history for externally submitted records.',
    'login_otp_codes':'Time-limited email sign-in challenges.',
    'notifications':'Per-user application notifications and read state.',
    'ocr_jobs':'One background extraction job per document with progress and retry state.',
    'offices':'Organizational office reference list.',
    'password_reset_codes':'Time-limited password-reset verification codes.',
    'password_reset_tokens':'Single-use password-reset token records.',
    'permissions':'Named module/action permissions.', 'positions':'Position reference list.',
    'privacy_events':'Privacy acknowledgement and optional-consent decision history.',
    'privacy_requests':'Personal-data requests and administrator responses.',
    'recently_viewed_documents':'Most recent viewing time per user/document pair.',
    'record_access_rules':'Explicit document access grants to users or organizational/role scopes.',
    'record_followups':'Recorded contacts and next follow-up dates for incoming records.',
    'record_validation_history':'Review and registration decisions for a document.',
    'report_runs':'Private scheduled-report snapshots, generated PDF bytes and execution results.',
    'report_schedules':'Owner-defined automatic report settings and next due time.',
    'retention_reviews':'Recorded retention/disposal review decisions.',
    'role_permissions':'Many-to-many mapping between roles and permissions.',
    'roles':'Named access-control roles.', 'saved_searches':'User-owned reusable search settings.',
    'search_log':'Search activity, retrieval mode and returned-result count.',
    'user_committees':'Many-to-many committee assignments.',
    'user_profile_photos':'Optional profile image bytes stored separately from account identity.',
    'user_sessions':'Issued login sessions, activity timestamps and revocation state.',
    'users':'Account identity, authentication state and organizational assignments.',
}
FIELDS = {
    'event_date':'Source event date supplied by the recorder, distinct from the LRDMS entry timestamp.',
    'event_title':'Description of the reported source event.',
    'source_office':'Office or system reported as the origin of the event.',
    'destination_office':'Reported receiving office, if supplied.',
    'actor_name':'Reported source actor or role; not an authenticated LRDMS identity.',
    'reference':'Supporting document/page reference supplied as text.',
    'remarks':'Recorder-supplied event description or clarification.',
    'evidence_type':'Manual source record or explicitly simulated demo entry.',
    'recorded_by':'Authenticated LRDMS user who entered the source event.',
    'bucket':'SHA-256 identifier of operation, subject and fixed time window.',
    'hits':'Atomic request count for this bucket.',
    'id':'Surrogate row identifier.', 'name':'Human-readable name (migration identifier in application_migrations).',
    'title':'Record or report title.', 'description':'Explanatory description.',
    'created_at':'Time this row was created.', 'updated_at':'Most recent update time.',
    'is_active':'Whether this account/reference/session is active.',
    'note':'Operator-entered explanatory note.', 'action':'Recorded operation or review decision.',
    'status':'Lifecycle state; interpretation depends on the table.',
    'doc_number':'External/business document reference. Indexed but deliberately not globally UNIQUE.',
    'doc_type':'Category of legislative record.', 'sponsor':'Sponsor name retained as submitted text.',
    'owner_id':'Account responsible for the record.', 'is_public':'Explicit public-release flag; distinct from legislative status.',
    'verified_at':'Timestamp indicating that review/registration has completed.',
    'source_system':'Name of the originating external system.', 'enactment_date':'Date of legislative enactment, if supplied.',
    'file_path':'Local uploads path or configured remote storage URL.',
    'ocr_text':'Extracted document text used for retrieval.', 'body':'Structured or supplied legislative content.',
    'previous_version_id':'Previous registered revision; application also checks chain consistency.',
    'next_version_id':'Next revision; NULL normally identifies a current chain head.',
    'records_status':'Intake/records-management status, distinct from legislative status.',
    'classification':'Record classification label; does not itself grant public access.',
    'originating_office':'Original office label retained as provenance.',
    'originating_division':'Original division label retained as provenance.',
    'submitter_position':'Submitter position label retained as provenance.',
    'responsible_custodian':'Custodian label supplied with the record.',
    'related_legislative_item':'External legislative-item reference or descriptive label.',
    'source_record_id':'Identifier assigned by the originating system (not a local foreign key).',
    'source_status':'Status received from the originating system.',
    'source_status_date':'Timestamp associated with the source status.',
    'status_last_synced':'Most recent source-status synchronization time.',
    'received_at':'Time the record or receipt entered this system.',
    'registered_at':'Official registration timestamp.',
    'validation_note':'Latest reviewer explanation for the current intake state.',
    'pending_since':'Start of the current pending period.',
    'follow_up_due_at':'Next records follow-up deadline.',
    'agenda_monitoring_due_at':'Agenda monitoring deadline.',
    'retention_period_years':'Assigned retention duration in years, if specified.',
    'disposal_reference':'Reference documenting disposal authorization or decision.',
    'council_term':'Council term number associated with the record.',
    'display_name':'Original user-facing attachment name.', 'sort_order':'Attachment display order.',
    'relationship_type':'Semantic relationship between two records.',
    'destination_system':'Target archive/integration system.',
    'external_reference_id':'Source/target system identifier; not a local foreign key.',
    'transferred_at':'Recorded archive-transfer time.',
    'username_snapshot':'Actor username retained even if the account is later renamed.',
    'module':'Application module associated with the permission or event.',
    'detail':'Action detail or contextual explanation.',
    'ip_address':'Client network address associated with the event/session.',
    'requester_name':'Requester name captured at submission.', 'requester_email':'Requester contact address captured at submission.',
    'purpose':'Request purpose or consent-processing purpose identifier.',
    'request_letter_path':'Stored request-letter path or remote URL.',
    'decision_note':'Explanation of the access decision.', 'decided_at':'Time the request was decided.',
    'reason':'Reason for requesting a copy.', 'review_note':'Reviewer explanation.', 'reviewed_at':'Time review completed.',
    'payload_reference':'Reference to an external payload; not a database relationship.',
    'processing_status':'State of processing this integration receipt.',
    'error_message':'Latest operational failure or validation explanation.',
    'code':'Verification challenge value; sensitive authentication data.',
    'expires_at':'Expiry timestamp.', 'used_at':'Consumption timestamp; NULL until consumed.',
    'token':'Reset credential or worker lease token; sensitive operational value.',
    'type':'Notification category.', 'message':'Notification text.', 'is_read':'Whether the recipient has read the notification.',
    'files_json':'Snapshot of files queued for OCR; bounded job payload.',
    'requested_by':'Account that requested OCR; this legacy column has no declared foreign key.',
    'original_text_hash':'Fingerprint preventing stale OCR from overwriting newer content.',
    'file_index':'Index of the current OCR file.', 'file_count':'Number of files in the OCR job.',
    'pages_done':'Completed OCR pages.', 'pages_total':'Known total OCR pages.',
    'attempts':'Number of worker execution attempts.', 'heartbeat_at':'Most recent worker heartbeat.', 'finished_at':'Job completion time.',
    'decision':'Recorded consent/acknowledgement choice.', 'notice_version':'Version of the privacy notice presented.',
    'request_type':'Personal-data request category.', 'details':'Requester-provided explanation.', 'response':'Administrator response.',
    'viewed_at':'Most recent document-view timestamp.',
    'can_view':'Whether this rule grants viewing.', 'can_download':'Whether this rule grants downloading.',
    'valid_until':'Optional access-grant expiry.',
    'contact_person':'Person contacted during follow-up.', 'contact_method':'Follow-up communication channel.',
    'next_due_at':'Next follow-up date.', 'scheduled_for':'Schedule slot that produced this run.',
    'generated_at':'Report generation timestamp.',
    'snapshot_json':'Bounded report snapshot including original record IDs for later access rechecks.',
    'pdf_bytes':'Generated private PDF binary; included in database backup.',
    'options_json':'Allowlisted saved report filters, grouping and columns.',
    'frequency':'Daily, weekly or monthly generation frequency.', 'run_time':'HH:MM schedule time in Asia/Manila.',
    'enabled':'Whether automatic report generation is enabled.', 'next_run_at':'Next scheduled generation time.',
    'review_date':'Retention-review date.', 'outcome':'Retention-review decision.',
    'search_criteria':'Serialized reusable search settings.', 'query':'User-entered search text.',
    'search_type':'Keyword or semantic retrieval mode.', 'results_count':'Number of results reported by the search.',
    'mime_type':'Image media type.', 'image_data':'Optional profile-photo binary.',
    'session_token':'Credential identifying a login session; sensitive.', 'user_agent':'Reported browser/client identifier.',
    'last_seen':'Most recent activity timestamp.', 'full_name':'Account display name.',
    'username':'Unique sign-in name.', 'email':'Account email used for authentication/contact.',
    'password_hash':'One-way password hash; never plaintext.',
    'must_change_password':'Whether a password change is required before other actions.',
    'failed_attempts':'Consecutive failed authentication attempts.', 'locked_until':'Temporary account lockout deadline.',
    'last_login_at':'Most recent successful sign-in time.', 'totp_secret':'Stored authenticator secret, if configured; sensitive.',
    'totp_enabled':'Legacy account MFA preference; privileged policy may enforce MFA independently.',
    'applied_at':'Time a one-time migration marker was recorded.',
}

def cell(value):
    return str(value).replace('|', r'\|').replace('\n', '<br>')

def main():
    data=json.loads(Path(sys.argv[1]).read_text(encoding='utf-8-sig'))
    root=Path(__file__).resolve().parent.parent
    output=root/'docs'
    lines=['# Database data dictionary','','Generated from the local migrated schema; schema metadata only, no account or document rows. Regenerate after migrations. This snapshot does not certify that production has the same schema.','',f"Server: `{data['server_version']}`. Tables: **{len(data['tables'])}**. Columns: **{sum(len(t['columns']) for t in data['tables'].values())}**.",'','Field descriptions are application interpretations. Foreign-key targets and column definitions come directly from the database; external/provenance labels are not inferred to be enforced relationships.','']
    erd=['erDiagram']; unknown=[]
    for name,table in data['tables'].items():
        if name not in TABLES: raise ValueError('Missing table description: '+name)
        lines += [f'## {name}','',TABLES[name],'',f"Engine: {table['engine']}; collation: {table['collation']}.",'','| Column | Type | Nullable | Default | Key / extra | Description |','|---|---|---|---|---|---|']
        fks={f['COLUMN_NAME']:f for f in table['foreign_keys']}
        erd += [f'    {name} {{']
        for column in table['columns']:
            key=column['COLUMN_NAME']; description=FIELDS.get(key)
            if name=='security_rate_limits' and key=='expires_at': description='Unix epoch seconds when this fixed rate window ends; cleanup may remove expired buckets.'
            if key in fks:
                fk=fks[key]; ref=f"{fk['REFERENCED_TABLE_NAME']}.{fk['REFERENCED_COLUMN_NAME']}"
                description=(description+' ' if description else '')+f"Foreign key to {ref}; DELETE {fk['DELETE_RULE']}, UPDATE {fk['UPDATE_RULE']}."
            if not description: unknown.append(name+'.'+key); description='DESCRIPTION REQUIRED'
            default='NULL / none' if column['COLUMN_DEFAULT'] is None else column['COLUMN_DEFAULT']
            lines.append('| '+' | '.join(map(cell,[key,column['COLUMN_TYPE'],column['IS_NULLABLE'],default,(column['COLUMN_KEY']+' '+column['EXTRA']).strip(),description]))+' |')
            if column['COLUMN_KEY']=='PRI' or key in fks:
                marker='PK' if column['COLUMN_KEY']=='PRI' else 'FK'
                erd.append(f"        {column['COLUMN_TYPE'].split('(')[0].split()[0]} {key} {marker}")
        erd.append('    }')
        lines += ['','Indexes:','']
        indexes={}
        for index in table['indexes']:
            indexes.setdefault(index['INDEX_NAME'],{'kind':index['INDEX_TYPE'],'unique':not int(index['NON_UNIQUE']),'columns':[]})['columns'].append(index['COLUMN_NAME']+(f"({index['SUB_PART']})" if index['SUB_PART'] else ''))
        for index,meta in indexes.items(): lines.append(f"- `{index}`: {', '.join(meta['columns'])}; {meta['kind']}{'; unique' if meta['unique'] else ''}.")
        lines.append('')
        for fk in table['foreign_keys']:
            column=next(c for c in table['columns'] if c['COLUMN_NAME']==fk['COLUMN_NAME'])
            parent='|o' if column['IS_NULLABLE']=='YES' else '||'
            unique=any(meta['unique'] and meta['columns']==[fk['COLUMN_NAME']] for meta in indexes.values())
            child='o|' if unique else 'o{'
            erd.append(f"    {fk['REFERENCED_TABLE_NAME']} {parent}--{child} {name} : {fk['COLUMN_NAME']}")
    if unknown: raise ValueError('Missing descriptions: '+', '.join(unknown))
    (output/'database-data-dictionary.md').write_text('\n'.join(lines)+'\n',encoding='utf-8')
    (output/'database-erd.mmd').write_text('\n'.join(erd)+'\n',encoding='utf-8')
    (output/'database-schema.json').write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    print('Generated full dictionary, schema metadata and ER diagram; no missing field descriptions.')

if __name__=='__main__': main()
