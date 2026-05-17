<?php

return [
    'id' => '202605070002',
    'name' => 'crm_client_requisites',
    'description' => 'Add requisites columns to crm_clients for legal and banking data.',
    'up' => static function (PDO $pdo): void {
        if (!appTableExists($pdo, 'crm_clients')) {
            return;
        }

        $requiredColumns = [
            'legal_name_full' => "ALTER TABLE crm_clients ADD COLUMN legal_name_full VARCHAR(255) NULL AFTER name",
            'legal_name_short' => "ALTER TABLE crm_clients ADD COLUMN legal_name_short VARCHAR(255) NULL AFTER legal_name_full",
            'inn' => "ALTER TABLE crm_clients ADD COLUMN inn VARCHAR(32) NULL AFTER legal_name_short",
            'kpp' => "ALTER TABLE crm_clients ADD COLUMN kpp VARCHAR(32) NULL AFTER inn",
            'ogrn' => "ALTER TABLE crm_clients ADD COLUMN ogrn VARCHAR(32) NULL AFTER kpp",
            'legal_address' => "ALTER TABLE crm_clients ADD COLUMN legal_address TEXT NULL AFTER address",
            'postal_address' => "ALTER TABLE crm_clients ADD COLUMN postal_address TEXT NULL AFTER legal_address",
            'signer_name' => "ALTER TABLE crm_clients ADD COLUMN signer_name VARCHAR(255) NULL AFTER postal_address",
            'signer_position' => "ALTER TABLE crm_clients ADD COLUMN signer_position VARCHAR(255) NULL AFTER signer_name",
            'signer_authority' => "ALTER TABLE crm_clients ADD COLUMN signer_authority VARCHAR(255) NULL AFTER signer_position",
            'bank_name' => "ALTER TABLE crm_clients ADD COLUMN bank_name VARCHAR(255) NULL AFTER signer_authority",
            'bik' => "ALTER TABLE crm_clients ADD COLUMN bik VARCHAR(32) NULL AFTER bank_name",
            'checking_account' => "ALTER TABLE crm_clients ADD COLUMN checking_account VARCHAR(64) NULL AFTER bik",
            'correspondent_account' => "ALTER TABLE crm_clients ADD COLUMN correspondent_account VARCHAR(64) NULL AFTER checking_account",
        ];

        $existingColumns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM crm_clients') as $column) {
            $existingColumns[] = (string)($column['Field'] ?? '');
        }

        foreach ($requiredColumns as $columnName => $sql) {
            if (!in_array($columnName, $existingColumns, true)) {
                $pdo->exec($sql);
                $existingColumns[] = $columnName;
            }
        }
    },
];

