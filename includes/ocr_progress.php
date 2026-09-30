<?php
// Shared by incoming and registered document workspaces. Polling only observes
// progress; the independent CLI worker continues when the browser is closed.
?>
<div class="small mt-2" data-ocr-progress data-document-id="<?= (int)$doc['id'] ?>" role="status" aria-live="polite">
  <span data-ocr-message>Checking OCR status...</span>
  <progress data-ocr-bar class="w-100" hidden></progress>
  <a data-ocr-result href="document_text.php?id=<?= (int)$doc['id'] ?>" hidden>View extracted text</a>
</div>
<script src="assets/js/ocr-progress.js" defer></script>
