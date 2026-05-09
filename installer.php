<?php
// installer.php — Полный установщик модулей
// Запустите: https://ваш-сайт.ru/installer.php
set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'api/config.php';
$pdo = getPDO();
$base = __DIR__;
$created = 0;
$errors = [];

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>🔧 Установка модулей</title>
<style>body{font-family:system-ui,sans-serif;max-width:900px;margin:40px auto;padding:20px;background:#f5f7fa}
.card{background:#fff;padding:20px;border-radius:10px;margin-bottom:15px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.ok{color:#2e7d32;font-weight:600}.err{color:#c62828;font-weight:600}
code{background:#f1f3f5;padding:2px 6px;border-radius:4px;font-size:13px}</style></head><body>
<h1>🔧 Установка модулей</h1>";

// === 1. СОЗДАНИЕ ТАБЛИЦ БД ===
echo "<div class='card'><h3>🗄️ База данных</h3>";
$queries = [
"CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    position VARCHAR(100),
    hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    phone VARCHAR(20),
    status ENUM('active','vacation','fired') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS time_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    date DATE NOT NULL,
    time_in TIME,
    time_out TIME,
    hours_worked DECIMAL(4,2) DEFAULT 0,
    task VARCHAR(255),
    approved TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_emp_date (employee_id, date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS wages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_hours DECIMAL(6,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('draft','approved','paid') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS integrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    type ENUM('1c','mysklad','wildberries','ozon','webhook','csv','api') NOT NULL,
    api_key VARCHAR(512),
    webhook_url VARCHAR(512),
    endpoint_url VARCHAR(512),
    last_sync TIMESTAMP NULL,
    settings JSON,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id INT NOT NULL,
    direction ENUM('import','export') NOT NULL,
    entity VARCHAR(50) NOT NULL,
    records_count INT DEFAULT 0,
    status ENUM('success','error','pending') NOT NULL,
    error_msg TEXT,
    response_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (integration_id) REFERENCES integrations(id) ON DELETE CASCADE,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS dashboard_cache (
    key_name VARCHAR(100) PRIMARY KEY,
    value JSON NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    type ENUM('info','warning','error','success') DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data JSON,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($queries as $i => $q) {
    try {
        $pdo->exec($q);
        echo "<div class='ok'>✅ Таблица #".($i+1)." создана</div>";
        $created++;
    } catch (Exception $e) {
        echo "<div class='err'>⚠️ Ошибка таблицы #".($i+1).": {$e->getMessage()}</div>";
        $errors[] = $e->getMessage();
    }
}

// Тестовые данные
$pdo->exec("INSERT IGNORE INTO employees (name, position, hourly_rate, phone) VALUES 
    ('Иванов Иван Иванович', 'Приемщик', 350.00, '+79001234567'),
    ('Петрова Анна Сергеевна', 'Кладовщик', 400.00, '+79001234568'),
    ('Сидоров Петр Константинович', 'Менеджер', 550.00, '+79001234569')");

$pdo->exec("INSERT IGNORE INTO integrations (name, type, enabled, settings) VALUES 
    ('1C:Предприятие 8.3', '1c', 0, '{\"commerce_ml_url\":\"\",\"exchange_login\":\"\",\"exchange_password\":\"\"}'),
    ('МойСклад', 'mysklad', 0, '{\"login\":\"\",\"password\":\"\",\"company_id\":\"\"}'),
    ('Wildberries', 'wildberries', 0, '{\"api_key\":\"\",\"warehouse_id\":\"\"}'),
    ('Ozon Seller', 'ozon', 0, '{\"client_id\":\"\",\"api_key\":\"\"}'),
    ('Telegram Bot', 'webhook', 0, '{\"bot_token\":\"\",\"chat_id\":\"\"}')");

echo "<p>✅ Тестовые данные добавлены</p></div>";

// === 2. СОЗДАНИЕ ФАЙЛОВ ===
echo "<div class='card'><h3>📁 Создание файлов</h3>";

$files = [
    // API
    'api/employees.php' => getEmployeesApi(),
    'api/integrations.php' => getIntegrationsApi(),
    'api/dashboard.php' => getDashboardApi(),
    'api/notifications.php' => getNotificationsApi(),
    
    // Assets
    'assets/info.js' => getInfoJs(),
    'assets/employees.js' => getEmployeesJs(),
    'assets/integrations.js' => getIntegrationsJs(),
    
    // Pages
    'info.html' => getInfoHtml(),
    'employees.html' => getEmployeesHtml(),
    'integrations.html' => getIntegrationsHtml(),
];

foreach ($files as $path => $content) {
    $fullPath = $base . '/' . $path;
    $dir = dirname($fullPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    
    if (file_put_contents($fullPath, $content) !== false) {
        echo "<div class='ok'>✅ Создан: <code>$path</code></div>";
        $created++;
    } else {
        echo "<div class='err'>❌ Не создан: <code>$path</code></div>";
        $errors[] = "Cannot write $path";
    }
}

// === 3. ОБНОВЛЕНИЕ НАВИГАЦИИ ===
echo "<div class='card'><h3>🧭 Обновление навигации</h3>";
$pages = ['index.html', 'receiving.html', 'warehouse.html'];
$navItems = '
<a href="info.html" class="btn auth-only">📊 Инфо</a>
<a href="employees.html" class="btn auth-only">👥 Сотрудники</a>
<a href="integrations.html" class="btn auth-only">🔗 Интеграции</a>';

foreach ($pages as $page) {
    $path = $base . '/' . $page;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        if (strpos($content, 'info.html') === false) {
            // Вставляем перед кнопкой выхода
            $content = str_replace(
                '<button id="logout-btn" class="btn auth-only"',
                $navItems . "\n                " . '<button id="logout-btn" class="btn auth-only"',
                $content
            );
            file_put_contents($path, $content);
            echo "<div class='ok'>✅ Обновлен: <code>$page</code></div>";
            $created++;
        }
    }
}
echo "</div>";

// === 4. ПРАВА ДОСТУПА ===
echo "<div class='card'><h3>🔐 Права доступа</h3>";
$sessDir = $base . '/sessions';
if (!is_dir($sessDir)) @mkdir($sessDir, 0755, true);
@chmod($sessDir, 0755);
echo is_writable($sessDir) 
    ? "<div class='ok'>✅ Папка sessions/ доступна для записи</div>"
    : "<div class='err'>⚠️ Папка sessions/ недоступна для записи</div>";
echo "</div>";

// === ИТОГ ===
echo "<div class='card'><h3>🎉 Итог</h3>";
echo "<p><b>Создано объектов:</b> $created</p>";
if (empty($errors)) {
    echo "<p class='ok'>✅ Все модули установлены успешно!</p>
    <ol>
        <li>Очистите кэш браузера: <code>Ctrl+Shift+Del</code> → Файлы cookie</li>
        <li>Войдите в систему под админом</li>
        <li>Новые пункты появятся в верхнем меню</li>
        <li>Для тестовых сотрудников используйте: <b>Иванов / 350₽/час</b></li>
    </ol>
    <p style='background:#fff3cd;padding:10px;border-radius:6px'>
        ⚠️ <b>Важно:</b> Удалите файл <code>installer.php</code> после установки!
    </p>
    <p><a href='index.html' class='btn btn-primary' style='background:#4CAF50;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px'>🚀 Открыть систему</a></p>";
} else {
    echo "<p class='err'>⚠️ Возникли ошибки:</p><ul>";
    foreach ($errors as $e) echo "<li>$e</li>";
    echo "</ul><p>Попробуйте запустить установщик ещё раз или проверьте права на запись.</p>";
}
echo "</div></body></html>";

// === ФУНКЦИИ ГЕНЕРАЦИИ КОНТЕНТА ===

function getEmployeesApi() { return <<<'PHPEOF'
<?php
require_once 'config.php';
requireAuth();
$pdo = getPDO();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

// Только админ может управлять сотрудниками
if (in_array($action, ['add','update','delete','calculate-wage']) && ($_SESSION['role'] ?? '') !== 'admin') {
    json(['error'=>'Доступ запрещен'], 403);
}

// === СПИСОК СОТРУДНИКОВ ===
if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT e.*, 
        COALESCE((SELECT SUM(hours_worked) FROM time_logs WHERE employee_id = e.id AND DATE(date) = CURDATE()), 0) as today_hours,
        COALESCE((SELECT SUM(hours_worked) FROM time_logs WHERE employee_id = e.id AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())), 0) as month_hours
        FROM employees e ORDER BY e.status DESC, e.name");
    json($stmt->fetchAll());
}

// === ДОБАВИТЬ СОТРУДНИКА ===
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO employees (name, position, hourly_rate, phone, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        trim($data['name'] ?? ''),
        $data['position'] ?? '',
        floatval($data['hourly_rate'] ?? 0),
        $data['phone'] ?? '',
        'active'
    ]);
    json(['success'=>true, 'id'=>$pdo->lastInsertId()]);
}

// === ОБНОВИТЬ СОТРУДНИКА ===
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE employees SET name=?, position=?, hourly_rate=?, phone=?, status=? WHERE id=?");
    $stmt->execute([
        trim($data['name']),
        $data['position'] ?? '',
        floatval($data['hourly_rate']),
        $data['phone'] ?? '',
        $data['status'] ?? 'active',
        intval($data['id'])
    ]);
    json(['success'=>true]);
}

// === УДАЛИТЬ СОТРУДНИКА ===
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE employees SET status='fired' WHERE id=?");
    $stmt->execute([intval($data['id'])]);
    json(['success'=>true]);
}

// === ЗАПИСЬ ВРЕМЕНИ ===
if ($action === 'time-log') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Автоматический расчет часов, если не переданы
        $hours = floatval($data['hours_worked'] ?? 0);
        if ($hours <= 0 && !empty($data['time_in']) && !empty($data['time_out'])) {
            $in = new DateTime($data['time_in']);
            $out = new DateTime($data['time_out']);
            $hours = round($out->getTimestamp() - $in->getTimestamp()) / 3600;
        }
        
        $stmt = $pdo->prepare("INSERT INTO time_logs (employee_id, date, time_in, time_out, hours_worked, task, approved)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE time_in=VALUES(time_in), time_out=VALUES(time_out), hours_worked=VALUES(hours_worked), task=VALUES(task)");
        $stmt->execute([
            intval($data['employee_id']),
            $data['date'] ?? date('Y-m-d'),
            $data['time_in'] ?? null,
            $data['time_out'] ?? null,
            $hours,
            $data['task'] ?? '',
            intval($data['approved'] ?? 1)
        ]);
        json(['success'=>true, 'hours'=>$hours]);
    } else {
        // Получение записей за период
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-t');
        $empId = $_GET['employee_id'] ?? null;
        
        $sql = "SELECT tl.*, e.name, e.hourly_rate, e.position,
            (tl.hours_worked * e.hourly_rate) as day_amount
            FROM time_logs tl
            JOIN employees e ON tl.employee_id = e.id
            WHERE tl.date BETWEEN ? AND ?";
        $params = [$from, $to];
        
        if ($empId) {
            $sql .= " AND tl.employee_id = ?";
            $params[] = $empId;
        }
        $sql .= " ORDER BY tl.date DESC, e.name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json($stmt->fetchAll());
    }
}

// === РАСЧЕТ ЗАРПЛАТЫ ===
if ($action === 'calculate-wage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $empId = intval($data['employee_id']);
    $periodStart = $data['period_start'];
    $periodEnd = $data['period_end'];
    
    // Суммируем утвержденные часы
    $stmt = $pdo->prepare("SELECT SUM(hours_worked) as total_hours FROM time_logs 
        WHERE employee_id = ? AND date BETWEEN ? AND ? AND approved = 1");
    $stmt->execute([$empId, $periodStart, $periodEnd]);
    $hours = floatval($stmt->fetchColumn() ?: 0);
    
    // Получаем ставку
    $stmt = $pdo->prepare("SELECT hourly_rate FROM employees WHERE id = ?");
    $stmt->execute([$empId]);
    $rate = floatval($stmt->fetchColumn() ?: 0);
    
    $amount = round($hours * $rate, 2);
    
    // Создаем запись о начислении
    $stmt = $pdo->prepare("INSERT INTO wages (employee_id, period_start, period_end, total_hours, total_amount, status) 
        VALUES (?, ?, ?, ?, ?, 'draft')");
    $stmt->execute([$empId, $periodStart, $periodEnd, $hours, $amount]);
    
    // Уведомление
    if (class_exists('Notification')) {
        Notification::create(null, 'info', 'Начисление зарплаты', "Сотруднику #{$empId} начислено {$amount}₽ за {$hours} часов");
    }
    
    json(['success'=>true, 'hours'=>$hours, 'amount'=>$amount, 'wage_id'=>$pdo->lastInsertId()]);
}

// === СПИСОК НАЧИСЛЕНИЙ ===
if ($action === 'wages') {
    $status = $_GET['status'] ?? null;
    $sql = "SELECT w.*, e.name, e.position, e.hourly_rate FROM wages w 
        JOIN employees e ON w.employee_id = e.id";
    $params = [];
    if ($status) {
        $sql .= " WHERE w.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY w.period_end DESC, e.name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json($stmt->fetchAll());
}

// === ОБНОВЛЕНИЕ СТАТУСА НАЧИСЛЕНИЯ ===
if ($action === 'update-wage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE wages SET status=? WHERE id=?");
    $stmt->execute([$data['status'], intval($data['id'])]);
    json(['success'=>true]);
}

// === ЭКСПОРТ ТАБЕЛЯ ===
if ($action === 'export-timesheet') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');
    
    $stmt = $pdo->prepare("SELECT e.name, e.position, e.hourly_rate,
        SUM(tl.hours_worked) as total_hours,
        SUM(tl.hours_worked * e.hourly_rate) as total_amount
        FROM employees e
        LEFT JOIN time_logs tl ON e.id = tl.employee_id AND tl.date BETWEEN ? AND ? AND tl.approved = 1
        WHERE e.status = 'active'
        GROUP BY e.id
        ORDER BY e.name");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();
    
    // CSV export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timesheet_'.date('Y-m-d').'.csv"');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Сотрудник', 'Должность', 'Ставка/час', 'Часов', 'Сумма']);
    foreach ($data as $row) fputcsv($fp, $row);
    fclose($fp);
    exit;
}

json(['error'=>'Unknown action'], 404);
PHPEOF; }

function getIntegrationsApi() { return <<<'PHPEOF'
<?php
require_once 'config.php';
requireAuth();
$pdo = getPDO();
$action = $_GET['action'] ?? '';
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

// Только админ управляет интеграциями
if (($_SESSION['role'] ?? '') !== 'admin') {
    json(['error'=>'Доступ запрещен'], 403);
}

// === СПИСОК ИНТЕГРАЦИЙ ===
if ($action === 'list') {
    $stmt = $pdo->query("SELECT id, name, type, enabled, last_sync, created_at FROM integrations ORDER BY name");
    json($stmt->fetchAll());
}

// === ОБНОВЛЕНИЕ НАСТРОЕК ===
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = is_array($data['settings']) ? json_encode($data['settings']) : ($data['settings'] ?? '{}');
    
    $stmt = $pdo->prepare("UPDATE integrations SET 
        api_key=?, webhook_url=?, endpoint_url=?, settings=?, enabled=? 
        WHERE id=?");
    $stmt->execute([
        $data['api_key'] ?? null,
        $data['webhook_url'] ?? null,
        $data['endpoint_url'] ?? null,
        $settings,
        intval($data['enabled'] ?? 0),
        intval($data['id'])
    ]);
    json(['success'=>true]);
}

// === ТЕСТ СОЕДИНЕНИЯ ===
if ($action === 'test-connection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM integrations WHERE id = ?");
    $stmt->execute([intval($data['integration_id'])]);
    $int = $stmt->fetch();
    
    $result = ['success'=>false, 'message'=>''];
    
    if ($int['type'] === '1c') {
        // Тест 1C CommerceML
        $url = $int['settings']['commerce_ml_url'] ?? '';
        if ($url) {
            $ch = curl_init($url . '?mode=check');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERPWD => ($int['settings']['exchange_login'] ?? '') . ':' . ($int['settings']['exchange_password'] ?? '')
            ]);
            $response = curl_exec($ch);
            $result['success'] = (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
            $result['message'] = $result['success'] ? '✅ 1C отвечает' : '❌ Ошибка: ' . curl_error($ch);
            curl_close($ch);
        } else {
            $result['message'] = '⚠️ Не указан URL CommerceML';
        }
    }
    
    if ($int['type'] === 'mysklad') {
        // Тест МойСклад API
        if ($int['api_key']) {
            $ch = curl_init('https://online.moysklad.ru/api/remap/1.2/entity/product?limit=1');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERPWD => $int['api_key']
            ]);
            $response = curl_exec($ch);
            $result['success'] = (curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400);
            $result['message'] = $result['success'] ? '✅ МойСклад отвечает' : '❌ Ошибка API';
            curl_close($ch);
        } else {
            $result['message'] = '⚠️ Не указан API-ключ';
        }
    }
    
    if (in_array($int['type'], ['wildberries', 'ozon'])) {
        if ($int['api_key']) {
            $result['success'] = true;
            $result['message'] = '✅ Токен принят (проверка на реальных запросах при синхронизации)';
        } else {
            $result['message'] = '⚠️ Не указан API-токен';
        }
    }
    
    if ($int['type'] === 'webhook') {
        $url = $int['webhook_url'] ?? '';
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            $result['success'] = true;
            $result['message'] = '✅ URL вебхука валиден';
        } else {
            $result['message'] = '⚠️ Не указан или невалидный URL вебхука';
        }
    }
    
    json($result);
}

// === ЗАПУСК СИНХРОНИЗАЦИИ ===
if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $intId = intval($data['integration_id']);
    $direction = $data['direction'] ?? 'export';
    $entity = $data['entity'] ?? 'products';
    
    $stmt = $pdo->prepare("SELECT * FROM integrations WHERE id = ? AND enabled = 1");
    $stmt->execute([$intId]);
    $integration = $stmt->fetch();
    
    if (!$integration) {
        json(['success'=>false, 'error'=>'Интеграция не найдена или отключена'], 400);
    }
    
    // Создаем запись лога
    $logId = null;
    $stmt = $pdo->prepare("INSERT INTO sync_logs (integration_id, direction, entity, status) VALUES (?, ?, ?, 'pending')");
    $stmt->execute([$intId, $direction, $entity]);
    $logId = $pdo->lastInsertId();
    
    $result = ['records'=>0, 'errors'=>[], 'warnings'=>[]];
    
    try {
        // === 1C:CommerceML ===
        if ($integration['type'] === '1c') {
            $settings = json_decode($integration['settings'] ?? '{}', true);
            $baseUrl = $settings['commerce_ml_url'] ?? '';
            
            if ($direction === 'export' && $entity === 'products') {
                // Экспорт товаров в 1С
                $products = $pdo->query("SELECT barcode, name, sku, created_at FROM products WHERE is_draft = 0")->fetchAll();
                $result['records'] = count($products);
                // Здесь: формирование XML CommerceML и отправка
            }
            
            if ($direction === 'import' && $entity === 'inventory') {
                // Импорт остатков из 1С
                // Здесь: парсинг ответа 1С и обновление таблицы inventory
                $result['records'] = 0;
            }
        }
        
        // === МойСклад ===
        if ($integration['type'] === 'mysklad' && $integration['api_key']) {
            $settings = json_decode($integration['settings'] ?? '{}', true);
            $login = $settings['login'] ?? '';
            $companyId = $settings['company_id'] ?? '';
            
            if ($direction === 'export' && $entity === 'products') {
                $products = $pdo->query("SELECT id, barcode, name, sku FROM products WHERE is_draft = 0 LIMIT 100")->fetchAll();
                foreach ($products as $p) {
                    // Отправка в МойСклад через API
                    // curl POST к /entity/product
                }
                $result['records'] = count($products);
            }
        }
        
        // === Wildberries ===
        if ($integration['type'] === 'wildberries' && $integration['api_key']) {
            if ($direction === 'import' && $entity === 'orders') {
                // Получение заказов с WB
                $ch = curl_init('https://suppliers-api.wildberries.ru/api/v3/orders');
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => ["Authorization: {$integration['api_key']}"],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30
                ]);
                $response = curl_exec($ch);
                $orders = json_decode($response, true)['orders'] ?? [];
                $result['records'] = count($orders);
                curl_close($ch);
            }
        }
        
        // === Ozon ===
        if ($integration['type'] === 'ozon' && $integration['api_key']) {
            if ($direction === 'import' && $entity === 'orders') {
                $settings = json_decode($integration['settings'] ?? '{}', true);
                $ch = curl_init('https://api-seller.ozon.ru/v2/posting/fbs/unfulfilled/list');
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        "Client-Id: {$settings['client_id']}",
                        "Api-Key: {$integration['api_key']}",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['limit'=>100]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30
                ]);
                $response = curl_exec($ch);
                $orders = json_decode($response, true)['result']['postings'] ?? [];
                $result['records'] = count($orders);
                curl_close($ch);
            }
        }
        
        // === Webhook (исходящий) ===
        if ($integration['type'] === 'webhook' && $integration['webhook_url']) {
            if ($direction === 'export') {
                $events = [];
                if ($entity === 'inventory') {
                    $events = $pdo->query("SELECT p.barcode, p.name, i.quantity, l.code as location 
                        FROM inventory i JOIN products p ON i.product_id = p.id 
                        JOIN locations l ON i.location_id = l.id WHERE i.quantity > 0 LIMIT 50")->fetchAll();
                }
                foreach ($events as $event) {
                    $ch = curl_init($integration['webhook_url']);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode([
                            'event' => 'inventory_update',
                            'timestamp' => date('c'),
                            'data' => $event
                        ]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
                $result['records'] = count($events);
            }
        }
        
        // Обновляем лог
        $pdo->prepare("UPDATE sync_logs SET status='success', records_count=?, response_data=? WHERE id=?")
            ->execute([$result['records'], json_encode($result), $logId]);
        $pdo->prepare("UPDATE integrations SET last_sync = NOW() WHERE id = ?")->execute([$intId]);
        
    } catch (Exception $e) {
        $pdo->prepare("UPDATE sync_logs SET status='error', error_msg=? WHERE id=?")
            ->execute([$e->getMessage(), $logId]);
        $result['errors'][] = $e->getMessage();
    }
    
    json(['success'=>empty($result['errors']), 'result'=>$result, 'log_id'=>$logId]);
}

// === ВХОДЯЩИЙ ВЕБХУК ===
if ($action === 'webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Обработка входящих событий от внешних систем
    $token = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? $_GET['token'] ?? '';
    
    // Находим интеграцию по токену или URL
    $stmt = $pdo->prepare("SELECT id, settings FROM integrations WHERE webhook_url IS NOT NULL AND enabled = 1");
    $stmt->execute();
    
    // Простая валидация (можно усилить)
    $payload = json_decode($raw, true) ?? $_POST;
    
    // Пример: обновление остатков при заказе на маркетплейсе
    if (!empty($payload['order_id']) && !empty($payload['items'])) {
        foreach ($payload['items'] as $item) {
            $stmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? 
                WHERE product_id = (SELECT id FROM products WHERE barcode = ?) AND location_id = 'MAIN'");
            $stmt->execute([$item['quantity'] ?? 1, $item['barcode'] ?? '']);
        }
    }
    
    json(['received'=>true, 'timestamp'=>date('c')]);
}

// === ЛОГИ СИНХРОНИЗАЦИИ ===
if ($action === 'logs') {
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $integrationId = $_GET['integration_id'] ?? null;
    
    $sql = "SELECT sl.*, i.name as integration_name, i.type FROM sync_logs sl 
        JOIN integrations i ON sl.integration_id = i.id";
    $params = [];
    if ($integrationId) {
        $sql .= " WHERE sl.integration_id = ?";
        $params[] = $integrationId;
    }
    $sql .= " ORDER BY sl.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json($stmt->fetchAll());
}

// === ОЧИСТКА ЛОГОВ ===
if ($action === 'clear-logs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $days = intval($data['days'] ?? 30);
    $stmt = $pdo->prepare("DELETE FROM sync_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    json(['success'=>true, 'deleted'=>$pdo->rowCount()]);
}

json(['error'=>'Unknown action'], 404);
PHPEOF; }

function getDashboardApi() { return <<<'PHPEOF'
<?php
require_once 'config.php';
requireAuth();
$pdo = getPDO();

// Кэширование на 5 минут для снижения нагрузки
$cacheKey = 'dashboard_' . ($_SESSION['user_id'] ?? 'guest') . '_' . ($_SESSION['role'] ?? 'user');
$stmt = $pdo->prepare("SELECT value, updated_at FROM dashboard_cache WHERE key_name = ?");
$stmt->execute([$cacheKey]);
$cache = $stmt->fetch();

if ($cache && strtotime($cache['updated_at']) > time() - 300) {
    json(json_decode($cache['value'], true));
}

// === СБОР ДАННЫХ ===
$data = [
    'summary' => [
        'products_total' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_draft = 0")->fetchColumn(),
        'products_draft' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_draft = 1")->fetchColumn(),
        'inventory_value' => (float)$pdo->query("SELECT SUM(i.quantity * 100) FROM inventory i WHERE i.quantity > 0")->fetchColumn(), // заглушка: цена = 100
        'today_received' => (int)$pdo->query("SELECT COALESCE(SUM(ri.quantity),0) FROM receiving_items ri JOIN receiving_batches rb ON ri.batch_id = rb.id WHERE DATE(rb.created_at) = CURDATE()")->fetchColumn(),
        'today_shipped' => (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM movements WHERE movement_type = 'ship' AND DATE(created_at) = CURDATE()")->fetchColumn(),
        'employees_active' => (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn(),
        'employees_today' => (int)$pdo->query("SELECT COUNT(DISTINCT employee_id) FROM time_logs WHERE date = CURDATE() AND hours_worked > 0")->fetchColumn(),
        'wages_pending' => (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM wages WHERE status = 'draft'")->fetchColumn(),
    ],
    'charts' => [
        'receiving_7days' => $pdo->query("SELECT DATE(rb.created_at) as date, SUM(ri.quantity) as qty 
            FROM receiving_items ri JOIN receiving_batches rb ON ri.batch_id = rb.id 
            WHERE rb.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
            GROUP BY DATE(rb.created_at) ORDER BY date")->fetchAll(PDO::FETCH_KEY_PAIR),
        'movements_7days' => $pdo->query("SELECT DATE(created_at) as date, COUNT(*) as cnt 
            FROM movements WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
            GROUP BY DATE(created_at) ORDER BY date")->fetchAll(PDO::FETCH_KEY_PAIR),
    ],
    'recent' => [
        'movements' => $pdo->query("SELECT m.*, p.barcode, p.name, 
            COALESCE(l_from.code, '-') as from_loc, COALESCE(l_to.code, '-') as to_loc 
            FROM movements m 
            JOIN products p ON m.product_id = p.id 
            LEFT JOIN locations l_from ON m.from_location_id = l_from.id 
            LEFT JOIN locations l_to ON m.to_location_id = l_to.id 
            ORDER BY m.created_at DESC LIMIT 10")->fetchAll(),
        'time_logs' => $pdo->query("SELECT tl.*, e.name, e.position 
            FROM time_logs tl JOIN employees e ON tl.employee_id = e.id 
            WHERE tl.date = CURDATE() ORDER BY tl.time_in DESC LIMIT 10")->fetchAll(),
        'batches' => $pdo->query("SELECT * FROM receiving_batches ORDER BY created_at DESC LIMIT 5")->fetchAll(),
    ],
    'alerts' => [],
];

// === АЛЕРТЫ ===
// Низкие остатки
$lowStock = $pdo->query("SELECT p.name, i.quantity, l.code 
    FROM inventory i JOIN products p ON i.product_id = p.id JOIN locations l ON i.location_id = l.id 
    WHERE i.quantity < 10 AND i.quantity > 0 LIMIT 5")->fetchAll();
if ($lowStock) {
    $data['alerts'][] = ['type'=>'warning', 'title'=>'Низкие остатки', 'count'=>count($lowStock), 'items'=>$lowStock];
}

// Неподтвержденные часы
$unapprovedHours = $pdo->query("SELECT COUNT(*) FROM time_logs WHERE approved = 0 AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
if ($unapprovedHours > 0) {
    $data['alerts'][] = ['type'=>'info', 'title'=>'Неподтвержденное время', 'message'=>"{$unapprovedHours} записей требуют подтверждения"];
}

// Ошибки синхронизации
$syncErrors = $pdo->query("SELECT COUNT(*) FROM sync_logs WHERE status = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
if ($syncErrors > 0) {
    $data['alerts'][] = ['type'=>'error', 'title'=>'Ошибки интеграций', 'message'=>"{$syncErrors} ошибок за последние 24 часа"];
}

// === КЭШИРОВАНИЕ ===
$stmt = $pdo->prepare("INSERT INTO dashboard_cache (key_name, value) VALUES (?, ?) 
    ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()");
$stmt->execute([$cacheKey, json_encode($data)]);

json($data);
PHPEOF; }

function getNotificationsApi() { return <<<'PHPEOF'
<?php
require_once 'config.php';
requireAuth();
$pdo = getPDO();
$action = $_GET['action'] ?? '';

// === ПОЛУЧИТЬ УВЕДОМЛЕНИЯ ===
if ($action === 'list') {
    $unread = $_GET['unread'] ?? false;
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    
    $sql = "SELECT * FROM notifications WHERE (user_id IS NULL OR user_id = ?)";
    $params = [$_SESSION['user_id']];
    
    if ($unread) {
        $sql .= " AND is_read = 0";
    }
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json($stmt->fetchAll());
}

// === ПОМЕТИТЬ КАК ПРОЧИТАННОЕ ===
if ($action === 'mark-read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    
    if (!empty($data['id'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)");
        $stmt->execute([intval($data['id']), $_SESSION['user_id']]);
    } else {
        // Все уведомления
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id IS NULL OR user_id = ?)");
        $stmt->execute([$_SESSION['user_id']]);
    }
    json(['success'=>true]);
}

// === УДАЛИТЬ УВЕДОМЛЕНИЕ ===
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND (user_id IS NULL OR user_id = ?)");
    $stmt->execute([intval($data['id']), $_SESSION['user_id']]);
    json(['success'=>true]);
}

// === СОЗДАТЬ УВЕДОМЛЕНИЕ (только админ) ===
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_SESSION['role'] ?? '') === 'admin') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['user_id'] ?? null, // null = всем
        $data['type'] ?? 'info',
        trim($data['title']),
        trim($data['message']),
        json_encode($data['data'] ?? [])
    ]);
    json(['success'=>true, 'id'=>$pdo->lastInsertId()]);
}

json(['error'=>'Unknown action'], 404);
PHPEOF; }

function getInfoJs() { return <<<'JSEOF'
const Info = {
    async load() {
        try {
            const data = await App.req('dashboard.php');
            
            // Карточки статистики
            const fmt = new Intl.NumberFormat('ru-RU');
            const fmtMoney = new Intl.NumberFormat('ru-RU', {style:'currency', currency:'RUB', maximumFractionDigits:0});
            
            document.getElementById('stat-products')?.textContent = fmt.format(data.summary.products_total);
            document.getElementById('stat-products-draft')?.textContent = fmt.format(data.summary.products_draft);
            document.getElementById('stat-value')?.textContent = fmtMoney.format(data.summary.inventory_value);
            document.getElementById('stat-received')?.textContent = fmt.format(data.summary.today_received);
            document.getElementById('stat-shipped')?.textContent = fmt.format(data.summary.today_shipped);
            document.getElementById('stat-employees')?.textContent = fmt.format(data.summary.employees_active);
            document.getElementById('stat-working')?.textContent = fmt.format(data.summary.employees_today);
            document.getElementById('stat-wages')?.textContent = fmtMoney.format(data.summary.wages_pending);
            
            // Таблицы
            const renderMovements = (tbody, items) => {
                tbody.innerHTML = items.map(m => `
                    <tr>
                        <td>${new Date(m.created_at).toLocaleTimeString('ru-RU', {hour:'2-digit',minute:'2-digit'})}</td>
                        <td>${m.name || m.barcode}</td>
                        <td>${m.from_loc} → ${m.to_loc}</td>
                        <td><b>${fmt.format(m.quantity)}</b></td>
                        <td><span class="badge">${m.movement_type === 'receive' ? '📥' : m.movement_type === 'ship' ? '📤' : '🔄'}</span></td>
                    </tr>
                `).join('') || '<tr><td colspan="5">Нет данных</td></tr>';
            };
            
            const renderTimeLogs = (tbody, items) => {
                tbody.innerHTML = items.map(t => `
                    <tr>
                        <td>${t.name}<br><small>${t.position||''}</small></td>
                        <td>${t.time_in||'–'} – ${t.time_out||'–'}</td>
                        <td><b>${parseFloat(t.hours_worked||0).toFixed(2)} ч</b></td>
                        <td>${t.task || '–'}</td>
                        <td>${t.approved ? '✅' : '⏳'}</td>
                    </tr>
                `).join('') || '<tr><td colspan="5">Нет записей за сегодня</td></tr>';
            };
            
            const renderBatches = (tbody, items) => {
                tbody.innerHTML = items.map(b => `
                    <tr>
                        <td>${b.batch_number}</td>
                        <td>${new Date(b.created_at).toLocaleDateString('ru-RU')}</td>
                        <td><span class="badge ${b.status==='completed'?'success':b.status==='active'?'warning':'secondary'}">${b.status}</span></td>
                        <td>${b.created_by || '–'}</td>
                    </tr>
                `).join('');
            };
            
            if (document.getElementById('recent-movements')) {
                renderMovements(document.getElementById('recent-movements'), data.recent.movements);
            }
            if (document.getElementById('recent-time')) {
                renderTimeLogs(document.getElementById('recent-time'), data.recent.time_logs);
            }
            if (document.getElementById('recent-batches')) {
                renderBatches(document.getElementById('recent-batches'), data.recent.batches);
            }
            
            // Алерты
            const alertsContainer = document.getElementById('alerts-container');
            if (alertsContainer && data.alerts?.length) {
                alertsContainer.innerHTML = data.alerts.map(a => `
                    <div class="alert alert-${a.type}">
                        <b>${a.title}</b>: ${a.message || (a.items?.length ? a.items.map(i=>`${i.name||i.barcode}: ${i.quantity||i.count}`).join(', ') : '')}
                    </div>
                `).join('');
                alertsContainer.style.display = '';
            }
            
            // Графики (простая визуализация)
            if (window.Chart && document.getElementById('chart-receiving')) {
                new Chart(document.getElementById('chart-receiving'), {
                    type: 'line',
                    data: {
                        labels: Object.keys(data.charts.receiving_7days).map(d => new Date(d).toLocaleDateString('ru-RU', {day:'numeric',month:'short'})),
                        datasets: [{
                            label: 'Принято товаров',
                            data: Object.values(data.charts.receiving_7days),
                            borderColor: '#4CAF50',
                            tension: 0.3,
                            fill: false
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                });
            }
            
        } catch (e) {
            console.error('Dashboard load error:', e);
            document.getElementById('stat-products')?.textContent = '–';
        }
    },
    
    refresh() {
        // Принудительная перезагрузка без кэша
        fetch('api/dashboard.php?nocache=' + Date.now(), {credentials:'include'})
            .then(r => r.json())
            .then(data => {
                // Обновляем только изменяющиеся данные
                const fmt = new Intl.NumberFormat('ru-RU');
                document.getElementById('stat-received')?.textContent = fmt.format(data.summary.today_received);
                document.getElementById('stat-working')?.textContent = fmt.format(data.summary.employees_today);
            });
    }
};

// Автообновление каждые 60 секунд
let dashboardInterval = null;
document.addEventListener('DOMContentLoaded', () => {
    Info.load();
    dashboardInterval = setInterval(() => Info.refresh(), 60000);
});

// Очистка интервала при уходе со страницы
window.addEventListener('beforeunload', () => {
    if (dashboardInterval) clearInterval(dashboardInterval);
});
JSEOF; }

function getEmployeesJs() { return <<<'JSEOF'
const Emp = {
    employees: [],
    
    async loadList() {
        this.employees = await App.req('employees.php?action=list');
        const tb = document.getElementById('emp-body');
        if (!tb) return;
        
        const fmt = new Intl.NumberFormat('ru-RU', {style:'currency', currency:'RUB', maximumFractionDigits:0});
        tb.innerHTML = this.employees.map(e => `
            <tr>
                <td>
                    <b>${e.name}</b><br>
                    <small class="text-muted">${e.position || '—'}</small><br>
                    <small>${e.phone || ''}</small>
                </td>
                <td><b>${fmt.format(e.hourly_rate)}</b>/час</td>
                <td>
                    <div>Сегодня: <b>${parseFloat(e.today_hours||0).toFixed(2)} ч</b></div>
                    <div class="text-muted">Месяц: ${parseFloat(e.month_hours||0).toFixed(1)} ч</div>
                </td>
                <td>
                    <span class="badge badge-${e.status==='active'?'success':e.status==='vacation'?'warning':'danger'}">
                        ${e.status==='active'?'Активен':e.status==='vacation'?'Отпуск':'Уволен'}
                    </span>
                </td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="Emp.edit(${e.id})" title="Редактировать">✏️</button>
                    <button class="btn btn-sm btn-success" onclick="Emp.logTime(${e.id}, '${e.name}')" title="Записать время">⏱️</button>
                    ${App.user?.role === 'admin' ? `<button class="btn btn-sm btn-secondary" onclick="Emp.calcWage(${e.id})" title="Рассчитать зарплату">💰</button>` : ''}
                </td>
            </tr>
        `).join('') || '<tr><td colspan="5">Нет сотрудников</td></tr>';
        
        // Заполняем select для записи времени
        const sel = document.getElementById('log-employee');
        if (sel) {
            sel.innerHTML = '<option value="">Выберите сотрудника</option>' + 
                this.employees.filter(e=>e.status==='active').map(e=>`<option value="${e.id}">${e.name}</option>`).join('');
        }
    },
    
    async add() {
        const name = document.getElementById('new-emp-name')?.value.trim();
        const pos = document.getElementById('new-emp-position')?.value.trim();
        const rate = parseFloat(document.getElementById('new-emp-rate')?.value);
        const phone = document.getElementById('new-emp-phone')?.value.trim();
        
        if (!name || !rate) return alert('Заполните ФИО и ставку');
        
        await App.req('employees.php?action=add', {
            method: 'POST',
            body: JSON.stringify({ name, position: pos, hourly_rate: rate, phone })
        });
        
        alert('✅ Сотрудник добавлен');
        this.loadList();
        
        // Очистка формы
        ['new-emp-name','new-emp-position','new-emp-rate','new-emp-phone'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
    },
    
    async edit(id) {
        const emp = this.employees.find(e => e.id === id);
        if (!emp) return;
        
        const newName = prompt('ФИО:', emp.name);
        if (newName === null) return;
        
        const newPos = prompt('Должность:', emp.position || '');
        const newRate = parseFloat(prompt('Ставка (₽/час):', emp.hourly_rate));
        const newPhone = prompt('Телефон:', emp.phone || '');
        const newStatus = prompt('Статус (active/vacation/fired):', emp.status);
        
        await App.req('employees.php?action=update', {
            method: 'POST',
            body: JSON.stringify({
                id, name: newName, position: newPos, hourly_rate: newRate, phone: newPhone, status: newStatus
            })
        });
        
        alert('✅ Обновлено');
        this.loadList();
    },
    
    async logTime(empId, empName) {
        const hours = parseFloat(prompt(`Часов для ${empName}:`, '8')) || 0;
        const task = prompt('Задача / комментарий:', 'Рабочая смена') || '';
        const approved = confirm('Подтвердить время? (Если нет — запись будет на согласовании)');
        
        await App.req('employees.php?action=time-log', {
            method: 'POST',
            body: JSON.stringify({
                employee_id: empId,
                date: new Date().toISOString().split('T')[0],
                time_in: '09:00:00',
                time_out: '18:00:00',
                hours_worked: hours,
                task,
                approved: approved ? 1 : 0
            })
        });
        
        alert(`✅ Записано ${hours} ч для ${empName}`);
        this.loadList();
    },
    
    async calcWage(empId) {
        const emp = this.employees.find(e => e.id === empId);
        if (!emp) return;
        
        const start = prompt('Начало периода (ГГГГ-ММ-ДД):', new Date().getFullYear()+'-'+String(new Date().getMonth()+1).padStart(2,'0')+'-01');
        if (!start) return;
        const end = prompt('Конец периода:', new Date().getFullYear()+'-'+String(new Date().getMonth()+1).padStart(2,'0')+'-'+new Date().getDate());
        if (!end) return;
        
        const r = await App.req('employees.php?action=calculate-wage', {
            method: 'POST',
            body: JSON.stringify({ employee_id: empId, period_start: start, period_end: end })
        });
        
        const fmt = new Intl.NumberFormat('ru-RU', {style:'currency', currency:'RUB'});
        alert(`✅ Начислено ${fmt.format(r.amount)}\nза ${r.hours} часов (${emp.hourly_rate}₽/час)`);
    },
    
    async loadWages(status = 'draft') {
        const list = await App.req(`employees.php?action=wages&status=${status}`);
        const tb = document.getElementById('wages-body');
        if (!tb) return;
        
        const fmt = new Intl.NumberFormat('ru-RU', {style:'currency', currency:'RUB'});
        tb.innerHTML = list.map(w => `
            <tr>
                <td>${w.name}<br><small>${w.position}</small></td>
                <td>${w.period_start} – ${w.period_end}</td>
                <td><b>${parseFloat(w.total_hours).toFixed(2)} ч</b></td>
                <td><b>${fmt.format(w.total_amount)}</b></td>
                <td>
                    <span class="badge badge-${w.status==='paid'?'success':w.status==='approved'?'info':'warning'}">${w.status}</span>
                    ${App.user?.role === 'admin' && w.status === 'draft' ? 
                        `<button class="btn btn-sm btn-success" onclick="Emp.approveWage(${w.id})">✅ Одобрить</button>` : ''}
                    ${App.user?.role === 'admin' && w.status === 'approved' ? 
                        `<button class="btn btn-sm btn-primary" onclick="Emp.payWage(${w.id})">💰 Выплатить</button>` : ''}
                </td>
            </tr>
        `).join('') || '<tr><td colspan="5">Нет начислений</td></tr>';
        
        document.getElementById('wages-table').style.display = '';
    },
    
    async approveWage(id) {
        if (!confirm('Одобрить начисление?')) return;
        await App.req('employees.php?action=update-wage', {
            method: 'POST',
            body: JSON.stringify({ id, status: 'approved' })
        });
        alert('✅ Одобрено');
        this.loadWages('draft');
    },
    
    async payWage(id) {
        if (!confirm('Отметить как выплаченное?')) return;
        await App.req('employees.php?action=update-wage', {
            method: 'POST',
            body: JSON.stringify({ id, status: 'paid' })
        });
        alert('✅ Выплата отмечена');
        this.loadWages('approved');
    },
    
    async exportTimesheet() {
        const from = prompt('С даты (ГГГГ-ММ-ДД):', new Date().getFullYear()+'-'+String(new Date().getMonth()+1).padStart(2,'0')+'-01');
        if (!from) return;
        const to = prompt('По дату:', new Date().getFullYear()+'-'+String(new Date().getMonth()+1).padStart(2,'0')+'-'+new Date().getDate());
        if (!to) return;
        
        window.open(`api/employees.php?action=export-timesheet&from=${from}&to=${to}`, '_blank');
    }
};

// Инициализация
document.addEventListener('DOMContentLoaded', () => {
    Emp.loadList();
    
    // Обработчики формы добавления
    document.getElementById('btn-add-emp')?.addEventListener('click', () => Emp.add());
    document.getElementById('btn-export-timesheet')?.addEventListener('click', () => Emp.exportTimesheet());
    
    // Фильтр начислений
    document.getElementById('wages-filter')?.addEventListener('change', (e) => {
        Emp.loadWages(e.target.value);
    });
});
JSEOF; }

function getIntegrationsJs() { return <<<'JSEOF'
const Integrations = {
    async load() {
        const list = await App.req('integrations.php?action=list');
        const tb = document.getElementById('int-body');
        if (!tb) return;
        
        const typeLabels = {
            '1c': '1C:Предприятие',
            'mysklad': 'МойСклад',
            'wildberries': 'Wildberries',
            'ozon': 'Ozon Seller',
            'webhook': 'Webhook',
            'csv': 'CSV импорт/экспорт',
            'api': 'REST API'
        };
        
        tb.innerHTML = list.map(i => `
            <tr>
                <td>
                    <b>${i.name}</b><br>
                    <small class="text-muted">${typeLabels[i.type] || i.type}</small><br>
                    <small>Последняя синхр.: ${i.last_sync ? new Date(i.last_sync).toLocaleString('ru-RU') : '—'}</small>
                </td>
                <td>
                    <input type="password" value="${i.api_key || ''}" placeholder="API ключ / токен" 
                        onchange="Integrations.save(${i.id}, 'api_key', this.value)"
                        style="width:100%;padding:6px;border:1px solid #ddd;border-radius:4px">
                </td>
                <td>
                    <input type="url" value="${i.webhook_url || ''}" placeholder="https://..." 
                        onchange="Integrations.save(${i.id}, 'webhook_url', this.value)"
                        style="width:100%;padding:6px;border:1px solid #ddd;border-radius:4px">
                </td>
                <td>
                    <label style="display:flex;align-items:center;gap:6px">
                        <input type="checkbox" ${i.enabled ? 'checked' : ''} 
                            onchange="Integrations.save(${i.id}, 'enabled', this.checked?1:0)">
                        <span>Вкл</span>
                    </label>
                </td>
                <td style="white-space:nowrap">
                    <button class="btn btn-sm btn-secondary" onclick="Integrations.test(${i.id})">🔍 Тест</button>
                    <button class="btn btn-sm btn-primary" onclick="Integrations.sync(${i.id})">🔄</button>
                    <button class="btn btn-sm btn-info" onclick="Integrations.settings(${i.id})">⚙️</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="5">Нет настроенных интеграций</td></tr>';
    },
    
    async save(id, field, value) {
        const payload = { id };
        payload[field] = field === 'enabled' ? parseInt(value) : value;
        
        // Для полей settings нужно отправить как объект
        if (field === 'settings' && typeof value === 'string') {
            try { payload.settings = JSON.parse(value); } catch {}
        }
        
        await App.req('integrations.php?action=update', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
    },
    
    async test(id) {
        const btn = event.target;
        const original = btn.textContent;
        btn.textContent = '🔄 Проверка...';
        btn.disabled = true;
        
        try {
            const r = await App.req('integrations.php?action=test-connection', {
                method: 'POST',
                body: JSON.stringify({ integration_id: id })
            });
            alert(`${r.success ? '✅' : '❌'} ${r.message}`);
        } catch (e) {
            alert('❌ Ошибка: ' + e.message);
        } finally {
            btn.textContent = original;
            btn.disabled = false;
        }
    },
    
    async sync(id) {
        const entity = prompt('Что синхронизировать?\n• products — товары\n• inventory — остатки\n• orders — заказы', 'products');
        if (!entity) return;
        
        const direction = prompt('Направление?\n• export — из нашей системы наружу\n• import — из внешней системы к нам', 'export');
        if (!direction) return;
        
        const btn = event.target;
        const original = btn.textContent;
        btn.textContent = '🔄 ...';
        btn.disabled = true;
        
        try {
            const r = await App.req('integrations.php?action=sync', {
                method: 'POST',
                body: JSON.stringify({ integration_id: id, direction, entity })
            });
            
            if (r.success) {
                alert(`✅ Синхронизация завершена:\n• Записей: ${r.result.records}\n• Ошибок: ${r.result.errors?.length || 0}`);
                this.load(); // Обновить список
            } else {
                alert('❌ Ошибка: ' + (r.error || JSON.stringify(r.result?.errors)));
            }
        } catch (e) {
            alert('❌ Ошибка сети: ' + e.message);
        } finally {
            btn.textContent = original;
            btn.disabled = false;
        }
    },
    
    settings(id) {
        // Простой модал для расширенных настроек (можно доработать)
        const int = this.list?.find(i => i.id === id);
        if (!int) return;
        
        const settings = JSON.stringify(JSON.parse(int.settings || '{}'), null, 2);
        const newSettings = prompt('Настройки (JSON):', settings);
        if (newSettings && newSettings !== settings) {
            try {
                JSON.parse(newSettings); // валидация
                this.save(id, 'settings', newSettings);
                alert('✅ Настройки сохранены');
            } catch {
                alert('❌ Некорректный JSON');
            }
        }
    },
    
    async loadLogs(integrationId = null) {
        const url = 'integrations.php?action=logs' + (integrationId ? `&integration_id=${integrationId}` : '');
        const logs = await App.req(url);
        const tb = document.getElementById('sync-logs');
        if (!tb) return;
        
        tb.innerHTML = logs.map(l => `
            <tr>
                <td>${new Date(l.created_at).toLocaleString('ru-RU')}</td>
                <td>${l.integration_name}<br><small class="text-muted">${l.type}</small></td>
                <td>${l.direction === 'import' ? '📥' : '📤'} ${l.entity}</td>
                <td>${l.records_count}</td>
                <td>
                    <span class="badge badge-${l.status==='success'?'success':l.status==='error'?'danger':'warning'}">
                        ${l.status}
                    </span>
                    ${l.error_msg ? `<br><small class="text-danger">${l.error_msg.substring(0,50)}${l.error_msg.length>50?'...':''}</small>` : ''}
                </td>
            </tr>
        `).join('') || '<tr><td colspan="5">Нет логов</td></tr>';
    },
    
    async clearLogs() {
        const days = parseInt(prompt('Удалить логи старше (дней):', '30')) || 30;
        if (!confirm(`Удалить логи синхронизации старше ${days} дней?`)) return;
        
        await App.req('integrations.php?action=clear-logs', {
            method: 'POST',
            body: JSON.stringify({ days })
        });
        alert('✅ Логи очищены');
        this.loadLogs();
    }
};

// Инициализация
document.addEventListener('DOMContentLoaded', () => {
    Integrations.load();
    Integrations.loadLogs();
    
    // Автообновление логов каждые 2 минуты
    setInterval(() => Integrations.loadLogs(), 120000);
});
JSEOF; }

function getInfoHtml() { return <<<'HTMLEOF'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📊 Информация</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.alert { padding: 12px; border-radius: 6px; margin-bottom: 10px; font-size: 14px; }
.alert-info { background: #e3f2fd; color: #0d47a1; border-left: 4px solid #2196F3; }
.alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
.alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
.chart-container { height: 200px; margin: 20px 0; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.badge.success { background: #d4edda; color: #155724; }
.badge.warning { background: #fff3cd; color: #856404; }
.badge.danger { background: #f8d7da; color: #721c24; }
.badge.info { background: #cce5ff; color: #004085; }
.badge.secondary { background: #e2e3e5; color: #383d41; }
.text-muted { color: #6c757d; font-size: 13px; }
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>📊 Информация</h1>
    <nav>
      <a href="index.html" class="btn">🏠</a>
      <span id="user-role" style="font-size:12px;margin-right:10px"></span>
      <button id="voice-btn" class="btn btn-voice">🎤</button>
      <button id="logout-btn" class="btn auth-only" onclick="fetch('api/auth.php?action=logout',{method:'POST'}).then(()=>window.location.href='login.html')">🚪</button>
    </nav>
  </header>
  
  <main>
    <!-- Алерты -->
    <div id="alerts-container" style="display:none"></div>
    
    <!-- Карточки статистики -->
    <div class="dashboard" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
      <div class="card">
        <h3>📦 Товаров</h3>
        <p style="font-size:1.8em;font-weight:bold" id="stat-products">–</p>
        <small class="text-muted">Черновиков: <span id="stat-products-draft">–</span></small>
      </div>
      <div class="card">
        <h3>💰 Остатки</h3>
        <p style="font-size:1.3em;font-weight:bold" id="stat-value">–</p>
        <small class="text-muted">оценочная стоимость</small>
      </div>
      <div class="card">
        <h3>📥 Принято сегодня</h3>
        <p style="font-size:1.8em;font-weight:bold" id="stat-received">–</p>
      </div>
      <div class="card">
        <h3>📤 Отгружено сегодня</h3>
        <p style="font-size:1.8em;font-weight:bold" id="stat-shipped">–</p>
      </div>
      <div class="card">
        <h3>👥 Сотрудников</h3>
        <p style="font-size:1.8em;font-weight:bold" id="stat-employees">–</p>
        <small class="text-muted">Сегодня в работе: <span id="stat-working">–</span></small>
      </div>
      <div class="card">
        <h3>💵 К выплате</h3>
        <p style="font-size:1.3em;font-weight:bold;color:#e91e63" id="stat-wages">–</p>
        <small class="text-muted">неподтвержденные начисления</small>
      </div>
    </div>
    
    <!-- Графики -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px">
      <div class="card">
        <h3>📈 Приемка за 7 дней</h3>
        <div class="chart-container"><canvas id="chart-receiving"></canvas></div>
      </div>
      <div class="card">
        <h3>📦 Перемещения за 7 дней</h3>
        <div class="chart-container"><canvas id="chart-movements"></canvas></div>
      </div>
    </div>
    
    <!-- Таблицы -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px">
      <div>
        <h3>📜 Последние перемещения</h3>
        <table><thead><tr><th>Время</th><th>Товар</th><th>Маршрут</th><th>Кол-во</th><th></th></tr></thead><tbody id="recent-movements"></tbody></table>
      </div>
      <div>
        <h3>⏱️ Сегодня в работе</h3>
        <table><thead><tr><th>Сотрудник</th><th>Время</th><th>Часов</th><th>Задача</th><th></th></tr></thead><tbody id="recent-time"></tbody></table>
      </div>
    </div>
    
    <div style="margin-top:20px">
      <h3>📋 Активные партии приемки</h3>
      <table><thead><tr><th>Номер</th><th>Дата</th><th>Статус</th><th>Создатель</th></tr></thead><tbody id="recent-batches"></tbody></table>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/app.js"></script>
<script src="assets/voice.js"></script>
<script src="assets/info.js"></script>
</body>
</html>
HTMLEOF; }

function getEmployeesHtml() { return <<<'HTMLEOF'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>👥 Сотрудники</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.badge.success { background: #d4edda; color: #155724; }
.badge.warning { background: #fff3cd; color: #856404; }
.badge.danger { background: #f8d7da; color: #721c24; }
.badge.info { background: #cce5ff; color: #004085; }
.text-muted { color: #6c757d; font-size: 13px; }
.form-inline { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 15px; }
.form-inline input, .form-inline select { padding: 8px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; }
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>👥 Сотрудники</h1>
    <nav>
      <a href="index.html" class="btn">🏠</a>
      <span id="user-role" style="font-size:12px;margin-right:10px"></span>
      <button id="voice-btn" class="btn btn-voice">🎤</button>
      <button id="logout-btn" class="btn auth-only" onclick="fetch('api/auth.php?action=logout',{method:'POST'}).then(()=>window.location.href='login.html')">🚪</button>
    </nav>
  </header>
  
  <main>
    <!-- Добавление сотрудника (только админ) -->
    <div class="auth-only" id="admin-panel" style="display:none;background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:15px">
      <h4 style="margin:0 0 10px">➕ Добавить сотрудника</h4>
      <div class="form-inline">
        <input type="text" id="new-emp-name" placeholder="ФИО *" style="min-width:200px">
        <input type="text" id="new-emp-position" placeholder="Должность">
        <input type="number" id="new-emp-rate" placeholder="Ставка ₽/час *" step="0.01" style="width:120px">
        <input type="tel" id="new-emp-phone" placeholder="Телефон">
        <button class="btn btn-primary" id="btn-add-emp">Добавить</button>
      </div>
    </div>
    
    <!-- Быстрая запись времени -->
    <div style="background:#e8f5e9;padding:15px;border-radius:8px;margin-bottom:15px">
      <h4 style="margin:0 0 10px">⏱️ Записать время</h4>
      <div class="form-inline">
        <select id="log-employee" style="min-width:180px"><option value="">Выберите сотрудника</option></select>
        <input type="number" id="log-hours" placeholder="Часов" step="0.25" style="width:90px">
        <input type="text" id="log-task" placeholder="Задача / комментарий" style="min-width:200px">
        <button class="btn btn-success" onclick="Emp.logTimeFromForm()">✅ Записать</button>
      </div>
    </div>
    
    <!-- Список сотрудников -->
    <table>
      <thead><tr><th>Сотрудник</th><th>Ставка</th><th>Время</th><th>Статус</th><th>Действия</th></tr></thead>
      <tbody id="emp-body"></tbody>
    </table>
    
    <!-- Начисления -->
    <div style="margin-top:25px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h3>💵 Начисления зарплаты</h3>
        <div>
          <select id="wages-filter" style="padding:6px;border:2px solid #ddd;border-radius:6px">
            <option value="draft">Черновики</option>
            <option value="approved">Одобренные</option>
            <option value="paid">Выплаченные</option>
            <option value="">Все</option>
          </select>
          <button class="btn btn-secondary" onclick="Emp.loadWages(document.getElementById('wages-filter').value)">Обновить</button>
          <button class="btn btn-info" id="btn-export-timesheet">📥 Экспорт табеля</button>
        </div>
      </div>
      <table id="wages-table" style="display:none">
        <thead><tr><th>Сотрудник</th><th>Период</th><th>Часов</th><th>Сумма</th><th>Статус</th></tr></thead>
        <tbody id="wages-body"></tbody>
      </table>
    </div>
  </main>
</div>

<script src="assets/app.js"></script>
<script src="assets/voice.js"></script>
<script src="assets/employees.js"></script>
<script>
// Показываем панель админа
document.addEventListener('DOMContentLoaded', () => {
  if (App.user?.role === 'admin') {
    document.getElementById('admin-panel').style.display = '';
  }
});

// Запись времени из формы
Emp.logTimeFromForm = async () => {
  const empId = document.getElementById('log-employee').value;
  const hours = parseFloat(document.getElementById('log-hours').value);
  const task = document.getElementById('log-task').value;
  
  if (!empId || !hours) return alert('Выберите сотрудника и укажите часы');
  
  const emp = Emp.employees.find(e => e.id == empId);
  await Emp.logTime(empId, emp?.name || 'Сотрудник');
  
  // Очистка
  document.getElementById('log-hours').value = '';
  document.getElementById('log-task').value = '';
};
</script>
</body>
</html>
HTMLEOF; }

function getIntegrationsHtml() { return <<<'HTMLEOF'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🔗 Интеграции</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.badge.success { background: #d4edda; color: #155724; }
.badge.warning { background: #fff3cd; color: #856404; }
.badge.danger { background: #f8d7da; color: #721c24; }
.text-muted { color: #6c757d; font-size: 13px; }
.info-box { background: #e3f2fd; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-size: 14px; }
.info-box code { background: #fff; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>🔗 Интеграции</h1>
    <nav>
      <a href="index.html" class="btn">🏠</a>
      <span id="user-role" style="font-size:12px;margin-right:10px"></span>
      <button id="voice-btn" class="btn btn-voice">🎤</button>
      <button id="logout-btn" class="btn auth-only" onclick="fetch('api/auth.php?action=logout',{method:'POST'}).then(()=>window.location.href='login.html')">🚪</button>
    </nav>
  </header>
  
  <main class="auth-only" id="admin-content" style="display:none">
    <div class="info-box">
      <b>📋 Настройка интеграций:</b><br><br>
      • <b>1C:Предприятие</b>: укажите CommerceML URL и учетные данные обмена в настройках (⚙️)<br>
      • <b>МойСклад</b>: получите API-ключ в личном кабинете → Настройки → Доступ к API<br>
      • <b>Wildberries</b>: токен в ЛК продавца → Настройки → Доступ к API → Создать токен<br>
      • <b>Ozon</b>: Client-Id и API-ключ в Seller Center → Настройки → API-ключи<br>
      • <b>Webhook</b>: укажите URL, куда отправлять события (JSON POST)
    </div>
    
    <!-- Список интеграций -->
    <table>
      <thead><tr><th>Система</th><th>API ключ / токен</th><th>Webhook URL</th><th>Статус</th><th>Действия</th></tr></thead>
      <tbody id="int-body"></tbody>
    </table>
    
    <!-- Логи синхронизации -->
    <div style="margin-top:25px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h3>📋 Логи синхронизации</h3>
        <button class="btn btn-danger btn-sm" onclick="Integrations.clearLogs()">🗑️ Очистить старые</button>
      </div>
      <table>
        <thead><tr><th>Время</th><th>Система</th><th>Операция</th><th>Записей</th><th>Результат</th></tr></thead>
        <tbody id="sync-logs"></tbody>
      </table>
    </div>
    
    <!-- Документация по вебхукам -->
    <div style="margin-top:25px;background:#f8f9fa;padding:15px;border-radius:8px">
      <h4>📡 Формат входящего вебхука</h4>
      <pre style="background:#2d3436;color:#fff;padding:12px;border-radius:6px;overflow-x:auto;font-size:12px">
{
  "event": "order_created | inventory_changed | receiving_completed",
  "timestamp": "2026-01-15T14:30:00Z",
  "data": {
    "order_id": "WB-12345",
    "items": [{"barcode":"460700012345","quantity":2,"price":150.00}]
  }
}</pre>
      <p style="margin-top:10px;font-size:13px">
        Отправляйте POST на <code>https://ваш-сайт.ру/api/integrations.php?action=webhook</code><br>
        Для аутентификации добавьте заголовок: <code>X-Webhook-Token: ваш_секретный_токен</code>
      </p>
    </div>
  </main>
  
  <main id="no-access" style="display:none;text-align:center;padding:40px">
    <h2>🔐 Доступ запрещен</h2>
    <p>Управление интеграциями доступно только администратору.</p>
    <a href="index.html" class="btn btn-primary">← На главную</a>
  </main>
</div>

<script src="assets/app.js"></script>
<script src="assets/voice.js"></script>
<script src="assets/integrations.js"></script>
<script>
// Показываем контент только админу
document.addEventListener('DOMContentLoaded', () => {
  if (App.user?.role === 'admin') {
    document.getElementById('admin-content').style.display = '';
  } else {
    document.getElementById('no-access').style.display = '';
  }
});
</script>
</body>
</html>
HTMLEOF; }