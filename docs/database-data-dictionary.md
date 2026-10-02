# Database data dictionary

Generated from the local migrated schema; schema metadata only, no account or document rows. Regenerate after migrations. This snapshot does not certify that production has the same schema.

Server: `10.4.32-MariaDB`. Tables: **38**. Columns: **290**.

Field descriptions are application interpretations. Foreign-key targets and column definitions come directly from the database; external/provenance labels are not inferred to be enforced relationships.

## access_requests

Requests for access to a restricted document, including request-letter reference and decision.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| requester_id | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| requester_name | varchar(180) | NO | NULL / none |  | Requester name captured at submission. |
| requester_email | varchar(180) | YES | NULL |  | Requester contact address captured at submission. |
| purpose | text | NO | NULL / none |  | Request purpose or consent-processing purpose identifier. |
| request_letter_path | varchar(500) | YES | NULL |  | Stored request-letter path or remote URL. |
| status | enum('Pending','Granted','Denied','Appeal Pending','Closed') | NO | 'Pending' | MUL | Lifecycle state; interpretation depends on the table. |
| decision_note | text | YES | NULL |  | Explanation of the access decision. |
| decided_by | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| decided_at | datetime | YES | NULL |  | Time the request was decided. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |
| updated_at | datetime | NO | current_timestamp() | on update current_timestamp() | Most recent update time. |

Indexes:

- `decided_by`: decided_by; BTREE.
- `document_id`: document_id; BTREE.
- `idx_access_request_status`: status, created_at; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `requester_id`: requester_id; BTREE.

## access_request_reviews

Review events for restricted-document access requests.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| request_id | bigint(20) | NO | NULL / none | MUL | Foreign key to access_requests.id; DELETE CASCADE, UPDATE RESTRICT. |
| reviewer_id | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| action | enum('Granted','Denied','Appeal Filed','Appeal Resolved','Note') | NO | NULL / none |  | Recorded operation or review decision. |
| note | text | YES | NULL |  | Operator-entered explanatory note. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `request_id`: request_id; BTREE.
- `reviewer_id`: reviewer_id; BTREE.

## application_migrations

One-time migration markers; not business records.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| name | varchar(100) | NO | NULL / none | PRI | Human-readable name (migration identifier in application_migrations). |
| applied_at | datetime | NO | current_timestamp() |  | Time a one-time migration marker was recorded. |

Indexes:

- `PRIMARY`: name; BTREE; unique.

## archive_transfers

Preparation and tracking of record transfers to another archive system.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| destination_system | varchar(100) | NO | 'System 8' |  | Target archive/integration system. |
| status | enum('Prepared','Transferred','Failed','Cancelled') | NO | 'Prepared' |  | Lifecycle state; interpretation depends on the table. |
| external_reference_id | varchar(180) | YES | NULL |  | Source/target system identifier; not a local foreign key. |
| prepared_by | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| transferred_at | datetime | YES | NULL |  | Recorded archive-transfer time. |
| note | text | YES | NULL |  | Operator-entered explanatory note. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `document_id`: document_id; BTREE.
- `prepared_by`: prepared_by; BTREE.
- `PRIMARY`: id; BTREE; unique.

## audit_log

Append-only application action history with an actor-name snapshot.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| username_snapshot | varchar(60) | YES | NULL |  | Actor username retained even if the account is later renamed. |
| module | varchar(60) | NO | NULL / none | MUL | Application module associated with the permission or event. |
| action | varchar(100) | NO | NULL / none |  | Recorded operation or review decision. |
| detail | varchar(500) | YES | NULL |  | Action detail or contextual explanation. |
| ip_address | varchar(45) | YES | NULL |  | Client network address associated with the event/session. |
| created_at | datetime | NO | current_timestamp() | MUL | Time this row was created. |

Indexes:

- `idx_audit_created`: created_at, id; BTREE.
- `idx_audit_module_created`: module, created_at, id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `user_id`: user_id; BTREE.

## committees

Committee reference list.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| name | varchar(150) | NO | NULL / none |  | Human-readable name (migration identifier in application_migrations). |

Indexes:

- `PRIMARY`: id; BTREE; unique.

## divisions

