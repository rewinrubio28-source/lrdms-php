(() => {
  document.querySelectorAll('[data-ocr-progress]').forEach(panel => {
    const label = panel.querySelector('[data-ocr-message]');
    const bar = panel.querySelector('[data-ocr-bar]');
    const result = panel.querySelector('[data-ocr-result]');
    const buttons = document.querySelectorAll('input[value="run_ocr"], input[value="run_record_ocr"]');
    async function poll() {
      let active = false;
      try {
        const response = await fetch(`api/ocr_status.php?id=${encodeURIComponent(panel.dataset.documentId)}`, {cache: 'no-store'});
        const job = await response.json();
        if (!response.ok) throw new Error(job.error || 'Could not check OCR progress.');
        active = job.status === 'queued' || job.status === 'running';
        buttons.forEach(input => { input.form.querySelector('button[type="submit"]').disabled = active; });
        bar.hidden = !active;
        result.hidden = job.status !== 'completed';
        if (job.status === 'queued') {
          label.textContent = 'Queued for OCR. You can leave this page; processing continues in the background.';
          bar.removeAttribute('value');
        } else if (job.status === 'running') {
          const done = Number(job.pages_done);
          const total = Number(job.pages_total);
          label.textContent = `Scanning attachment ${job.file_index || 1} of ${job.file_count}: ${done}${total ? ` of ${total}` : ''} pages processed. You can leave this page.`;
          if (total) { bar.max = total; bar.value = done; }
          else bar.removeAttribute('value');
        } else if (job.status === 'completed') {
          label.textContent = 'OCR completed. Extracted text is saved. ';
        } else if (job.status === 'failed') {
          label.textContent = `OCR failed: ${job.error_message} Use Run OCR to retry. Previous saved text was kept.`;
        } else label.textContent = '';
      } catch (error) {
        label.textContent = error.message || 'Could not check OCR progress. Reconnecting...';
        active = true;
      }
      window.setTimeout(poll, active ? 3000 : 15000);
    }
    poll();
  });
})();
