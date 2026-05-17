<?php
declare(strict_types=1);

function crmMergeCompactSql(string $sql): string
{
    return trim((string)(preg_replace('/\s+/', ' ', $sql) ?? $sql));
}

function crmMergePrepare(PDO $pdo, string $sql, string $context): PDOStatement
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('PDO::prepare returned non-statement result');
        }
        return $stmt;
    } catch (Throwable $e) {
        throw new RuntimeException(
            sprintf(
                'SQL prepare failed during %s: %s | SQL: %s',
                $context,
                $e->getMessage(),
                crmMergeCompactSql($sql)
            ),
            0,
            $e
        );
    }
}

function crmMergeExecute(PDOStatement $stmt, array $params, string $context, ?string $sql = null): void
{
    try {
        $stmt->execute($params);
    } catch (Throwable $e) {
        $details = ['SQL execute failed during ' . $context . ': ' . $e->getMessage()];
        if ($sql !== null && $sql !== '') {
            $details[] = 'SQL: ' . crmMergeCompactSql($sql);
        }
        if ($params !== []) {
            $encoded = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $details[] = 'params: ' . $encoded;
            }
        }
        throw new RuntimeException(implode(' | ', $details), 0, $e);
    }
}

function crmMergeQuoteLikeLiteral(PDO $pdo, string $value): string
{
    $quoted = $pdo->quote($value);
    if ($quoted === false) {
        throw new RuntimeException('PDO::quote failed for SHOW ... LIKE value');
    }
    return $quoted;
}

function crmMergeEscapeIdentifier(string $value): string
{
    return str_replace('`', '``', $value);
}

function crmMergeNormalizeClientName(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = str_replace(['"', '«', '»'], '', $value);
    return trim($value);
}

function crmMergeCanonicalizeClientName(string $value): string
{
    $value = crmMergeNormalizeClientName($value);
    $value = preg_replace('/\b(ооо|ооо\.|ип|ао|пао|зао|оао)\b/iu', ' ', $value) ?? $value;
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;
    return trim($value);
}

function crmMergeTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $sql = 'SHOW TABLES LIKE ' . crmMergeQuoteLikeLiteral($pdo, $table);
    $stmt = $pdo->query($sql);
    if (!$stmt instanceof PDOStatement) {
        throw new RuntimeException('SQL query failed during merge.table_exists | SQL: ' . crmMergeCompactSql($sql));
    }
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

function crmMergeColumnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!crmMergeTableExists($pdo, $table)) {
        $cache[$key] = false;
        return false;
    }

    $safeTable = crmMergeEscapeIdentifier($table);
    $sql = "SHOW COLUMNS FROM `{$safeTable}` LIKE " . crmMergeQuoteLikeLiteral($pdo, $column);
    $stmt = $pdo->query($sql);
    if (!$stmt instanceof PDOStatement) {
        throw new RuntimeException('SQL query failed during merge.column_exists | SQL: ' . crmMergeCompactSql($sql));
    }
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function crmMergeCandidateReferenceMap(PDO $pdo): array
{
    $candidates = [
        ['table' => 'crm_client_contacts', 'column' => 'client_id', 'kind' => 'direct'],
        ['table' => 'crm_deals', 'column' => 'client_id', 'kind' => 'direct'],
        ['table' => 'crm_client_monthly_sales', 'column' => 'client_id', 'kind' => 'sales'],
        ['table' => 'tasks', 'column' => 'client_id', 'kind' => 'direct'],
        ['table' => 'document_generations', 'column' => 'client_id', 'kind' => 'direct'],
        ['table' => 'helpdesk_tickets', 'column' => 'crm_client_id', 'kind' => 'direct'],
        ['table' => 'crm_activity', 'column' => 'entity_id', 'kind' => 'activity', 'entity_type' => 'client'],
    ];

    $result = [];
    foreach ($candidates as $candidate) {
        if (!crmMergeColumnExists($pdo, $candidate['table'], $candidate['column'])) {
            continue;
        }
        if (($candidate['kind'] ?? '') === 'activity' && !crmMergeColumnExists($pdo, $candidate['table'], 'entity_type')) {
            continue;
        }
        $result[] = $candidate;
    }

    return $result;
}

function crmMergeFetchClients(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, name, legal_name_full, legal_name_short, inn, kpp, ogrn, type, email, phone, site, address, legal_address, postal_address, signer_name, signer_position, signer_authority, bank_name, bik, checking_account, correspondent_account, tags, status, customer_segment, notes, custom_fields, created_by, owner_id, user_id, created_at, updated_at FROM crm_clients ORDER BY id ASC");
    $clients = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $client) {
        $clientId = (int)($client['id'] ?? 0);
        if ($clientId <= 0) {
            continue;
        }
        $clients[$clientId] = $client;
    }
    return $clients;
}