Divisions under an organizational office.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| office_id | int(11) | YES | NULL | MUL | Foreign key to offices.id; DELETE SET NULL, UPDATE RESTRICT. |
| name | varchar(180) | NO | NULL / none |  | Human-readable name (migration identifier in application_migrations). |
| is_active | tinyint(1) | NO | 1 |  | Whether this account/reference/session is active. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `uq_division_office`: office_id, name; BTREE; unique.

## documents

Legislative record metadata, content, intake state, provenance and revision links.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| doc_number | varchar(60) | NO | NULL / none | MUL | External/business document reference. Indexed but deliberately not globally UNIQUE. |
| title | varchar(500) | NO | NULL / none | MUL | Record or report title. |
| description | text | YES | NULL |  | Explanatory description. |
| doc_type | enum('Ordinance','Resolution','Committee Report','Minutes','Other') | NO | 'Other' |  | Category of legislative record. |
| sponsor | varchar(150) | YES | NULL |  | Sponsor name retained as submitted text. |
| committee_id | int(11) | YES | NULL | MUL | Foreign key to committees.id; DELETE RESTRICT, UPDATE RESTRICT. |
| owner_id | int(11) | NO | NULL / none | MUL | Account responsible for the record. Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| status | enum('Draft','Submitted','Under Review','Enacted','Amended','Superseded','Withdrawn','Rejected') | NO | 'Draft' |  | Lifecycle state; interpretation depends on the table. |
| is_public | tinyint(1) | NO | 0 |  | Explicit public-release flag; distinct from legislative status. |
| verified_at | datetime | YES | NULL | MUL | Timestamp indicating that review/registration has completed. |
| source_system | varchar(100) | NO | 'Manual Encoding' |  | Name of the originating external system. |
| enactment_date | date | YES | NULL |  | Date of legislative enactment, if supplied. |
| file_path | varchar(500) | YES | NULL |  | Local uploads path or configured remote storage URL. |
| ocr_text | mediumtext | YES | NULL |  | Extracted document text used for retrieval. |
| body | longtext | YES | NULL |  | Structured or supplied legislative content. |
| previous_version_id | int(11) | YES | NULL | MUL | Previous registered revision; application also checks chain consistency. Foreign key to documents.id; DELETE RESTRICT, UPDATE RESTRICT. |
| next_version_id | int(11) | YES | NULL | MUL | Next revision; NULL normally identifies a current chain head. Foreign key to documents.id; DELETE RESTRICT, UPDATE RESTRICT. |
| created_at | datetime | NO | current_timestamp() | MUL | Time this row was created. |
| updated_at | datetime | NO | current_timestamp() | on update current_timestamp() | Most recent update time. |
| records_status | enum('Submitted','Pending Validation','Returned for Correction','Validated','Registered','Active','Archive Eligible','Archive Preparation','Transferred to Archive System','Duplicate','Unauthorized Submission') | NO | 'Registered' |  | Intake/records-management status, distinct from legislative status. |
| classification | enum('PUBLIC','INTERNAL','RESTRICTED','CONFIDENTIAL') | NO | 'INTERNAL' |  | Record classification label; does not itself grant public access. |
| originating_office | varchar(180) | YES | NULL |  | Original office label retained as provenance. |
| originating_division | varchar(180) | YES | NULL |  | Original division label retained as provenance. |
| submitter_position | varchar(180) | YES | NULL |  | Submitter position label retained as provenance. |
| responsible_custodian | varchar(180) | YES | NULL |  | Custodian label supplied with the record. |
| related_legislative_item | varchar(180) | YES | NULL |  | External legislative-item reference or descriptive label. |
| source_record_id | varchar(180) | YES | NULL |  | Identifier assigned by the originating system (not a local foreign key). |
| source_status | varchar(100) | YES | NULL |  | Status received from the originating system. |
| source_status_date | datetime | YES | NULL |  | Timestamp associated with the source status. |
| status_last_synced | datetime | YES | NULL |  | Most recent source-status synchronization time. |
| received_at | datetime | YES | NULL |  | Time the record or receipt entered this system. |
| registered_at | datetime | YES | NULL |  | Official registration timestamp. |
| validation_note | text | YES | NULL |  | Latest reviewer explanation for the current intake state. |
| pending_since | datetime | YES | NULL |  | Start of the current pending period. |
| follow_up_due_at | datetime | YES | NULL |  | Next records follow-up deadline. |
| agenda_monitoring_due_at | datetime | YES | NULL |  | Agenda monitoring deadline. |
| retention_period_years | smallint(5) unsigned | YES | NULL |  | Assigned retention duration in years, if specified. |
| disposal_reference | varchar(255) | YES | NULL |  | Reference documenting disposal authorization or decision. |
| council_term | smallint(5) unsigned | YES | NULL | MUL | Council term number associated with the record. |

