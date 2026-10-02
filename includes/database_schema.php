<?php
function schema_identifier(string $name): string { return '`'.str_replace('`','``',$name).'`'; }

/** Metadata only; never reads account credentials or document contents. */
function database_schema(PDO $pdo,?string $database=null): array {
    $database=$database??$pdo->query('SELECT DATABASE()')->fetchColumn();
    $schema=['server_version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'tables'=>[]];
    $query=$pdo->prepare("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"); $query->execute([$database]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $table) {
        $name=$table['TABLE_NAME'];
        $columns=$pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'); $columns->execute([$database,$name]);
        $indexes=$pdo->prepare('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX'); $indexes->execute([$database,$name]);
        $fks=$pdo->prepare('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.ORDINAL_POSITION,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION'); $fks->execute([$database,$name]);
        $foreignKeys=$fks->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreignKeys as &$fk) $fk['REFERENCED_TABLE_SCHEMA']=$fk['REFERENCED_TABLE_SCHEMA']===$database?'(same database)':$fk['REFERENCED_TABLE_SCHEMA']; unset($fk);
        $schema['tables'][$name]=['engine'=>$table['ENGINE'],'collation'=>$table['TABLE_COLLATION'],'columns'=>$columns->fetchAll(PDO::FETCH_ASSOC),'indexes'=>$indexes->fetchAll(PDO::FETCH_ASSOC),'foreign_keys'=>$foreignKeys];
    }
    return $schema;
}

/** Explicitly check existing rows; turning FOREIGN_KEY_CHECKS on is insufficient. */
function database_foreign_key_violations(PDO $pdo,?array $schema=null): array {
    $schema=$schema??database_schema($pdo); $results=[];
    foreach ($schema['tables'] as $table=>$metadata) {
        $groups=[]; foreach ($metadata['foreign_keys'] as $fk) $groups[$fk['CONSTRAINT_NAME']][]=$fk;
        foreach ($groups as $name=>$parts) {
            $joins=[]; $notNull=[];
            foreach ($parts as $fk) { $joins[]='p.'.schema_identifier($fk['REFERENCED_COLUMN_NAME']).'=c.'.schema_identifier($fk['COLUMN_NAME']); $notNull[]='c.'.schema_identifier($fk['COLUMN_NAME']).' IS NOT NULL'; }
            $parent=$parts[0]['REFERENCED_TABLE_NAME'];
            if ($parts[0]['REFERENCED_TABLE_SCHEMA']!=='(same database)') throw new RuntimeException('Cross-database foreign keys require a coordinated native backup.');
            $sql='SELECT COUNT(*) FROM '.schema_identifier($table).' c WHERE '.implode(' AND ',$notNull).' AND NOT EXISTS (SELECT 1 FROM '.schema_identifier($parent).' p WHERE '.implode(' AND ',$joins).')';
            $count=(int)$pdo->query($sql)->fetchColumn();
            $results[]=['table'=>$table,'constraint'=>$name,'parent'=>$parent,'orphan_count'=>$count];
        }
    }
    return $results;
}
