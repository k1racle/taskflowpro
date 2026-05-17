<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/crm_client_merge_lib.php';

function crmToolsProjectRoot(): string
{
    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
}

function crmToolsEnsureZipAvailable(): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP extension zip (ZipArchive) is not installed, import cannot read .xlsx files');
    }
}

function crmToolsNormalizeClientName(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = str_replace(['"', '«', '»'], '', $value);
    return trim($value);
}

function crmToolsCanonicalizeClientName(string $value): string
{
    $value = crmToolsNormalizeClientName($value);
    $value = preg_replace('/\b(ооо|ооо\.|ип|ао|пао|зао|оао)\b/iu', ' ', $value) ?? $value;
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;
    return trim($value);
}

function crmToolsRegisterClientCandidate(array &$exactMap, array &$canonicalMap, string $candidate, int $clientId): void
{
    $normalized = crmToolsNormalizeClientName($candidate);
    if ($normalized !== '') {
        $exactMap[$normalized] = $clientId;
    }

    $canonical = crmToolsCanonicalizeClientName($candidate);
    if ($canonical !== '') {
        if (!isset($canonicalMap[$canonical])) {
            $canonicalMap[$canonical] = [];
        }
        if (!in_array($clientId, $canonicalMap[$canonical], true)) {
            $canonicalMap[$canonical][] = $clientId;
        }
    }
}

function crmToolsBuildClientResolutionStats(PDO $pdo): array
{
    $salesRows = [];
    try {
        $stmt = $pdo->query("SELECT client_id, COUNT(*) AS cnt FROM crm_client_monthly_sales GROUP BY client_id");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $salesRows[(int)($row['client_id'] ?? 0)] = (int)($row['cnt'] ?? 0);
        }
    } catch (Throwable $e) {
        $salesRows = [];
    }

    $linkCounts = [];
    $queries = [
        'crm_deals' => 'SELECT client_id, COUNT(*) AS cnt FROM crm_deals GROUP BY client_id',
        'crm_client_contacts' => 'SELECT client_id, COUNT(*) AS cnt FROM crm_client_contacts GROUP BY client_id',
        'tasks' => 'SELECT client_id, COUNT(*) AS cnt FROM tasks WHERE client_id IS NOT NULL GROUP BY client_id',
    ];

    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->query($sql);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $clientId = (int)($row['client_id'] ?? 0);
                if ($clientId <= 0) {
                    continue;
                }
                if (!isset($linkCounts[$clientId])) {
                    $linkCounts[$clientId] = 0;
                }
                $linkCounts[$clientId] += (int)($row['cnt'] ?? 0);
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return [
        'sales_rows' => $salesRows,
        'link_counts' => $linkCounts,
    ];
}

function crmToolsChooseBestClientCandidate(array $candidateIds, array $clientStats): ?int
{
    $ranked = [];
    foreach ($candidateIds as $clientId) {
        $clientId = (int)$clientId;
        $ranked[] = [
            'id' => $clientId,
            'sales_rows' => (int)($clientStats['sales_rows'][$clientId] ?? 0),
            'link_counts' => (int)($clientStats['link_counts'][$clientId] ?? 0),
        ];
    }

    usort($ranked, static function (array $a, array $b): int {
        return [
            $b['sales_rows'],
            $b['link_counts'],
            $a['id'] * -1,
        ] <=> [
            $a['sales_rows'],
            $a['link_counts'],
            $b['id'] * -1,
        ];
    });

    if (!$ranked) {
        return null;
    }

    if (count($ranked) === 1) {
        return (int)$ranked[0]['id'];
    }

    $best = $ranked[0];
    $second = $ranked[1];
    if ($best['sales_rows'] > $second['sales_rows']) {
        return (int)$best['id'];
    }
    if ($best['sales_rows'] > 0 && $second['sales_rows'] === 0 && $best['link_counts'] >= $second['link_counts']) {
        return (int)$best['id'];
    }
    if ($best['sales_rows'] === 0 && $best['link_counts'] > 0 && $second['link_counts'] === 0) {
        return (int)$best['id'];
    }

    return null;
}

function crmToolsResolveClientId(string $sourceClientName, array $exactMap, array $canonicalMap, array $clientStats = []): array
{
    $normalized = crmToolsNormalizeClientName($sourceClientName);
    if ($normalized !== '' && isset($exactMap[$normalized])) {
        return [
            'client_id' => $exactMap[$normalized],
            'match_type' => 'exact',
            'normalized_name' => $normalized,
        ];
    }

    $canonical = crmToolsCanonicalizeClientName($sourceClientName);
    if ($canonical !== '' && isset($canonicalMap[$canonical])) {
        $matches = array_values(array_unique(array_map('intval', $canonicalMap[$canonical])));
        if (count($matches) === 1) {
            return [
                'client_id' => $matches[0],
                'match_type' => 'canonical',
                'normalized_name' => $normalized,
            ];
        }
        if (count($matches) > 1) {
            $bestCandidateId = crmToolsChooseBestClientCandidate($matches, $clientStats);
            if ($bestCandidateId !== null) {
                return [
                    'client_id' => $bestCandidateId,
                    'match_type' => 'canonical_preferred',
                    'normalized_name' => $normalized,
                    'candidate_ids' => $matches,
                ];
            }
            return [
                'client_id' => null,
                'match_type' => 'ambiguous',
                'normalized_name' => $normalized,
                'candidate_ids' => $matches,
            ];
        }
    }

    return [
        'client_id' => null,
        'match_type' => 'new',
        'normalized_name' => $normalized,
    ];
}

function crmToolsMoneyToFloat(mixed $value): float
{
    if ($value === null) {
        return 0.0;
    }
    $str = trim((string)$value);
    if ($str === '' || $str === '########' || preg_match('/^#+$/', $str)) {
        return 0.0;
    }
    $str = str_replace(["\xC2\xA0", ' '], '', $str);
    $str = str_replace(',', '.', $str);
    return is_numeric($str) ? round((float)$str, 2) : 0.0;
}

function crmToolsColumnLettersToIndex(string $letters): int
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function crmToolsExcelDateSerialToDateString(float $serial): string
{
    $base = new DateTimeImmutable('1899-12-30');
    return $base->modify('+' . (int)floor($serial) . ' days')->format('Y-m-01');
}