Indexes:

- `committee_id`: committee_id; BTREE.
- `fk_documents_previous_version_id`: previous_version_id; BTREE.
- `ft_search`: title, ocr_text, body; FULLTEXT.
- `idx_documents_council_term`: council_term; BTREE.
- `idx_document_created`: created_at, id; BTREE.
- `idx_document_intake`: verified_at, created_at; BTREE.
- `idx_document_number`: doc_number; BTREE.
- `idx_next_version`: next_version_id; BTREE.
- `owner_id`: owner_id; BTREE.
- `PRIMARY`: id; BTREE; unique.

## document_attachments

Multiple stored files associated with one document.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| file_path | varchar(500) | NO | NULL / none |  | Local uploads path or configured remote storage URL. |
| display_name | varchar(255) | YES | NULL |  | Original user-facing attachment name. |
| sort_order | int(11) | NO | 0 |  | Attachment display order. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `idx_document`: document_id, sort_order; BTREE.
- `PRIMARY`: id; BTREE; unique.

## document_change_notes

Notes explaining changes to document metadata or versions.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| note | text | NO | NULL / none |  | Operator-entered explanatory note. |
| created_by | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `created_by`: created_by; BTREE.
- `document_id`: document_id; BTREE.
- `PRIMARY`: id; BTREE; unique.

## document_copy_requests

Requests for an authorized document copy and their review outcome.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE RESTRICT, UPDATE RESTRICT. |
| requester_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| reason | text | NO | NULL / none |  | Reason for requesting a copy. |
| status | enum('Pending','Approved','Denied') | NO | 'Pending' |  | Lifecycle state; interpretation depends on the table. |
| reviewed_by | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| review_note | text | YES | NULL |  | Reviewer explanation. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |
| reviewed_at | datetime | YES | NULL |  | Time review completed. |

Indexes:

- `document_id`: document_id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `requester_id`: requester_id; BTREE.
- `reviewed_by`: reviewed_by; BTREE.

## document_relationships

Typed links between related legislative records.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| related_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| relationship_type | enum('amends','repeals','substitutes','consolidates','related') | NO | NULL / none |  | Semantic relationship between two records. |
| created_by | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `created_by`: created_by; BTREE.
- `idx_document`: document_id; BTREE.
- `idx_related`: related_id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `uniq_rel`: document_id, related_id, relationship_type; BTREE; unique.

## integration_receipts

Receipt and processing history for externally submitted records.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| source_system | varchar(100) | NO | NULL / none |  | Name of the originating external system. |
| external_reference_id | varchar(180) | YES | NULL |  | Source/target system identifier; not a local foreign key. |
| payload_reference | varchar(255) | YES | NULL |  | Reference to an external payload; not a database relationship. |
| received_at | datetime | NO | current_timestamp() |  | Time the record or receipt entered this system. |
| received_by | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| processing_status | enum('Pending Validation','Validated','Registered','Returned for Correction','Duplicate','Unauthorized Submission','Error') | NO | 'Pending Validation' | MUL | State of processing this integration receipt. |
| lrdms_record_id | int(11) | YES | NULL | MUL | Foreign key to documents.id; DELETE SET NULL, UPDATE RESTRICT. |
| error_message | text | YES | NULL |  | Latest operational failure or validation explanation. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `idx_receipt_status`: processing_status, received_at; BTREE.
- `lrdms_record_id`: lrdms_record_id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `received_by`: received_by; BTREE.

## login_otp_codes

