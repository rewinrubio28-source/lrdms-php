# External integration API

Set `API_SHARED_KEY` in the PHP application's environment or local `.env`. Send the same value in `X-API-Key`; never put it in the URL. Examples below use placeholders, not working credentials. These endpoints use API-key authentication, not browser sessions.

## Search

`GET /api/search.php?query=traffic&mode=keyword`

`query`: required non-empty string, maximum 500 characters. `mode`: `keyword` (default) or `semantic`.

Returns at most 25 records. Only verified records with `is_public=1` are returned; Draft, Submitted and Under Review are excluded. Previously published amended records remain searchable. Fields: `id`, `doc_number`, `title`, `doc_type`, `enactment_date`.

```json
{"mode":"semantic","effective_mode":"keyword","fallback":true,"query":"traffic","results":[]}
```

`mode` preserves the requested mode for compatibility. `effective_mode` reports the engine actually used. `fallback=true` means the semantic service was unavailable or returned an invalid response. An empty valid semantic result is not treated as a failure. Search logs record the effective mode. BERT connection timeout is three seconds; total request timeout is 30 seconds before keyword fallback.

## Intake

`POST /api/upload_document.php`

Content type must be `application/json` or `multipart/form-data`. JSON must contain one object, not a list. Required fields: `doc_number` (max 60 characters), `title` (max 500).

```json
{
  "doc_number":"DEMO-2026-001",
  "title":"Sample ordinance",
  "doc_type":"Ordinance",
  "status":"Enacted",
  "source_status":"Enacted",
  "source_system":"System 1 - Lifecycle",
  "source_record_id":"SOURCE-001",
  "classification":"INTERNAL",
  "enactment_date":"2026-10-01"
}
```

Additional fields: `sponsor`, `committee_id`, `previous_version_id`, `council_term`, `source_status_date` (`Y-m-d H:i:s`), `originating_office`, `originating_division`, `submitter_position`, `responsible_custodian`, `related_legislative_item`, `body`, `ocr_text`.

Document types: Ordinance, Resolution, Committee Report, Minutes, Other. Legacy `status` values: Draft, Submitted, Under Review, Enacted, Amended, Rejected. `source_status` can preserve source-specific descriptions such as Final minutes or Approved report. If it matches a legacy status, the two must agree; otherwise an omitted legacy status defaults to Submitted, not Enacted. Classification: PUBLIC, INTERNAL, RESTRICTED, CONFIDENTIAL. Intake always sets private visibility and Pending Validation, even if the caller requests public visibility. Authorized staff must validate/register it; this is not upstream legislative approval. Source-history events currently use the manual UI, not this API.

Multipart requests use the same fields plus `attachment[]`. Accepted extensions: pdf, png, jpg, jpeg, gif, webp, doc, docx, txt. Multiple attachments belong to one record; this is not a bulk-record importer. OCR is not automatically run by this endpoint.

Successful response (HTTP 200, retained for existing clients):

```json
{"document_id":123,"status":"Enacted","records_status":"Pending Validation","is_public":false,"file_path":null,"attachment_count":0}
```

Existing document numbers return 409. A previous version must be a registered current record of the same document type. Intake needs the reserved `system.integration` account and current database migrations.

## Errors and integration checks

Validation errors return `{"error":"message"}`.

| HTTP | Meaning |
|---|---|
| 401 | Missing/incorrect API key |
| 405 | Wrong method; `Allow` header identifies the supported method |
| 409 | Duplicate document number or conflicting database reference |
| 415 | Unsupported intake content type |
| 422 | Invalid query, mode, JSON, metadata or attachment |
| 500 | Intake storage/save failure or missing integration account |
| 503 | API key not configured; maintenance may also return 503 |

The shared database/bootstrap and maintenance code can still emit a non-JSON error during infrastructure failure; clients must handle unexpected content types and retry safely. Do not assume all 5xx responses prove a submission was unsaved: check the document number before retrying. Automatic retries of a duplicate are rejected, not treated as a second creation.

Automated checks:

```powershell
php database/test_api_contract.php
php database/test_search_recovery.php
php database/test_record_workflow.php
```

The first checks request rejection; the second executes successful searches against temporary fixtures, including AI outage fallback; the third checks intake normalization and registration transactions. A successful HTTP multipart intake through the deployed web server still needs a staging/demo test, including storage failure cleanup. Shared request throttling is described in `security-hardening-review.md`.
