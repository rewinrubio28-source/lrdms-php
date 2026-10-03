<?php
// Read-only metadata; caller has already enforced document visibility.
$recordDetailGroups = [
    'Source information' => [
        'source_system' => 'Source system',
        'source_record_id' => 'Source record ID / reference',
        'source_status' => 'Status reported by source',
        'source_status_date' => 'Source status date',
        'originating_office' => 'Originating office',
        'originating_division' => 'Originating division',
        'submitter_position' => 'Submitted by (position)',
    ],
    'Document references' => [
        'related_legislative_item' => 'Related legislative reference',
        'enactment_date' => 'Enactment date (if applicable)',
    ],
    'LRDMS receiving and registration' => [
        'records_status' => 'Records status',
        'classification' => 'Classification',
        'responsible_custodian' => 'Responsible custodian',
        'owner_name' => 'Receiving / encoding account',
        'received_at' => 'Received at',
        'created_at' => 'Recorded in LRDMS at',
        'verified_at' => 'Verified at',
        'registered_at' => 'Registered at',
    ],
];
?>
<div class="verification-metadata" aria-labelledby="record-details-heading">
  <h3 id="record-details-heading">Record details</h3>
  <p class="small text-muted">Source information describes the submitted record. LRDMS receiving and registration track its handling in this repository.</p>
  <?php foreach ($recordDetailGroups as $heading => $fields): ?>
    <section class="mt-3" aria-label="<?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?>">
      <h4 class="h6 mb-3"><?= htmlspecialchars($heading) ?></h4>
      <div class="verification-metadata__grid">
        <?php foreach ($fields as $field => $label):
            $value = trim((string)($doc[$field] ?? ''));
            if ($value !== '' && in_array($field, ['source_status_date','enactment_date','received_at','created_at','verified_at','registered_at'], true)) {
                $timestamp = strtotime($value);
                if ($timestamp !== false) $value = date($field === 'enactment_date' ? 'M j, Y' : 'M j, Y, g:i A', $timestamp);
            }
        ?>
          <div><span><?= htmlspecialchars($label) ?></span><strong><?= htmlspecialchars($value !== '' ? $value : 'Not recorded', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
