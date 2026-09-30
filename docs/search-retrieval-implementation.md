# Search and retrieval

Implemented:

- Year (enactment year), committee, originating office, classification, type, status and enactment-date filters are applied to the SQL visibility clause before keyword retrieval. Filter-only searches work.
- Keyword search prioritizes exact document number, exact title, title matches, then date. The internal page retrieves all matching rows and paginates 20 at a time; the external API retains its default 25-result limit. Large collections will need database-side pagination for memory efficiency.
- Saved searches retain all new filters. Semantic results remain limited to candidates returned by the configured service; fallback keyword search uses the full filtered result set internally.
- Open Document leads to the existing metadata, version information, and attachment viewer. Print Record Details prints a metadata summary.
- Download Document Copy is a separate role permission. The download endpoint checks record visibility and registration, resolves only stored attachment IDs, restricts local paths to uploads, and records download dispatch in the audit log. Remote downloads accept only the configured HTTPS storage URL and do not follow redirects.
- Users can request copies of visible registered records. Review Copy Requests is a separate role permission. Reviewers must be able to view the record, cannot approve their own requests, and must provide a review note. Approved requests permit downloads only while the requester can still view the record.
- Migration: `php database/migrate_retrieval.php`. Neither permission is granted automatically. Admins select them manually in Roles & Permissions.

Limits: This is a document-copy request workflow, not the full revision-plan access-request/appeal process. It does not expose hidden records or implement request-letter attachments, identity verification, expiry, appeals, or certification. Existing direct attachment previews/public storage URLs still allow viewers to save content; the new download permission controls the dedicated download endpoint, not browser saving of already-visible files. A full storage protection rollout requires routing every file consumer through authorized delivery and securing the storage origin.

Verification: PHP lint on changed files; database checks for full keyword result count, classification filtering, and saved filter round-trip. Browser and remote-storage end-to-end flows remain unverified.
