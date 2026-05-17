<?php
/**
 * api/documents.php - Шаблоны документов и генерация пакетов
 */

require_once __DIR__ . '/permissions.php';

function handleDocuments(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    if (!hasPermission($currentUser, 'crm.view')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Нет доступа к документам CRM']);
        exit;
    }

    documentsEnsureSchema($pdo);

    $action = $action ?? '';
    $subaction = $subaction ?? '';

    if ($method === 'GET' && $action === 'templates' && ($id === null || $id === '')) {
        documentsListTemplates($pdo);
        exit;
    }

    if ($method === 'POST' && $action === 'templates' && ($id === null || $id === '')) {
        documentsSaveTemplate($pdo, $currentUser, null);
        exit;
    }

    if ($action === 'templates' && is_numeric($id) && ($subaction === null || $subaction === '')) {
        $templateId = (int)$id;
        if ($method === 'GET') {
            documentsGetTemplate($pdo, $templateId);
            exit;
        }
        if ($method === 'PUT') {
            documentsSaveTemplate($pdo, $currentUser, $templateId);
            exit;
        }
        if ($method === 'DELETE') {
            documentsDeleteTemplate($pdo, $currentUser, $templateId);
            exit;
        }
    }

    if ($method === 'GET' && $action === 'fields') {
        $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
        documentsGetFields($pdo, $clientId);
        exit;
    }

    if ($method === 'GET' && $action === 'clients') {
        documentsListClients($pdo);
        exit;
    }

    if ($method === 'GET' && $action === 'history') {
        $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 20;
        documentsListHistory($pdo, $clientId, $limit);
        exit;
    }

    if ($method === 'POST' && $action === 'generate') {
        documentsGenerateSingle($pdo, $currentUser);
        exit;
    }

    if ($method === 'POST' && $action === 'generate-batch') {
        documentsGenerateBatch($pdo, $currentUser);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Маршрут документов не найден']);
}

function documentsEnsureSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS document_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        description TEXT NULL,
        category VARCHAR(100) NULL,
        content LONGTEXT NOT NULL,
        output_format VARCHAR(20) NOT NULL DEFAULT 'html',
        source_origin VARCHAR(20) NOT NULL DEFAULT 'inline',
        source_path VARCHAR(500) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_document_templates_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS document_generations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_id INT NULL,
        client_id INT NOT NULL,
        mode VARCHAR(20) NOT NULL DEFAULT 'single',
        source_entity_type VARCHAR(50) NULL,
        source_entity_id INT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(120) NOT NULL DEFAULT 'text/html',
        size_bytes INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_document_generations_client (client_id),
        INDEX idx_document_generations_template (template_id),
        INDEX idx_document_generations_source (source_entity_type, source_entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    documentsEnsureTemplateColumns($pdo);
    documentsEnsureGenerationColumns($pdo);

    documentsEnsureSeedTemplates($pdo);

    $storagePath = documentsStoragePath();
    if (!is_dir($storagePath)) {
        @mkdir($storagePath, 0755, true);
    }

    $packagesPath = documentsStoragePath() . '/packages';
    if (!is_dir($packagesPath)) {
        @mkdir($packagesPath, 0755, true);
    }

    $initialized = true;
}

function documentsStoragePath(): string {
    return dirname(__DIR__) . '/uploads/documents';
}

function documentsTemplateDocsPath(): string {
    return dirname(__DIR__) . '/docs';
}

function documentsPublicPath(string $basename): string {
    return 'uploads/documents/' . $basename;
}

function documentsListTemplates(PDO $pdo): void {
    $stmt = $pdo->query("SELECT id, name, slug, description, category, output_format, source_origin, source_path, is_active, created_at, updated_at FROM document_templates ORDER BY FIELD(output_format, 'docx', 'html'), updated_at DESC, id DESC");
    $templates = array_map('documentsEnrichTemplateRuntimeMeta', $stmt->fetchAll());
    usort($templates, 'documentsCompareTemplatesForList');
    echo json_encode(['success' => true, 'data' => $templates]);
}

function documentsGetTemplate(PDO $pdo, int $templateId): void {
    $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE id=?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Шаблон не найден']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => documentsEnrichTemplateRuntimeMeta($template)]);
}

function documentsSaveTemplate(PDO $pdo, array $currentUser, ?int $templateId): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $data = is_array($data) ? $data : [];

    $name = trim((string)($data['name'] ?? ''));
    $content = (string)($data['content'] ?? '');
    $outputFormat = trim(mb_strtolower((string)($data['output_format'] ?? 'html'), 'UTF-8'));
    if (!in_array($outputFormat, ['html', 'docx'], true)) {
        $outputFormat = 'html';
    }
    $sourceOrigin = trim(mb_strtolower((string)($data['source_origin'] ?? 'inline'), 'UTF-8'));
    if (!in_array($sourceOrigin, ['inline', 'docs'], true)) {
        $sourceOrigin = 'inline';
    }
    $sourcePath = documentsNormalizeTemplateSourcePath((string)($data['source_path'] ?? ''));

    if ($name === '' || (($outputFormat === 'html') && trim($content) === '') || (($outputFormat === 'docx') && $sourcePath === '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите название и содержимое шаблона']);
        exit;
    }

    $slug = trim((string)($data['slug'] ?? ''));
    if ($slug === '') {
        $slug = documentsSlugify($name);
    }

    $slug = substr($slug, 0, 200);
    $description = trim((string)($data['description'] ?? ''));
    $category = trim((string)($data['category'] ?? 'CRM'));
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($templateId) {
        $existing = documentsFindAnyTemplateById($pdo, $templateId);
        if (($existing['output_format'] ?? 'html') === 'docx' && ($existing['source_origin'] ?? 'inline') === 'docs') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'DOCX-шаблоны из папки docs пока доступны только для генерации копии и не редактируются через интерфейс']);
            exit;
        }
    }

    if ($templateId) {
        $check = $pdo->prepare("SELECT id FROM document_templates WHERE slug=? AND id<>?");
        $check->execute([$slug, $templateId]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Шаблон с таким slug уже существует']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE document_templates SET name=?, slug=?, description=?, category=?, content=?, output_format=?, source_origin=?, source_path=?, is_active=?, updated_by=? WHERE id=?");
        $stmt->execute([$name, $slug, $description ?: null, $category ?: null, $content, $outputFormat, $sourceOrigin, $sourcePath ?: null, $isActive, $currentUser['id'], $templateId]);
        echo json_encode(['success' => true, 'data' => ['id' => $templateId]]);
        return;
    }

    $check = $pdo->prepare("SELECT id FROM document_templates WHERE slug=?");
    $check->execute([$slug]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Шаблон с таким slug уже существует']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO document_templates (name, slug, description, category, content, output_format, source_origin, source_path, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $slug, $description ?: null, $category ?: null, $content, $outputFormat, $sourceOrigin, $sourcePath ?: null, $isActive, $currentUser['id'], $currentUser['id']]);
    echo json_encode(['success' => true, 'data' => ['id' => (int)$pdo->lastInsertId()]]);
}

function documentsDeleteTemplate(PDO $pdo, array $currentUser, int $templateId): void {
    $stmt = $pdo->prepare("DELETE FROM document_templates WHERE id=?");
    $stmt->execute([$templateId]);
    echo json_encode(['success' => true, 'data' => ['id' => $templateId, 'deleted_by' => $currentUser['id']]]);
}

function documentsListClients(PDO $pdo): void {
    $stmt = $pdo->query("SELECT id, name, email, phone, status, type, updated_at FROM crm_clients ORDER BY updated_at DESC LIMIT 200");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function documentsListHistory(PDO $pdo, int $clientId, int $limit): void {
    $limit = max(1, min($limit, 100));
    $sql = "SELECT dg.id,
                   dg.template_id,
                   dg.client_id,
                   dg.mode,
                   dg.source_entity_type,
                   dg.source_entity_id,
                   dg.file_name,
                   dg.file_path,
                   dg.mime_type,
                   dg.size_bytes,
                   dg.created_at,
                   dt.name AS template_name,
                   c.name AS client_name,
                   u.full_name AS created_by_name
            FROM document_generations dg
            LEFT JOIN document_templates dt ON dt.id = dg.template_id
            LEFT JOIN crm_clients c ON c.id = dg.client_id
            LEFT JOIN users u ON u.id = dg.created_by";

    $params = [];
    if ($clientId > 0) {
        $sql .= " WHERE dg.client_id = ?";
        $params[] = $clientId;
    }

    $sql .= " ORDER BY dg.created_at DESC, dg.id DESC LIMIT " . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $data = array_map(static function (array $row): array {
        $mode = (string)($row['mode'] ?? 'single');
        $templateName = $row['template_name'] ?? null;
        if (!$templateName) {
            $templateName = match ($mode) {
                'batch_zip' => 'Пакет документов (ZIP)',
                'batch_manifest' => 'Пакет документов (fallback)',
                default => 'Документ без шаблона',
            };
        }

        return [
            'id' => (int)$row['id'],
            'template_id' => $row['template_id'] !== null ? (int)$row['template_id'] : null,
            'template_name' => $templateName,
            'client_id' => (int)$row['client_id'],
            'client_name' => (string)($row['client_name'] ?? ('Клиент #' . (int)$row['client_id'])),
            'mode' => $mode,
            'mode_label' => documentsGenerationModeLabel($mode),
            'source_entity_type' => $row['source_entity_type'] ?: null,
            'source_entity_id' => $row['source_entity_id'] !== null ? (int)$row['source_entity_id'] : null,
            'file_name' => (string)$row['file_name'],
            'file_url' => (string)$row['file_path'],
            'mime_type' => (string)($row['mime_type'] ?? 'text/html'),
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'created_by_name' => (string)($row['created_by_name'] ?? ''),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $data, 'meta' => ['client_id' => $clientId ?: null, 'limit' => $limit]]);
}

function documentsGetFields(PDO $pdo, int $clientId): void {
    $context = $clientId > 0 ? documentsBuildContext($pdo, $clientId) : null;
    echo json_encode(['success' => true, 'data' => [
        'groups' => documentsFieldCatalog(),
        'preview' => $context,
        'docx' => documentsDocxSupportInfo(),
    ]]);
}

function documentsGenerateSingle(PDO $pdo, array $currentUser): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $data = is_array($data) ? $data : [];

    $templateId = isset($data['template_id']) && is_numeric($data['template_id']) ? (int)$data['template_id'] : 0;
    $clientId = isset($data['client_id']) && is_numeric($data['client_id']) ? (int)$data['client_id'] : 0;
    if ($templateId <= 0 || $clientId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Выберите шаблон и клиента']);
        exit;
    }

    $template = documentsFindTemplate($pdo, $templateId);
    $context = documentsBuildContext($pdo, $clientId);
    $sourceEntityType = documentsNormalizeSourceEntityType($data['source_entity_type'] ?? null);
    $sourceEntityId = isset($data['source_entity_id']) && is_numeric($data['source_entity_id']) ? (int)$data['source_entity_id'] : null;
    $result = documentsRenderAndSave($pdo, $template, $context, $clientId, $currentUser['id'], 'single', [
        'source_entity_type' => $sourceEntityType,
        'source_entity_id' => $sourceEntityId,
    ]);

    echo json_encode(['success' => true, 'data' => $result]);
}

function documentsGenerateBatch(PDO $pdo, array $currentUser): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $data = is_array($data) ? $data : [];

    $clientId = isset($data['client_id']) && is_numeric($data['client_id']) ? (int)$data['client_id'] : 0;
    $templateIds = array_values(array_filter(array_map('intval', (array)($data['template_ids'] ?? [])), fn($v) => $v > 0));
    $sourceEntityType = documentsNormalizeSourceEntityType($data['source_entity_type'] ?? null);
    $sourceEntityId = isset($data['source_entity_id']) && is_numeric($data['source_entity_id']) ? (int)$data['source_entity_id'] : null;
    if ($clientId <= 0 || !$templateIds) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Выберите клиента и хотя бы один шаблон']);
        exit;
    }

    $context = documentsBuildContext($pdo, $clientId);
    $items = [];
    foreach ($templateIds as $templateId) {
        $template = documentsFindTemplate($pdo, $templateId);
        $items[] = documentsRenderAndSave($pdo, $template, $context, $clientId, $currentUser['id'], 'batch', [
            'source_entity_type' => $sourceEntityType,
            'source_entity_id' => $sourceEntityId,
        ]);
    }

    $archive = documentsCreateBatchArchive($pdo, $context, $items, $clientId, (int)$currentUser['id'], [
        'source_entity_type' => $sourceEntityType,
        'source_entity_id' => $sourceEntityId,
    ]);

    echo json_encode(['success' => true, 'data' => [
        'client' => [
            'id' => $context['client']['id'] ?? $clientId,
            'name' => $context['client']['name'] ?? ('Клиент ' . $clientId),
        ],
        'items' => $items,
        'count' => count($items),
        'archive' => $archive,
    ]]);
}