function crmMergeCollectClientNameKeys(array $client): array
{
    $normalized = [];
    $canonical = [];

    foreach (['name', 'legal_name_full', 'legal_name_short'] as $field) {
        $value = trim((string)($client[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        $normalizedKey = crmMergeNormalizeClientName($value);
        if ($normalizedKey !== '') {
            $normalized[$normalizedKey] = true;
        }

        $canonicalKey = crmMergeCanonicalizeClientName($value);
        if ($canonicalKey !== '') {
            $canonical[$canonicalKey] = true;
        }
    }

    return [
        'normalized' => array_keys($normalized),
        'canonical' => array_keys($canonical),
    ];
}

function crmMergeUnionFindCreate(array $ids): array
{
    $parent = [];
    foreach ($ids as $id) {
        $parent[(int)$id] = (int)$id;
    }
    return $parent;
}

function crmMergeUnionFindFind(array &$parent, int $id): int
{
    if (!isset($parent[$id])) {
        $parent[$id] = $id;
    }
    if ($parent[$id] !== $id) {
        $parent[$id] = crmMergeUnionFindFind($parent, $parent[$id]);
    }
    return $parent[$id];
}

function crmMergeUnionFindUnion(array &$parent, int $a, int $b): void
{
    $rootA = crmMergeUnionFindFind($parent, $a);
    $rootB = crmMergeUnionFindFind($parent, $b);
    if ($rootA === $rootB) {
        return;
    }
    if ($rootA < $rootB) {
        $parent[$rootB] = $rootA;
    } else {
        $parent[$rootA] = $rootB;
    }
}

function crmMergeFetchSalesStats(PDO $pdo): array
{
    if (!crmMergeTableExists($pdo, 'crm_client_monthly_sales')) {
        return [];
    }

    $stmt = $pdo->query("SELECT client_id, COUNT(*) AS sales_rows, COUNT(DISTINCT sale_month) AS sales_months, COALESCE(SUM(amount), 0) AS sales_total, MIN(sale_month) AS first_sale_month, MAX(sale_month) AS last_sale_month FROM crm_client_monthly_sales GROUP BY client_id");
    $stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $clientId = (int)($row['client_id'] ?? 0);
        if ($clientId <= 0) {
            continue;
        }
        $stats[$clientId] = [
            'sales_rows' => (int)($row['sales_rows'] ?? 0),
            'sales_months' => (int)($row['sales_months'] ?? 0),
            'sales_total' => (float)($row['sales_total'] ?? 0),
            'first_sale_month' => $row['first_sale_month'] ?? null,
            'last_sale_month' => $row['last_sale_month'] ?? null,
        ];
    }
    return $stats;
}

function crmMergeFetchSourceLinks(PDO $pdo): array
{
    if (!crmMergeTableExists($pdo, 'crm_client_monthly_sales')) {
        return [];
    }

    $stmt = $pdo->query("SELECT client_id, source_client_name FROM crm_client_monthly_sales WHERE TRIM(COALESCE(source_client_name, '')) <> ''");
    $links = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $clientId = (int)($row['client_id'] ?? 0);
        $sourceName = trim((string)($row['source_client_name'] ?? ''));
        if ($clientId <= 0 || $sourceName === '') {
            continue;
        }
        $normalized = crmMergeNormalizeClientName($sourceName);
        if ($normalized === '') {
            continue;
        }
        if (!isset($links[$normalized])) {
            $links[$normalized] = [];
        }
        $links[$normalized][$clientId] = true;
    }

    foreach ($links as $key => $clientIds) {
        $links[$key] = array_map('intval', array_keys($clientIds));
        sort($links[$key]);
    }

    return $links;
}

function crmMergeFetchReferenceCounts(PDO $pdo, array $clientIds): array
{
    $stats = [];
    foreach ($clientIds as $clientId) {
        $stats[(int)$clientId] = [
            'refs' => [],
            'non_sales_links_total' => 0,
        ];
    }

    if (!$clientIds) {
        return $stats;
    }

    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));

    foreach (crmMergeCandidateReferenceMap($pdo) as $ref) {
        $table = $ref['table'];
        $column = $ref['column'];
        $key = $table . '.' . $column;
        $sql = "SELECT {$column} AS client_id, COUNT(*) AS cnt FROM {$table} WHERE {$column} IN ({$placeholders})";
        $params = $clientIds;
        if (($ref['kind'] ?? '') === 'activity') {
            $sql .= " AND entity_type = ?";
            $params[] = $ref['entity_type'];
        }
        $sql .= " GROUP BY {$column}";

        $stmt = crmMergePrepare($pdo, $sql, 'merge.fetch_reference_counts.prepare');
        crmMergeExecute($stmt, $params, 'merge.fetch_reference_counts.execute', $sql);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clientId = (int)($row['client_id'] ?? 0);
            $count = (int)($row['cnt'] ?? 0);
            if ($clientId <= 0 || !isset($stats[$clientId])) {
                continue;
            }
            $stats[$clientId]['refs'][$key] = $count;
            if (($ref['kind'] ?? '') !== 'sales') {
                $stats[$clientId]['non_sales_links_total'] += $count;
            }
        }
    }

    return $stats;
}