Time-limited email sign-in challenges.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| code | varchar(10) | NO | NULL / none |  | Verification challenge value; sensitive authentication data. |
| expires_at | datetime | NO | NULL / none |  | Expiry timestamp. |
| used_at | datetime | YES | NULL |  | Consumption timestamp; NULL until consumed. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `user_id`: user_id; BTREE.

## notifications

Per-user application notifications and read state.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| type | varchar(40) | NO | NULL / none |  | Notification category. |
| document_id | int(11) | YES | NULL | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| message | varchar(500) | NO | NULL / none |  | Notification text. |
| is_read | tinyint(1) | NO | 0 |  | Whether the recipient has read the notification. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `document_id`: document_id; BTREE.
- `idx_user_unread`: user_id, is_read, created_at; BTREE.
- `PRIMARY`: id; BTREE; unique.

## ocr_jobs

One background extraction job per document with progress and retry state.

Engine: InnoDB; collation: utf8mb4_general_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| document_id | int(11) | NO | NULL / none | PRI | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| requested_by | int(11) | NO | NULL / none |  | Account that requested OCR; this legacy column has no declared foreign key. |
| status | varchar(20) | NO | 'queued' | MUL | Lifecycle state; interpretation depends on the table. |
| token | char(32) | NO | NULL / none |  | Reset credential or worker lease token; sensitive operational value. |
| files_json | longtext | NO | NULL / none |  | Snapshot of files queued for OCR; bounded job payload. |
| original_text_hash | char(64) | NO | NULL / none |  | Fingerprint preventing stale OCR from overwriting newer content. |
| file_index | int(11) | NO | 0 |  | Index of the current OCR file. |
| file_count | int(11) | NO | 0 |  | Number of files in the OCR job. |
| pages_done | int(11) | NO | 0 |  | Completed OCR pages. |
| pages_total | int(11) | YES | NULL |  | Known total OCR pages. |
| error_message | varchar(500) | YES | NULL |  | Latest operational failure or validation explanation. |
| attempts | int(11) | NO | 0 |  | Number of worker execution attempts. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |
| heartbeat_at | datetime | NO | current_timestamp() |  | Most recent worker heartbeat. |
| finished_at | datetime | YES | NULL |  | Job completion time. |

Indexes:

- `idx_ocr_queue`: status, created_at; BTREE.
- `PRIMARY`: document_id; BTREE; unique.

## offices

Organizational office reference list.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| name | varchar(180) | NO | NULL / none | UNI | Human-readable name (migration identifier in application_migrations). |
| is_active | tinyint(1) | NO | 1 |  | Whether this account/reference/session is active. |

Indexes:

- `name`: name; BTREE; unique.
- `PRIMARY`: id; BTREE; unique.

## password_reset_codes

Time-limited password-reset verification codes.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| code | varchar(10) | NO | NULL / none |  | Verification challenge value; sensitive authentication data. |
| expires_at | datetime | NO | NULL / none |  | Expiry timestamp. |
| used_at | datetime | YES | NULL |  | Consumption timestamp; NULL until consumed. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `user_id`: user_id; BTREE.

## password_reset_tokens

Single-use password-reset token records.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| token | varchar(255) | NO | NULL / none | UNI | Reset credential or worker lease token; sensitive operational value. |
| expires_at | datetime | NO | NULL / none |  | Expiry timestamp. |
| used_at | datetime | YES | NULL |  | Consumption timestamp; NULL until consumed. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `token`: token; BTREE; unique.
- `user_id`: user_id; BTREE.

## permissions

Named module/action permissions.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| module | varchar(60) | NO | NULL / none | MUL | Application module associated with the permission or event. |
| action | varchar(60) | NO | NULL / none |  | Recorded operation or review decision. |
| description | varchar(255) | YES | NULL |  | Explanatory description. |

Indexes:

- `module_action`: module, action; BTREE; unique.
- `PRIMARY`: id; BTREE; unique.

## positions

Position reference list.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| name | varchar(180) | NO | NULL / none | UNI | Human-readable name (migration identifier in application_migrations). |
| is_active | tinyint(1) | NO | 1 |  | Whether this account/reference/session is active. |

Indexes:

- `name`: name; BTREE; unique.
- `PRIMARY`: id; BTREE; unique.

## privacy_events

Privacy acknowledgement and optional-consent decision history.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| purpose | varchar(40) | NO | NULL / none |  | Request purpose or consent-processing purpose identifier. |
| decision | varchar(20) | NO | NULL / none |  | Recorded consent/acknowledgement choice. |
| notice_version | varchar(30) | NO | NULL / none |  | Version of the privacy notice presented. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `idx_privacy_user`: user_id, id; BTREE.
- `PRIMARY`: id; BTREE; unique.

## privacy_requests

Personal-data requests and administrator responses.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| request_type | varchar(30) | NO | NULL / none |  | Personal-data request category. |
| details | text | NO | NULL / none |  | Requester-provided explanation. |
| status | varchar(20) | NO | 'Open' | MUL | Lifecycle state; interpretation depends on the table. |
| response | text | YES | NULL |  | Administrator response. |
| reviewed_by | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |
| updated_at | datetime | NO | current_timestamp() |  | Most recent update time. |

Indexes:

- `idx_privacy_request`: status, created_at; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `reviewed_by`: reviewed_by; BTREE.
- `user_id`: user_id; BTREE.

## recently_viewed_documents

Most recent viewing time per user/document pair.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| viewed_at | datetime | NO | current_timestamp() | on update current_timestamp() | Most recent document-view timestamp. |

Indexes:

- `document_id`: document_id; BTREE.
- `idx_user_viewed`: user_id, viewed_at; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `uniq_user_doc`: user_id, document_id; BTREE; unique.

## record_access_rules

Explicit document access grants to users or organizational/role scopes.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| user_id | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| role_id | int(11) | YES | NULL | MUL | Foreign key to roles.id; DELETE CASCADE, UPDATE RESTRICT. |
| office_id | int(11) | YES | NULL | MUL | Foreign key to offices.id; DELETE CASCADE, UPDATE RESTRICT. |
| committee_id | int(11) | YES | NULL | MUL | Foreign key to committees.id; DELETE CASCADE, UPDATE RESTRICT. |
| can_view | tinyint(1) | NO | 1 |  | Whether this rule grants viewing. |
| can_download | tinyint(1) | NO | 0 |  | Whether this rule grants downloading. |
| valid_until | datetime | YES | NULL |  | Optional access-grant expiry. |
| created_by | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `committee_id`: committee_id; BTREE.
- `created_by`: created_by; BTREE.
- `document_id`: document_id; BTREE.
- `office_id`: office_id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `role_id`: role_id; BTREE.
- `user_id`: user_id; BTREE.

## record_followups

Recorded contacts and next follow-up dates for incoming records.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE RESTRICT, UPDATE RESTRICT. |
| recorded_by | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| contact_person | varchar(180) | NO | NULL / none |  | Person contacted during follow-up. |
| contact_method | varchar(30) | NO | NULL / none |  | Follow-up communication channel. |
| note | text | NO | NULL / none |  | Operator-entered explanatory note. |
| next_due_at | datetime | YES | NULL |  | Next follow-up date. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `idx_followup_document`: document_id, id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `recorded_by`: recorded_by; BTREE.

## record_validation_history

Review and registration decisions for a document.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| actor_id | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| action | enum('Validated','Returned for Correction','Registered','Duplicate','Unauthorized Submission','Visibility Changed') | NO | NULL / none |  | Recorded operation or review decision. |
| note | text | YES | NULL |  | Operator-entered explanatory note. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `actor_id`: actor_id; BTREE.
- `document_id`: document_id; BTREE.
- `PRIMARY`: id; BTREE; unique.

## report_runs

Private scheduled-report snapshots, generated PDF bytes and execution results.

