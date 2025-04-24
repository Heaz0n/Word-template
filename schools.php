<?php
session_start();

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Подключение к базе данных
$host = 'localhost';
$dbname = 'SystemDocument';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка подключения к базе данных: ' . $e->getMessage()];
    header('Location: schools.php');
    exit;
}

// Функция для обработки действий со школами
function handleSchoolAction($pdo, $action, $data) {
    $name = trim($data['name']);
    $abbreviation = trim($data['abbreviation'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if (empty($name)) {
        return ['type' => 'error', 'message' => 'Наименование школы не может быть пустым'];
    }

    try {
        if ($action === 'add_school') {
            $stmt = $pdo->prepare("INSERT INTO Schools (name, abbreviation, notes) VALUES (?, ?, ?)");
            $stmt->execute([$name, $abbreviation, $notes]);
            return ['type' => 'success', 'message' => "Школа '$name' успешно добавлена"];
        } elseif ($action === 'edit_school') {
            $code = $data['code'];
            $stmt = $pdo->prepare("UPDATE Schools SET name = ?, abbreviation = ?, notes = ? WHERE code = ?");
            $stmt->execute([$name, $abbreviation, $notes, $code]);
            return ['type' => 'success', 'message' => "Школа '$name' успешно обновлена"];
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            return ['type' => 'error', 'message' => "Школа с названием '$name' уже существует"];
        }
        return ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
    }
    return ['type' => 'error', 'message' => 'Неизвестное действие'];
}

// Функция для обработки действий с программами
function handleProgramAction($pdo, $action, $data) {
    $vsh_code = trim($data['vsh_code']);
    $program_name = trim($data['program_name']);
    $level = trim($data['level']);
    $notes = trim($data['notes'] ?? '');

    if (empty($vsh_code) || empty($program_name) || empty($level)) {
        return ['type' => 'error', 'message' => 'Все обязательные поля должны быть заполнены'];
    }

    // Проверка существования школы
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Schools WHERE code = ?");
    $stmt->execute([$vsh_code]);
    if ($stmt->fetchColumn() == 0) {
        return ['type' => 'error', 'message' => 'Выбранная школа не существует'];
    }

    try {
        if ($action === 'add_program') {
            $stmt = $pdo->prepare("INSERT INTO Programs (vsh_code, program_name, level, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$vsh_code, $program_name, $level, $notes]);
            return ['type' => 'success', 'message' => "Направление '$program_name' успешно добавлено"];
        } elseif ($action === 'edit_program') {
            $code = $data['code'];
            $stmt = $pdo->prepare("UPDATE Programs SET vsh_code = ?, program_name = ?, level = ?, notes = ? WHERE code = ?");
            $stmt->execute([$vsh_code, $program_name, $level, $notes, $code]);
            return ['type' => 'success', 'message' => "Направление '$program_name' успешно обновлено"];
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            return ['type' => 'error', 'message' => "Направление '$program_name' уже существует для выбранной школы"];
        }
        return ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
    }
    return ['type' => 'error', 'message' => 'Неизвестное действие'];
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Недействительный CSRF-токен'];
        header('Location: schools.php');
        exit;
    }

    $action = $_POST['action'];

    if (in_array($action, ['add_school', 'edit_school'])) {
        $_SESSION['notification'] = handleSchoolAction($pdo, $action, $_POST);
    } elseif (in_array($action, ['add_program', 'edit_program'])) {
        $_SESSION['notification'] = handleProgramAction($pdo, $action, $_POST);
    } elseif ($action === 'delete_school') {
        $code = $_POST['code'];
        try {
            // Проверка связанных программ
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Programs WHERE vsh_code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['notification'] = ['type' => 'error', 'message' => 'Нельзя удалить школу, так как она связана с направлениями'];
            } else {
                // Проверка связанных групп (если таблица существует)
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `Groups` g JOIN Programs p ON g.program_id = p.code WHERE p.vsh_code = ?");
                    $stmt->execute([$code]);
                    if ($stmt->fetchColumn() > 0) {
                        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Нельзя удалить школу, так как она связана с группами'];
                    } else {
                        $stmt = $pdo->prepare("SELECT name FROM Schools WHERE code = ?");
                        $stmt->execute([$code]);
                        $schoolName = $stmt->fetchColumn();

                        $stmt = $pdo->prepare("DELETE FROM Schools WHERE code = ?");
                        $stmt->execute([$code]);
                        $_SESSION['notification'] = ['type' => 'success', 'message' => "Школа '$schoolName' успешно удалена"];
                    }
                } catch (PDOException $e) {
                    // Если таблицы Groups нет, пропускаем проверку
                    if (stripos($e->getMessage(), 'table') !== false && stripos($e->getMessage(), 'doesn\'t exist') !== false) {
                        $stmt = $pdo->prepare("SELECT name FROM Schools WHERE code = ?");
                        $stmt->execute([$code]);
                        $schoolName = $stmt->fetchColumn();

                        $stmt = $pdo->prepare("DELETE FROM Schools WHERE code = ?");
                        $stmt->execute([$code]);
                        $_SESSION['notification'] = ['type' => 'success', 'message' => "Школа '$schoolName' успешно удалена"];
                    } else {
                        throw $e;
                    }
                }
            }
        } catch (PDOException $e) {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка при удалении школы: ' . $e->getMessage()];
        }
    } elseif ($action === 'delete_program') {
        $code = $_POST['code'];
        try {
            // Проверка связанных групп
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `Groups` WHERE program_id = ?");
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() > 0) {
                    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Нельзя удалить направление, так как оно связано с группами'];
                } else {
                    $stmt = $pdo->prepare("SELECT program_name FROM Programs WHERE code = ?");
                    $stmt->execute([$code]);
                    $programName = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("DELETE FROM Programs WHERE code = ?");
                    $stmt->execute([$code]);
                    $_SESSION['notification'] = ['type' => 'success', 'message' => "Направление '$programName' успешно удалено"];
                }
            } catch (PDOException $e) {
                // Если таблицы Groups нет, пропускаем проверку
                if (stripos($e->getMessage(), 'table') !== false && stripos($e->getMessage(), 'doesn\'t exist') !== false) {
                    $stmt = $pdo->prepare("SELECT program_name FROM Programs WHERE code = ?");
                    $stmt->execute([$code]);
                    $programName = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("DELETE FROM Programs WHERE code = ?");
                    $stmt->execute([$code]);
                    $_SESSION['notification'] = ['type' => 'success', 'message' => "Направление '$programName' успешно удалено"];
                } else {
                    throw $e;
                }
            }
        } catch (PDOException $e) {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка при удалении направления: ' . $e->getMessage()];
        }
    }

    header('Location: schools.php');
    exit;
}

