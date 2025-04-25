<?php
session_start();

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Подключение к базе данных
$host = '127.0.0.1';
$dbname = 'SystemDocument';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;port=3306;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка подключения к базе данных: ' . $e->getMessage()];
    header('Location: main-group.php');
    exit;
}

// Функция для обработки действий с группами
function handleGroupAction($pdo, $action, $data) {
    $group_name = trim($data['group_name'] ?? '');
    $direction_id = trim($data['direction_id'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if (empty($group_name) || empty($direction_id)) {
        return ['type' => 'error', 'message' => 'Наименование и направление обязательны'];
    }

    // Проверка существования направления
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Directions WHERE code = ?");
    $stmt->execute([$direction_id]);
    if ($stmt->fetchColumn() == 0) {
        return ['type' => 'error', 'message' => 'Выбранное направление не существует'];
    }

    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO `Groups` (direction_id, group_name, notes) VALUES (?, ?, ?)");
            $stmt->execute([$direction_id, $group_name, $notes]);
            return ['type' => 'success', 'message' => "Группа '$group_name' успешно добавлена"];
        } elseif ($action === 'edit') {
            $id = $data['id'];
            $stmt = $pdo->prepare("UPDATE `Groups` SET direction_id = ?, group_name = ?, notes = ? WHERE id = ?");
            $stmt->execute([$direction_id, $group_name, $notes, $id]);
            return ['type' => 'success', 'message' => "Группа '$group_name' успешно обновлена"];
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            return ['type' => 'error', 'message' => "Группа с названием '$group_name' уже существует"];
        }
        return ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
    }
    return ['type' => 'error', 'message' => 'Неизвестное действие'];
}

// Обработка AJAX-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['error' => 'Недействительный CSRF-токен']);
        exit;
    }

    if ($_POST['action'] === 'get_directions') {
        $school_code = isset($_POST['school_code']) ? (int)$_POST['school_code'] : null;
        $directions = [];

        try {
            if ($school_code) {
                $stmt = $pdo->prepare("SELECT code, direction_name, vsh_code FROM Directions WHERE vsh_code = ? ORDER BY code");
                $stmt->execute([$school_code]);
                $directions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->query("SELECT code, direction_name, vsh_code FROM Directions ORDER BY code");
                $directions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($directions);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Ошибка при получении направлений: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($_POST['action'] === 'lock_direction') {
        if (isset($_POST['direction_id'])) {
            $_SESSION['locked_direction_id'] = $_POST['direction_id'];
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['error' => 'Направление не указано']);
            exit;
        }
    }

    echo json_encode(['error' => 'Неизвестное действие']);
    exit;
}

// Обработка не-AJAX POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Недействительный CSRF-токен'];
        header('Location: main-group.php');
        exit;
    }

    $action = $_POST['action'];

    if (in_array($action, ['add', 'edit'])) {
        $_SESSION['notification'] = handleGroupAction($pdo, $action, $_POST);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            $stmt = $pdo->prepare("SELECT group_name FROM `Groups` WHERE id = ?");
            $stmt->execute([$id]);
            $groupName = $stmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM `Groups` WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['notification'] = ['type' => 'success', 'message' => "Группа '$groupName' успешно удалена"];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (PDOException $e) {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка при удалении группы: ' . $e->getMessage()];
        }
    }

    header('Location: main-group.php');
    exit;
}

// Получение данных групп
$query = $pdo->query("
    SELECT g.id, g.direction_id, g.group_name, g.notes, 
           p.code AS direction_code, p.direction_name, p.vsh_code, 
           s.code AS school_code, s.name AS school_name
    FROM `Groups` g
    LEFT JOIN Directions p ON g.direction_id = p.code
    LEFT JOIN Schools s ON p.vsh_code = s.code
    ORDER BY g.id
");
$groups = $query->fetchAll(PDO::FETCH_ASSOC);

// Получение списка школ
$schools_query = $pdo->query("SELECT code, name FROM Schools ORDER BY code");
$schools = $schools_query->fetchAll(PDO::FETCH_ASSOC);

// Получение последнего добавленного направления
$last_direction_id = $_SESSION['locked_direction_id'] ?? ($groups[0]['direction_id'] ?? '');
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Группы и студенты</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: 'Roboto', sans-serif;
    background-color: #f5f6f5;
    color: #333;
    line-height: 1.6;
}