Engine: InnoDB; collation: utf8mb4_general_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| schedule_id | bigint(20) | NO | NULL / none | MUL | Foreign key to report_schedules.id; DELETE CASCADE, UPDATE RESTRICT. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| scheduled_for | datetime | NO | NULL / none |  | Schedule slot that produced this run. |
| status | varchar(16) | NO | 'Processing' |  | Lifecycle state; interpretation depends on the table. |
| attempts | int(11) | NO | 0 |  | Number of worker execution attempts. |
| generated_at | datetime | YES | NULL |  | Report generation timestamp. |
| snapshot_json | mediumtext | YES | NULL |  | Bounded report snapshot including original record IDs for later access rechecks. |
| pdf_bytes | mediumblob | YES | NULL |  | Generated private PDF binary; included in database backup. |
| error_message | varchar(250) | YES | NULL |  | Latest operational failure or validation explanation. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `report_run_owner`: user_id, created_at; BTREE.
- `report_slot`: schedule_id, scheduled_for; BTREE; unique.

## report_schedules

Owner-defined automatic report settings and next due time.

Engine: InnoDB; collation: utf8mb4_general_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE RESTRICT, UPDATE RESTRICT. |
| title | varchar(120) | NO | NULL / none |  | Record or report title. |
| options_json | text | NO | NULL / none |  | Allowlisted saved report filters, grouping and columns. |
| frequency | varchar(10) | NO | NULL / none |  | Daily, weekly or monthly generation frequency. |
| run_time | char(5) | NO | NULL / none |  | HH:MM schedule time in Asia/Manila. |
| enabled | tinyint(4) | NO | 1 | MUL | Whether automatic report generation is enabled. |
| next_run_at | datetime | NO | NULL / none |  | Next scheduled generation time. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `report_due`: enabled, next_run_at; BTREE.
- `report_owner`: user_id; BTREE.

## retention_reviews

Recorded retention/disposal review decisions.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | bigint(20) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| document_id | int(11) | NO | NULL / none | MUL | Foreign key to documents.id; DELETE CASCADE, UPDATE RESTRICT. |
| reviewed_by | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| review_date | date | NO | NULL / none |  | Retention-review date. |
| outcome | enum('Retain','Archive Eligible','Transfer Prepared','TBD') | NO | 'TBD' |  | Retention-review decision. |
| note | text | YES | NULL |  | Operator-entered explanatory note. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |

Indexes:

- `document_id`: document_id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `reviewed_by`: reviewed_by; BTREE.

## roles

Named access-control roles.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| name | varchar(60) | NO | NULL / none | UNI | Human-readable name (migration identifier in application_migrations). |
| description | varchar(255) | YES | NULL |  | Explanatory description. |

Indexes:

- `name`: name; BTREE; unique.
- `PRIMARY`: id; BTREE; unique.

## role_permissions

Many-to-many mapping between roles and permissions.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| role_id | int(11) | NO | NULL / none | PRI | Foreign key to roles.id; DELETE CASCADE, UPDATE RESTRICT. |
| permission_id | int(11) | NO | NULL / none | PRI | Foreign key to permissions.id; DELETE CASCADE, UPDATE RESTRICT. |

Indexes:

- `permission_id`: permission_id; BTREE.
- `PRIMARY`: role_id, permission_id; BTREE; unique.

## saved_searches

User-owned reusable search settings.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| name | varchar(150) | NO | NULL / none |  | Human-readable name (migration identifier in application_migrations). |
| search_criteria | text | NO | NULL / none |  | Serialized reusable search settings. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |
| updated_at | datetime | NO | current_timestamp() | on update current_timestamp() | Most recent update time. |

Indexes:

- `idx_user`: user_id; BTREE.
- `PRIMARY`: id; BTREE; unique.

## search_log

Search activity, retrieval mode and returned-result count.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | YES | NULL | MUL | Foreign key to users.id; DELETE SET NULL, UPDATE RESTRICT. |
| query | varchar(255) | NO | NULL / none |  | User-entered search text. |
| search_type | enum('keyword','semantic') | NO | 'keyword' |  | Keyword or semantic retrieval mode. |
| results_count | int(11) | NO | 0 |  | Number of results reported by the search. |
| created_at | datetime | NO | current_timestamp() | MUL | Time this row was created. |

Indexes:

- `idx_search_created`: created_at, search_type; BTREE.
- `idx_search_user_created`: user_id, created_at; BTREE.
- `PRIMARY`: id; BTREE; unique.

## users