function crmMergeProfileScore(array $client): int
{
    $score = 0;
    foreach (['inn', 'kpp', 'ogrn', 'email', 'phone', 'site', 'address', 'legal_address', 'postal_address', 'signer_name', 'signer_position', 'bank_name', 'bik', 'checking_account', 'correspondent_account', 'owner_id', 'user_id', 'legal_name_full', 'legal_name_short'] as $field) {
        $value = $client[$field] ?? null;
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        if (is_numeric($value) && (int)$value === 0) {
            continue;
        }
        $score++;
    }
    return $score;
}

function crmMergeComparePrimaryCandidates(array $a, array $b): int
{
    $left = [
        (int)($a['forced'] ?? 0),
        (int)($a['non_sales_links_total'] ?? 0),
        (int)($a['profile_score'] ?? 0),
        (int)($a['sales_rows'] ?? 0),
        -strtotime((string)($a['created_at'] ?? '9999-12-31 23:59:59')),
        -(int)($a['id'] ?? PHP_INT_MAX),
    ];
    $right = [
        (int)($b['forced'] ?? 0),
        (int)($b['non_sales_links_total'] ?? 0),
        (int)($b['profile_score'] ?? 0),
        (int)($b['sales_rows'] ?? 0),
        -strtotime((string)($b['created_at'] ?? '9999-12-31 23:59:59')),
        -(int)($b['id'] ?? PHP_INT_MAX),
    ];
    return $right <=> $left;
}

function crmMergeChoosePrimaryClient(array $clients, array $referenceStats, array $salesStats, ?int $forcedPrimaryId = null): int
{
    $candidates = [];
    foreach ($clients as $client) {
        $clientId = (int)($client['id'] ?? 0);
        if ($clientId <= 0) {
            continue;
        }
        $candidates[] = [
            'id' => $clientId,
            'created_at' => $client['created_at'] ?? null,
            'non_sales_links_total' => (int)($referenceStats[$clientId]['non_sales_links_total'] ?? 0),
            'profile_score' => crmMergeProfileScore($client),
            'sales_rows' => (int)($salesStats[$clientId]['sales_rows'] ?? 0),
            'forced' => $forcedPrimaryId !== null && $clientId === $forcedPrimaryId ? 1 : 0,
        ];
    }

    usort($candidates, 'crmMergeComparePrimaryCandidates');
    return (int)($candidates[0]['id'] ?? 0);
}