function crmToolsParseMonthLabel(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_numeric($value)) {
        return crmToolsExcelDateSerialToDateString((float)$value);
    }

    $str = trim((string)$value);
    if ($str === '' || mb_strtolower($str, 'UTF-8') === 'итого') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $str, $m)) {
        return sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    }

    $months = [
        'январ' => 1,
        'феврал' => 2,
        'март' => 3,
        'апрел' => 4,
        'май' => 5,
        'июн' => 6,
        'июл' => 7,
        'август' => 8,
        'сентябр' => 9,
        'октябр' => 10,
        'ноябр' => 11,
        'декабр' => 12,
    ];

    $lower = mb_strtolower($str, 'UTF-8');
    foreach ($months as $prefix => $monthNumber) {
        if (mb_strpos($lower, $prefix) !== false && preg_match('/(20\d{2})/u', $lower, $yearMatch)) {
            return sprintf('%04d-%02d-01', (int)$yearMatch[1], $monthNumber);
        }
    }

    return null;
}

function crmToolsSharedStringsFromZip(ZipArchive $zip): array
{
    $index = $zip->locateName('xl/sharedStrings.xml');
    if ($index === false) {
        return [];
    }
    $xml = simplexml_load_string($zip->getFromIndex($index));
    if (!$xml) {
        return [];
    }
    $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $result = [];
    foreach ($xml->si as $si) {
        $chunks = [];
        if (isset($si->t)) {
            $chunks[] = (string)$si->t;
        }
        foreach ($si->r as $run) {
            $chunks[] = (string)$run->t;
        }
        $result[] = implode('', $chunks);
    }
    return $result;
}

function crmToolsWorkbookSheetPath(ZipArchive $zip, string $targetSheet): string
{
    $workbookXml = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $relsXml = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    if (!$workbookXml || !$relsXml) {
        throw new RuntimeException('Failed to read workbook metadata');
    }

    $workbookXml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relsMap = [];
    foreach ($relsXml->Relationship as $rel) {
        $relsMap[(string)$rel['Id']] = (string)$rel['Target'];
    }

    foreach ($workbookXml->sheets->sheet as $sheet) {
        if ((string)$sheet['name'] !== $targetSheet) {
            continue;
        }
        $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        if (!isset($relsMap[$rid])) {
            break;
        }
        return 'xl/' . ltrim($relsMap[$rid], '/');
    }

    throw new RuntimeException('Sheet not found: ' . $targetSheet);
}

function crmToolsReadSheetRows(string $file, string $sheetName): array
{
    $extension = mb_strtolower(pathinfo($file, PATHINFO_EXTENSION), 'UTF-8');
    if (class_exists('ZipArchive') && in_array($extension, ['xlsx', 'xlsm', 'xltx', 'xltm'], true)) {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException('Failed to open Excel file');
        }

        $sharedStrings = crmToolsSharedStringsFromZip($zip);
        $sheetPath = crmToolsWorkbookSheetPath($zip, $sheetName);
        $sheetXml = simplexml_load_string($zip->getFromName($sheetPath));
        $zip->close();

        if (!$sheetXml || !isset($sheetXml->sheetData)) {
            throw new RuntimeException('Failed to read sheet XML');
        }

        $rows = [];
        foreach ($sheetXml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                $letters = preg_replace('/\d+/', '', $ref) ?? '';
                $columnIndex = crmToolsColumnLettersToIndex($letters);
                $type = (string)$cell['t'];

                $value = null;
                if (isset($cell->is)) {
                    $chunks = [];
                    if (isset($cell->is->t)) {
                        $chunks[] = (string)$cell->is->t;
                    }
                    foreach ($cell->is->r as $run) {
                        $chunks[] = (string)$run->t;
                    }
                    $value = implode('', $chunks);
                } elseif (isset($cell->v)) {
                    $raw = (string)$cell->v;
                    if ($type === 's') {
                        $value = $sharedStrings[(int)$raw] ?? null;
                    } else {
                        $value = $raw;
                    }
                }
                $rowData[$columnIndex] = $value;
            }

            if ($rowData) {
                ksort($rowData);
                $rows[] = $rowData;
            }
        }

        return $rows;
    }

    $projectRoot = crmToolsProjectRoot();
    $script = $projectRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'inspect_excel_com.ps1';
    if (!is_file($script)) {
        throw new RuntimeException('Excel reader script not found: ' . $script);
    }

    $command = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($script)
        . ' -Path ' . escapeshellarg($file)
        . ' -SheetName ' . escapeshellarg($sheetName)
        . ' -MaxRows 20000 -MaxCols 80 2>&1';

    $output = shell_exec($command);
    if (!is_string($output) || trim($output) === '') {
        throw new RuntimeException('Failed to read Excel sheet via PowerShell COM');
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Failed to parse Excel sheet output: ' . trim($output));
    }

    $rows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowData = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $normalized = is_string($value) ? trim($value) : $value;
            if ($normalized === '' || $normalized === null) {
                continue;
            }
            $rowData[$columnIndex] = $normalized;
        }
        if ($rowData) {
            $rows[] = $rowData;
        }
    }

    return $rows;
}

function crmToolsEnsureSalesSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_client_monthly_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        sale_month DATE NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        source_sheet VARCHAR(100) NULL,
        source_client_name VARCHAR(255) NULL,
        source_manager_name VARCHAR(255) NULL,
        source_total_amount DECIMAL(14,2) NULL,
        import_batch VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client_month (client_id, sale_month),
        INDEX idx_sale_month (sale_month),
        INDEX idx_client_month (client_id, sale_month),
        INDEX idx_amount (amount),
        CONSTRAINT fk_crm_client_monthly_sales_client_cli FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function crmToolsResolveImportFile(?string $requestedFile = null): string
{
    $projectRoot = crmToolsProjectRoot();

    if ($requestedFile !== null && trim($requestedFile) !== '') {
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($requestedFile));
        $absolute = preg_match('~^[A-Za-z]:[\\/]~', $candidate) === 1
            ? $candidate
            : $projectRoot . DIRECTORY_SEPARATOR . ltrim($candidate, '\\/');

        $real = realpath($absolute);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException('Import file not found: ' . $requestedFile);
        }
        if (strpos($real, $projectRoot) !== 0) {
            throw new RuntimeException('Import file must be inside the project directory');
        }
        return $real;
    }

    $preferred = $projectRoot . DIRECTORY_SEPARATOR . 'old' . DIRECTORY_SEPARATOR . 'База B2B-1 (2).xlsx';
    $preferredReal = realpath($preferred);
    if ($preferredReal !== false && is_file($preferredReal)) {
        return $preferredReal;
    }

    $matches = glob($projectRoot . DIRECTORY_SEPARATOR . 'old' . DIRECTORY_SEPARATOR . '*.{xlsx,xls,xlsm,xltx,xltm}', GLOB_BRACE);
    if (count($matches) === 1) {
        $real = realpath($matches[0]);
        if ($real !== false) {
            return $real;
        }
    }

    throw new RuntimeException('Excel file not specified. Provide file path or place a single workbook in old/');
}

