# Repository demo and current scope

LRDMS (System 6) receives, validates, registers, stores, searches and controls access to documents/data supplied by other legislative systems. Retention-policy ownership belongs to System 8. Operational backups in LRDMS remain recovery safeguards, not a retention-management workflow.

Upstream approval, readings and committee decisions are not performed again in LRDMS. Registration checks the received record; it is not legislative approval. A received document is not necessarily enacted. The currently accepted integration stage must be agreed with each source when live integration is implemented.

## Three distinct concepts

- `source_status`: the supplied description, such as Final minutes, Approved report, Enacted, or Withdrawn. It is descriptive metadata and can be corrected before registration by an authorized metadata editor.
- `records_status`: LRDMS handling, such as Pending Validation, Returned for Correction, Validated and Registered.
- `status`: existing legacy legislative filter used by reports, visibility rules and search. Accepted intake values remain Draft, Submitted, Under Review, Enacted, Amended and Rejected. A source-specific status does not silently become Enacted; when no legacy status is supplied it defaults to Submitted. Existing public-release eligibility rules remain in effect. This field is not a universal source-workflow engine.

## Manual demonstration (no live integration needed)

1. Prepare your own fictional metadata records using the existing dataset import template. Clearly label sample titles and document numbers DEMO. No sample dataset is bundled with this guide.
2. Open the received record in Encoding & Submission. Check the metadata, add an appropriate sample attachment through the existing correction/upload path if needed, and verify the source reference and source status.
3. Validate and register a private copy. Demonstrate return-for-correction if desired before registration. Registration does not change the originating system's status.
4. Open the registered document in Repository. Review Source information, Document references, and LRDMS receiving/registration.
5. Select Tracking & History → Add source event. Choose Simulated demo for fictional events. Use Manual source record only with a supporting document/page reference. Source history is separate from LRDMS processing events.
6. Demonstrate retrieval and permitted version linking. Do not describe manually imported samples or simulated events as successfully integrated transactions.

No existing records are changed automatically; repeated imports are subject to normal duplicate checks. Do not publish fictional records as official public documents.

The old interview workflow remains historical background in `ordinance-process-workflow.md`, not the current receiving boundary. A complete live integration contract and generic cross-system status filtering are future work; they are not required to demonstrate this manual receiving flow.