Account identity, authentication state and organizational assignments.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| full_name | varchar(150) | NO | NULL / none |  | Account display name. |
| username | varchar(60) | NO | NULL / none | UNI | Unique sign-in name. |
| email | varchar(150) | YES | NULL | UNI | Account email used for authentication/contact. |
| password_hash | varchar(255) | NO | NULL / none |  | One-way password hash; never plaintext. |
| role_id | int(11) | NO | NULL / none | MUL | Foreign key to roles.id; DELETE RESTRICT, UPDATE RESTRICT. |
| committee_id | int(11) | YES | NULL | MUL | Foreign key to committees.id; DELETE RESTRICT, UPDATE RESTRICT. |
| is_active | tinyint(1) | NO | 1 |  | Whether this account/reference/session is active. |
| must_change_password | tinyint(1) | NO | 0 |  | Whether a password change is required before other actions. |
| failed_attempts | int(11) | NO | 0 |  | Consecutive failed authentication attempts. |
| locked_until | datetime | YES | NULL |  | Temporary account lockout deadline. |
| last_login_at | datetime | YES | NULL |  | Most recent successful sign-in time. |
| totp_secret | varchar(64) | YES | NULL |  | Stored authenticator secret, if configured; sensitive. |
| totp_enabled | tinyint(1) | NO | 0 |  | Legacy account MFA preference; privileged policy may enforce MFA independently. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |
| office_id | int(11) | YES | NULL | MUL | Foreign key to offices.id; DELETE SET NULL, UPDATE RESTRICT. |
| division_id | int(11) | YES | NULL | MUL | Foreign key to divisions.id; DELETE SET NULL, UPDATE RESTRICT. |
| position_id | int(11) | YES | NULL | MUL | Foreign key to positions.id; DELETE SET NULL, UPDATE RESTRICT. |

Indexes:

- `committee_id`: committee_id; BTREE.
- `email`: email; BTREE; unique.
- `fk_v5_division_id`: division_id; BTREE.
- `fk_v5_office_id`: office_id; BTREE.
- `fk_v5_position_id`: position_id; BTREE.
- `PRIMARY`: id; BTREE; unique.
- `role_id`: role_id; BTREE.
- `username`: username; BTREE; unique.

## user_committees

Many-to-many committee assignments.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| user_id | int(11) | NO | NULL / none | PRI | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| committee_id | int(11) | NO | NULL / none | PRI | Foreign key to committees.id; DELETE CASCADE, UPDATE RESTRICT. |

Indexes:

- `committee_id`: committee_id; BTREE.
- `PRIMARY`: user_id, committee_id; BTREE; unique.

## user_profile_photos

Optional profile image bytes stored separately from account identity.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| user_id | int(11) | NO | NULL / none | PRI | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| mime_type | varchar(30) | NO | NULL / none |  | Image media type. |
| image_data | mediumblob | NO | NULL / none |  | Optional profile-photo binary. |
| updated_at | datetime | NO | current_timestamp() |  | Most recent update time. |

Indexes:

- `PRIMARY`: user_id; BTREE; unique.

## user_sessions

Issued login sessions, activity timestamps and revocation state.

Engine: InnoDB; collation: utf8mb4_unicode_ci.

| Column | Type | Nullable | Default | Key / extra | Description |
|---|---|---|---|---|---|
| id | int(11) | NO | NULL / none | PRI auto_increment | Surrogate row identifier. |
| user_id | int(11) | NO | NULL / none | MUL | Foreign key to users.id; DELETE CASCADE, UPDATE RESTRICT. |
| session_token | varchar(64) | NO | NULL / none | UNI | Credential identifying a login session; sensitive. |
| ip_address | varchar(45) | YES | NULL |  | Client network address associated with the event/session. |
| user_agent | varchar(255) | YES | NULL |  | Reported browser/client identifier. |
| created_at | datetime | NO | current_timestamp() |  | Time this row was created. |
| last_seen | datetime | NO | current_timestamp() | on update current_timestamp() | Most recent activity timestamp. |
| is_active | tinyint(1) | NO | 1 |  | Whether this account/reference/session is active. |

Indexes:

- `PRIMARY`: id; BTREE; unique.
- `session_token`: session_token; BTREE; unique.
- `user_id`: user_id; BTREE.

