<?php

// Include database configuration
require_once 'db_config.php';

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Функция для обработки действий со школами
function handleSchoolAction($pdo, $action, $data) {
    $name = trim($data['name'] ?? '');
    $abbreviation = trim($data['abbreviation'] ?? '');
    $notes = trim($data['notes'] ?? '');

    // Валидация
    if (empty($name)) {
        return ['type' => 'error', 'message' => 'Наименование школы обязательно'];
    }
    if (strlen($name) > 255) {
        return ['type' => 'error', 'message' => 'Наименование школы не должно превышать 255 символов'];
    }
    if ($abbreviation && strlen($abbreviation) > 50) {
        return ['type' => 'error', 'message' => 'Сокращение не должно превышать 50 символов'];
    }

    try {
        if ($action === 'add_school') {
            $stmt = $pdo->prepare("INSERT INTO Schools (name, abbreviation, notes) VALUES (?, ?, ?)");
            $stmt->execute([$name, $abbreviation ?: null, $notes ?: null]);
            return ['type' => 'success', 'message' => "Школа '$name' успешно добавлена"];
        } elseif ($action === 'edit_school') {
            $code = $data['code'];
            $stmt = $pdo->prepare("UPDATE Schools SET name = ?, abbreviation = ?, notes = ? WHERE code = ?");
            $stmt->execute([$name, $abbreviation ?: null, $notes ?: null, $code]);
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

// Функция для обработки действий с направлениями
function handleDirectionAction($pdo, $action, $data) {
    $vsh_code = trim($data['vsh_code'] ?? '');
    $direction_name = trim($data['direction_name'] ?? '');
    $level = trim($data['level'] ?? '');
    $notes = trim($data['notes'] ?? '');

    // Валидация
    if (empty($direction_name)) {
        return ['type' => 'error', 'message' => 'Наименование направления обязательно'];
    }
    if (strlen($direction_name) > 255) {
        return ['type' => 'error', 'message' => 'Наименование направления не должно превышать 255 символов'];
    }
    if ($level && !in_array($level, ['Бакалавриат', 'Магистратура', 'Аспирантура'])) {
        return ['type' => 'error', 'message' => 'Недопустимый уровень направления'];
    }
    if (empty($vsh_code)) {
        return ['type' => 'error', 'message' => 'Выбор школы обязателен'];
    }

    // Проверка существования школы
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Schools WHERE code = ?");
        $stmt->execute([$vsh_code]);
        if ($stmt->fetchColumn() == 0) {
            return ['type' => 'error', 'message' => "Школа с кодом '$vsh_code' не существует"];
        }
    } catch (PDOException $e) {
        return ['type' => 'error', 'message' => 'Ошибка проверки школы: ' . $e->getMessage()];
    }

    try {
        if ($action === 'add_direction') {
            $stmt = $pdo->prepare("INSERT INTO Directions (vsh_code, direction_name, level, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$vsh_code, $direction_name, $level ?: null, $notes ?: null]);
            return ['type' => 'success', 'message' => "Направление '$direction_name' успешно добавлено"];
        } elseif ($action === 'edit_direction') {
            $code = $data['code'];
            $stmt = $pdo->prepare("UPDATE Directions SET direction_name = ?, level = ?, notes = ? WHERE code = ?");
            $stmt->execute([$direction_name, $level ?: null, $notes ?: null, $code]);
            return ['type' => 'success', 'message' => "Направление '$direction_name' успешно обновлено"];
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            return ['type' => 'error', 'message' => "Направление '$direction_name' уже существует для выбранной школы"];
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
    } elseif (in_array($action, ['add_direction', 'edit_direction'])) {
        $_SESSION['notification'] = handleDirectionAction($pdo, $action, $_POST);
    } elseif ($action === 'delete_school') {
        $code = $_POST['code'];
        try {
            $pdo->beginTransaction();

            // Получение имени школы для уведомления
            $stmt = $pdo->prepare("SELECT name FROM Schools WHERE code = ?");
            $stmt->execute([$code]);
            $schoolName = $stmt->fetchColumn();

            // Удаление школы (направления, группы, студенты и связанные данные удаляются автоматически благодаря ON DELETE CASCADE)
            $stmt = $pdo->prepare("DELETE FROM Schools WHERE code = ?");
            $stmt->execute([$code]);

            $pdo->commit();
            $_SESSION['notification'] = ['type' => 'success', 'message' => "Школа '$schoolName' и все связанные данные успешно удалены"];
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка при удалении школы: ' . $e->getMessage()];
        }
    } elseif ($action === 'delete_direction') {
        $code = $_POST['code'];
        try {
            // Получение имени направления для уведомления
            $stmt = $pdo->prepare("SELECT direction_name FROM Directions WHERE code = ?");
            $stmt->execute([$code]);
            $directionName = $stmt->fetchColumn();

            // Удаление направления (группы, студенты и связанные данные удаляются автоматически благодаря ON DELETE CASCADE)
            $stmt = $pdo->prepare("DELETE FROM Directions WHERE code = ?");
            $stmt->execute([$code]);

            $_SESSION['notification'] = ['type' => 'success', 'message' => "Направление '$directionName' и все связанные данные успешно удалены"];
        } catch (PDOException $e) {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка при удалении направления: ' . $e->getMessage()];
        }
    }

    header('Location: schools.php');
    exit;
}

// Получение данных из таблиц одним запросом
try {
    $query = $pdo->query("
        SELECT s.code, s.name, s.abbreviation, s.notes, 
               d.code AS direction_code, d.vsh_code, d.direction_name, d.level, d.notes AS direction_notes
        FROM Schools s
        LEFT JOIN Directions d ON s.code = d.vsh_code
        ORDER BY s.code, d.code
    ");
    $results = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка при получении данных: ' . $e->getMessage()];
    $results = [];
}

$schools = [];
$directions = [];
foreach ($results as $row) {
    $schoolCode = $row['code'];
    if (!isset($schools[$schoolCode])) {
        $schools[$schoolCode] = [
            'code' => $row['code'],
            'name' => $row['name'],
            'abbreviation' => $row['abbreviation'],
            'notes' => $row['notes'],
            'directions' => []
        ];
    }
    if ($row['direction_code']) {
        $schools[$schoolCode]['directions'][] = [
            'code' => $row['direction_code'],
            'direction_name' => $row['direction_name'],
            'level' => $row['level'],
            'notes' => $row['direction_notes'],
            'vsh_code' => $row['vsh_code']
        ];
        $directions[] = [
            'code' => $row['direction_code'],
            'vsh_code' => $row['vsh_code'],
            'direction_name' => $row['direction_name'],
            'level' => $row['level'],
            'notes' => $row['direction_notes'],
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
    <title>Высшие школы и направления</title>
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

        header {
            position: fixed;
            top: 0;
            width: 100%;
            background-color: #003087;
            color: white;
            padding: 15px 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }

        header nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1280px;
            margin: 0 auto;
        }

        header nav ul {
            list-style: none;
            display: flex;
            gap: 15px;
        }

        header nav ul li a {
            color: white;
            text-decoration: none;
            font-size: 16px;
            font-weight: 500;
            padding: 8px 12px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        header nav ul li a:hover {
            background-color: #005bb5;
        }

        header nav ul li a.active {
            background-color: #005bb5;
        }

        .burger-menu {
            display: none;
            font-size: 24px;
            cursor: pointer;
        }

        .container {
            max-width: 1280px;
            margin: 80px auto 30px;
            padding: 20px;
        }

        h1 {
            font-size: 2.2em;
            font-weight: 700;
            color: #003087;
            text-align: center;
            margin-bottom: 30px;
        }

        h2 {
            font-size: 1.6em;
            font-weight: 500;
            color: #333;
            margin-bottom: 20px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 1px solid #ddd;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: #f9f9f9;
            font-weight: 500;
            color: #333;
            text-transform: uppercase;
            font-size: 13px;
        }

        tr:hover {
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
            white-space: nowrap;
        }

        button:hover {
            transform: translateY(-1px);
        }

        button.add-button {
            background-color: #2e7d32;
            color: white;
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

        form textarea {
            resize: vertical;
            min-height: 100px;
        }

        form select.readonly {
            background-color: #e0e0e0;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .filter-select {
            padding: 8px;
            font-size: 14px;
            border-radius: 4px;
            border: 1px solid #ccc;
            background-color: #f9f9f9;
            cursor: pointer;
            min-width: 200px;
        }

        .filter-select:focus {
            border-color: #0288d1;
            outline: none;
        }

        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
            max-width: 350px;
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
            color: #000000;
            font-weight: 500;
            transition: color 0.3s;
        }

        .clickable-name:hover {
            color: #000000;
            text-decoration: underline;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
                margin-top: 60px;
            }

            header nav ul {
                display: none;
                flex-direction: column;
                position: absolute;
                top: 60px;
                left: 0;
                width: 100%;
                background-color: #003087;
                padding: 10px;
            }

            header nav ul.active {
                display: flex;
            }

            .burger-menu {
                display: block;
            }

            table {
                font-size: 13px;
            }

            th, td {
                padding: 8px;
            }

            .modal-content {
                width: 95%;
                padding: 15px;
            }

            .actions {
                flex-wrap: wrap;
            }

            button {
                padding: 6px 10px;
                font-size: 13px;
            }

            .filter-select {
                width: 100%;
                min-width: auto;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .add-buttons {
                width: 100%;
                display: flex;
                justify-content: space-between;
            }
        }

        @media (max-width: 480px) {
            h1 {
                font-size: 1.8em;
            }

            h2 {
                font-size: 1.4em;
            }

            table {
                font-size: 12px;
            }

            th, td {
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
        <h1>Высшие школы и направления</h1>
        <div class="section-header">
            <h2>Высшие школы</h2>
            <div class="add-buttons">
                <button class="add-button" onclick="openModal('add-school-modal')"><i class="fas fa-circle-plus"></i> Добавить школу</button>
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
                                <?= htmlspecialchars($school['name'] ?? '') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($school['abbreviation'] ?? '') ?></td>
                        <td><?= htmlspecialchars($school['notes'] ?? '') ?></td>
                        <td>
                            <div class="actions">
                                <button class="edit-button" onclick="openEditSchoolModal('<?= addslashes($school['code']) ?>', '<?= addslashes($school['name'] ?? '') ?>', '<?= addslashes($school['abbreviation'] ?? '') ?>', '<?= addslashes($school['notes'] ?? '') ?>')"><i class="fas fa-pen"></i></button>
                                <button class="delete-button" onclick="confirmDeleteSchool('<?= addslashes($school['code']) ?>')"><i class="fas fa-trash-can"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-header">
            <h2>Направления</h2>
            <div class="add-buttons">
                <select id="school-filter" class="filter-select">
                    <option value="">Все школы</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= htmlspecialchars($school['code']) ?>">
                            <?= htmlspecialchars($school['code'] . ' - ' . ($school['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="add-button" onclick="openAddDirectionModal()"><i class="fas fa-circle-plus"></i> Добавить направление</button>
            </div>
        </div>
        <table id="directions-table">
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
                <?php foreach ($directions as $direction): ?>
                    <tr data-vsh-code="<?= htmlspecialchars($direction['vsh_code']) ?>">
                        <td><?= htmlspecialchars($direction['code']) ?></td>
                        <td><?= htmlspecialchars($direction['vsh_code']) ?></td>
                        <td>
                            <span class="clickable-name" data-direction='<?= htmlspecialchars(json_encode($direction)) ?>'>
                                <?= htmlspecialchars($direction['direction_name'] ?? '') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($direction['level'] ?? '') ?></td>
                        <td><?= htmlspecialchars($direction['notes'] ?? '') ?></td>
                        <td>
                            <div class="actions">
                                <button class="edit-button" onclick="openEditDirectionModal('<?= addslashes($direction['code']) ?>', '<?= addslashes($direction['vsh_code']) ?>', '<?= addslashes($direction['direction_name'] ?? '') ?>', '<?= addslashes($direction['level'] ?? '') ?>', '<?= addslashes($direction['notes'] ?? '') ?>')"><i class="fas fa-pen"></i></button>
                                <button class="delete-button" onclick="confirmDeleteDirection('<?= addslashes($direction['code']) ?>')"><i class="fas fa-trash-can"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
                <button type="submit" class="add-button"><i class="fas fa-circle-plus"></i> Добавить</button>
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
                <button type="submit" class="save-button"><i class="fas fa-floppy-disk"></i> Сохранить</button>
            </form>
        </div>
    </div>

    <div id="add-direction-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('add-direction-modal')">×</span>
            <h3>Добавить новое направление</h3>
            <form method="POST" onsubmit="return validateDirectionForm()">
                <input type="hidden" name="action" value="add_direction">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="vsh_code">Школа:</label>
                <select id="vsh_code" name="vsh_code" required>
                    <option value="" disabled selected>Выберите школу</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= htmlspecialchars($school['code']) ?>">
                            <?= htmlspecialchars($school['code'] . ' - ' . ($school['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="direction_name">Наименование:</label>
                <input type="text" id="direction_name" name="direction_name" placeholder="Введите наименование направления" required>
                <label for="level">Уровень:</label>
                <select id="level" name="level">
                    <option value="" selected>Выберите уровень</option>
                    <option value="Бакалавриат">Бакалавриат</option>
                    <option value="Магистратура">Магистратура</option>
                    <option value="Аспирантура">Аспирантура</option>
                </select>
                <label for="notes">Примечание:</label>
                <textarea id="notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="add-button"><i class="fas fa-circle-plus"></i> Добавить</button>
            </form>
        </div>
    </div>

    <div id="edit-direction-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('edit-direction-modal')">×</span>
            <h3>Редактировать направление</h3>
            <form id="edit-direction-form" method="POST" onsubmit="return validateDirectionForm()">
                <input type="hidden" name="action" value="edit_direction">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="edit-direction-code" name="code">
                <label for="edit_vsh_code">Школа:</label>
                <select id="edit_vsh_code" name="vsh_code" required>
                    <option value="" disabled>Выберите школу</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= htmlspecialchars($school['code']) ?>">
                            <?= htmlspecialchars($school['code'] . ' - ' . ($school['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="edit-direction-name">Наименование:</label>
                <input type="text" id="edit-direction-name" name="direction_name" placeholder="Введите наименование направления" required>
                <label for="edit-direction-level">Уровень:</label>
                <select id="edit-direction-level" name="level">
                    <option value="" selected>Выберите уровень</option>
                    <option value="Бакалавриат">Бакалавриат</option>
                    <option value="Магистратура">Магистратура</option>
                    <option value="Аспирантура">Аспирантура</option>
                </select>
                <label for="edit-direction-notes">Примечание:</label>
                <textarea id="edit-direction-notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="save-button"><i class="fas fa-floppy-disk"></i> Сохранить</button>
            </form>
        </div>
    </div>

    <script>
        let currentSchoolCode = null;

        function toggleMenu() {
            const menu = document.getElementById('nav-menu');
            if (menu) {
                menu.classList.toggle('active');
            }
        }

        function showNotification(message, type = 'success') {
            const container = document.querySelector('.notification-container');
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
            } else {
                showNotification('Ошибка: модальное окно не найдено', 'error');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
            currentSchoolCode = null;
        }

        function openAddDirectionModal() {
            try {
                const modal = document.getElementById('add-direction-modal');
                const vshCodeSelect = document.getElementById('vsh_code');
                const directionNameInput = document.getElementById('direction_name');
                const levelSelect = document.getElementById('level');
                const notesInput = document.getElementById('notes');

                const schoolFilter = document.getElementById('school-filter');
                const selectedSchoolCode = schoolFilter.value;

                // Очищаем поля формы
                directionNameInput.value = '';
                levelSelect.value = '';
                notesInput.value = '';

                // Если выбрана школа в фильтре, подставляем её
                if (selectedSchoolCode) {
                    vshCodeSelect.value = selectedSchoolCode;
                    vshCodeSelect.classList.add('readonly');
                    vshCodeSelect.onchange = (e) => e.preventDefault();
                } else {
                    vshCodeSelect.value = '';
                    vshCodeSelect.classList.remove('readonly');
                    vshCodeSelect.onchange = null;
                }

                openModal('add-direction-modal');
            } catch (error) {
                showNotification('Ошибка при открытии формы добавления направления: ' + error.message, 'error');
            }
        }

        document.querySelectorAll('.clickable-name[data-school]').forEach(item => {
            item.addEventListener('click', (event) => {
                try {
                    const schoolData = JSON.parse(event.target.getAttribute('data-school'));
                    if (schoolData) {
                        const schoolFilter = document.getElementById('school-filter');
                        schoolFilter.value = schoolData.code || '';
                        schoolFilter.disabled = true;
                        currentSchoolCode = schoolData.code;
                        sessionStorage.setItem('schoolFilterValue', schoolFilter.value);
                        filterDirections();
                    }
                } catch (error) {
                    showNotification('Ошибка при загрузке данных школы', 'error');
                }
            });
        });

        function openEditSchoolModal(code, name, abbreviation, notes) {
            try {
                const modal = document.getElementById('edit-school-modal');
                if (!modal) {
                    showNotification('Модальное окно редактирования школы не найдено', 'error');
                    return;
                }
                document.getElementById('edit-school-code').value = code || '';
                document.getElementById('edit-school-name').value = name || '';
                document.getElementById('edit-school-abbreviation').value = abbreviation || '';
                document.getElementById('edit-school-notes').value = notes || '';
                openModal('edit-school-modal');
            } catch (error) {
                showNotification('Ошибка при открытии формы редактирования школы', 'error');
            }
        }

        function openEditDirectionModal(code, vsh_code, direction_name, level, notes) {
            try {
                const modal = document.getElementById('edit-direction-modal');
                if (!modal) {
                    showNotification('Модальное окно редактирования направления не найдено', 'error');
                    return;
                }
                document.getElementById('edit-direction-code').value = code || '';
                document.getElementById('edit_vsh_code').value = vsh_code || '';
                document.getElementById('edit-direction-name').value = direction_name || '';
                document.getElementById('edit-direction-level').value = level || '';
                document.getElementById('edit-direction-notes').value = notes || '';

                const editVshCodeSelect = document.getElementById('edit_vsh_code');
                editVshCodeSelect.classList.add('readonly');
                editVshCodeSelect.onchange = (e) => e.preventDefault();

                openModal('edit-direction-modal');
            } catch (error) {
                showNotification('Ошибка при открытии формы редактирования направления: ' + error.message, 'error');
            }
        }

        function confirmDeleteSchool(code) {
            if (confirm('Вы уверены, что хотите удалить эту школу? Все связанные данные (направления, группы, студенты) будут удалены.')) {
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

        function confirmDeleteDirection(code) {
            if (confirm('Вы уверены, что хотите удалить это направление? Все связанные данные (группы, студенты) будут удалены.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_direction">
                    <input type="hidden" name="code" value="${code}">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateSchoolForm() {
            try {
                const nameInput = document.getElementById('name')?.value.trim() || document.getElementById('edit-school-name')?.value.trim();
                if (!nameInput) {
                    showNotification('Наименование школы обязательно', 'error');
                    return false;
                }
                if (nameInput.length > 255) {
                    showNotification('Наименование школы не должно превышать 255 символов', 'error');
                    return false;
                }
                const abbreviationInput = document.getElementById('abbreviation')?.value.trim() || document.getElementById('edit-school-abbreviation')?.value.trim();
                if (abbreviationInput && abbreviationInput.length > 50) {
                    showNotification('Сокращение не должно превышать 50 символов', 'error');
                    return false;
                }
                return true;
            } catch (error) {
                showNotification('Ошибка при валидации формы школы', 'error');
                return false;
            }
        }

        function validateDirectionForm() {
            try {
                const directionInput = document.getElementById('direction_name')?.value.trim() || document.getElementById('edit-direction-name')?.value.trim();
                const vshCodeInput = document.getElementById('vsh_code')?.value.trim() || document.getElementById('edit_vsh_code')?.value.trim();
                const levelInput = document.getElementById('level')?.value.trim() || document.getElementById('edit-direction-level')?.value.trim();

                if (!directionInput) {
                    showNotification('Наименование направления обязательно', 'error');
                    return false;
                }
                if (directionInput.length > 255) {
                    showNotification('Наименование направления не должно превышать 255 символов', 'error');
                    return false;
                }
                if (levelInput && !['Бакалавриат', 'Магистратура', 'Аспирантура'].includes(levelInput)) {
                    showNotification('Недопустимый уровень направления', 'error');
                    return false;
                }
                if (!vshCodeInput) {
                    showNotification('Выбор школы обязателен', 'error');
                    return false;
                }
                return true;
            } catch (error) {
                showNotification('Ошибка при валидации формы направления: ' + error.message, 'error');
                return false;
            }
        }

        function filterDirections() {
            const filterValue = document.getElementById('school-filter').value;
            const schoolFilter = document.getElementById('school-filter');
            const table = document.getElementById('directions-table');
            const rows = table.querySelectorAll('tbody tr');

            currentSchoolCode = filterValue || null;
            sessionStorage.setItem('schoolFilterValue', filterValue);

            schoolFilter.disabled = !!filterValue;

            rows.forEach(row => {
                const vshCode = row.getAttribute('data-vsh-code');
                if (!filterValue || vshCode === filterValue) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        document.getElementById('school-filter').addEventListener('change', () => {
            filterDirections();
        });

        document.addEventListener('DOMContentLoaded', () => {
            const schoolFilter = document.getElementById('school-filter');
            const savedFilterValue = sessionStorage.getItem('schoolFilterValue');
            if (savedFilterValue) {
                schoolFilter.value = savedFilterValue;
                schoolFilter.disabled = !!savedFilterValue;
                filterDirections();
            } else if (schoolFilter.value) {
                schoolFilter.disabled = true;
                filterDirections();
            }
        });
    </script>
</body>
</html>