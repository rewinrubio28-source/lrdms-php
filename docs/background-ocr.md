# Background OCR

Run OCR on a saved incoming or registered document to enqueue its attachments.
The document page shows queued, running, completed, or failed, plus the current
attachment and completed page count. Leaving the page or signing out does not
stop the worker. Return to the document to view progress or open the saved text.
Run OCR again to retry a failed job. Existing text remains until all attachments
finish successfully. Files or extracted text changed during scanning cause the
job to fail rather than overwrite newer content.

## Deploy

The PHP Docker image starts Apache and the OCR worker under Supervisor and
applies the additive queue migration on worker startup. Deploy the updated Python
OCR service as well, and configure `OCR_SERVICE_URL` on the PHP container.
The worker shares the PHP container's database configuration and uploaded files.
Use persistent uploads or the configured remote storage across deployments.

For XAMPP or a separate worker host, run from the project root:

```powershell
php database/migrate_ocr_jobs.php
php bin/ocr_worker.php
```

Keep the worker running through your service manager or Windows Task Scheduler
(configure restart on failure). The web server alone does not process queued jobs.
For a scheduled task that handles at most one document per invocation, use
`php bin/ocr_worker.php --once`. Do not run the worker through a browser.
`php database/upgrade.php` also includes the migration for existing installations.

Only one active job is allowed per document. Workers claim jobs transactionally;
a worker interrupted for ten minutes is retried from the beginning, up to three
attempts. Old workers cannot overwrite a newer attempt. Page results are held by
the worker until completion, so failed attempts never save partial text.

This queue covers **Run OCR on saved documents**. The legacy upload-preview API
and OCR during creation of an amended version still run synchronously.

## Checks

`php database/test_ocr_jobs.php` exercises the queue using temporary tables on a
local database; it does not modify existing documents. Run the layout tests with
`python -m unittest discover -s ocr_service -p "test_*.py"`.