function crmToolsCompactSql(string $sql): string
{
    return trim((string)(preg_replace('/\s+/', ' ', $sql) ?? $sql));
}

function crmToolsPrepare(PDO $pdo, string $sql, string $context): PDOStatement
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
                crmToolsCompactSql($sql)
            ),
            0,
            $e
        );
    }
}

function crmToolsExecute(PDOStatement $stmt, array $params, string $context, ?string $sql = null): void
{
    try {
        $stmt->execute($params);
    } catch (Throwable $e) {
        $details = ['SQL execute failed during ' . $context . ': ' . $e->getMessage()];
        if ($sql !== null && $sql !== '') {
            $details[] = 'SQL: ' . crmToolsCompactSql($sql);
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

function crmToolsCleanImportedText(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
    $value = trim($value);
    return $value === '' ? null : $value;
}

function crmToolsNormalizeHeaderName(?string $value): string
{
    $value = crmToolsCleanImportedText($value);
    if ($value === null) {
        return '';
    }

    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['ё', '.', ',', ':', ';', '(', ')', '"', "'"], ['е', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' '], $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function crmToolsBuildHeaderMap(array $row): array
{
    $map = [];
    foreach ($row as $index => $value) {
        $header = crmToolsNormalizeHeaderName((string)$value);
        if ($header !== '' && !isset($map[$header])) {
            $map[$header] = (int)$index;
        }
    }
    return $map;
}

function crmToolsFindHeaderColumn(array $headerMap, array $candidates, ?int $fallback = null): ?int
{
    foreach ($candidates as $candidate) {
        $normalized = crmToolsNormalizeHeaderName($candidate);
        if ($normalized !== '' && isset($headerMap[$normalized])) {
            return (int)$headerMap[$normalized];
        }
    }

    return $fallback;
}

function crmToolsCellValueByColumn(array $row, ?int $columnIndex): ?string
{
    if ($columnIndex === null) {
        return null;
    }

    return crmToolsCleanImportedText(isset($row[$columnIndex]) ? (string)$row[$columnIndex] : null);
}

function crmToolsExtractPhones(string $value): array
{
    if ($value === '') {
        return [];
    }

    preg_match_all('/(?:\+?7|8)[\d\s\-()\x{00A0}?]{8,}/u', $value, $matches);
    $phones = [];
    foreach ($matches[0] ?? [] as $match) {
        $normalized = preg_replace('/[^\d+]/u', '', $match) ?? '';
        $normalized = str_replace('?', '', $normalized);
        if ($normalized === '') {
            continue;
        }
        if (str_starts_with($normalized, '8') && strlen($normalized) === 11) {
            $normalized = '+7' . substr($normalized, 1);
        } elseif (!str_starts_with($normalized, '+') && strlen($normalized) === 11 && str_starts_with($normalized, '7')) {
            $normalized = '+' . $normalized;
        }
        if ($normalized !== '') {
            $phones[$normalized] = true;
        }
    }

    return array_keys($phones);
}

function crmToolsExtractEmails(string $value): array
{
    if ($value === '') {
        return [];
    }

    preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $value, $matches);
    $emails = [];
    foreach ($matches[0] ?? [] as $match) {
        $email = mb_strtolower(trim($match), 'UTF-8');
        if ($email !== '') {
            $emails[$email] = true;
        }
    }
    return array_keys($emails);
}

function crmToolsParseContactBlock(?string $value): array
{
    $text = crmToolsCleanImportedText($value);
    if ($text === null) {
        return [];
    }

    $phones = crmToolsExtractPhones($text);
    $emails = crmToolsExtractEmails($text);
    $chunks = preg_split('/\n\s*\n/u', $text) ?: [$text];
    $contacts = [];

    foreach ($chunks as $chunk) {
        $chunk = crmToolsCleanImportedText($chunk);
        if ($chunk === null) {
            continue;
        }

        $lines = preg_split('/\n/u', $chunk) ?: [];
        $name = null;
        $contactPhones = crmToolsExtractPhones($chunk);
        $contactEmails = crmToolsExtractEmails($chunk);
        foreach ($lines as $line) {
            $line = crmToolsCleanImportedText($line);
            if ($line === null) {
                continue;
            }
            if (preg_match('/(?:\+?7|8)[\d\s\-()\x{00A0}?]{8,}/u', $line) || str_contains($line, '@')) {
                continue;
            }
            $name = $line;
            break;
        }

        if ($name === null && $contactPhones) {
            $name = 'Контакт клиента';
        }

        if ($name === null) {
            continue;
        }

        $contacts[] = [
            'name' => $name,
            'phone' => $contactPhones[0] ?? null,
            'email' => $contactEmails[0] ?? null,
        ];
    }

    if (!$contacts && ($phones || $emails)) {
        $contacts[] = [
            'name' => 'Контакт клиента',
            'phone' => $phones[0] ?? null,
            'email' => $emails[0] ?? null,
        ];
    }

    $deduped = [];
    foreach ($contacts as $contact) {
        $key = mb_strtolower(trim((string)$contact['name']), 'UTF-8')
            . '|' . trim((string)($contact['phone'] ?? ''))
            . '|' . mb_strtolower(trim((string)($contact['email'] ?? '')), 'UTF-8');
        $deduped[$key] = $contact;
    }

    return array_values($deduped);
}

function crmToolsStripClientAlias(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s*[\(（].*?[\)）]\s*/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function crmToolsSegmentToCustomerSegment(?string $segment): string
{
    $value = mb_strtolower(trim((string)$segment), 'UTF-8');
    if ($value === '') {
        return 'regular';
    }
    if (str_contains($value, 'vip')) {
        return 'vip';
    }
    if (str_contains($value, 'ушед') || str_contains($value, 'недозвон') || str_contains($value, 'ндз')) {
        return 'lead';
    }
    return 'regular';
}

function crmToolsMapAkbStatusToCrmStatus(?string $value): string
{
    $normalized = crmToolsNormalizeHeaderName($value);
    if ($normalized === '') {
        return 'active';
    }

    if (str_contains($normalized, 'акб') || str_contains($normalized, 'актив') || str_contains($normalized, 'действ')) {
        return 'active';
    }

    if (str_contains($normalized, 'не актив') || str_contains($normalized, 'неакт') || str_contains($normalized, 'потер') || str_contains($normalized, 'архив') || str_contains($normalized, 'закрыт')) {
        return 'inactive';
    }

    if (str_contains($normalized, 'лид') || str_contains($normalized, 'в работе') || str_contains($normalized, 'недозвон') || str_contains($normalized, 'ndz')) {
        return 'lead';
    }

    return 'active';
}

function crmToolsBuildClientPayloadFromAkbRow(array $row, array $headerMap = []): ?array
{
    $companyColumn = crmToolsFindHeaderColumn($headerMap, ['Контрагент юр.лицо', 'Контрагент юр лицо', 'Контрагент', 'Компания'], 0);
    $segmentColumn = crmToolsFindHeaderColumn($headerMap, ['Сегмент'], 1);
    $contactColumn = crmToolsFindHeaderColumn($headerMap, ['Контактное лицо', 'Контакты', 'Контакт'], 2);
    $commentColumn = crmToolsFindHeaderColumn($headerMap, ['Комментарий', 'Комментарии', 'Коммент'], 3);
    $crmStatusColumn = crmToolsFindHeaderColumn($headerMap, ['Статус CRM', 'Статус'], 4);

    $companyNameRaw = crmToolsCellValueByColumn($row, $companyColumn);
    if ($companyNameRaw === null || mb_strtolower($companyNameRaw, 'UTF-8') === 'контрагент юр.лицо') {
        return null;
    }

    $companyName = crmToolsStripClientAlias($companyNameRaw);
    $segment = crmToolsCellValueByColumn($row, $segmentColumn);
    $contactBlock = crmToolsCellValueByColumn($row, $contactColumn);
    $comment = crmToolsCellValueByColumn($row, $commentColumn);
    $crmStatus = crmToolsCellValueByColumn($row, $crmStatusColumn);

    $contacts = crmToolsParseContactBlock($contactBlock);
    $clientPhone = $contacts[0]['phone'] ?? null;
    $clientEmail = $contacts[0]['email'] ?? null;

    return [
        'source_name' => $companyNameRaw,
        'name' => $companyName,
        'legal_name_full' => $companyNameRaw,
        'legal_name_short' => $companyName,
        'type' => preg_match('/\b(ооо|ао|пао|зао|оао)\b/iu', $companyNameRaw) ? 'company' : 'person',
        'phone' => $clientPhone,
        'email' => $clientEmail,
        'address' => $segment,
        'customer_segment' => null,
        'status' => crmToolsMapAkbStatusToCrmStatus($crmStatus),
        'notes' => $comment,
        'contacts' => $contacts,
    ];
}

function crmToolsLoadClientsMap(PDO $pdo): array
{
    $clientsStmt = $pdo->query("SELECT id, name, legal_name_full, legal_name_short FROM crm_clients");
    $clientsMap = [];
    $clientsCanonicalMap = [];
    foreach ($clientsStmt->fetchAll(PDO::FETCH_ASSOC) as $client) {
        foreach ([$client['name'], $client['legal_name_full'], $client['legal_name_short']] as $candidate) {
            if ($candidate === null || trim((string)$candidate) === '') {
                continue;
            }
            crmToolsRegisterClientCandidate($clientsMap, $clientsCanonicalMap, (string)$candidate, (int)$client['id']);
        }
    }

    return [$clientsMap, $clientsCanonicalMap];
}

function crmToolsBuildSalesRows(array $rows): array
{
    if (count($rows) < 4) {
        throw new RuntimeException('Sales sheet does not contain expected rows');
    }

    $headerMonths = $rows[0];
    $monthColumns = [];
    $totalColumn = null;
    foreach ($headerMonths as $columnIndex => $value) {
        $month = crmToolsParseMonthLabel($value);
        if ($month !== null) {
            $monthColumns[$columnIndex] = $month;
            continue;
        }
        if (trim((string)$value) === 'Итого') {
            $totalColumn = $columnIndex;
        }
    }

    if (!$monthColumns) {
        throw new RuntimeException('Failed to detect month columns in sales sheet');
    }

    $sales = [];
    for ($i = 3, $count = count($rows); $i < $count; $i++) {
        $row = $rows[$i];
        $sourceClientName = trim((string)($row[1] ?? ''));
        if ($sourceClientName === '' || mb_strtolower($sourceClientName, 'UTF-8') === 'итого') {
            continue;
        }

        $months = [];
        foreach ($monthColumns as $columnIndex => $saleMonth) {
            $amount = crmToolsMoneyToFloat($row[$columnIndex] ?? null);
            if ($amount <= 0) {
                continue;
            }
            $months[] = [
                'sale_month' => $saleMonth,
                'amount' => $amount,
            ];
        }

        $sales[] = [
            'manager_name' => trim((string)($row[0] ?? '')),
            'source_client_name' => $sourceClientName,
            'source_client_name_stripped' => crmToolsStripClientAlias($sourceClientName),
            'total_amount' => $totalColumn !== null ? crmToolsMoneyToFloat($row[$totalColumn] ?? null) : 0.0,
            'months' => $months,
        ];
    }

    return [
        'month_columns' => array_values($monthColumns),
        'sales' => $sales,
    ];
}

function crmToolsImportSales(array $opts = []): array
{
    $file = crmToolsResolveImportFile(isset($opts['file']) ? (string)$opts['file'] : null);
    $sheet = trim((string)($opts['sheet'] ?? 'База клиентов'));
    $clientsSheet = trim((string)($opts['clients_sheet'] ?? 'Работа с АКБ'));
    $dryRun = !empty($opts['dry_run']);

    $salesRowsRaw = crmToolsReadSheetRows($file, $sheet);
    $clientsRowsRaw = crmToolsReadSheetRows($file, $clientsSheet);
    $salesData = crmToolsBuildSalesRows($salesRowsRaw);
    $clientsHeaderMap = crmToolsBuildHeaderMap($clientsRowsRaw[0] ?? []);

    $pdo = getPDO();
    crmToolsEnsureSalesSchema($pdo);
    $batch = date('Ymd_His');
    $clientStats = crmToolsBuildClientResolutionStats($pdo);
    [$clientsMap, $clientsCanonicalMap] = crmToolsLoadClientsMap($pdo);

    $insertedClients = 0;
    $updatedClients = 0;
    $insertedContacts = 0;
    $salesRows = 0;
    $createdClients = [];
    $ambiguousClients = [];
    $matchedExact = 0;
    $matchedCanonical = 0;
    $skippedAmbiguousRows = 0;
    $skippedContactRows = 0;
    $linkedSalesFromAkbClients = 0;

    $processedSalesKeys = [];

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    try {
        $insertClientSql = "INSERT INTO crm_clients (name, legal_name_full, legal_name_short, type, email, phone, address, customer_segment, notes, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $updateClientSql = "UPDATE crm_clients SET name=?, legal_name_full=COALESCE(NULLIF(?, ''), legal_name_full), legal_name_short=COALESCE(NULLIF(?, ''), legal_name_short), type=?, email=COALESCE(NULLIF(?, ''), email), phone=COALESCE(NULLIF(?, ''), phone), address=COALESCE(NULLIF(?, ''), address), customer_segment=COALESCE(NULLIF(?, ''), customer_segment), notes=CASE WHEN NULLIF(?, '') IS NULL THEN notes WHEN notes IS NULL OR notes='' THEN ? ELSE CONCAT(notes, '\n\n', ?) END, status=COALESCE(NULLIF(?, ''), status), updated_at=NOW() WHERE id=?";
        $findContactSql = "SELECT id FROM crm_client_contacts WHERE client_id=? AND LOWER(name)=LOWER(?) AND COALESCE(phone, '') = COALESCE(?, '') AND COALESCE(LOWER(email), '') = COALESCE(LOWER(?), '') LIMIT 1";
        $insertContactSql = "INSERT INTO crm_client_contacts (client_id, name, position, email, phone, is_primary) VALUES (?, ?, ?, ?, ?, ?)";
        $upsertSaleSql = "INSERT INTO crm_client_monthly_sales (client_id, sale_month, amount, source_sheet, source_client_name, source_manager_name, source_total_amount, import_batch) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount=VALUES(amount), source_sheet=VALUES(source_sheet), source_client_name=VALUES(source_client_name), source_manager_name=VALUES(source_manager_name), source_total_amount=VALUES(source_total_amount), import_batch=VALUES(import_batch)";
        $insertClient = null;
        $updateClient = null;
        $findContact = null;
        $insertContact = null;
        $upsertSale = null;

        if (!$dryRun) {
            $insertClient = crmToolsPrepare($pdo, $insertClientSql, 'import_sales.prepare_insert_client');
            $updateClient = crmToolsPrepare($pdo, $updateClientSql, 'import_sales.prepare_update_client');
            $findContact = crmToolsPrepare($pdo, $findContactSql, 'import_sales.prepare_find_contact');
            $insertContact = crmToolsPrepare($pdo, $insertContactSql, 'import_sales.prepare_insert_contact');
            $upsertSale = crmToolsPrepare($pdo, $upsertSaleSql, 'import_sales.prepare_upsert_sale');
        }

        $salesByClientName = [];
        foreach ($salesData['sales'] as $salesRow) {
            foreach ([$salesRow['source_client_name'], $salesRow['source_client_name_stripped']] as $candidateName) {
                $normalized = crmToolsNormalizeClientName($candidateName);
                if ($normalized !== '') {
                    $salesByClientName[$normalized][] = $salesRow;
                }
            }
        }

        for ($i = 1, $count = count($clientsRowsRaw); $i < $count; $i++) {
            $row = $clientsRowsRaw[$i];
            $clientPayload = crmToolsBuildClientPayloadFromAkbRow($row, $clientsHeaderMap);
            if ($clientPayload === null) {
                $skippedContactRows++;
                continue;
            }

            $resolvedClient = crmToolsResolveClientId($clientPayload['name'], $clientsMap, $clientsCanonicalMap, $clientStats);
            $clientId = $resolvedClient['client_id'];

            if ($resolvedClient['match_type'] === 'exact') {
                $matchedExact++;
            } elseif ($resolvedClient['match_type'] === 'canonical' || $resolvedClient['match_type'] === 'canonical_preferred') {
                $matchedCanonical++;
            } elseif ($resolvedClient['match_type'] === 'ambiguous') {
                $ambiguousClients[] = [
                    'source_client_name' => $sourceClientName,
                    'candidate_ids' => $resolvedClient['candidate_ids'] ?? [],
                ];
                $skippedAmbiguousRows++;
                continue;
            }

            if ($clientId === null) {
                if (!$dryRun) {
                    crmToolsExecute(
                        $insertClient,
                        [
                            $clientPayload['name'],
                            $clientPayload['legal_name_full'],
                            $clientPayload['legal_name_short'],
                            $clientPayload['type'],
                            $clientPayload['email'],
                            $clientPayload['phone'],
                            $clientPayload['address'],
                            $clientPayload['customer_segment'],
                            $clientPayload['notes'],
                            $clientPayload['status'],
                        ],
                        'import_sales.insert_client row ' . ($i + 1) . ' client ' . $clientPayload['name'],
                        $insertClientSql
                    );
                    $clientId = (int)$pdo->lastInsertId();
                } else {
                    $clientId = -1;
                }
                crmToolsRegisterClientCandidate($clientsMap, $clientsCanonicalMap, $clientPayload['name'], $clientId);
                crmToolsRegisterClientCandidate($clientsMap, $clientsCanonicalMap, $clientPayload['legal_name_full'], $clientId);
                $insertedClients++;
                $createdClients[] = $clientPayload['name'];
            } elseif (!$dryRun) {
                crmToolsExecute(
                    $updateClient,
                    [
                        $clientPayload['name'],
                        $clientPayload['legal_name_full'],
                        $clientPayload['legal_name_short'],
                        $clientPayload['type'],
                        $clientPayload['email'],
                        $clientPayload['phone'],
                        $clientPayload['address'],
                        $clientPayload['customer_segment'],
                        $clientPayload['notes'],
                        $clientPayload['notes'],
                        $clientPayload['notes'],
                        $clientPayload['status'],
                        $clientId,
                    ],
                    'import_sales.update_client row ' . ($i + 1) . ' client ' . $clientPayload['name'],
                    $updateClientSql
                );
                $updatedClients++;
            }

            if (!$dryRun && $clientId > 0) {
                foreach ($clientPayload['contacts'] as $index => $contact) {
                    crmToolsExecute(
                        $findContact,
                        [$clientId, $contact['name'], $contact['phone'], $contact['email']],
                        'import_sales.find_contact client ' . $clientPayload['name'],
                        $findContactSql
                    );
                    $existingContactId = (int)$findContact->fetchColumn();
                    $findContact->closeCursor();
                    if ($existingContactId > 0) {
                        continue;
                    }

                    crmToolsExecute(
                        $insertContact,
                        [$clientId, $contact['name'], null, $contact['email'], $contact['phone'], $index === 0 ? 1 : 0],
                        'import_sales.insert_contact client ' . $clientPayload['name'],
                        $insertContactSql
                    );
                    $insertedContacts++;
                }
            }

            $salesCandidates = [];
            foreach ([$clientPayload['source_name'], $clientPayload['name'], $clientPayload['legal_name_full']] as $candidateName) {
                $normalized = crmToolsNormalizeClientName((string)$candidateName);
                if ($normalized !== '' && isset($salesByClientName[$normalized])) {
                    foreach ($salesByClientName[$normalized] as $salesRow) {
                        $salesCandidates[$salesRow['source_client_name']] = $salesRow;
                    }
                }
            }

            foreach ($salesCandidates as $salesRow) {
                foreach ($salesRow['months'] as $sale) {
                    if ($sale['amount'] <= 0) {
                        continue;
                    }
                    $saleKey = $clientId . '|' . $sale['sale_month'];
                    if (isset($processedSalesKeys[$saleKey])) {
                        continue;
                    }
                    if (!$dryRun) {
                        crmToolsExecute(
                            $upsertSale,
                            [
                                $clientId,
                                $sale['sale_month'],
                                $sale['amount'],
                                $sheet,
                                $salesRow['source_client_name'],
                                $salesRow['manager_name'] !== '' ? $salesRow['manager_name'] : null,
                                $salesRow['total_amount'] > 0 ? $salesRow['total_amount'] : null,
                                $batch,
                            ],
                            'import_sales.upsert_sale client ' . $clientPayload['name'] . ' month ' . $sale['sale_month'],
                            $upsertSaleSql
                        );
                    }
                    $processedSalesKeys[$saleKey] = true;
                    $salesRows++;
                    $linkedSalesFromAkbClients++;
                }
            }
        }

        foreach ($salesData['sales'] as $salesRow) {
            $resolvedClient = crmToolsResolveClientId($salesRow['source_client_name'], $clientsMap, $clientsCanonicalMap, $clientStats);
            $clientId = $resolvedClient['client_id'];
            if ($clientId === null || $resolvedClient['match_type'] === 'ambiguous') {
                continue;
            }

            foreach ($salesRow['months'] as $sale) {
                $saleKey = $clientId . '|' . $sale['sale_month'];
                if (isset($processedSalesKeys[$saleKey])) {
                    continue;
                }
                if (!$dryRun) {
                    crmToolsExecute(
                        $upsertSale,
                        [
                            $clientId,
                            $sale['sale_month'],
                            $sale['amount'],
                            $sheet,
                            $salesRow['source_client_name'],
                            $salesRow['manager_name'] !== '' ? $salesRow['manager_name'] : null,
                            $salesRow['total_amount'] > 0 ? $salesRow['total_amount'] : null,
                            $batch,
                        ],
                        'import_sales.upsert_sale fallback client ' . $salesRow['source_client_name'] . ' month ' . $sale['sale_month'],
                        $upsertSaleSql
                    );
                }
                $processedSalesKeys[$saleKey] = true;
                $salesRows++;
            }
        }

        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'success' => true,
        'operation' => 'import_sales',
        'file' => $file,
        'sheet' => $sheet,
        'clients_sheet' => $clientsSheet,
        'dry_run' => $dryRun,
        'month_columns' => $salesData['month_columns'],
        'inserted_clients' => $insertedClients,
        'updated_clients' => $updatedClients,
        'inserted_contacts' => $insertedContacts,
        'sales_rows' => $salesRows,
        'sales_rows_linked_from_akb_clients' => $linkedSalesFromAkbClients,
        'matched_exact_clients' => $matchedExact,
        'matched_canonical_clients' => $matchedCanonical,
        'skipped_ambiguous_rows' => $skippedAmbiguousRows,
        'skipped_akb_rows' => $skippedContactRows,
        'created_clients_preview' => array_slice($createdClients, 0, 20),
        'ambiguous_clients_preview' => array_slice($ambiguousClients, 0, 20),
    ];
}

function crmToolsDiagnoseDuplicates(array $opts = []): array
{
    $pdo = getPDO();
    $filterClientId = isset($opts['client_id']) ? (int)$opts['client_id'] : null;
    if ($filterClientId !== null && $filterClientId <= 0) {
        $filterClientId = null;
    }

    $report = [];
    $report['operation'] = 'diagnose_duplicates';
    $report['clients_total'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_clients")->fetchColumn();

    if (crmMergeTableExists($pdo, 'crm_client_monthly_sales')) {
        $salesSummary = $pdo->query("SELECT COUNT(*) AS sales_rows, COUNT(DISTINCT client_id) AS clients_with_sales FROM crm_client_monthly_sales")
            ->fetch(PDO::FETCH_ASSOC) ?: [];
        $report['sales_rows'] = (int)($salesSummary['sales_rows'] ?? 0);
        $report['clients_with_sales'] = (int)($salesSummary['clients_with_sales'] ?? 0);
    } else {
        $report['sales_rows'] = 0;
        $report['clients_with_sales'] = 0;
    }

    $groups = crmMergeBuildDuplicateGroups($pdo, $filterClientId);
    $report['duplicate_groups_total'] = count($groups);
    $report['duplicate_groups'] = array_map(static function (array $group): array {
        return [
            'confidence' => $group['confidence'],
            'proposed_primary_id' => (int)$group['proposed_primary_id'],
            'member_ids' => array_values(array_map('intval', $group['member_ids'] ?? [])),
            'evidence' => $group['evidence'] ?? [],
            'clients' => array_map(static function (array $client): array {
                return [
                    'id' => (int)($client['id'] ?? 0),
                    'name' => (string)($client['name'] ?? ''),
                    'legal_name_full' => $client['legal_name_full'] ?? null,
                    'legal_name_short' => $client['legal_name_short'] ?? null,
                    'inn' => $client['inn'] ?? null,
                    'email' => $client['email'] ?? null,
                    'phone' => $client['phone'] ?? null,
                    'created_at' => $client['created_at'] ?? null,
                    'profile_score' => (int)($client['profile_score'] ?? 0),
                    'non_sales_links_total' => (int)($client['non_sales_links_total'] ?? 0),
                    'reference_counts' => $client['reference_counts'] ?? [],
                    'sales_stats' => $client['sales_stats'] ?? [],
                ];
            }, $group['clients'] ?? []),
        ];
    }, $groups);

    $report['reference_tables_considered'] = crmMergeCandidateReferenceMap($pdo);
    return $report;
}

function crmToolsMergeMoveDirectReferences(PDO $pdo, string $table, string $column, int $primaryId, int $duplicateId, ?string $entityType = null, bool $apply = false): int
{
    $where = "{$column} = ?";
    $params = [$duplicateId];
    if ($entityType !== null) {
        $where .= " AND entity_type = ?";
        $params[] = $entityType;
    }

    $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $countStmt = crmToolsPrepare($pdo, $countSql, 'merge.move_direct_references.prepare_count');
    crmToolsExecute($countStmt, $params, 'merge.move_direct_references.execute_count', $countSql);
    $count = (int)$countStmt->fetchColumn();
    if ($count <= 0 || !$apply) {
        return $count;
    }

    $updateSql = "UPDATE {$table} SET {$column} = ? WHERE {$where}";
    $updateStmt = crmToolsPrepare($pdo, $updateSql, 'merge.move_direct_references.prepare_update');
    crmToolsExecute($updateStmt, array_merge([$primaryId], $params), 'merge.move_direct_references.execute_update', $updateSql);
    return $count;
}

function crmToolsMergeUpsertMonthlySales(PDO $pdo, int $primaryId, int $duplicateId, bool $apply = false): array
{
    $rowsSql = "SELECT sale_month, amount, source_sheet, source_client_name, source_manager_name, source_total_amount, import_batch FROM crm_client_monthly_sales WHERE client_id = ? ORDER BY sale_month ASC";
    $rowsStmt = crmToolsPrepare($pdo, $rowsSql, 'merge.upsert_monthly_sales.prepare_select_rows');
    crmToolsExecute($rowsStmt, [$duplicateId], 'merge.upsert_monthly_sales.execute_select_rows', $rowsSql);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $conflicts = crmMergeDetectMonthlySalesConflicts($pdo, $primaryId, $duplicateId);
    if ($conflicts) {
        throw new RuntimeException('Monthly sales conflict detected between clients #' . $primaryId . ' and #' . $duplicateId . '. Resolve manually before merge.');
    }

    $movedRows = count($rows);
    if (!$apply || !$rows) {
        return [
            'moved_sales_rows' => $movedRows,
            'sales_conflicts' => $conflicts,
        ];
    }

    $insertSql = "INSERT INTO crm_client_monthly_sales (client_id, sale_month, amount, source_sheet, source_client_name, source_manager_name, source_total_amount, import_batch) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), source_sheet = COALESCE(crm_client_monthly_sales.source_sheet, VALUES(source_sheet)), source_client_name = COALESCE(crm_client_monthly_sales.source_client_name, VALUES(source_client_name)), source_manager_name = COALESCE(crm_client_monthly_sales.source_manager_name, VALUES(source_manager_name)), source_total_amount = COALESCE(crm_client_monthly_sales.source_total_amount, VALUES(source_total_amount)), import_batch = COALESCE(crm_client_monthly_sales.import_batch, VALUES(import_batch))";
    $insertStmt = crmToolsPrepare($pdo, $insertSql, 'merge.upsert_monthly_sales.prepare_insert');
    foreach ($rows as $row) {
        crmToolsExecute($insertStmt, [
            $primaryId,
            $row['sale_month'],
            $row['amount'],
            $row['source_sheet'],
            $row['source_client_name'],
            $row['source_manager_name'],
            $row['source_total_amount'],
            $row['import_batch'],
        ], 'merge.upsert_monthly_sales.execute_insert', $insertSql);
    }

    $deleteSql = "DELETE FROM crm_client_monthly_sales WHERE client_id = ?";
    $deleteStmt = crmToolsPrepare($pdo, $deleteSql, 'merge.upsert_monthly_sales.prepare_delete_duplicate_rows');
    crmToolsExecute($deleteStmt, [$duplicateId], 'merge.upsert_monthly_sales.execute_delete_duplicate_rows', $deleteSql);

    return [
        'moved_sales_rows' => $movedRows,
        'sales_conflicts' => [],
    ];
}

function crmToolsMergeUpdatePrimaryClient(PDO $pdo, array $primaryClient, array $mergedClient, int $primaryId, bool $apply = false): array
{
    $changes = [];
    foreach (['name', 'legal_name_full', 'legal_name_short', 'inn', 'kpp', 'ogrn', 'type', 'email', 'phone', 'site', 'address', 'legal_address', 'postal_address', 'signer_name', 'signer_position', 'signer_authority', 'bank_name', 'bik', 'checking_account', 'correspondent_account', 'tags', 'status', 'customer_segment', 'notes', 'custom_fields', 'created_by', 'owner_id', 'user_id'] as $field) {
        $before = $primaryClient[$field] ?? null;
        $after = $mergedClient[$field] ?? null;
        if ((string)$before !== (string)$after) {
            $changes[$field] = ['before' => $before, 'after' => $after];
        }
    }

    if (!$apply || !$changes) {
        return $changes;
    }

    $sql = "UPDATE crm_clients SET name=?, legal_name_full=?, legal_name_short=?, inn=?, kpp=?, ogrn=?, type=?, email=?, phone=?, site=?, address=?, legal_address=?, postal_address=?, signer_name=?, signer_position=?, signer_authority=?, bank_name=?, bik=?, checking_account=?, correspondent_account=?, tags=?, status=?, customer_segment=?, notes=?, custom_fields=?, created_by=?, owner_id=?, user_id=?, updated_at=NOW() WHERE id=?";
    $stmt = crmToolsPrepare($pdo, $sql, 'merge.update_primary_client.prepare');
    crmToolsExecute($stmt, [
        $mergedClient['name'],
        $mergedClient['legal_name_full'],
        $mergedClient['legal_name_short'],
        $mergedClient['inn'],
        $mergedClient['kpp'],
        $mergedClient['ogrn'],
        $mergedClient['type'],
        $mergedClient['email'],
        $mergedClient['phone'],
        $mergedClient['site'],
        $mergedClient['address'],
        $mergedClient['legal_address'],
        $mergedClient['postal_address'],
        $mergedClient['signer_name'],
        $mergedClient['signer_position'],
        $mergedClient['signer_authority'],
        $mergedClient['bank_name'],
        $mergedClient['bik'],
        $mergedClient['checking_account'],
        $mergedClient['correspondent_account'],
        $mergedClient['tags'],
        $mergedClient['status'],
        $mergedClient['customer_segment'],
        $mergedClient['notes'],
        $mergedClient['custom_fields'],
        $mergedClient['created_by'],
        $mergedClient['owner_id'],
        $mergedClient['user_id'],
        $primaryId,
    ], 'merge.update_primary_client.execute', $sql);

    return $changes;
}

function crmToolsMergeDeleteDuplicateClient(PDO $pdo, int $duplicateId, bool $apply = false): void
{
    if (!$apply) {
        return;
    }
    $sql = 'DELETE FROM crm_clients WHERE id = ?';
    $stmt = crmToolsPrepare($pdo, $sql, 'merge.delete_duplicate_client.prepare');
    crmToolsExecute($stmt, [$duplicateId], 'merge.delete_duplicate_client.execute', $sql);
}

function crmToolsMergeGroup(PDO $pdo, array $group, bool $apply, string $logSource = 'web'): array
{
    $primaryId = (int)$group['proposed_primary_id'];
    $clientsById = [];
    foreach ($group['clients'] as $client) {
        $clientsById[(int)$client['id']] = $client;
    }
    if (!isset($clientsById[$primaryId])) {
        throw new RuntimeException('Primary client not found in group #' . $primaryId);
    }

    $primaryClient = $clientsById[$primaryId];
    $mergedClient = $primaryClient;
    $operations = [];

    foreach ($group['member_ids'] as $memberId) {
        $memberId = (int)$memberId;
        if ($memberId === $primaryId) {
            continue;
        }
        $duplicateClient = $clientsById[$memberId] ?? null;
        if ($duplicateClient === null) {
            continue;
        }

        $salesResult = crmMergeTableExists($pdo, 'crm_client_monthly_sales')
            ? crmToolsMergeUpsertMonthlySales($pdo, $primaryId, $memberId, $apply)
            : ['moved_sales_rows' => 0, 'sales_conflicts' => []];

        $refUpdates = [];
        foreach (crmMergeCandidateReferenceMap($pdo) as $ref) {
            if (($ref['kind'] ?? '') === 'sales') {
                continue;
            }
            $refKey = $ref['table'] . '.' . $ref['column'];
            $refUpdates[$refKey] = crmToolsMergeMoveDirectReferences(
                $pdo,
                $ref['table'],
                $ref['column'],
                $primaryId,
                $memberId,
                $ref['kind'] === 'activity' ? (string)$ref['entity_type'] : null,
                $apply
            );
        }

        $mergedClient = crmMergeOverlayClientData($mergedClient, $duplicateClient);
        $operations[] = [
            'duplicate_id' => $memberId,
            'duplicate_name' => $duplicateClient['name'] ?? null,
            'moved_sales_rows' => $salesResult['moved_sales_rows'],
            'reference_updates' => $refUpdates,
            'sales_conflicts' => $salesResult['sales_conflicts'],
        ];
    }

    $clientChanges = crmToolsMergeUpdatePrimaryClient($pdo, $primaryClient, $mergedClient, $primaryId, $apply);
    foreach ($group['member_ids'] as $memberId) {
        $memberId = (int)$memberId;
        if ($memberId === $primaryId) {
            continue;
        }
        crmToolsMergeDeleteDuplicateClient($pdo, $memberId, $apply);
    }

    if ($apply && function_exists('crmLog')) {
        crmLog(
            $pdo,
            'client',
            $primaryId,
            'merge_duplicates',
            null,
            strtoupper($logSource) . ' merge: объединены дубли CRM-клиента',
            [
                'merged_duplicate_ids' => array_values(array_filter(array_map('intval', $group['member_ids']), static fn(int $id): bool => $id !== $primaryId)),
                'evidence' => $group['evidence'] ?? [],
            ]
        );
    }

    return [
        'primary_id' => $primaryId,
        'primary_name' => $primaryClient['name'] ?? null,
        'member_ids' => array_values(array_map('intval', $group['member_ids'] ?? [])),
        'confidence' => $group['confidence'] ?? 'medium',
        'evidence' => $group['evidence'] ?? [],
        'client_changes' => $clientChanges,
        'operations' => $operations,
    ];
}

function crmToolsMergeSelectGroups(array $groups, array $opts): array
{
    if (($opts['group_index'] ?? null) !== null) {
        $index = (int)$opts['group_index'];
        if (!isset($groups[$index])) {
            throw new RuntimeException('Group index not found: ' . $index);
        }
        return [$groups[$index]];
    }

    if (!empty($opts['all'])) {
        return $groups;
    }

    return array_slice($groups, 0, 1);
}

function crmToolsMergeBuildPreview(PDO $pdo, array $opts): array
{
    $clientId = isset($opts['client_id']) ? (int)$opts['client_id'] : null;
    $primaryId = isset($opts['primary_id']) ? (int)$opts['primary_id'] : null;
    if ($clientId !== null && $clientId <= 0) {
        $clientId = null;
    }
    if ($primaryId !== null && $primaryId <= 0) {
        $primaryId = null;
    }

    $groups = crmMergeBuildDuplicateGroups($pdo, $clientId, $primaryId);
    if (!$groups) {
        return [
            'groups_found' => 0,
            'selected_groups' => 0,
            'groups' => [],
        ];
    }

    $selectedGroups = crmToolsMergeSelectGroups($groups, $opts);
    return [
        'groups_found' => count($groups),
        'selected_groups' => count($selectedGroups),
        'groups' => $selectedGroups,
    ];
}

function crmToolsMergeDuplicates(array $opts = []): array
{
    $apply = !empty($opts['apply']);
    $logSource = trim((string)($opts['log_source'] ?? 'web')) ?: 'web';
    $pdo = getPDO();
    $preview = crmToolsMergeBuildPreview($pdo, $opts);

    if (($preview['selected_groups'] ?? 0) === 0) {
        return [
            'success' => true,
            'operation' => 'merge_duplicates',
            'apply' => $apply,
            'message' => 'No duplicate groups with monthly sales found for merge.',
            'groups_found' => 0,
            'groups_processed' => 0,
            'results' => [],
        ];
    }

    $results = [];
    if ($apply) {
        $pdo->beginTransaction();
        try {
            foreach ($preview['groups'] as $group) {
                $results[] = crmToolsMergeGroup($pdo, $group, true, $logSource);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } else {
        foreach ($preview['groups'] as $group) {
            $results[] = crmToolsMergeGroup($pdo, $group, false, $logSource);
        }
    }

    return [
        'success' => true,
        'operation' => 'merge_duplicates',
        'apply' => $apply,
        'groups_found' => $preview['groups_found'],
        'groups_processed' => count($results),
        'results' => $results,
    ];
}