function documentsEnsureSeedTemplates(PDO $pdo): void {
    $ownerId = (int)($pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetch()['id'] ?? 1);
    $templates = documentsSeedTemplates();
    $selectStmt = $pdo->prepare("SELECT id FROM document_templates WHERE slug=? LIMIT 1");
    $insertStmt = $pdo->prepare("INSERT INTO document_templates (name, slug, description, category, content, output_format, source_origin, source_path, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, ?, 'html', 'inline', NULL, 1, ?, ?)");

    foreach ($templates as $template) {
        $slug = (string)($template['slug'] ?? '');
        if ($slug === '') {
            continue;
        }

        $selectStmt->execute([$slug]);
        if ($selectStmt->fetch()) {
            continue;
        }

        $insertStmt->execute([
            (string)$template['name'],
            $slug,
            (string)$template['description'],
            (string)$template['category'],
            (string)$template['content'],
            $ownerId,
            $ownerId,
        ]);
    }

    documentsEnsureDocxCatalogTemplates($pdo, $ownerId);
}

function documentsEnsureDocxCatalogTemplates(PDO $pdo, int $ownerId): void {
    $catalog = documentsDiscoverDocxTemplates();
    if (!$catalog) {
        return;
    }

    $selectStmt = $pdo->prepare("SELECT id, name, description, category, source_path, is_active FROM document_templates WHERE slug=? LIMIT 1");
    $insertStmt = $pdo->prepare("INSERT INTO document_templates (name, slug, description, category, content, output_format, source_origin, source_path, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, '', 'docx', 'docs', ?, 1, ?, ?)");
    $updateStmt = $pdo->prepare("UPDATE document_templates SET name=?, description=?, category=?, source_path=?, output_format='docx', source_origin='docs', is_active=1, updated_by=? WHERE id=?");

    foreach ($catalog as $template) {
        $selectStmt->execute([$template['slug']]);
        $existing = $selectStmt->fetch();
        if ($existing) {
            $needsUpdate = ($existing['name'] ?? '') !== $template['name']
                || ($existing['description'] ?? '') !== $template['description']
                || ($existing['category'] ?? '') !== $template['category']
                || ($existing['source_path'] ?? '') !== $template['source_path']
                || (int)($existing['is_active'] ?? 0) !== 1;
            if ($needsUpdate) {
                $updateStmt->execute([
                    $template['name'],
                    $template['description'],
                    $template['category'],
                    $template['source_path'],
                    $ownerId,
                    (int)$existing['id'],
                ]);
            }
            continue;
        }

        $insertStmt->execute([
            $template['name'],
            $template['slug'],
            $template['description'],
            $template['category'],
            $template['source_path'],
            $ownerId,
            $ownerId,
        ]);
    }
}

function documentsDiscoverDocxTemplates(): array {
    $docsPath = documentsTemplateDocsPath();
    if (!is_dir($docsPath)) {
        return [];
    }

    $files = @scandir($docsPath);
    if (!is_array($files)) {
        return [];
    }

    $templates = [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $extension = mb_strtolower(pathinfo($file, PATHINFO_EXTENSION), 'UTF-8');
        if ($extension !== 'docx') {
            continue;
        }

        $profile = documentsDocxCatalogProfile($file);
        $relativePath = 'docs/' . $file;
        $templates[] = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'slug' => 'docx-' . documentsSlugify(pathinfo($file, PATHINFO_FILENAME)),
            'description' => $profile['short_description'],
            'category' => 'DOCX',
            'source_path' => $relativePath,
        ];
    }

    return $templates;
}