.container {
    margin: 20px 0;
    padding: 20px;
    text-align: left;
}

.content-wrapper {
    display: flex;
    justify-content: flex-start;
    margin: 20px 20px 20px 40px;
    gap: 20px;
}

.left-column {
    margin-left: 90px;
    max-width: 950px;
    flex: 1;
}

.right-column {
    width: 500px;
    margin-left: auto;
    margin-right: 20px;
}

h1 {
    font-size: 2.2em;
    font-weight: 700;
    color: #003087;
    margin-bottom: 30px;
}

h2 {
    font-size: 1.6em;
    font-weight: 500;
    color: #333;
    margin-bottom: 20px;
}

.filter-form {
    background-color: white;
    border-radius: 8px;
    padding: 20px;
    margin-top: 10px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 20px;
    width: 100%;
    max-width: none;
    position: relative;
}

.groups-list {
    margin-top: 10px;
    background-color: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.groups-list table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.students-list {
    background-color: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-top: 10px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    display: block;
}

.groups-list h3, .students-list h3 {
    font-size: 1.4em;
    margin-bottom: 15px;
    color: #333;
    padding: 10px;
}

.students-list table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.groups-list th, .groups-list td, .students-list th, .students-list td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.groups-list th, .students-list th {
    background-color: #f9f9f9;
    font-weight: 500;
    color: #333;
    text-transform: uppercase;
}

.groups-list tr:hover, .students-list tr:hover {
    background-color: #f1f5f9;
}

button {
    padding: 8px 12px;
    border: none;
    cursor: pointer;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background-color 0.3s, transform 0.2s;
}

button:hover {
    transform: translateY(-1px);
}

button.add-button {
    background-color: #2e7d32;
    color: white;
    width: 100%;
    max-width: 500px;
}

button.edit-button {
    background-color: #fbc02d;
    color: white;
    padding: 6px;
}

button.edit-button:hover {
    background-color: #000000;
    color: white;
}

button.save-button {
    background-color: #0288d1;
    color: white;
}

button.delete-button {
    background-color: #d32f2f;
    color: white;
    padding: 6px;
}

.actions {
    display: flex;
    gap: 8px;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
}

.modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.modal-content h3 {
    font-size: 1.4em;
    margin-bottom: 15px;
    color: #333;
}

.close {
    color: #666;
    position: absolute;
    top: 10px;
    right: 15px;
    font-size: 20px;
    cursor: pointer;
    transition: color 0.3s;
}

.close:hover {
    color: #d32f2f;
}

form label {
    display: block;
    margin: 10px 0 5px;
    font-weight: 500;
    color: #333;
    font-size: 14px;
}

form input, form textarea, form select {
    width: 100%;
    padding: 10px;
    margin-bottom: 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
    background-color: #f9f9f9;
    transition: border-color 0.3s;
}

form input:focus, form textarea:focus, form select:focus {
    border-color: #0288d1;
    outline: none;
}

form select:disabled {
    background-color: #e0e0e0;
    cursor: not-allowed;
}

form textarea {
    resize: vertical;
    min-height: 100px;
}

.filter-select {
    padding: 8px;
    font-size: 14px;
    border-radius: 4px;
    border: 1px solid #ccc;
    background-color: #f9f9f9;
    cursor: pointer;
    width: 100%;
    max-width: 500px;
}

.filter-select:focus {
    border-color: #0288d1;
    outline: none;
}

.sidebar {
    display: none;
    position: fixed;
    right: 0;
    top: 0;
    width: 380px;
    height: 100%;
    background-color: white;
    border-left: 1px solid #ddd;
    padding: 20px;
    z-index: 1000;
    overflow-y: auto;
}

.sidebar-content {
    font-size: 14px;
    color: #333;
}

.sidebar-content strong {
    font-weight: 500;
    color: #333;
}

.sidebar-close {
    color: white;
    background-color: #d32f2f;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: absolute;
    top: 15px;
    right: 15px;
}

.sidebar-title {
    font-size: 1.4em;
    font-weight: 500;
    color: #333;
    margin-bottom: 20px;
}

.sidebar-close-button {
    width: 100%;
    margin-top: 10px;
    padding: 10px;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    background-color: #d32f2f;
    color: white;
    transition: background-color 0.3s;
}

.notification-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1100;
    max-width: 380px;
}

