<?php
require_once __DIR__.'/database_schema.php';
function database_index_plan(): array {
    return [
        ['documents','idx_document_number',['doc_number']],
        ['documents','idx_document_created',['created_at','id']],
        ['documents','idx_document_intake',['verified_at','created_at']],
        ['audit_log','idx_audit_created',['created_at','id']],
        ['audit_log','idx_audit_module_created',['module','created_at','id']],
        ['search_log','idx_search_created',['created_at','search_type']],
        ['search_log','idx_search_user_created',['user_id','created_at']]
    ];
}
function database_add_indexes(PDO $pdo): array {
    $added=[];
    foreach (database_index_plan() as [$table,$name,$columns]) {
        $existing=[];
        foreach ($pdo->query('SHOW INDEX FROM '.schema_identifier($table))->fetchAll(PDO::FETCH_ASSOC) as $row) if ($row['Index_type']==='BTREE' && $row['Sub_part']===null) $existing[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
        $covered=false;
        foreach ($existing as $parts) { ksort($parts); if (array_slice(array_values($parts),0,count($columns))===$columns) $covered=true; }
        if ($covered) continue;
        if (isset($existing[$name])) throw new RuntimeException('Index name exists with a different definition: '.$name);
        $pdo->exec('ALTER TABLE '.schema_identifier($table).' ADD INDEX '.schema_identifier($name).' ('.implode(',',array_map('schema_identifier',$columns)).')');
        $added[]=$table.'.'.$name;
    }
    return $added;
}

function database_add_revision_keys(PDO $pdo): array {
    $added=[];
    $database=$pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach (['previous_version_id','next_version_id'] as $column) {
        $query=$pdo->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME='documents' AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME='documents' AND REFERENCED_COLUMN_NAME='id'"); $query->execute([$database,$column]);
        if ($query->fetchColumn()) continue;
        $orphans=(int)$pdo->query('SELECT COUNT(*) FROM documents c LEFT JOIN documents p ON p.id=c.'.schema_identifier($column).' WHERE c.'.schema_identifier($column).' IS NOT NULL AND p.id IS NULL')->fetchColumn();
        if ($orphans) throw new RuntimeException('Resolve '.$orphans.' orphan version references in documents.'.$column.' before migration. No records were deleted.');
        $name='fk_documents_'.$column;
        $pdo->exec('ALTER TABLE documents ADD CONSTRAINT '.schema_identifier($name).' FOREIGN KEY ('.schema_identifier($column).') REFERENCES documents(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        $added[]=$name;
    }
    return $added;
}