function documentsSeedTemplates(): array {
    return [
        [
            'name' => 'Коммерческое предложение',
            'slug' => 'commercial-offer',
            'description' => 'Базовый шаблон КП с юридическими данными клиента, подписантом и банковскими реквизитами.',
            'category' => 'Продажи',
            'content' => "<section style=\"font-family:Arial,sans-serif;padding:36px;color:#111827;line-height:1.5\">\n"
                . "  <div style=\"display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:28px\">\n"
                . "    <div>\n"
                . "      <div style=\"font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280\">Коммерческое предложение</div>\n"
                . "      <h1 style=\"margin:8px 0 0;font-size:30px\">Для {{client.display_name_for_documents}}</h1>\n"
                . "      <div style=\"margin-top:8px;font-size:14px;color:#4b5563\">{{company.full_name}}</div>\n"
                . "    </div>\n"
                . "    <div style=\"text-align:right;font-size:13px;color:#6b7280\">\n"
                . "      <div>Дата: {{system.generated_date}}</div>\n"
                . "      <div>Ответственный: {{client.owner_name}}</div>\n"
                . "      <div>Подписант: {{client.signer_display}}</div>\n"
                . "    </div>\n"
                . "  </div>\n"
                . "  <p style=\"margin:0 0 18px\">Уважаемые коллеги, подготовили предложение для клиента <strong>{{client.display_name_for_documents}}</strong> с учетом текущего статуса <strong>{{client.status_label}}</strong> и активных потребностей.</p>\n"
                . "  <div style=\"background:#f8fafc;border:1px solid #dbe3ee;border-radius:16px;padding:18px;margin-bottom:22px\">\n"
                . "    <div style=\"font-size:16px;font-weight:700;margin-bottom:8px\">Краткая рамка сотрудничества</div>\n"
                . "    <ul style=\"margin:0;padding-left:18px\">\n"
                . "      <li>Клиент: {{client.name}}</li>\n"
                . "      <li>Юр. лицо: {{company.short_name}}</li>\n"
                . "      <li>ИНН / КПП: {{company.inn}} / {{company.kpp}}</li>\n"
                . "      <li>Контакт: {{contact.primary_name}} / {{contact.primary_phone}}</li>\n"
                . "      <li>Последняя сделка: {{latest_deal.title}}</li>\n"
                . "      <li>Ожидаемая сумма: {{latest_deal.amount}} {{latest_deal.currency}}</li>\n"
                . "    </ul>\n"
                . "  </div>\n"
                . "  <div style=\"display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:22px\">\n"
                . "    <div style=\"border:1px solid #dbe3ee;border-radius:16px;padding:16px\">\n"
                . "      <div style=\"font-weight:700;margin-bottom:8px\">Юридические реквизиты</div>\n"
                . "      <div>Полное наименование: {{company.full_name}}</div>\n"
                . "      <div>ОГРН: {{company.ogrn}}</div>\n"
                . "      <div>Юр. адрес: {{company.legal_address}}</div>\n"
                . "      <div>Почтовый адрес: {{company.postal_address}}</div>\n"
                . "    </div>\n"
                . "    <div style=\"border:1px solid #dbe3ee;border-radius:16px;padding:16px\">\n"
                . "      <div style=\"font-weight:700;margin-bottom:8px\">Банк и подписание</div>\n"
                . "      <div>Подписант: {{client.signer_name}}</div>\n"
                . "      <div>Должность: {{client.signer_position}}</div>\n"
                . "      <div>Основание: {{client.signer_authority}}</div>\n"
                . "      <div>Банк: {{bank.name}}</div>\n"
                . "      <div>Р/с: {{bank.checking_account}}</div>\n"
                . "      <div>БИК: {{bank.bik}}</div>\n"
                . "    </div>\n"
                . "  </div>\n"
                . "  <h2 style=\"font-size:20px;margin:0 0 12px\">Что уже есть в работе</h2>\n"
                . "  <div>{{tasks.table}}</div>\n"
                . "  <p style=\"margin:22px 0 0;color:#4b5563\">При необходимости этот шаблон можно быстро адаптировать под конкретное предложение, цены и условия. Для договорных приложений уже доступны поля {{company.*}}, {{bank.*}} и {{client.signer_*}}.</p>\n"
                . "</section>",
        ],
        [
            'name' => 'Шаблон договора',
            'slug' => 'contract-template',
            'description' => 'Заготовка договора с предметом сотрудничества, подписантом и полными реквизитами клиента.',
            'category' => 'Юридические',
            'content' => "<section style=\"font-family:Arial,sans-serif;padding:36px;color:#111827;line-height:1.55\">\n"
                . "  <h1 style=\"margin:0 0 18px;font-size:28px;text-align:center\">ДОГОВОР / ШАБЛОН ДОГОВОРА</h1>\n"
                . "  <p style=\"margin:0 0 18px\">г. ____________ <span style=\"float:right\">{{system.generated_date}}</span></p>\n"
                . "  <p style=\"margin:0 0 16px\"><strong>{{company.full_name}}</strong>, ИНН {{company.inn}}, КПП {{company.kpp}}, в лице {{client.signer_name}}, действующего на основании {{client.signer_authority}}, далее - Заказчик, и Исполнитель, далее совместно - Стороны, заключили настоящий договор о нижеследующем.</p>\n"
                . "  <h2 style=\"font-size:18px;margin:22px 0 10px\">1. Предмет договора</h2>\n"
                . "  <p style=\"margin:0\">Исполнитель оказывает услуги для клиента {{client.display_name_for_documents}} в объеме, согласованном сторонами. Основанием для запуска работ может служить сделка <strong>{{latest_deal.title}}</strong>.</p>\n"
                . "  <h2 style=\"font-size:18px;margin:22px 0 10px\">2. Контактные данные Заказчика</h2>\n"
                . "  <ul style=\"margin:0;padding-left:18px\">\n"
                . "    <li>Email: {{client.email}}</li>\n"
                . "    <li>Телефон: {{client.phone}}</li>\n"
                . "    <li>Адрес: {{client.address}}</li>\n"
                . "    <li>Контактное лицо: {{contact.primary_name}} ({{contact.primary_position}})</li>\n"
                . "  </ul>\n"
                . "  <h2 style=\"font-size:18px;margin:22px 0 10px\">3. Реквизиты Заказчика</h2>\n"
                . "  <table style=\"width:100%;border-collapse:collapse;font-size:14px\">\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee;width:36%\">Полное наименование</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{company.full_name}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">Краткое наименование</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{company.short_name}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">ИНН / КПП</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{company.inn}} / {{company.kpp}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">ОГРН</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{company.ogrn}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">Юридический адрес</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{company.legal_address}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">Почтовый адрес</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{company.postal_address}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">Банк</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{bank.name}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">БИК</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{bank.bik}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">Расчетный счет</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{bank.checking_account}}</td></tr>\n"
                . "    <tr><td style=\"padding:8px;border:1px solid #dbe3ee\">Корреспондентский счет</td><td style=\"padding:8px;border:1px solid #dbe3ee\">{{bank.correspondent_account}}</td></tr>\n"
                . "  </table>\n"
                . "  <h2 style=\"font-size:18px;margin:22px 0 10px\">4. Текущий контекст проекта</h2>\n"
                . "  <div>{{deals.table}}</div>\n"
                . "  <p style=\"margin-top:22px;color:#4b5563\">Шаблон является стартовой юридической формой и требует проверки перед отправкой клиенту. Для подписного блока используйте {{client.signer_name}}, {{client.signer_position}} и {{client.signer_authority}}.</p>\n"
                . "</section>",
        ],
        [
            'name' => 'Карточка клиента',
            'slug' => 'client-summary',
            'description' => 'Быстрый HTML-документ с основными данными, юридическим блоком и банковскими реквизитами клиента.',
            'category' => 'CRM',
            'content' => "<section style=\"font-family:Arial,sans-serif;padding:32px;color:#111827\">\n"
                . "  <h1 style=\"margin:0 0 12px;font-size:28px\">Карточка клиента {{client.name}}</h1>\n"
                . "  <p style=\"margin:0 0 24px;color:#6b7280\">Сформировано {{system.generated_at}}</p>\n"
                . "  <h2 style=\"font-size:18px;margin:0 0 12px\">Основные данные</h2>\n"
                . "  <ul>\n"
                . "    <li>Email: {{client.email}}</li>\n"
                . "    <li>Телефон: {{client.phone}}</li>\n"
                . "    <li>Статус: {{client.status_label}}</li>\n"
                . "    <li>Адрес: {{client.address}}</li>\n"
                . "  </ul>\n"
                . "  <h2 style=\"font-size:18px;margin:24px 0 12px\">Юридические реквизиты</h2>\n"
                . "  <ul>\n"
                . "    <li>Полное наименование: {{company.full_name}}</li>\n"
                . "    <li>Краткое наименование: {{company.short_name}}</li>\n"
                . "    <li>ИНН / КПП: {{company.inn}} / {{company.kpp}}</li>\n"
                . "    <li>ОГРН: {{company.ogrn}}</li>\n"
                . "    <li>Юр. адрес: {{company.legal_address}}</li>\n"
                . "    <li>Почтовый адрес: {{company.postal_address}}</li>\n"
                . "  </ul>\n"
                . "  <h2 style=\"font-size:18px;margin:24px 0 12px\">Подписание и банк</h2>\n"
                . "  <ul>\n"
                . "    <li>Подписант: {{client.signer_name}}</li>\n"
                . "    <li>Должность: {{client.signer_position}}</li>\n"
                . "    <li>Основание: {{client.signer_authority}}</li>\n"
                . "    <li>Банк: {{bank.name}}</li>\n"
                . "    <li>БИК: {{bank.bik}}</li>\n"
                . "    <li>Р/с: {{bank.checking_account}}</li>\n"
                . "    <li>К/с: {{bank.correspondent_account}}</li>\n"
                . "  </ul>\n"
                . "  <h2 style=\"font-size:18px;margin:24px 0 12px\">Сводка</h2>\n"
                . "  <ul>\n"
                . "    <li>Контактов: {{stats.contacts_count}}</li>\n"
                . "    <li>Сделок: {{stats.deals_count}}</li>\n"
                . "    <li>Задач: {{stats.tasks_count}}</li>\n"
                . "  </ul>\n"
                . "  <h2 style=\"font-size:18px;margin:24px 0 12px\">Ближайшие задачи</h2>\n"
                . "  <div>{{tasks.table}}</div>\n"
                . "</section>",
        ],
        [
            'name' => 'Краткий отчет по задачам клиента',
            'slug' => 'client-task-report',
            'description' => 'Сжатый отчет по задачам клиента с контактным, юридическим и подписным контекстом.',
            'category' => 'Отчеты',
            'content' => "<section style=\"font-family:Arial,sans-serif;padding:32px;color:#111827\">\n"
                . "  <div style=\"display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:20px\">\n"
                . "    <div>\n"
                . "      <h1 style=\"margin:0;font-size:28px\">Отчет по задачам: {{client.name}}</h1>\n"
                . "      <div style=\"margin-top:6px;color:#6b7280\">Сформировано {{system.generated_at}}</div>\n"
                . "    </div>\n"
                . "    <div style=\"font-size:13px;color:#4b5563;text-align:right\">\n"
                . "      <div>Всего задач: {{stats.tasks_count}}</div>\n"
                . "      <div>Последняя задача: {{latest_task.title}}</div>\n"
                . "    </div>\n"
                . "  </div>\n"
                . "  <div style=\"background:#f8fafc;border:1px solid #dbe3ee;border-radius:16px;padding:16px;margin-bottom:20px\">\n"
                . "    <div style=\"font-size:16px;font-weight:700;margin-bottom:8px\">Краткое резюме</div>\n"
                . "    <ul style=\"margin:0;padding-left:18px\">\n"
                . "      <li>Ответственный: {{latest_task.assignee_name}}</li>\n"
                . "      <li>Приоритет последней задачи: {{latest_task.priority}}</li>\n"
                . "      <li>Срок последней задачи: {{latest_task.deadline}}</li>\n"
                . "      <li>Проект: {{latest_task.project_name}}</li>\n"
                . "    </ul>\n"
                . "  </div>\n"
                . "  <div style=\"display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:20px\">\n"
                . "    <div style=\"border:1px solid #dbe3ee;border-radius:16px;padding:16px\">\n"
                . "      <div style=\"font-weight:700;margin-bottom:8px\">Контакт и подписание</div>\n"
                . "      <div>Контакт: {{contact.primary_name}}</div>\n"
                . "      <div>Email: {{contact.primary_email}}</div>\n"
                . "      <div>Телефон: {{contact.primary_phone}}</div>\n"
                . "      <div>Подписант: {{client.signer_name}}</div>\n"
                . "      <div>Основание: {{client.signer_authority}}</div>\n"
                . "    </div>\n"
                . "    <div style=\"border:1px solid #dbe3ee;border-radius:16px;padding:16px\">\n"
                . "      <div style=\"font-weight:700;margin-bottom:8px\">Юридический профиль</div>\n"
                . "      <div>{{company.full_name}}</div>\n"
                . "      <div>ИНН / КПП: {{company.inn}} / {{company.kpp}}</div>\n"
                . "      <div>ОГРН: {{company.ogrn}}</div>\n"
                . "      <div>Банк: {{bank.name}}</div>\n"
                . "      <div>Р/с: {{bank.checking_account}}</div>\n"
                . "    </div>\n"
                . "  </div>\n"
                . "  <div>{{tasks.table}}</div>\n"
                . "</section>",
        ],
    ];
}

function documentsCreateBatchArchive(PDO $pdo, array $context, array $items, int $clientId, int $userId, array $source = []): array {
    if (!$items) {
        return [
            'available' => false,
            'download_mode' => 'none',
            'warning' => 'Для архивации нет файлов.',
        ];
    }

    $clientName = (string)($context['client']['name'] ?? ('client-' . $clientId));
    $safeClientName = documentsSlugify($clientName);
    $timestamp = date('Ymd-His');
    $packageBaseName = $timestamp . '-' . $safeClientName . '-documents';

    if (class_exists('ZipArchive')) {
        $zipFileName = $packageBaseName . '.zip';
        $zipFullPath = documentsStoragePath() . '/packages/' . $zipFileName;
        $zip = new ZipArchive();
        $opened = $zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened === true) {
            foreach ($items as $item) {
                $fileName = basename((string)($item['file_name'] ?? 'document.html'));
                $source = documentsStoragePath() . '/' . $fileName;
                if (is_file($source)) {
                    $zip->addFile($source, $fileName);
                }
            }

            $manifest = documentsBuildBatchManifestHtml($context, $items, true, null);
            $zip->addFromString('index.html', $manifest);
            $zip->close();

            $size = is_file($zipFullPath) ? (int)filesize($zipFullPath) : 0;
            $stmt = $pdo->prepare("INSERT INTO document_generations (template_id, client_id, mode, source_entity_type, source_entity_id, file_name, file_path, mime_type, size_bytes, created_by) VALUES (NULL, ?, 'batch_zip', ?, ?, ?, ?, 'application/zip', ?, ?)");
            $stmt->execute([$clientId, $source['source_entity_type'] ?? null, $source['source_entity_id'] ?? null, $zipFileName, documentsPublicPath('packages/' . $zipFileName), $size, $userId]);

            return [
                'available' => true,
                'download_mode' => 'zip',
                'file_name' => $zipFileName,
                'file_url' => documentsPublicPath('packages/' . $zipFileName),
                'size_bytes' => $size,
                'fallback_used' => false,
                'warning' => null,
            ];
        }
    }

    $manifestFileName = $packageBaseName . '-manifest.html';
    $manifestFullPath = documentsStoragePath() . '/packages/' . $manifestFileName;
    $warning = 'ZipArchive недоступен на сервере, поэтому пакет сохранен как HTML-страница со ссылками на отдельные документы.';
    file_put_contents($manifestFullPath, documentsBuildBatchManifestHtml($context, $items, false, $warning));
    $size = is_file($manifestFullPath) ? (int)filesize($manifestFullPath) : 0;
    $stmt = $pdo->prepare("INSERT INTO document_generations (template_id, client_id, mode, source_entity_type, source_entity_id, file_name, file_path, mime_type, size_bytes, created_by) VALUES (NULL, ?, 'batch_manifest', ?, ?, ?, ?, 'text/html', ?, ?)");
    $stmt->execute([$clientId, $source['source_entity_type'] ?? null, $source['source_entity_id'] ?? null, $manifestFileName, documentsPublicPath('packages/' . $manifestFileName), $size, $userId]);

    return [
        'available' => true,
        'download_mode' => 'manifest',
        'file_name' => $manifestFileName,
        'file_url' => documentsPublicPath('packages/' . $manifestFileName),
        'size_bytes' => $size,
        'fallback_used' => true,
        'warning' => $warning,
    ];
}