function crmMergeBuildDuplicateGroups(PDO $pdo, ?int $filterClientId = null, ?int $forcedPrimaryId = null): array
{
    $clients = crmMergeFetchClients($pdo);
    $salesStats = crmMergeFetchSalesStats($pdo);
    $sourceLinks = crmMergeFetchSourceLinks($pdo);
    $clientIds = array_map('intval', array_keys($clients));
    $parent = crmMergeUnionFindCreate($clientIds);
    $evidence = [];

    $normalizedGroups = [];
    $canonicalGroups = [];
    foreach ($clients as $clientId => $client) {
        $keys = crmMergeCollectClientNameKeys($client);
        foreach ($keys['normalized'] as $normalizedKey) {
            $normalizedGroups[$normalizedKey][] = $clientId;
        }
        foreach ($keys['canonical'] as $canonicalKey) {
            $canonicalGroups[$canonicalKey][] = $clientId;
        }
    }

    foreach ($normalizedGroups as $key => $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) < 2) {
            continue;
        }
        $first = $ids[0];
        foreach (array_slice($ids, 1) as $otherId) {
            crmMergeUnionFindUnion($parent, $first, $otherId);
        }
        $evidence['normalized'][$key] = $ids;
    }

    foreach ($canonicalGroups as $key => $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) < 2) {
            continue;
        }
        $first = $ids[0];
        foreach (array_slice($ids, 1) as $otherId) {
            crmMergeUnionFindUnion($parent, $first, $otherId);
        }
        $evidence['canonical'][$key] = $ids;
    }

    foreach ($sourceLinks as $key => $ids) {
        if (count($ids) < 2) {
            continue;
        }
        $first = $ids[0];
        foreach (array_slice($ids, 1) as $otherId) {
            crmMergeUnionFindUnion($parent, $first, $otherId);
        }
        $evidence['source'][$key] = $ids;
    }

    $components = [];
    foreach ($clientIds as $clientId) {
        $root = crmMergeUnionFindFind($parent, $clientId);
        $components[$root][] = $clientId;
    }

    $groups = [];
    foreach ($components as $memberIds) {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        sort($memberIds);
        if (count($memberIds) < 2) {
            continue;
        }
        if ($filterClientId !== null && !in_array($filterClientId, $memberIds, true)) {
            continue;
        }

        $hasSales = false;
        foreach ($memberIds as $memberId) {
            if ((int)($salesStats[$memberId]['sales_rows'] ?? 0) > 0) {
                $hasSales = true;
                break;
            }
        }
        if (!$hasSales) {
            continue;
        }

        $referenceStats = crmMergeFetchReferenceCounts($pdo, $memberIds);
        $groupClients = [];
        foreach ($memberIds as $memberId) {
            $client = $clients[$memberId];
            $client['reference_counts'] = $referenceStats[$memberId]['refs'] ?? [];
            $client['non_sales_links_total'] = (int)($referenceStats[$memberId]['non_sales_links_total'] ?? 0);
            $client['profile_score'] = crmMergeProfileScore($client);
            $client['sales_stats'] = $salesStats[$memberId] ?? [
                'sales_rows' => 0,
                'sales_months' => 0,
                'sales_total' => 0.0,
                'first_sale_month' => null,
                'last_sale_month' => null,
            ];
            $groupClients[] = $client;
        }

        $groupEvidence = [
            'normalized_keys' => [],
            'canonical_keys' => [],
            'source_names' => [],
        ];
        foreach ($evidence['normalized'] ?? [] as $key => $ids) {
            if (count(array_intersect($memberIds, $ids)) >= 2) {
                $groupEvidence['normalized_keys'][] = $key;
            }
        }
        foreach ($evidence['canonical'] ?? [] as $key => $ids) {
            if (count(array_intersect($memberIds, $ids)) >= 2) {
                $groupEvidence['canonical_keys'][] = $key;
            }
        }
        foreach ($evidence['source'] ?? [] as $key => $ids) {
            if (count(array_intersect($memberIds, $ids)) >= 2) {
                $groupEvidence['source_names'][] = $key;
            }
        }

        $confidence = 'medium';
        if ($groupEvidence['source_names'] || $groupEvidence['normalized_keys']) {
            $confidence = 'high';
        }

        $primaryId = crmMergeChoosePrimaryClient($groupClients, $referenceStats, $salesStats, $forcedPrimaryId);
        if ($primaryId <= 0) {
            continue;
        }

        usort($groupClients, static function (array $a, array $b) use ($primaryId): int {
            if ((int)$a['id'] === $primaryId) {
                return -1;
            }
            if ((int)$b['id'] === $primaryId) {
                return 1;
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });

        $groups[] = [
            'member_ids' => $memberIds,
            'confidence' => $confidence,
            'proposed_primary_id' => $primaryId,
            'clients' => $groupClients,
            'evidence' => $groupEvidence,
        ];
    }

    usort($groups, static function (array $a, array $b): int {
        $left = [$a['confidence'] === 'high' ? 0 : 1, count($b['member_ids'])];
        $right = [$b['confidence'] === 'high' ? 0 : 1, count($a['member_ids'])];
        return $left <=> $right;
    });

    return $groups;
}