.notification {
    padding: 12px 15px;
    margin-bottom: 10px;
    border-radius: 4px;
    color: white;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    animation: fadeIn 0.3s ease;
}

.notification.success {
    background-color: #2e7d32;
}

.notification.error {
    background-color: #d32f2f;
}

.notification .close-btn {
    cursor: pointer;
    font-size: 16px;
    margin-left: auto;
}

.notification i {
    font-size: 16px;
}

.clickable-name {
    cursor: pointer;
    color: #0288d1;
    font-weight: 500;
    transition: color 0.3s;
}

.clickable-name:hover {
    color: #005b9f;
    text-decoration: underline;
}

.hidden {
    display: none;
}

.loading-spinner {
    display: none;
    text-align: left;
    margin: 20px 0 20px 20px;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    display: inline-block;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@media (max-width: 1024px) {
    .content-wrapper {
        flex-direction: column;
    }

    .left-column {
        margin-left: 10px;
        max-width: none;
    }

    .right-column {
        width: 100%;
        margin-left: 0;
        margin-right: 0;
        margin-top: 20px;
    }

    .groups-list {
        max-width: none;
    }
}

@media (max-width: 768px) {
    .container {
        padding: 15px;
        margin-top: 10px;
    }

    .filter-form {
        margin-top: 10px;
        background-color: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .groups-list table, .students-list table {
        font-size: 12px;
    }

    .groups-list th, .groups-list td, .students-list th, .students-list td {
        padding: 8px;
    }

    .modal-content {
        width: 95%;
        padding: 15px;
    }

    .sidebar {
        width: 100%;
    }

    .actions {
        flex-wrap: wrap;
    }

    .filter-select, .add-button {
        width: 100%;
        max-width: none;
    }

    .loading-spinner {
        text-align: center;
        margin: 10px 0;
    }
}

@media (max-width: 480px) {
    h1 {
        font-size: 1.8em;
    }

    h2 {
        font-size: 1.4em;
    }

    .groups-list table, .students-list table {
        font-size: 11px;
    }

    .groups-list th, .groups-list td, .students-list th, .students-list td {
        padding: 6px;
    }
}
    </style>
</head>
<body>
    <?php include 'header.html'; ?>

    <div class="notification-container">
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="notification <?= htmlspecialchars($_SESSION['notification']['type']) ?>">
                <i class="fas <?= $_SESSION['notification']['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                <span><?= htmlspecialchars($_SESSION['notification']['message']) ?></span>
                <span class="close-btn" onclick="this.parentElement.style.opacity='0'; setTimeout(() => this.parentElement.remove(), 500);">×</span>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>
    </div>

    <div class="container">
        <h1>Группы и студенты</h1>
        <h2>Группы</h2>
    </div>

    <div class="content-wrapper">
        <div class="left-column">
            <form class="filter-form">
                <select id="school-filter" class="filter-select">
                    <option value="">Выберите школу</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= htmlspecialchars($school['code']) ?>">
                            <?= htmlspecialchars($school['code'] . ' - ' . $school['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="direction-filter" class="filter-select hidden">
                    <option value="">Выберите направление</option>
                </select>
                <button id="add-group-button" class="add-button hidden" type="button" onclick="openModal('add-group-modal')"><i class="fas fa-circle-plus"></i> Добавить группу</button>
            </form>

            <div id="loading-spinner" class="loading-spinner">
                <div class="spinner"></div>
            </div>

            <div id="groups-list" class="groups-list hidden">
                <table>
                    <thead>
                        <tr>
                            <th>Код направления</th>
                            <th>Наименование</th>
                            <th>Примечания</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $group): ?>
                            <tr data-direction-id="<?= htmlspecialchars($group['direction_id']) ?>" data-vsh-code="<?= htmlspecialchars($group['vsh_code']) ?>" data-group-id="<?= htmlspecialchars($group['id']) ?>">
                                <td><?= htmlspecialchars($group['direction_id'] ?? 'Не указано') ?></td>
                                <td>
                                    <span class="clickable-name" data-group='<?= htmlspecialchars(json_encode($group)) ?>'>
                                        <?= htmlspecialchars($group['group_name'] ?? 'Не указано') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($group['notes'] ?? '') ?></td>
                                <td>
                                    <div class="actions">
                                        <button class="edit-button" onclick="openEditGroupModal('<?= addslashes($group['id']) ?>', '<?= addslashes($group['direction_id']) ?>', '<?= addslashes($group['group_name']) ?>', '<?= addslashes($group['notes'] ?? '') ?>')"><i class="fas fa-pen"></i></button>
                                        <button class="delete-button" onclick="confirmDeleteGroup('<?= addslashes($group['id']) ?>')"><i class="fas fa-trash-can"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="right-column">
            <aside id="students-list" class="students-list">
                <h3>Список студентов</h3>
                <table>
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>ФИО</th>
                            <th>Контакты</th>
                        </tr>
                    </thead>
                    <tbody id="students-table-body"></tbody>
                </table>
            </aside>
        </div>
    </div>

    <div id="group-sidebar" class="sidebar">
        <span class="sidebar-close" onclick="closeSidebar()">×</span>
        <div class="sidebar-title">Информация о группе</div>
        <div class="sidebar-content" id="sidebar-content"></div>
    </div>

    <div id="add-group-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('add-group-modal')">×</span>
            <h3>Добавить новую группу</h3>
            <form method="POST" onsubmit="return validateGroupForm()">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="direction_id">Направление:</label>
                <select id="direction_id" name="direction_id" required>
                    <option value="">Выберите направление</option>
                </select>
                <label for="group_name">Наименование:</label>
                <input type="text" id="group_name" name="group_name" placeholder="Введите наименование группы" required>
                <label for="notes">Примечание:</label>
                <textarea id="notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="add-button"><i class="fas fa-circle-plus"></i> Добавить</button>
            </form>
        </div>
    </div>

    <div id="edit-group-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('edit-group-modal')">×</span>
            <h3>Редактировать группу</h3>
            <form id="edit-group-form" method="POST" onsubmit="return validateGroupForm()">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="edit-group-id" name="id">
                <label for="edit-direction-id">Направление:</label>
                <select id="edit-direction-id" name="direction_id" required>
                    <option value="">Выберите направление</option>
                </select>
                <label for="edit-group-name">Наименование:</label>
                <input type="text" id="edit-group-name" name="group_name" placeholder="Введите наименование группы" required>
                <label for="edit-group-notes">Примечание:</label>
                <textarea id="edit-group-notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="save-button"><i class="fas fa-floppy-disk"></i> Сохранить</button>
            </form>
        </div>
    </div>

    <script>
        let lockedDirectionId = '<?php echo isset($_SESSION['locked_direction_id']) ? htmlspecialchars($_SESSION['locked_direction_id']) : ''; ?>';
        let selectedSchoolCode = '';
        let selectedGroupId = '';
        let directionsData = [];

        function showNotification(message, type = 'success') {
            const container = document.querySelector('.notification-container');
            if (!container) return;
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                <span>${message}</span>
                <span class="close-btn" onclick="this.parentElement.style.opacity='0'; setTimeout(() => this.parentElement.remove(), 500);">×</span>
            `;
            container.appendChild(notification);
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 500);
            }, 5000);
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
                const selectId = modalId === 'add-group-modal' ? 'direction_id' : 'edit-direction-id';
                updateDirectionSelect(selectId);
                const select = document.getElementById(selectId);
                if (select && lockedDirectionId) {
                    select.value = lockedDirectionId;
                    select.disabled = true;
                } else if (select) {
                    select.disabled = false;
                }
            } else {
                showNotification('Ошибка: модальное окно не найдено', 'error');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function showGroupSidebar(group) {
            try {
                const sidebar = document.getElementById('group-sidebar');
                const content = document.getElementById('sidebar-content');
                if (!sidebar || !content) return;

                content.innerHTML = `
                    <div>
                        <strong>Код группы:</strong> ${group.id || 'Не указано'}<br>
                        <strong>Наименование:</strong> ${group.group_name || 'Не указано'}<br>
                        <strong>Направление:</strong> ${group.direction_name || 'Не указано'}<br>
                        <strong>Школа:</strong> ${group.school_name || 'Не указано'}<br>
                        <strong>Примечание:</strong> ${group.notes || 'Нет примечания'}<br>
                        <button class="sidebar-close-button" onclick="closeSidebar()">Закрыть</button>
                    </div>
                `;

                sidebar.style.display = 'block';

                document.addEventListener('click', function closeSidebarOnClick(e) {
                    if (!sidebar.contains(e.target) && !e.target.classList.contains('clickable-name')) {
                        sidebar.style.display = 'none';
                        document.removeEventListener('click', closeSidebarOnClick);
                    }
                });

                selectedGroupId = group.id;
                fetchStudents(selectedGroupId);
            } catch (error) {
                showNotification('Ошибка при отображении боковой панели', 'error');
            }
        }

        function closeSidebar() {
            const sidebar = document.getElementById('group-sidebar');
            if (sidebar) {
                sidebar.style.display = 'none';
            }
        }

        document.querySelectorAll('.clickable-name[data-group]').forEach(item => {
            item.addEventListener('click', (event) => {
                try {
                    const groupData = JSON.parse(event.target.getAttribute('data-group'));
                    if (groupData) {
                        showGroupSidebar(groupData);
                        const directionFilter = document.getElementById('direction-filter');
                        const schoolFilter = document.getElementById('school-filter');
                        schoolFilter.value = groupData.vsh_code || '';
                        selectedSchoolCode = groupData.vsh_code;
                        fetchDirections(selectedSchoolCode).then(() => {
                            directionFilter.value = groupData.direction_id || '';
                            lockedDirectionId = groupData.direction_id;
                            directionFilter.classList.remove('hidden');
                            document.getElementById('add-group-button').classList.remove('hidden');
                            document.getElementById('groups-list').classList.remove('hidden');
                            filterGroups();
                        });
                    }
                } catch (error) {
                    showNotification('Ошибка при загрузке данных группы', 'error');
                }
            });
        });

        function openEditGroupModal(id, direction_id, group_name, notes) {
            try {
                const modal = document.getElementById('edit-group-modal');
                if (!modal) {
                    showNotification('Модальное окно редактирования группы не найдено', 'error');
                    return;
                }
                document.getElementById('edit-group-id').value = id || '';
                const directionSelect = document.getElementById('edit-direction-id');
                directionSelect.value = direction_id || '';
                document.getElementById('edit-group-name').value = group_name || '';
                document.getElementById('edit-group-notes').value = notes || '';
                openModal('edit-group-modal');
            } catch (error) {
                showNotification('Ошибка при открытии формы редактирования группы', 'error');
            }
        }

        function confirmDeleteGroup(id) {
            if (confirm('Вы уверены, что хотите удалить эту группу?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateGroupForm() {
            try {
                const directionSelect = document.getElementById('direction_id')?.value.trim() || document.getElementById('edit-direction-id')?.value.trim();
                const groupInput = document.getElementById('group_name')?.value.trim() || document.getElementById('edit-group-name')?.value.trim();
                if (!directionSelect) {
                    showNotification('Направление обязательно', 'error');
                    return false;
                }
                if (!groupInput) {
                    showNotification('Наименование группы не может быть пустым', 'error');
                    return false;
                }
                if (groupInput.length > 255) {
                    showNotification('Наименование группы не должно превышать 255 символов', 'error');
                    return false;
                }
                return true;
            } catch (error) {
                showNotification('Ошибка при валидации формы группы', 'error');
                return false;
            }
        }

        function updateDirectionSelect(selectId) {
            const select = document.getElementById(selectId);
            if (!select) return;
            const currentValue = select.value;

            select.innerHTML = '<option value="">Выберите направление</option>';
            directionsData.forEach(direction => {
                if (!selectedSchoolCode || direction.vsh_code == selectedSchoolCode) {
                    const option = document.createElement('option');
                    option.value = direction.code;
                    option.text = `${direction.code} - ${direction.direction_name}`;
                    option.setAttribute('data-vsh-code', direction.vsh_code);
                    select.appendChild(option);
                }
            });

            if (currentValue) {
                const validOption = Array.from(select.options).find(option => option.value === currentValue);
                if (validOption) {
                    select.value = currentValue;
                }
            }
        }

        function fetchDirections(schoolCode) {
            showLoadingSpinner();
            return fetch('main-group.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_directions&school_code=' + encodeURIComponent(schoolCode) + '&csrf_token=' + encodeURIComponent('<?= htmlspecialchars($_SESSION['csrf_token']) ?>')
            })
            .then(response => {
                console.log('Response Status:', response.status);
                console.log('Response Headers:', response.headers.get('Content-Type'));
                return response.text();
            })
            .then(text => {
                console.log('Raw Response:', text);
                try {
                    const data = JSON.parse(text);
                    hideLoadingSpinner();
                    if (data.error) {
                        showNotification(data.error, 'error');
                        return;
                    }
                    directionsData = data;
                    updateDirectionSelect('direction-filter');
                    updateDirectionSelect('direction_id');
                    updateDirectionSelect('edit-direction-id');
                    document.getElementById('direction-filter').classList.remove('hidden');
                    document.getElementById('add-group-button').classList.remove('hidden');
                    return Promise.resolve();
                } catch (error) {
                    throw new Error('JSON Parse Error: ' + error.message);
                }
            })
            .catch(error => {
                hideLoadingSpinner();
                console.error('Fetch Error:', error);
                showNotification('Ошибка при загрузке направлений: ' + error.message, 'error');
                return Promise.resolve();
            });
        }

        function fetchStudents(groupId) {
            const studentsList = document.getElementById('students-list');
            const studentsTableBody = document.getElementById('students-table-body');
            if (!studentsList || !studentsTableBody) return;

            studentsTableBody.innerHTML = '';

            if (!groupId) {
                studentsTableBody.innerHTML = '<tr><td colspan="3">Студенты не найдены</td></tr>';
                studentsList.style.display = 'block';
                return;
            }

            fetch('main-group.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_students&group_id=' + encodeURIComponent(groupId) + '&csrf_token=' + encodeURIComponent('<?= htmlspecialchars($_SESSION['csrf_token']) ?>')
            })
            .then(response => {
                console.log('Response Status:', response.status);
                console.log('Response Headers:', response.headers.get('Content-Type'));
                return response.text();
            })
            .then(text => {
                console.log('Raw Response:', text);
                try {
                    const data = JSON.parse(text);
                    if (data.error) {
                        showNotification(data.error, 'error');
                        return;
                    }
                    if (data.length === 0) {
                        studentsTableBody.innerHTML = '<tr><td colspan="3">Студенты не найдены</td></tr>';
                    } else {
                        data.forEach((student, index) => {
                            const row = document.createElement('tr');
                            row.innerHTML = `
                                <td>${index + 1}</td>
                                <td>${student.full_name || 'Не указано'}</td>
                                <td>${student.email || 'Не указано'}<br>${student.phone || 'Не указано'}</td>
                            `;
                            studentsTableBody.appendChild(row);
                        });
                    }
                    studentsList.style.display = 'block';
                } catch (error) {
                    throw new Error('JSON Parse Error: ' + error.message);
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                showNotification('Ошибка при загрузке студентов: ' + error.message, 'error');
            });
        }

        function filterGroups() {
            const schoolFilterValue = document.getElementById('school-filter').value;
            const directionFilterValue = document.getElementById('direction-filter').value;
            const table = document.getElementById('groups-list').querySelector('table');
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const directionId = row.getAttribute('data-direction-id');
                const vshCode = row.getAttribute('data-vsh-code');
                const matchesSchool = !schoolFilterValue || vshCode === schoolFilterValue;
                const matchesDirection = !directionFilterValue || directionId === directionFilterValue;
                if (matchesSchool && matchesDirection) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function showLoadingSpinner() {
            const spinner = document.getElementById('loading-spinner');
            if (spinner) spinner.style.display = 'block';
        }

        function hideLoadingSpinner() {
            const spinner = document.getElementById('loading-spinner');
            if (spinner) spinner.style.display = 'none';
        }

        document.getElementById('school-filter').addEventListener('change', (e) => {
            selectedSchoolCode = e.target.value;
            const directionFilter = document.getElementById('direction-filter');
            const addButton = document.getElementById('add-group-button');
            const groupsList = document.getElementById('groups-list');

            if (selectedSchoolCode) {
                fetchDirections(selectedSchoolCode);
                directionFilter.value = '';
                lockedDirectionId = '';
                groupsList.classList.add('hidden');
                fetchStudents(null);
            } else {
                directionFilter.classList.add('hidden');
                addButton.classList.add('hidden');
                groupsList.classList.add('hidden');
                directionFilter.value = '';
                lockedDirectionId = '';
                directionsData = [];
                updateDirectionSelect('direction-filter');
                updateDirectionSelect('direction_id');
                updateDirectionSelect('edit-direction-id');
                fetchStudents(null);
            }
        });

        document.getElementById('direction-filter').addEventListener('change', (e) => {
            lockedDirectionId = e.target.value;
            const groupsList = document.getElementById('groups-list');
            showLoadingSpinner();
            fetch('main-group.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lock_direction&direction_id=' + encodeURIComponent(lockedDirectionId) + '&csrf_token=' + encodeURIComponent('<?= htmlspecialchars($_SESSION['csrf_token']) ?>')
            })
            .then(response => {
                console.log('Response Status:', response.status);
                console.log('Response Headers:', response.headers.get('Content-Type'));
                return response.text();
            })
            .then(text => {
                console.log('Raw Response:', text);
                try {
                    const data = JSON.parse(text);
                    hideLoadingSpinner();
                    if (data.error) {
                        showNotification(data.error, 'error');
                        return;
                    }
                    if (lockedDirectionId) {
                        groupsList.classList.remove('hidden');
                    }
                    selectedGroupId = '';
                    fetchStudents(null);
                    filterGroups();
                } catch (error) {
                    throw new Error('JSON Parse Error: ' + error.message);
                }
            })
            .catch(error => {
                hideLoadingSpinner();
                showNotification('Ошибка при отправке запроса: ' + error.message, 'error');
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            fetchStudents(null);
            filterGroups();
            if (lockedDirectionId) {
                const selectedDirectionId = lockedDirectionId;
                fetchDirections(null).then(() => {
                    const selectedDirection = directionsData.find(p => p.code == selectedDirectionId);
                    if (selectedDirection) {
                        document.getElementById('school-filter').value = selectedDirection.vsh_code;
                        selectedSchoolCode = selectedDirection.vsh_code;
                        fetchDirections(selectedSchoolCode).then(() => {
                            document.getElementById('direction-filter').value = selectedDirectionId;
                            document.getElementById('direction-filter').classList.remove('hidden');
                            document.getElementById('add-group-button').classList.remove('hidden');
                            document.getElementById('groups-list').classList.remove('hidden');
                            filterGroups();
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>