function documentsBuildBatchManifestHtml(array $context, array $items, bool $zipReady, ?string $warning): string {
    $clientName = htmlspecialchars((string)($context['client']['name'] ?? 'Клиент'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $generatedAt = htmlspecialchars((string)($context['system']['generated_at'] ?? date('d.m.Y H:i')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $statusLabel = $zipReady ? 'ZIP-пакет успешно подготовлен.' : 'ZIP недоступен, используйте ссылки ниже.';
    $warningHtml = $warning ? '<div style="margin:0 0 16px;padding:14px 16px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412">' . htmlspecialchars($warning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' : '';
    $rows = '';

    foreach ($items as $item) {
        $name = htmlspecialchars((string)($item['template_name'] ?? 'Документ'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fileName = htmlspecialchars((string)($item['file_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fileNameOnly = htmlspecialchars(basename((string)($item['file_name'] ?? 'document.html')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb">' . $name . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb">' . $fileName . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb"><a href="../' . $fileNameOnly . '" target="_blank" rel="noopener">Скачать</a></td>'
            . '</tr>';
    }

    return '<!doctype html>'
        . '<html lang="ru"><head><meta charset="utf-8"><title>Пакет документов</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '</head><body style="margin:0;background:#f3f6fb;font-family:Arial,sans-serif;color:#111827">'
        . '<div style="max-width:900px;margin:0 auto;padding:32px 20px">'
        . '<div style="background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.08)">'
        . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280">Пакет документов</div>'
        . '<h1 style="margin:8px 0 6px;font-size:28px">' . $clientName . '</h1>'
        . '<div style="color:#6b7280;margin-bottom:18px">Сформировано ' . $generatedAt . '</div>'
        . $warningHtml
        . '<div style="margin:0 0 20px;padding:14px 16px;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8">' . htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
        . '<table style="width:100%;border-collapse:collapse;background:#fff">'
        . '<thead><tr><th style="text-align:left;padding:10px 12px;border-bottom:1px solid #dbe3ee;color:#374151">Шаблон</th><th style="text-align:left;padding:10px 12px;border-bottom:1px solid #dbe3ee;color:#374151">Файл</th><th style="text-align:left;padding:10px 12px;border-bottom:1px solid #dbe3ee;color:#374151">Действие</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '</div></div></body></html>';
}

function documentsFindTemplate(PDO $pdo, int $templateId): array {
    $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE id=? AND is_active=1");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Шаблон не найден или отключен']);
        exit;
    }
    return $template;
}

function documentsBuildContext(PDO $pdo, int $clientId): array {
    $stmt = $pdo->prepare("SELECT c.*, u.full_name as owner_name FROM crm_clients c LEFT JOIN users u ON u.id=c.owner_id WHERE c.id=?");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Клиент не найден']);
        exit;
    }

    $client['tags'] = $client['tags'] ? json_decode($client['tags'], true) : [];
    $client['custom_fields'] = $client['custom_fields'] ? json_decode($client['custom_fields'], true) : [];
    $client['status_label'] = documentsClientStatusLabel((string)($client['status'] ?? ''));
    $client['type_label'] = (($client['type'] ?? 'person') === 'company') ? 'Компания' : 'Физлицо';
    $client['tags_text'] = implode(', ', array_filter((array)$client['tags']));
    $client['display_name_for_documents'] = $client['legal_name_full'] ?: ($client['legal_name_short'] ?: ($client['name'] ?? ''));
    $client['signer_display'] = implode(', ', array_filter([
        (string)($client['signer_name'] ?? ''),
        (string)($client['signer_position'] ?? ''),
    ]));
    $client['bank_details_text'] = implode(', ', array_filter([
        $client['bank_name'] ?? '',
        !empty($client['bik']) ? ('БИК ' . $client['bik']) : '',
        !empty($client['checking_account']) ? ('р/с ' . $client['checking_account']) : '',
        !empty($client['correspondent_account']) ? ('к/с ' . $client['correspondent_account']) : '',
    ]));
    $client['legal_details_text'] = implode(', ', array_filter([
        $client['legal_name_full'] ?? '',
        !empty($client['inn']) ? ('ИНН ' . $client['inn']) : '',
        !empty($client['kpp']) ? ('КПП ' . $client['kpp']) : '',
        !empty($client['ogrn']) ? ('ОГРН ' . $client['ogrn']) : '',
    ]));

    $contactsStmt = $pdo->prepare("SELECT id, name, position, email, phone, is_primary FROM crm_client_contacts WHERE client_id=? ORDER BY is_primary DESC, id DESC LIMIT 20");
    $contactsStmt->execute([$clientId]);
    $contacts = $contactsStmt->fetchAll();

    $dealsStmt = $pdo->prepare("SELECT d.id, d.title, d.amount, d.currency, d.probability, d.expected_close_date, s.name as stage_name FROM crm_deals d LEFT JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE d.client_id=? ORDER BY d.updated_at DESC LIMIT 20");
    $dealsStmt->execute([$clientId]);
    $deals = $dealsStmt->fetchAll();

    $tasksStmt = $pdo->prepare("SELECT t.id, t.title, t.description, t.status, t.priority, t.deadline, t.created_at, u.full_name as assignee_name, p.name as project_name FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN projects p ON p.id=t.project_id WHERE t.client_id=? ORDER BY t.created_at DESC LIMIT 20");
    $tasksStmt->execute([$clientId]);
    $tasks = $tasksStmt->fetchAll();

    $activityStmt = $pdo->prepare("SELECT action, message, created_at FROM crm_activity WHERE entity_type='client' AND entity_id=? ORDER BY created_at DESC LIMIT 20");
    $activityStmt->execute([$clientId]);
    $activity = $activityStmt->fetchAll();

    $latestContact = $contacts[0] ?? null;
    $latestDeal = $deals[0] ?? null;
    $latestTask = $tasks[0] ?? null;
    $latestBooking = null;

    return [
        'client' => $client,
        'company' => [
            'full_name' => $client['legal_name_full'] ?? '',
            'short_name' => $client['legal_name_short'] ?? '',
            'inn' => $client['inn'] ?? '',
            'kpp' => $client['kpp'] ?? '',
            'ogrn' => $client['ogrn'] ?? '',
            'legal_address' => $client['legal_address'] ?? '',
            'postal_address' => $client['postal_address'] ?? '',
            'signer_name' => $client['signer_name'] ?? '',
            'signer_position' => $client['signer_position'] ?? '',
            'signer_authority' => $client['signer_authority'] ?? '',
            'details_text' => $client['legal_details_text'] ?? '',
        ],
        'bank' => [
            'name' => $client['bank_name'] ?? '',
            'bik' => $client['bik'] ?? '',
            'checking_account' => $client['checking_account'] ?? '',
            'correspondent_account' => $client['correspondent_account'] ?? '',
            'details_text' => $client['bank_details_text'] ?? '',
        ],
        'contact' => [
            'primary_name' => $latestContact['name'] ?? '',
            'primary_email' => $latestContact['email'] ?? ($client['email'] ?? ''),
            'primary_phone' => $latestContact['phone'] ?? ($client['phone'] ?? ''),
            'primary_position' => $latestContact['position'] ?? '',
        ],
        'latest_deal' => [
            'title' => $latestDeal['title'] ?? '',
            'amount' => $latestDeal['amount'] ?? '',
            'currency' => $latestDeal['currency'] ?? 'RUB',
            'stage' => $latestDeal['stage_name'] ?? '',
            'probability' => $latestDeal['probability'] ?? '',
            'expected_close_date' => $latestDeal['expected_close_date'] ?? '',
        ],
        'latest_task' => [
            'title' => $latestTask['title'] ?? '',
            'status' => $latestTask['status'] ?? '',
            'priority' => $latestTask['priority'] ?? '',
            'deadline' => $latestTask['deadline'] ?? '',
            'project_name' => $latestTask['project_name'] ?? '',
            'assignee_name' => $latestTask['assignee_name'] ?? '',
        ],
        'stats' => [
            'contacts_count' => count($contacts),
            'deals_count' => count($deals),
            'tasks_count' => count($tasks),
        ],
        'tasks' => [
            'items' => $tasks,
            'table' => documentsRenderTable($tasks, [
                'title' => 'Задача',
                'status' => 'Статус',
                'deadline' => 'Срок',
                'assignee_name' => 'Ответственный',
            ]),
            'json' => json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'deals' => [
            'items' => $deals,
            'table' => documentsRenderTable($deals, [
                'title' => 'Сделка',
                'stage_name' => 'Этап',
                'amount' => 'Сумма',
                'expected_close_date' => 'Закрытие',
            ]),
            'json' => json_encode($deals, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'contacts' => [
            'items' => $contacts,
            'table' => documentsRenderTable($contacts, [
                'name' => 'Контакт',
                'position' => 'Должность',
                'email' => 'Email',
                'phone' => 'Телефон',
            ]),
            'json' => json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'activity' => [
            'items' => $activity,
            'table' => documentsRenderTable($activity, [
                'created_at' => 'Дата',
                'action' => 'Действие',
                'message' => 'Сообщение',
            ]),
            'json' => json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'system' => [
            'generated_at' => date('d.m.Y H:i'),
            'generated_date' => date('d.m.Y'),
            'generated_time' => date('H:i'),
        ],
    ];
}

function documentsRenderAndSave(PDO $pdo, array $template, array $context, int $clientId, int $userId, string $mode, array $source = []): array {
    if (($template['output_format'] ?? 'html') === 'docx') {
        return documentsRenderAndSaveDocx($pdo, $template, $context, $clientId, $userId, $mode, $source);
    }

    $html = documentsRenderTemplate((string)$template['content'], $context);
    $safeClientName = documentsSlugify((string)($context['client']['name'] ?? ('client-' . $clientId)));
    $safeTemplateName = documentsSlugify((string)$template['name']);
    $basename = date('Ymd-His') . '-' . $safeClientName . '-' . $safeTemplateName . '.html';
    $fullPath = documentsStoragePath() . '/' . $basename;
    file_put_contents($fullPath, $html);

    $size = is_file($fullPath) ? (int)filesize($fullPath) : 0;
    $stmt = $pdo->prepare("INSERT INTO document_generations (template_id, client_id, mode, source_entity_type, source_entity_id, file_name, file_path, mime_type, size_bytes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'text/html', ?, ?)");
    $stmt->execute([(int)$template['id'], $clientId, $mode, $source['source_entity_type'] ?? null, $source['source_entity_id'] ?? null, $basename, documentsPublicPath($basename), $size, $userId]);

    return [
        'generation_id' => (int)$pdo->lastInsertId(),
        'template_id' => (int)$template['id'],
        'template_name' => $template['name'],
        'file_name' => $basename,
        'file_url' => documentsPublicPath($basename),
        'size_bytes' => $size,
        'mode' => $mode,
        'mode_label' => documentsGenerationModeLabel($mode),
        'client_id' => $clientId,
        'client_name' => (string)($context['client']['name'] ?? ('Клиент #' . $clientId)),
        'created_at' => date('Y-m-d H:i:s'),
        'source_entity_type' => $source['source_entity_type'] ?? null,
        'source_entity_id' => $source['source_entity_id'] ?? null,
        'preview_html' => $html,
    ];
}

function documentsRenderAndSaveDocx(PDO $pdo, array $template, array $context, int $clientId, int $userId, string $mode, array $source = []): array {
    $sourcePath = documentsResolveTemplateSourcePath($template);
    if ($sourcePath === null || !is_file($sourcePath)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Исходный DOCX-файл шаблона не найден. Проверьте папку docs.']);
        exit;
    }

    $safeClientName = documentsSlugify((string)($context['client']['name'] ?? ('client-' . $clientId)));
    $safeTemplateName = documentsSlugify((string)$template['name']);
    $basename = date('Ymd-His') . '-' . $safeClientName . '-' . $safeTemplateName . '.docx';
    $fullPath = documentsStoragePath() . '/' . $basename;

    if (!@copy($sourcePath, $fullPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Не удалось создать копию DOCX-шаблона']);
        exit;
    }

    $replacement = documentsApplyDocxTokenReplacement($fullPath, $context);
    if (!$replacement['success']) {
        @unlink($fullPath);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $replacement['error'] ?: 'Не удалось обработать DOCX-шаблон']);
        exit;
    }

    $size = is_file($fullPath) ? (int)filesize($fullPath) : 0;
    $stmt = $pdo->prepare("INSERT INTO document_generations (template_id, client_id, mode, source_entity_type, source_entity_id, file_name, file_path, mime_type, size_bytes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ?, ?)");
    $stmt->execute([(int)$template['id'], $clientId, $mode, $source['source_entity_type'] ?? null, $source['source_entity_id'] ?? null, $basename, documentsPublicPath($basename), $size, $userId]);

    return [
        'generation_id' => (int)$pdo->lastInsertId(),
        'template_id' => (int)$template['id'],
        'template_name' => $template['name'],
        'file_name' => $basename,
        'file_url' => documentsPublicPath($basename),
        'size_bytes' => $size,
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'mode' => $mode,
        'mode_label' => documentsGenerationModeLabel($mode),
        'client_id' => $clientId,
        'client_name' => (string)($context['client']['name'] ?? ('Клиент #' . $clientId)),
        'created_at' => date('Y-m-d H:i:s'),
        'source_entity_type' => $source['source_entity_type'] ?? null,
        'source_entity_id' => $source['source_entity_id'] ?? null,
        'preview_html' => null,
        'generation_note' => $replacement['note'],
        'docx_replacements' => [
            'files_processed' => $replacement['files_processed'],
            'tokens_replaced' => $replacement['tokens_replaced'],
        ],
    ];
}

function documentsApplyDocxTokenReplacement(string $docxPath, array $context): array {
    if (!class_exists('ZipArchive')) {
        return [
            'success' => true,
            'tokens_replaced' => 0,
            'files_processed' => 0,
            'note' => 'DOCX-этап MVP: создана копия исходного файла. Замена токенов не выполнена, потому что на сервере недоступен ZipArchive.',
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) {
        return [
            'success' => false,
            'error' => 'Не удалось открыть DOCX-архив для замены токенов.',
        ];
    }

    $targets = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if ($name === 'word/document.xml' || preg_match('#^word/(header|footer)[0-9]*\.xml$#', $name) || in_array($name, ['word/footnotes.xml', 'word/endnotes.xml'], true)) {
            $targets[] = $name;
        }
    }

    $map = documentsBuildDocxTokenMap($context);
    $tokensReplaced = 0;
    $filesProcessed = 0;
    $runAwareReplacements = 0;

    foreach ($targets as $entryName) {
        $xml = $zip->getFromName($entryName);
        if (!is_string($xml) || $xml === '') {
            continue;
        }

        $updated = $xml;
        foreach ($map as $token => $replacement) {
            if ($token === '' || !str_contains($updated, $token)) {
                continue;
            }

            $count = 0;
            $updated = str_replace($token, $replacement, $updated, $count);
            $tokensReplaced += (int)$count;
        }

        $updated = documentsReplaceDocxTokensAcrossRuns($updated, $map, $tokensReplaced, $runAwareReplacements);

        if ($updated !== $xml) {
            $zip->addFromString($entryName, $updated);
        }

        $filesProcessed++;
    }

    $zip->close();

    return [
        'success' => true,
        'tokens_replaced' => $tokensReplaced,
        'files_processed' => $filesProcessed,
        'run_aware_replacements' => $runAwareReplacements,
        'note' => $tokensReplaced > 0
            ? ($runAwareReplacements > 0
                ? 'DOCX MVP+: выполнена замена простых токенов в основных XML-файлах документа, включая часть токенов, разбитых на соседние Word text/run-узлы внутри одного абзаца.'
                : 'DOCX MVP+: выполнена замена простых токенов в основных XML-файлах документа.')
            : 'DOCX MVP+: исходный DOCX подготовлен, но подходящие текстовые токены в основных XML-файлах не найдены.',
    ];
}

function documentsReplaceDocxTokensAcrossRuns(string $xml, array $map, int &$tokensReplaced, int &$runAwareReplacements): string {
    if ($xml === '' || !$map || !preg_match('#<w:p\b#', $xml)) {
        return $xml;
    }

    $sortedMap = $map;
    uksort($sortedMap, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });

    return preg_replace_callback('#<w:p\b[^>]*>.*?</w:p>#s', static function (array $matches) use ($sortedMap, &$tokensReplaced, &$runAwareReplacements): string {
        $paragraphXml = (string)($matches[0] ?? '');
        if ($paragraphXml === '' || !preg_match_all('#<w:t\b[^>]*>.*?</w:t>#s', $paragraphXml, $textMatches, PREG_OFFSET_CAPTURE)) {
            return $paragraphXml;
        }

        $segments = [];
        $combinedText = '';
        foreach ($textMatches[0] as $index => $matchInfo) {
            $fullNode = (string)($matchInfo[0] ?? '');
            $nodeOffset = (int)($matchInfo[1] ?? 0);
            if (!preg_match('#^(<w:t\b[^>]*>)(.*)(</w:t>)$#s', $fullNode, $parts)) {
                continue;
            }

            $decodedText = documentsDecodeDocxText((string)($parts[2] ?? ''));
            $segments[] = [
                'index' => $index,
                'offset' => $nodeOffset,
                'length' => strlen($fullNode),
                'open' => (string)($parts[1] ?? '<w:t>'),
                'text' => $decodedText,
                'close' => (string)($parts[3] ?? '</w:t>'),
                'start' => strlen($combinedText),
            ];
            $combinedText .= $decodedText;
        }

        if (!$segments || $combinedText === '' || !str_contains($combinedText, '{{')) {
            return $paragraphXml;
        }

        $occurrences = [];
        foreach ($sortedMap as $token => $replacement) {
            if ($token === '' || !str_contains($combinedText, $token)) {
                continue;
            }

            $searchOffset = 0;
            while (($pos = strpos($combinedText, $token, $searchOffset)) !== false) {
                $occurrences[] = [
                    'start' => $pos,
                    'end' => $pos + strlen($token),
                    'token' => $token,
                    'replacement' => $replacement,
                ];
                $searchOffset = $pos + strlen($token);
            }
        }

        if (!$occurrences) {
            return $paragraphXml;
        }

        usort($occurrences, static function (array $a, array $b): int {
            if ($a['start'] === $b['start']) {
                return strlen((string)$b['token']) <=> strlen((string)$a['token']);
            }
            return $a['start'] <=> $b['start'];
        });

        $selected = [];
        $cursor = -1;
        foreach ($occurrences as $occurrence) {
            if ($occurrence['start'] < $cursor) {
                continue;
            }
            $selected[] = $occurrence;
            $cursor = $occurrence['end'];
        }

        if (!$selected) {
            return $paragraphXml;
        }

        $segmentTexts = [];
        foreach ($segments as $segment) {
            $segmentTexts[$segment['index']] = $segment['text'];
        }

        $paragraphChanged = false;
        for ($i = count($selected) - 1; $i >= 0; $i--) {
            $occurrence = $selected[$i];
            $startInfo = documentsLocateDocxTextSegment($segments, (int)$occurrence['start']);
            $endInfo = documentsLocateDocxTextSegment($segments, (int)$occurrence['end'] - 1);
            if ($startInfo === null || $endInfo === null) {
                continue;
            }

            $firstIndex = $startInfo['segment']['index'];
            $lastIndex = $endInfo['segment']['index'];
            $firstText = (string)($segmentTexts[$firstIndex] ?? '');
            $lastText = (string)($segmentTexts[$lastIndex] ?? '');
            $prefix = substr($firstText, 0, $startInfo['inner_offset']);
            $suffix = substr($lastText, $endInfo['inner_offset'] + 1);

            $segmentTexts[$firstIndex] = $prefix . (string)$occurrence['replacement'] . ($firstIndex === $lastIndex ? $suffix : '');
            if ($firstIndex !== $lastIndex) {
                $segmentTexts[$lastIndex] = $suffix;
                for ($segmentIndex = $firstIndex + 1; $segmentIndex < $lastIndex; $segmentIndex++) {
                    $segmentTexts[$segmentIndex] = '';
                }
                $runAwareReplacements++;
            }

            $tokensReplaced++;
            $paragraphChanged = true;
        }

        if (!$paragraphChanged) {
            return $paragraphXml;
        }

        $rebuilt = $paragraphXml;
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $segment = $segments[$i];
            $replacementNode = $segment['open']
                . documentsEscapeDocxText((string)($segmentTexts[$segment['index']] ?? ''))
                . $segment['close'];
            $rebuilt = substr_replace($rebuilt, $replacementNode, $segment['offset'], $segment['length']);
        }

        return $rebuilt;
    }, $xml) ?? $xml;
}

function documentsLocateDocxTextSegment(array $segments, int $position): ?array {
    foreach ($segments as $segment) {
        $text = (string)($segment['text'] ?? '');
        $start = (int)($segment['start'] ?? 0);
        $length = strlen($text);
        if ($length <= 0) {
            continue;
        }

        $end = $start + $length - 1;
        if ($position >= $start && $position <= $end) {
            return [
                'segment' => $segment,
                'inner_offset' => $position - $start,
            ];
        }
    }

    return null;
}

function documentsBuildDocxTokenMap(array $context): array {
    $flat = documentsFlattenDocxContext($context);
    $map = [];
    foreach ($flat as $key => $value) {
        $escapedValue = documentsEscapeDocxText($value);
        foreach (documentsBuildDocxTokenAliases($key) as $tokenAlias) {
            $map[$tokenAlias] = $escapedValue;
        }
    }
    return $map;
}

function documentsBuildDocxTokenAliases(string $key): array {
    $key = trim($key);
    if ($key === '') {
        return [];
    }

    $aliases = [
        '{{' . $key . '}}',
        '{{ ' . $key . ' }}',
        '{{' . $key . ' }}',
        '{{ ' . $key . '}}',
    ];

    return array_values(array_unique($aliases));
}

function documentsFlattenDocxContext(array $value, string $prefix = ''): array {
    $result = [];
    foreach ($value as $key => $item) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($item)) {
            $isSequential = array_keys($item) === range(0, count($item) - 1);
            if ($isSequential) {
                continue;
            }
            $result = array_merge($result, documentsFlattenDocxContext($item, $path));
            continue;
        }

        if (is_object($item)) {
            continue;
        }

        $result[$path] = (string)($item ?? '');
    }
    return $result;
}

function documentsEscapeDocxText(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function documentsDecodeDocxText(string $value): string {
    return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function documentsDocxCatalogProfiles(): array {
    return [
        'Коммерческое_предложение_пример.docx' => [
            'priority' => 90,
            'priority_label' => 'Приоритет: КП для ручной адаптации',
            'readiness' => 'limited',
            'readiness_label' => 'Сложный макет, адаптация вручную',
            'short_description' => 'Дизайнерский DOCX из docs: подходит как база для ручной адаптации, но сейчас не содержит CRM-токенов и требует аккуратной токенизации в Word.',
            'practical_use' => 'Подходит как визуальная база для индивидуально подготовленного КП после ручной вставки токенов в копию шаблона.',
            'studied_basis' => 'Частично изучен по реальному XML word/document.xml: подтверждены статичный коммерческий текст, подпись менеджера, телефон, продуктовые позиции и расчетная таблица; файл перегружен графикой и shape/VML-узлами.',
            'recommended_tokens' => [
                ['token' => '{{client.display_name_for_documents}}', 'purpose' => 'Имя клиента в обращении, титульном блоке и персонализации КП.'],
                ['token' => '{{client.signer_name}}', 'purpose' => 'Контактное лицо клиента в персонализированном сопроводительном тексте.'],
                ['token' => '{{client.signer_position}}', 'purpose' => 'Должность контактного лица, если КП адресуется конкретному ЛПР.'],
                ['token' => '{{client.email}}', 'purpose' => 'Email адресата или строка для обратной связи в адаптированной версии КП.'],
                ['token' => '{{client.phone}}', 'purpose' => 'Телефон клиента в персонализированных контактных блоках.'],
                ['token' => '{{company.short_name}}', 'purpose' => 'Краткое название вашей компании, если макет переводится на другую организацию.'],
                ['token' => '{{company.signer_name}}', 'purpose' => 'Подпись, ФИО менеджера или руководителя в финальном блоке КП.'],
                ['token' => '{{company.signer_position}}', 'purpose' => 'Должность подписанта или автора коммерческого предложения.'],
                ['token' => '{{contact.primary_phone}}', 'purpose' => 'Рабочий телефон менеджера в нижнем контактном блоке.'],
                ['token' => '{{system.generated_date}}', 'purpose' => 'Дата подготовки коммерческого предложения.'],
            ],
            'token_notes' => [
                'Это не токенизированный шаблон, а готовый дизайнерский макет с большим количеством встроенной графики.',
                'Основной сценарий - сделать копию DOCX и вручную заменить только безопасные текстовые зоны без фигур и сложных врезок.',
                'Для этого шаблона безопаснее всего токенизировать адресный блок, дату, подпись, телефон и email, не трогая графические карточки и продуктовые врезки.',
                'Ценовую таблицу и продуктовые позиции лучше редактировать вручную внутри Word: текущий DOCX MVP не умеет строить такие блоки автоматически.',
            ],
            'limitations' => [
                'В изученном файле не найдено CRM-токенов вида {{...}}.',
                'Макет большой и оформленный, поэтому Word может часто дробить текст на много run-узлов.',
                'Перед боевым использованием стоит проверить каждое вставленное поле вручную в Word.',
            ],
        ],
        'Спецификация шаблон.docx' => [
            'priority' => 40,
            'priority_label' => 'Ниже приоритета: спецификация',
            'readiness' => 'partial',
            'readiness_label' => 'Годится для фиксированной спецификации',
            'short_description' => 'Реальная спецификация из docs: реквизитные блоки можно адаптировать под токены, но товарная таблица сейчас статическая и не строится из CRM автоматически.',
            'practical_use' => 'Практически usable для фиксированного набора позиций или как полу-ручной шаблон с заменой реквизитов.',
            'studied_basis' => 'Изучен как реальная спецификация к договору: роли Покупатель/Поставщик читаются, реквизитные блоки сторон статичны, товарная часть оформлена как уже заполненная таблица.',
            'recommended_tokens' => [
                ['token' => '{{client.display_name_for_documents}}', 'purpose' => 'Наименование покупателя в шапке и реквизитах.'],
                ['token' => '{{client.legal_name_full}}', 'purpose' => 'Полное наименование покупателя для юридических формулировок.'],
                ['token' => '{{client.inn}}', 'purpose' => 'ИНН покупателя в реквизитах.'],
                ['token' => '{{client.kpp}}', 'purpose' => 'КПП покупателя в реквизитах.'],
                ['token' => '{{client.legal_address}}', 'purpose' => 'Юридический адрес покупателя.'],
                ['token' => '{{client.signer_name}}', 'purpose' => 'ФИО подписанта покупателя.'],
                ['token' => '{{client.signer_position}}', 'purpose' => 'Должность подписанта покупателя.'],
                ['token' => '{{client.signer_authority}}', 'purpose' => 'Основание полномочий покупателя.'],
                ['token' => '{{bank.name}}', 'purpose' => 'Банк покупателя.'],
                ['token' => '{{bank.bik}}', 'purpose' => 'БИК банка покупателя.'],
                ['token' => '{{bank.checking_account}}', 'purpose' => 'Расчетный счет покупателя.'],
                ['token' => '{{bank.correspondent_account}}', 'purpose' => 'Корсчет банка покупателя.'],
                ['token' => '{{company.full_name}}', 'purpose' => 'Наименование поставщика, если шаблон переводится на другую компанию.'],
                ['token' => '{{company.details_text}}', 'purpose' => 'Краткий блок реквизитов поставщика одной строкой.'],
            ],
            'token_notes' => [
                'Таблица товаров остается ручной: строки номенклатуры, количество и суммы нужно заполнять в самом DOCX.',
                'Лучший сценарий - токенизировать только реквизитные и подписные блоки, не трогая разметку таблицы.',
            ],
            'limitations' => [
                'Товарные строки уже заполнены и не поддерживают циклы или автоповтор строк.',
                'Для массово меняющегося состава товаров шаблон нужно править вручную.',
            ],
        ],
        'Шаблон договора ИП в ворд.docx' => [
            'priority' => 80,
            'priority_label' => 'Высокий приоритет: договор ИП',
            'readiness' => 'adaptable',
            'readiness_label' => 'Практически пригоден после токенизации',
            'short_description' => 'Реальный договор ИП из docs: хорошо подходит как рабочая основа после замены захардкоженных реквизитов и пустых полей на CRM-токены.',
            'practical_use' => 'Один из самых практичных DOCX-шаблонов для договоров после точечной вставки токенов в Word.',
            'studied_basis' => 'Изучен как договор поставки с зашитым поставщиком ИП Мазанов и пустыми полями у покупателя; присутствует полный реквизитный блок и типовые подписные зоны.',
            'recommended_tokens' => [
                ['token' => '{{system.generated_date}}', 'purpose' => 'Дата договора в шапке.'],
                ['token' => '{{company.full_name}}', 'purpose' => 'Поставщик: полное наименование ИП/компании.'],
                ['token' => '{{company.inn}}', 'purpose' => 'ИНН поставщика.'],
                ['token' => '{{company.ogrn}}', 'purpose' => 'ОГРНИП/ОГРН поставщика.'],
                ['token' => '{{company.legal_address}}', 'purpose' => 'Адрес поставщика.'],
                ['token' => '{{company.signer_name}}', 'purpose' => 'ФИО подписанта поставщика.'],
                ['token' => '{{company.signer_position}}', 'purpose' => 'Статус или роль поставщика в преамбуле и подписи.'],
                ['token' => '{{company.signer_authority}}', 'purpose' => 'Основание полномочий поставщика.'],
                ['token' => '{{bank.name}}', 'purpose' => 'Банк поставщика или основной реквизитный банк.'],
                ['token' => '{{bank.bik}}', 'purpose' => 'БИК поставщика.'],
                ['token' => '{{bank.checking_account}}', 'purpose' => 'Расчетный счет поставщика.'],
                ['token' => '{{bank.correspondent_account}}', 'purpose' => 'Корсчет поставщика.'],
                ['token' => '{{client.legal_name_full}}', 'purpose' => 'Покупатель: полное юридическое наименование.'],
                ['token' => '{{client.inn}}', 'purpose' => 'ИНН покупателя.'],
                ['token' => '{{client.kpp}}', 'purpose' => 'КПП покупателя.'],
                ['token' => '{{client.legal_address}}', 'purpose' => 'Юридический адрес покупателя.'],
                ['token' => '{{client.signer_name}}', 'purpose' => 'ФИО подписанта покупателя.'],
                ['token' => '{{client.signer_position}}', 'purpose' => 'Должность подписанта покупателя.'],
                ['token' => '{{client.signer_authority}}', 'purpose' => 'Основание полномочий покупателя.'],
            ],
            'token_notes' => [
                'Это лучший кандидат для быстрого запуска DOCX-договоров: меняются в основном преамбула, реквизиты и подписи.',
                'Пустые подчеркивания и ручные поля у покупателя стоит заменить на цельные токены без дробления внутри Word.',
            ],
            'limitations' => [
                'Сейчас в шаблоне зашиты реквизиты поставщика ИП.',
                'Покупатель во многих местах оставлен как пустое место или подчеркивание, а не CRM-токен.',
            ],
        ],
        'Шаблон договора с ООО.docx' => [
            'priority' => 100,
            'priority_label' => 'Текущий приоритет: основной договор',
            'readiness' => 'adaptable',
            'readiness_label' => 'Практически пригоден после токенизации',
            'short_description' => 'Реальный договор с ООО из docs: полный договорный текст уже есть, и шаблон удобно адаптировать заменой реквизитов и подписных блоков на CRM-токены.',
            'practical_use' => 'Хорошая практическая база для типового договора после замены статичных реквизитов на токены.',
            'studied_basis' => 'Изучен как договор поставки с зафиксированным поставщиком ООО «КосметикЛаб»; банковские реквизиты и часть полей контрагента вшиты в текст.',
            'recommended_tokens' => [
                ['token' => '{{system.generated_date}}', 'purpose' => 'Дата договора.'],
                ['token' => '{{company.full_name}}', 'purpose' => 'Поставщик: полное наименование ООО.'],
                ['token' => '{{company.short_name}}', 'purpose' => 'Поставщик: краткое наименование в повторяющихся блоках.'],
                ['token' => '{{company.inn}}', 'purpose' => 'ИНН поставщика.'],
                ['token' => '{{company.kpp}}', 'purpose' => 'КПП поставщика.'],
                ['token' => '{{company.ogrn}}', 'purpose' => 'ОГРН поставщика.'],
                ['token' => '{{company.legal_address}}', 'purpose' => 'Юридический адрес поставщика.'],
                ['token' => '{{company.signer_name}}', 'purpose' => 'ФИО подписанта поставщика.'],
                ['token' => '{{company.signer_position}}', 'purpose' => 'Должность подписанта поставщика.'],
                ['token' => '{{company.signer_authority}}', 'purpose' => 'Основание полномочий поставщика.'],
                ['token' => '{{company.details_text}}', 'purpose' => 'Сжатый блок реквизитов поставщика, если в шаблоне удобно заменить несколько строк одной безопасной строкой.'],
                ['token' => '{{bank.name}}', 'purpose' => 'Банк поставщика.'],
                ['token' => '{{bank.bik}}', 'purpose' => 'БИК банка поставщика.'],
                ['token' => '{{bank.checking_account}}', 'purpose' => 'Расчетный счет поставщика.'],
                ['token' => '{{bank.correspondent_account}}', 'purpose' => 'Корсчет поставщика.'],
                ['token' => '{{client.legal_name_full}}', 'purpose' => 'Полное наименование покупателя.'],
                ['token' => '{{client.display_name_for_documents}}', 'purpose' => 'Короткое наименование покупателя для преамбулы и повторов в тексте, если полное юр. имя слишком длинное.'],
                ['token' => '{{client.inn}}', 'purpose' => 'ИНН покупателя.'],
                ['token' => '{{client.kpp}}', 'purpose' => 'КПП покупателя.'],
                ['token' => '{{client.legal_address}}', 'purpose' => 'Адрес покупателя.'],
                ['token' => '{{client.signer_name}}', 'purpose' => 'ФИО подписанта покупателя.'],
                ['token' => '{{client.signer_position}}', 'purpose' => 'Должность подписанта покупателя.'],
                ['token' => '{{client.signer_authority}}', 'purpose' => 'Основание полномочий покупателя.'],
                ['token' => '{{client.legal_details_text}}', 'purpose' => 'Краткая строка с ИНН/КПП/ОГРН покупателя для компактных реквизитных блоков.'],
            ],
            'token_notes' => [
                'Шаблон практически готов для замены реквизитов обеих сторон на CRM-данные.',
                'Для преамбулы обычно достаточно пары {{company.full_name}} / {{client.legal_name_full}} и полномочий подписантов без ручных подчеркиваний.',
                'Если в документе встречаются визуальные подчеркивания вместо текста, лучше заменить их одним цельным токеном в соответствующей строке.',
            ],
            'limitations' => [
                'Сейчас в шаблоне зафиксированы данные ООО "КосметикЛаб" и банковские реквизиты.',
                'Часть полей покупателя оформлена пустыми местами, а не машинными маркерами.',
            ],
        ],
        'Шаблон счет-договор.docx' => [
            'priority' => 30,
            'priority_label' => 'Ниже приоритета: счет-договор',
            'readiness' => 'partial',
            'readiness_label' => 'Пригоден для счет-договора с ручной таблицей',
            'short_description' => 'Реальный счет-договор из docs: реквизиты и шапка адаптируются, но продуктовая таблица остается ручной без поддержки циклов.',
            'practical_use' => 'Практически usable для типовых счет-договоров, если позиции не нужно собирать автоматически из CRM.',
            'studied_basis' => 'Изучен как счет-договор с зашитым поставщиком ИП Мазанов, пустым покупателем и ручной таблицей товаров/сумм.',
            'recommended_tokens' => [
                ['token' => '{{system.generated_date}}', 'purpose' => 'Дата счета-договора.'],
                ['token' => '{{company.full_name}}', 'purpose' => 'Поставщик: полное наименование.'],
                ['token' => '{{company.inn}}', 'purpose' => 'ИНН поставщика.'],
                ['token' => '{{company.ogrn}}', 'purpose' => 'ОГРН/ОГРНИП поставщика.'],
                ['token' => '{{company.legal_address}}', 'purpose' => 'Адрес поставщика.'],
                ['token' => '{{company.signer_name}}', 'purpose' => 'ФИО подписанта поставщика.'],
                ['token' => '{{company.signer_position}}', 'purpose' => 'Роль или должность подписанта поставщика.'],
                ['token' => '{{bank.name}}', 'purpose' => 'Банк поставщика.'],
                ['token' => '{{bank.bik}}', 'purpose' => 'БИК поставщика.'],
                ['token' => '{{bank.checking_account}}', 'purpose' => 'Расчетный счет поставщика.'],
                ['token' => '{{bank.correspondent_account}}', 'purpose' => 'Корсчет поставщика.'],
                ['token' => '{{client.legal_name_full}}', 'purpose' => 'Покупатель: полное наименование.'],
                ['token' => '{{client.inn}}', 'purpose' => 'ИНН покупателя.'],
                ['token' => '{{client.kpp}}', 'purpose' => 'КПП покупателя.'],
                ['token' => '{{client.legal_address}}', 'purpose' => 'Адрес покупателя.'],
                ['token' => '{{client.signer_name}}', 'purpose' => 'ФИО подписанта покупателя.'],
            ],
            'token_notes' => [
                'Реквизиты сторон и шапка хорошо подходят для токенизации.',
                'Строки товаров, количество, цена и итоговая сумма остаются ручными: текущий DOCX MVP не поддерживает циклы и расчетные таблицы.',
            ],
            'limitations' => [
                'Товарная таблица в текущем решении не наполняется автоматически строками из CRM.',
                'Реквизиты поставщика сейчас захардкожены под ИП.',
            ],
        ],
    ];
}

function documentsDocxCatalogProfile(string $fileName): array {
    $profiles = documentsDocxCatalogProfiles();
    return $profiles[$fileName] ?? [
        'priority' => 0,
        'priority_label' => '',
        'readiness' => 'unknown',
        'readiness_label' => 'Нужна ручная проверка',
        'short_description' => 'Реальный DOCX-шаблон из папки docs. Поддерживается легкая замена простых текстовых токенов в основных XML-файлах, включая часть токенов, разбитых на соседние Word run/text-узлы внутри одного абзаца.',
        'practical_use' => 'Требует ручной проверки структуры и токенов перед практическим использованием.',
        'studied_basis' => 'Файл не разобран заранее и требует ручной проверки структуры DOCX.',
        'recommended_tokens' => [],
        'token_notes' => [
            'Для неизвестного DOCX сначала проверьте, что токены вставлены как обычный текст внутри paragraph/run, а не в фигуры и нестандартные поля Word.',
        ],
        'limitations' => [
            'Не все Word-конструкции и не все варианты разбиения текста поддерживаются автоматически.',
        ],
    ];
}

function documentsEnrichTemplateRuntimeMeta(array $template): array {
    $format = (string)($template['output_format'] ?? 'html');
    if ($format !== 'docx') {
        return $template;
    }

    $sourcePath = documentsNormalizeTemplateSourcePath((string)($template['source_path'] ?? ''));
    $fileName = basename($sourcePath);
    $profile = documentsDocxCatalogProfile($fileName);
    $template['docx_priority'] = (int)($profile['priority'] ?? 0);
    $template['docx_priority_label'] = (string)($profile['priority_label'] ?? '');
    $template['docx_readiness'] = $profile['readiness'];
    $template['docx_readiness_label'] = $profile['readiness_label'];
    $template['docx_practical_use'] = $profile['practical_use'];
    $template['docx_limitations'] = $profile['limitations'];
    $template['docx_recommended_tokens'] = $profile['recommended_tokens'];
    $template['docx_token_notes'] = $profile['token_notes'];
    $template['docx_studied_basis'] = $profile['studied_basis'];
    $template['docx_studied'] = isset(documentsDocxCatalogProfiles()[$fileName]);
    if (empty($template['description'])) {
        $template['description'] = $profile['short_description'];
    }
    return $template;
}

function documentsCompareTemplatesForList(array $a, array $b): int {
    $aDocx = (($a['output_format'] ?? 'html') === 'docx') ? 1 : 0;
    $bDocx = (($b['output_format'] ?? 'html') === 'docx') ? 1 : 0;
    if ($aDocx !== $bDocx) {
        return $bDocx <=> $aDocx;
    }

    $aPriority = (int)($a['docx_priority'] ?? 0);
    $bPriority = (int)($b['docx_priority'] ?? 0);
    if ($aPriority !== $bPriority) {
        return $bPriority <=> $aPriority;
    }

    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
}

function documentsEnsureTemplateColumns(PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM document_templates") as $column) {
        $columns[] = $column['Field'] ?? '';
    }

    if (!in_array('source_origin', $columns, true)) {
        $pdo->exec("ALTER TABLE document_templates ADD COLUMN source_origin VARCHAR(20) NOT NULL DEFAULT 'inline' AFTER output_format");
    }

    if (!in_array('source_path', $columns, true)) {
        $pdo->exec("ALTER TABLE document_templates ADD COLUMN source_path VARCHAR(500) NULL AFTER source_origin");
    }
}

function documentsFindAnyTemplateById(PDO $pdo, int $templateId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE id=? LIMIT 1");
    $stmt->execute([$templateId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function documentsNormalizeTemplateSourcePath(string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    return $path;
}

function documentsResolveTemplateSourcePath(array $template): ?string {
    $relative = documentsNormalizeTemplateSourcePath((string)($template['source_path'] ?? ''));
    if ($relative === '') {
        return null;
    }

    $fullPath = dirname(__DIR__) . '/' . $relative;
    return is_file($fullPath) ? $fullPath : null;
}

function documentsEnsureGenerationColumns(PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM document_generations") as $column) {
        $columns[] = $column['Field'] ?? '';
    }

    if (!in_array('source_entity_type', $columns, true)) {
        $pdo->exec("ALTER TABLE document_generations ADD COLUMN source_entity_type VARCHAR(50) NULL AFTER mode");
    }

    if (!in_array('source_entity_id', $columns, true)) {
        $pdo->exec("ALTER TABLE document_generations ADD COLUMN source_entity_id INT NULL AFTER source_entity_type");
    }

    $indexes = [];
    foreach ($pdo->query("SHOW INDEX FROM document_generations") as $index) {
        $indexes[] = $index['Key_name'] ?? '';
    }

    if (!in_array('idx_document_generations_source', $indexes, true)) {
        $pdo->exec("ALTER TABLE document_generations ADD INDEX idx_document_generations_source (source_entity_type, source_entity_id)");
    }
}

function documentsNormalizeSourceEntityType(mixed $value): ?string {
    $allowed = ['task', 'deal', 'client', 'project'];
    $normalized = trim(mb_strtolower((string)($value ?? ''), 'UTF-8'));
    return in_array($normalized, $allowed, true) ? $normalized : null;
}

function documentsGenerationModeLabel(string $mode): string {
    return match ($mode) {
        'single' => 'Одиночный документ',
        'batch' => 'Документ из пакета',
        'batch_zip' => 'ZIP-пакет',
        'batch_manifest' => 'Fallback-пакет',
        default => $mode,
    };
}

function documentsRenderTemplate(string $template, array $context): string {
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($matches) use ($context) {
        $path = $matches[1] ?? '';
        $value = documentsGetValueByPath($context, $path);
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return (string)($value ?? '');
    }, $template) ?? $template;
}

function documentsGetValueByPath(array $context, string $path): mixed {
    $segments = explode('.', $path);
    $value = $context;
    foreach ($segments as $segment) {
        if (is_array($value) && array_key_exists($segment, $value)) {
            $value = $value[$segment];
            continue;
        }
        return '';
    }
    return $value;
}

function documentsFieldCatalog(): array {
    return [
        [
            'title' => 'Клиент',
            'fields' => [
                documentsFieldSpec('client.id', 'ID клиента', 'both', 'Служебный идентификатор клиента.'),
                documentsFieldSpec('client.name', 'Имя / компания', 'both', 'Короткое имя клиента из CRM.'),
                documentsFieldSpec('client.type_label', 'Тип клиента', 'both', 'Компания или физлицо.'),
                documentsFieldSpec('client.status', 'Код статуса', 'both', 'Системное значение статуса.'),
                documentsFieldSpec('client.status_label', 'Статус', 'both', 'Человекочитаемый статус клиента.'),
                documentsFieldSpec('client.email', 'Email', 'both', 'Основной email клиента.'),
                documentsFieldSpec('client.phone', 'Телефон', 'both', 'Основной телефон клиента.'),
                documentsFieldSpec('client.site', 'Сайт', 'both', 'Сайт клиента.'),
                documentsFieldSpec('client.address', 'Адрес', 'both', 'Свободный адрес клиента.'),
                documentsFieldSpec('client.display_name_for_documents', 'Имя для документов', 'both', 'Рекомендуемое имя для КП, договоров и карточек клиента.'),
                documentsFieldSpec('client.legal_name_full', 'Полное юр. наименование', 'both', 'Исходное юридическое наименование из карточки клиента.'),
                documentsFieldSpec('client.legal_name_short', 'Краткое наименование', 'both', 'Сокращенное юр. наименование.'),
                documentsFieldSpec('client.inn', 'ИНН', 'both', 'ИНН клиента.'),
                documentsFieldSpec('client.kpp', 'КПП', 'both', 'КПП клиента.'),
                documentsFieldSpec('client.ogrn', 'ОГРН / ОГРНИП', 'both', 'ОГРН или ОГРНИП клиента.'),
                documentsFieldSpec('client.legal_address', 'Юридический адрес', 'both', 'Юридический адрес клиента.'),
                documentsFieldSpec('client.postal_address', 'Почтовый адрес', 'both', 'Почтовый адрес клиента.'),
                documentsFieldSpec('client.signer_name', 'ФИО подписанта', 'both', 'Для договоров и подписных блоков.'),
                documentsFieldSpec('client.signer_position', 'Должность подписанта', 'both', 'Например: генеральный директор.'),
                documentsFieldSpec('client.signer_authority', 'Основание полномочий', 'both', 'Например: действует на основании устава.'),
                documentsFieldSpec('client.bank_name', 'Банк', 'both', 'Наименование банка клиента.'),
                documentsFieldSpec('client.bik', 'БИК', 'both', 'БИК банка клиента.'),
                documentsFieldSpec('client.checking_account', 'Расчетный счет', 'both', 'Расчетный счет клиента.'),
                documentsFieldSpec('client.correspondent_account', 'Корреспондентский счет', 'both', 'Корсчет банка клиента.'),
                documentsFieldSpec('client.signer_display', 'Подписант строкой', 'both', 'Готовая строка с ФИО и должностью.'),
                documentsFieldSpec('client.bank_details_text', 'Банковские реквизиты строкой', 'both', 'Готовая строка для кратких документов и карточек.'),
                documentsFieldSpec('client.legal_details_text', 'Юр. реквизиты строкой', 'both', 'Краткая строка с ИНН, КПП и ОГРН.'),
                documentsFieldSpec('client.notes', 'Заметки', 'html', 'Свободный текст; чаще полезен в HTML-шаблонах.'),
                documentsFieldSpec('client.owner_name', 'Ответственный', 'both', 'Ответственный менеджер.'),
                documentsFieldSpec('client.tags_text', 'Теги строкой', 'both', 'Теги клиента одной строкой.'),
            ],
        ],
        [
            'title' => 'Юридические и банковские блоки',
            'fields' => [
                documentsFieldSpec('company.full_name', 'Компания: полное наименование', 'both', 'Основное поле для договоров, счетов и КП.'),
                documentsFieldSpec('company.short_name', 'Компания: краткое наименование', 'both', 'Удобно для преамбулы и таблиц реквизитов.'),
                documentsFieldSpec('company.inn', 'Компания: ИНН', 'both', 'Рекомендуется для договоров и карточек клиента.'),
                documentsFieldSpec('company.kpp', 'Компания: КПП', 'both', 'Рекомендуется для договоров и карточек клиента.'),
                documentsFieldSpec('company.ogrn', 'Компания: ОГРН / ОГРНИП', 'both', 'Юридический идентификатор клиента.'),
                documentsFieldSpec('company.legal_address', 'Компания: юридический адрес', 'both', 'Для договора и карточки клиента.'),
                documentsFieldSpec('company.postal_address', 'Компания: почтовый адрес', 'both', 'Для договорных и отправочных документов.'),
                documentsFieldSpec('company.signer_name', 'Компания: ФИО подписанта', 'both', 'Алиас для реквизитного блока компании.'),
                documentsFieldSpec('company.signer_position', 'Компания: должность подписанта', 'both', 'Алиас для реквизитного блока компании.'),
                documentsFieldSpec('company.signer_authority', 'Компания: основание полномочий', 'both', 'Алиас для реквизитного блока компании.'),
                documentsFieldSpec('company.details_text', 'Компания: реквизиты строкой', 'both', 'Краткая строка с названием, ИНН, КПП и ОГРН.'),
                documentsFieldSpec('bank.name', 'Банк: наименование', 'both', 'Рекомендуется для договоров и карточек клиента.'),
                documentsFieldSpec('bank.bik', 'Банк: БИК', 'both', 'Рекомендуется для реквизитных таблиц.'),
                documentsFieldSpec('bank.checking_account', 'Банк: расчетный счет', 'both', 'Расчетный счет клиента.'),
                documentsFieldSpec('bank.correspondent_account', 'Банк: корреспондентский счет', 'both', 'Корсчет банка клиента.'),
                documentsFieldSpec('bank.details_text', 'Банк: реквизиты строкой', 'both', 'Краткая строка с банковскими реквизитами.'),
            ],
        ],
        [
            'title' => 'Контакт и быстрые связанные поля',
            'fields' => [
                documentsFieldSpec('contact.primary_name', 'Основной контакт', 'both', 'Имя главного контакта клиента.'),
                documentsFieldSpec('contact.primary_email', 'Email контакта', 'both', 'Email главного контакта.'),
                documentsFieldSpec('contact.primary_phone', 'Телефон контакта', 'both', 'Телефон главного контакта.'),
                documentsFieldSpec('contact.primary_position', 'Должность контакта', 'both', 'Должность главного контакта.'),
                documentsFieldSpec('latest_deal.title', 'Последняя сделка', 'both', 'Быстрый коммерческий контекст клиента.'),
                documentsFieldSpec('latest_deal.amount', 'Сумма последней сделки', 'both', 'Сумма последней сделки.'),
                documentsFieldSpec('latest_deal.stage', 'Этап последней сделки', 'both', 'Этап последней сделки.'),
                documentsFieldSpec('latest_task.title', 'Последняя задача', 'both', 'Последняя задача по клиенту.'),
                documentsFieldSpec('latest_task.status', 'Статус последней задачи', 'both', 'Статус последней задачи.'),
            ],
        ],
        [
            'title' => 'Сводка и системные поля',
            'fields' => [
                documentsFieldSpec('stats.contacts_count', 'Количество контактов', 'both', 'Сводка по контактам клиента.'),
                documentsFieldSpec('stats.deals_count', 'Количество сделок', 'both', 'Сводка по сделкам клиента.'),
                documentsFieldSpec('stats.tasks_count', 'Количество задач', 'both', 'Сводка по задачам клиента.'),
                documentsFieldSpec('system.generated_at', 'Дата и время генерации', 'both', 'Момент формирования документа.'),
                documentsFieldSpec('system.generated_date', 'Дата генерации', 'both', 'Только дата формирования.'),
                documentsFieldSpec('system.generated_time', 'Время генерации', 'both', 'Только время формирования.'),
            ],
        ],
        [
            'title' => 'Табличные блоки',
            'fields' => [
                documentsFieldSpec('tasks.table', 'HTML-таблица задач', 'html', 'Полезно для HTML-режима; не рекомендуется для DOCX MVP.'),
                documentsFieldSpec('deals.table', 'HTML-таблица сделок', 'html', 'Полезно для HTML-режима; не рекомендуется для DOCX MVP.'),
                documentsFieldSpec('contacts.table', 'HTML-таблица контактов', 'html', 'Полезно для HTML-режима; не рекомендуется для DOCX MVP.'),
                documentsFieldSpec('activity.table', 'HTML-таблица истории', 'html', 'Полезно для HTML-режима; не рекомендуется для DOCX MVP.'),
                documentsFieldSpec('tasks.json', 'JSON задач', 'html', 'Удобно для отладочного HTML или выгрузок.'),
                documentsFieldSpec('deals.json', 'JSON сделок', 'html', 'Удобно для отладочного HTML или выгрузок.'),
                documentsFieldSpec('contacts.json', 'JSON контактов', 'html', 'Удобно для отладочного HTML или выгрузок.'),
            ],
        ],
    ];
}

function documentsFieldSpec(string $key, string $label, string $bestFor = 'both', string $description = ''): array {
    return [
        'key' => $key,
        'label' => $label,
        'best_for' => $bestFor,
        'description' => $description,
        'token' => '{{' . $key . '}}',
        'docx_supported' => $bestFor !== 'html',
    ];
}

function documentsDocxSupportInfo(): array {
    return [
        'enabled' => class_exists('ZipArchive'),
        'token_syntax' => '{{client.name}}',
        'supported_files' => ['word/document.xml', 'word/header*.xml', 'word/footer*.xml', 'word/footnotes.xml', 'word/endnotes.xml'],
        'recommended_token_groups' => ['client.*', 'company.*', 'bank.*', 'contact.primary_*', 'latest_*', 'stats.*', 'system.*'],
        'run_aware_scope' => 'Поддерживается замена части токенов, разбитых на соседние <w:t> / run-узлы внутри одного абзаца Word.',
        'studied_templates' => array_keys(documentsDocxCatalogProfiles()),
        'limitations' => [
            'Заменяются только простые текстовые токены в основных XML-файлах DOCX.',
            'Если токен разбит на соседние text/run-узлы внутри одного абзаца, система теперь пытается собрать и заменить его.',
            'Если токен разорван более сложной структурой Word, полями, фигурами, текстовыми блоками или уходит через границы абзацев, замена может не сработать.',
            'При замене токена, разбитого на несколько run-узлов, итоговый текст наследует форматирование первого затронутого текстового узла.',
            'HTML-таблицы и JSON-блоки предназначены для HTML-режима и не рекомендуются для DOCX.',
        ],
    ];
}

function documentsRenderTable(array $rows, array $columns): string {
    if (!$rows) {
        return '<div style="color:#6b7280">Нет данных</div>';
    }

    $html = '<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">';
    $html .= '<thead><tr>';
    foreach ($columns as $key => $label) {
        $html .= '<th style="text-align:left;border-bottom:1px solid #d1d5db;padding:8px;color:#374151">' . htmlspecialchars((string)$label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($columns as $key => $label) {
            $value = isset($row[$key]) ? (string)$row[$key] : '';
            $html .= '<td style="padding:8px;border-bottom:1px solid #eef2f7;color:#111827;vertical-align:top">' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

function documentsSlugify(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y',
        'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
        'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? $value : ('template-' . date('YmdHis'));
}

function documentsClientStatusLabel(string $status): string {
    return match ($status) {
        'active' => 'Активен',
        'lead' => 'Лид',
        'inactive' => 'Не активен',
        default => $status,
    };
}