function crmMergeDecodeJson(?string $json): mixed
{
    if ($json === null) {
        return null;
    }
    $trimmed = trim($json);
    if ($trimmed === '') {
        return null;
    }
    try {
        return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
}

function crmMergeEncodeJson(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function crmMergeMergeTags(?string $primaryTags, ?string $duplicateTags): ?string
{
    $left = crmMergeDecodeJson($primaryTags);
    $right = crmMergeDecodeJson($duplicateTags);
    if (!is_array($left) && !is_array($right)) {
        return $primaryTags;
    }
    $left = is_array($left) ? $left : [];
    $right = is_array($right) ? $right : [];
    $merged = array_values(array_unique(array_map(static fn($v) => trim((string)$v), array_merge($left, $right))));
    $merged = array_values(array_filter($merged, static fn(string $v): bool => $v !== ''));
    return crmMergeEncodeJson($merged);
}

function crmMergeMergeCustomFields(?string $primaryJson, ?string $duplicateJson): ?string
{
    $left = crmMergeDecodeJson($primaryJson);
    $right = crmMergeDecodeJson($duplicateJson);
    if (!is_array($left) && !is_array($right)) {
        return $primaryJson;
    }
    $left = is_array($left) ? $left : [];
    $right = is_array($right) ? $right : [];
    foreach ($right as $key => $value) {
        if (!array_key_exists($key, $left) || $left[$key] === null || $left[$key] === '') {
            $left[$key] = $value;
        }
    }
    return crmMergeEncodeJson($left);
}

function crmMergeAppendNotes(?string $primaryNotes, ?string $duplicateNotes, array $duplicateClient): ?string
{
    $primaryNotes = trim((string)$primaryNotes);
    $duplicateNotes = trim((string)$duplicateNotes);
    if ($duplicateNotes === '') {
        return $primaryNotes !== '' ? $primaryNotes : null;
    }

    $header = '--- merged from duplicate client #' . (int)($duplicateClient['id'] ?? 0) . ' (' . trim((string)($duplicateClient['name'] ?? '')) . ') ---';
    $chunk = $header . PHP_EOL . $duplicateNotes;
    if ($primaryNotes === '') {
        return $chunk;
    }
    if (str_contains($primaryNotes, $chunk)) {
        return $primaryNotes;
    }
    return $primaryNotes . PHP_EOL . PHP_EOL . $chunk;
}

function crmMergeOverlayClientData(array $primaryClient, array $duplicateClient): array
{
    $result = $primaryClient;
    foreach ([
        'name', 'legal_name_full', 'legal_name_short', 'inn', 'kpp', 'ogrn', 'email', 'phone', 'site',
        'address', 'legal_address', 'postal_address', 'signer_name', 'signer_position', 'signer_authority',
        'bank_name', 'bik', 'checking_account', 'correspondent_account', 'owner_id', 'user_id'
    ] as $field) {
        $current = $result[$field] ?? null;
        $incoming = $duplicateClient[$field] ?? null;
        $currentEmpty = $current === null || (is_string($current) && trim($current) === '') || (is_numeric($current) && (int)$current === 0);
        $incomingEmpty = $incoming === null || (is_string($incoming) && trim((string)$incoming) === '') || (is_numeric($incoming) && (int)$incoming === 0);
        if ($currentEmpty && !$incomingEmpty) {
            $result[$field] = $incoming;
        }
    }

    if (trim((string)($result['type'] ?? '')) === '' && trim((string)($duplicateClient['type'] ?? '')) !== '') {
        $result['type'] = $duplicateClient['type'];
    }

    $result['tags'] = crmMergeMergeTags($primaryClient['tags'] ?? null, $duplicateClient['tags'] ?? null);
    $result['custom_fields'] = crmMergeMergeCustomFields($primaryClient['custom_fields'] ?? null, $duplicateClient['custom_fields'] ?? null);
    $result['notes'] = crmMergeAppendNotes($primaryClient['notes'] ?? null, $duplicateClient['notes'] ?? null, $duplicateClient);

    return $result;
}

function crmMergeDetectMonthlySalesConflicts(PDO $pdo, int $primaryId, int $duplicateId): array
{
    $sql = "SELECT d.sale_month, d.amount AS duplicate_amount, p.amount AS primary_amount, d.source_client_name AS duplicate_source_client_name, p.source_client_name AS primary_source_client_name FROM crm_client_monthly_sales d JOIN crm_client_monthly_sales p ON p.client_id = ? AND p.sale_month = d.sale_month WHERE d.client_id = ? ORDER BY d.sale_month ASC";
    $stmt = crmMergePrepare($pdo, $sql, 'merge.detect_monthly_sales_conflicts.prepare');
    crmMergeExecute($stmt, [$primaryId, $duplicateId], 'merge.detect_monthly_sales_conflicts.execute', $sql);
    $conflicts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conflicts[] = [
            'sale_month' => $row['sale_month'],
            'primary_amount' => (float)($row['primary_amount'] ?? 0),
            'duplicate_amount' => (float)($row['duplicate_amount'] ?? 0),
            'same_source_name' => crmMergeNormalizeClientName((string)($row['primary_source_client_name'] ?? '')) === crmMergeNormalizeClientName((string)($row['duplicate_source_client_name'] ?? '')),
        ];
    }
    return $conflicts;
}