// Получение данных из таблиц одним запросом
$query = $pdo->query("
    SELECT s.code, s.name, s.abbreviation, s.notes, 
           p.code AS program_code, p.vsh_code, p.program_name, p.level, p.notes AS program_notes
    FROM Schools s
    LEFT JOIN Programs p ON s.code = p.vsh_code
    ORDER BY s.code, p.code
");
$results = $query->fetchAll(PDO::FETCH_ASSOC);

$schools = [];
$programs = [];
foreach ($results as $row) {
    $schoolCode = $row['code'];
    if (!isset($schools[$schoolCode])) {
        $schools[$schoolCode] = [
            'code' => $row['code'],
            'name' => $row['name'],
            'abbreviation' => $row['abbreviation'],
            'notes' => $row['notes'],
            'programs' => []
        ];
    }
    if ($row['program_code']) {
        $schools[$schoolCode]['programs'][] = [
            'code' => $row['program_code'],
            'program_name' => $row['program_name'],
            'level' => $row['level'],
            'notes' => $row['program_notes']
        ];
        $programs[] = [
            'code' => $row['program_code'],
            'vsh_code' => $row['vsh_code'],
            'program_name' => $row['program_name'],
            'level' => $row['level'],
            'notes' => $row['program_notes'],
            'school_name' => $row['name']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система управления университетом</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        header {
            background-color: #333;
            color: white;
            padding: 10px;
        }
        header nav ul {
            list-style: none;
            display: flex;
            gap: 20px;
        }
        header nav ul li a {
            color: white;
            text-decoration: none;
        }
        .container {
            padding: 20px;
        }
        h1, h2 {
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
        }
        button {
            padding: 5px 10px;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
        button:hover {
            opacity: 0.9;
        }
        .actions {
            display: flex;
            gap: 5px;
        }
        button.add-button {
            background-color: #28a745;
            color: white;
        }
        button.edit-button {
            background-color: #ffc107;
            color: white;
            border: none;
            padding: 5px;
        }
        button.delete-button {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            border-radius: 8px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
        }
        form label {
            display: block;
            margin: 10px 0 5px;
        }
        form input, form textarea, form select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        form button {
            padding: 10px 15px;
            background-color: #28a745;
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
        form button:hover {
            background-color: #218838;
        }
        .info-window {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .info-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            border-radius: 8px;
        }
        .info-title {
            font-size: 1.5em;
            margin-bottom: 15px;
            color: #333;
        }
        .info-field {
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: bold;
        }
        .clickable-name {
            cursor: pointer;
            color: #000000;
        }
        .clickable-name:hover {
            color: #004499;
        }
        .sidebar {
            display: none;
            position: fixed;
            right: 0;
            top: 0;
            width: 300px;
            height: 100%;
            background-color: #fff;
            border-left: 1px solid #ccc;
            box-shadow: -2px 0 5px rgba(0, 0, 0, 0.1);
            padding: 20px;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-content {
            font-size: 14px;
            line-height: 1.5;
        }
        .sidebar-content strong {
            color: #333;
        }
        .sidebar-content ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .sidebar-content li {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 4px;
        }
        .sidebar-close {
            font-size: 20px;
            color: #fff;
            background-color: #dc3545;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            float: right;
        }
        .sidebar-close:hover {
            background-color: #c82333;
        }
        .sidebar-title {
            font-size: 1.2em;
            margin-bottom: 15px;
            color: #333;
        }
        .sidebar-button {
            margin-top: 10px;
            padding: 8px 12px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
        .sidebar-button:hover {
            background-color: #218838;
        }
        .sidebar-close-button {
            margin-top: 10px;
            padding: 8px 12px;
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
        .sidebar-close-button:hover {
            background-color: #c82333;
        }
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }
        .notification {
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            opacity: 1;
            transition: opacity 0.5s ease;
        }
        .notification.success {
            background-color: #28a745;
        }
        .notification.error {
            background-color: #dc3545;
        }
        .notification .close-btn {
            cursor: pointer;
            font-size: 16px;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <?php include 'header.html'; ?>

    <div class="notification-container">
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="notification <?= htmlspecialchars($_SESSION['notification']['type']) ?>">
                <span><?= htmlspecialchars($_SESSION['notification']['message']) ?></span>
                <span class="close-btn" onclick="this.parentElement.style.opacity='0'; setTimeout(() => this.parentElement.remove(), 500);">×</span>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>
    </div>

    <div class="container">
        <h1>Система управления университетом</h1>
        <div class="section-header">
            <h2>Высшие школы</h2>
            <div class="add-buttons">
                <button class="add-button" onclick="openModal('add-school-modal')"><i class="fas fa-plus"></i> Добавить школу</button>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Код</th>
                    <th>Наименование</th>
                    <th>Сокращение</th>
                    <th>Примечание</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $school): ?>
                    <tr>
                        <td><?= htmlspecialchars($school['code']) ?></td>
                        <td>
                            <span class="clickable-name" data-school='<?= htmlspecialchars(json_encode($school)) ?>'>
                                <?= htmlspecialchars($school['name']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($school['abbreviation'] ?? '') ?></td>
                        <td><?= htmlspecialchars($school['notes'] ?? '') ?></td>
                        <td>
                            <div class="actions">
                                <button class="edit-button" onclick="openEditSchoolModal(<?= json_encode($school['code']) ?>, <?= json_encode($school['name']) ?>, <?= json_encode($school['abbreviation'] ?? '') ?>, <?= json_encode($school['notes'] ?? '') ?>)"><i class="fas fa-edit"></i></button>
                                <button class="delete-button" onclick="confirmDeleteSchool(<?= json_encode($school['code']) ?>)"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-header">
            <h2>Направления</h2>
            <div class="add-buttons">
                <button class="add-button" onclick="openAddProgramModal()"><i class="fas fa-plus"></i> Добавить направление</button>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Код</th>
                    <th>Код школы</th>
                    <th>Наименование</th>
                    <th>Уровень</th>
                    <th>Примечание</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($programs as $program): ?>
                    <tr>
                        <td><?= htmlspecialchars($program['code']) ?></td>
                        <td><?= htmlspecialchars($program['vsh_code']) ?></td>
                        <td>
                            <span class="clickable-name" data-program='<?= htmlspecialchars(json_encode($program)) ?>'>
                                <?= htmlspecialchars($program['program_name']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($program['level']) ?></td>
                        <td><?= htmlspecialchars($program['notes'] ?? '') ?></td>
                        <td>
                            <div class="actions">
                                <button class="edit-button" onclick="openEditProgramModal(<?= json_encode($program['code']) ?>, <?= json_encode($program['vsh_code']) ?>, <?= json_encode($program['program_name']) ?>, <?= json_encode($program['level']) ?>, <?= json_encode($program['notes'] ?? '') ?>)"><i class="fas fa-edit"></i></button>
                                <button class="delete-button" onclick="confirmDeleteProgram(<?= json_encode($program['code']) ?>)"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="school-sidebar" class="sidebar">
        <span class="sidebar-close" onclick="closeSidebar()">×</span>
        <div class="sidebar-title">Информация о школе</div>
        <div class="sidebar-content" id="sidebar-content"></div>
    </div>

    <div id="school-info-window" class="info-window">
        <div class="info-content">
            <span class="close" onclick="closeInfoWindow('school-info-window')">×</span>
            <div class="info-title">Информация о школе</div>
            <div class="info-field">
                <span class="info-label">Код:</span>
                <span id="school-info-code"></span>
            </div>
            <div class="info-field">
                <span class="info-label">Наименование:</span>
                <span id="school-info-name"></span>
            </div>
            <div class="info-field">
                <span class="info-label">Сокращение:</span>
                <span id="school-info-abbreviation"></span>
            </div>
            <div class="info-field">
                <span class="info-label">Примечание:</span>
                <span id="school-info-notes"></span>
            </div>
        </div>
    </div>

    <div id="program-info-window" class="info-window">
        <div class="info-content">
            <span class="close" onclick="closeInfoWindow('program-info-window')">×</span>
            <div class="info-title">Информация о направлении</div>
            <div class="info-field">
                <span class="info-label">Код:</span>
                <span id="program-info-code"></span>
            </div>
            <div class="info-field">
                <span class="info-label">Код школы:</span>
                <span id="program-info-vsh-code"></span>
            </div>
            <div class="info-field">
                <span class="info-label">Наименование:</span>
                <span id="program-info-name"></span>
            </div>
            <div class="info-field">
                <span class="info-label">Уровень:</span>
                <span id="program-info-level"></span>
            </div>
            <div class="info-field">
                <span class="info-label">Примечание:</span>
                <span id="program-info-notes"></span>
            </div>
        </div>
    </div>

    <div id="add-school-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('add-school-modal')">×</span>
            <h3>Добавить новую школу</h3>
            <form method="POST" onsubmit="return validateSchoolForm()">
                <input type="hidden" name="action" value="add_school">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="name">Наименование:</label>
                <input type="text" id="name" name="name" placeholder="Введите полное название школы" required>
                <label for="abbreviation">Сокращение:</label>
                <input type="text" id="abbreviation" name="abbreviation" placeholder="Введите сокращенное название">
                <label for="notes">Примечание:</label>
                <textarea id="notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="add-button"><i class="fas fa-plus"></i> Добавить</button>
            </form>
        </div>
    </div>

    <div id="edit-school-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('edit-school-modal')">×</span>
            <h3>Редактировать школу</h3>
            <form id="edit-school-form" method="POST" onsubmit="return validateSchoolForm()">
                <input type="hidden" name="action" value="edit_school">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="edit-school-code" name="code">
                <label for="edit-school-name">Наименование:</label>
                <input type="text" id="edit-school-name" name="name" placeholder="Введите полное название школы" required>
                <label for="edit-school-abbreviation">Сокращение:</label>
                <input type="text" id="edit-school-abbreviation" name="abbreviation" placeholder="Введите сокращенное название">
                <label for="edit-school-notes">Примечание:</label>
                <textarea id="edit-school-notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="edit-button"><i class="fas fa-save"></i> Сохранить</button>
            </form>
        </div>
    </div>

    <div id="add-program-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('add-program-modal')">×</span>
            <h3>Добавить новое направление</h3>
            <form method="POST" onsubmit="return validateProgramForm()">
                <input type="hidden" name="action" value="add_program">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="vsh_code">Школа:</label>
                <select id="vsh_code" name="vsh_code" required>
                    <option value="">Выберите школу</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= htmlspecialchars($school['code']) ?>">
                            <?= htmlspecialchars($school['code'] . ' - ' . $school['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="program_name">Наименование:</label>
                <input type="text" id="program_name" name="program_name" placeholder="Введите наименование направления" required>
                <label for="level">Уровень:</label>
                <input type="text" id="level" name="level" placeholder="Введите уровень программы (например, Бакалавр)" required>
                <label for="notes">Примечание:</label>
                <textarea id="notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="add-button"><i class="fas fa-plus"></i> Добавить</button>
            </form>
        </div>
    </div>

    <div id="edit-program-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('edit-program-modal')">×</span>
            <h3>Редактировать направление</h3>
            <form id="edit-program-form" method="POST" onsubmit="return validateProgramForm()">
                <input type="hidden" name="action" value="edit_program">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="edit-program-code" name="code">
                <label for="edit-program-vsh-code">Школа:</label>
                <select id="edit-program-vsh-code" name="vsh_code" required>
                    <option value="">Выберите школу</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= htmlspecialchars($school['code']) ?>">
                            <?= htmlspecialchars($school['code'] . ' - ' . $school['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="edit-program-name">Наименование:</label>
                <input type="text" id="edit-program-name" name="program_name" placeholder="Введите наименование направления" required>
                <label for="edit-program-level">Уровень:</label>
                <input type="text" id="edit-program-level" name="level" placeholder="Введите уровень программы (например, Бакалавр)" required>
                <label for="edit-program-notes">Примечание:</label>
                <textarea id="edit-program-notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="edit-button"><i class="fas fa-save"></i> Сохранить</button>
            </form>
        </div>
    </div>

    <script>
        function showNotification(message, type = 'success') {
            const container = document.querySelector('.notification-container');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
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
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function closeInfoWindow(windowId) {
            document.getElementById(windowId).style.display = 'none';
        }

        function showSchoolInfo(school) {
            document.getElementById('school-info-code').textContent = school.code;
            document.getElementById('school-info-name').textContent = school.name;
            document.getElementById('school-info-abbreviation').textContent = school.abbreviation || 'Не указано';
            document.getElementById('school-info-notes').textContent = school.notes || 'Нет примечания';
            document.getElementById('school-info-window').style.display = 'block';
        }

        function showProgramInfo(program) {
            document.getElementById('program-info-code').textContent = program.code;
            document.getElementById('program-info-vsh-code').textContent = program.vsh_code;
            document.getElementById('program-info-name').textContent = program.program_name;
            document.getElementById('program-info-level').textContent = program.level;
            document.getElementById('program-info-notes').textContent = program.notes || 'Нет примечания';
            document.getElementById('program-info-window').style.display = 'block';
        }

        function showSchoolSidebar(school) {
            const sidebar = document.getElementById('school-sidebar');
            const content = document.getElementById('sidebar-content');

            let programsHtml = '<ul>';
            if (school.programs && school.programs.length > 0) {
                school.programs.forEach(program => {
                    programsHtml += `
                        <li>
                            Наименование направления: ${program.program_name}<br>
                            Уровень: ${program.level || 'Не указан'}<br>
                            Примечание: ${program.notes || 'Нет примечания'}
                        </li>
                    `;
                });
            } else {
                programsHtml += '<li>Направления отсутствуют</li>';
            }
            programsHtml += '</ul>';

            content.innerHTML = `
                <div>
                    Код школы: ${school.code}<br>
                    ${programsHtml}
                    <button class="sidebar-button" onclick="openAddProgramModalFromSidebar('${school.code}')">Добавить новое направление</button>
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
        }

        function openAddProgramModalFromSidebar(schoolCode) {
            const modal = document.getElementById('add-program-modal');
            const vshCodeSelect = document.getElementById('vsh_code');
            const programNameInput = document.getElementById('program_name');
            vshCodeSelect.value = schoolCode;
            programNameInput.value = '';
            openModal('add-program-modal');
        }

        function openAddProgramModal() {
            const modal = document.getElementById('add-program-modal');
            const vshCodeSelect = document.getElementById('vsh_code');
            const programNameInput = document.getElementById('program_name');
            vshCodeSelect.value = '';
            programNameInput.value = '';
            openModal('add-program-modal');
        }

        function closeSidebar() {
            document.getElementById('school-sidebar').style.display = 'none';
        }

        document.querySelectorAll('.clickable-name[data-school]').forEach(item => {
            item.addEventListener('dblclick', (event) => {
                const schoolData = JSON.parse(event.target.getAttribute('data-school'));
                if (schoolData) {
                    showSchoolSidebar(schoolData);
                }
            });
        });

        function openEditSchoolModal(code, name, abbreviation, notes) {
            document.getElementById('edit-school-code').value = code;
            document.getElementById('edit-school-name').value = name;
            document.getElementById('edit-school-abbreviation').value = abbreviation;
            document.getElementById('edit-school-notes').value = notes;
            openModal('edit-school-modal');
        }

        function openEditProgramModal(code, vsh_code, program_name, level, notes) {
            document.getElementById('edit-program-code').value = code;
            document.getElementById('edit-program-vsh-code').value = vsh_code;
            document.getElementById('edit-program-name').value = program_name;
            document.getElementById('edit-program-level').value = level;
            document.getElementById('edit-program-notes').value = notes;
            openModal('edit-program-modal');
        }

        function confirmDeleteSchool(code) {
            if (confirm('Вы уверены, что хотите удалить эту школу?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_school">
                    <input type="hidden" name="code" value="${code}">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function confirmDeleteProgram(code) {
            if (confirm('Вы уверены, что хотите удалить это направление?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_program">
                    <input type="hidden" name="code" value="${code}">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateSchoolForm() {
            const nameInput = document.getElementById('name')?.value.trim() || document.getElementById('edit-school-name')?.value.trim();
            if (!nameInput) {
                showNotification('Наименование школы обязательно', 'error');
                return false;
            }
            if (nameInput.length > 255) {
                showNotification('Наименование школы не должно превышать 255 символов', 'error');
                return false;
            }
            return true;
        }

        function validateProgramForm() {
            const vshCodeSelect = document.getElementById('vsh_code')?.value.trim() || document.getElementById('edit-program-vsh-code')?.value.trim();
            const programInput = document.getElementById('program_name')?.value.trim() || document.getElementById('edit-program-name')?.value.trim();
            const levelInput = document.getElementById('level')?.value.trim() || document.getElementById('edit-program-level')?.value.trim();
            if (!vshCodeSelect) {
                showNotification('Школа обязательна', 'error');
                return false;
            }
            if (!programInput) {
                showNotification('Наименование направления не может быть пустым', 'error');
                return false;
            }
            if (programInput.length > 255) {
                showNotification('Наименование направления не должно превышать 255 символов', 'error');
                return false;
            }
            if (!levelInput) {
                showNotification('Уровень программы обязателен', 'error');
                return false;
            }
            if (levelInput.length > 50) {
                showNotification('Уровень программы не должен превышать 50 символов', 'error');
                return false;
            }
            return true;
        }
    </script>
</body>
</html>