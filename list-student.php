<?php
header('Content-Type: text/html; charset=utf-8');
session_start();

// Database configuration
$host = '127.0.0.1';
$dbname = 'SystemDocument';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['status' => 'error', 'message' => 'Ошибка подzzключения к базе данных']);
    exit;
}

// Helper functions
function setFlashMessage($message, $type = 'success') {
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function getFlashMessages() {
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function validateFilename($filename, $extension = 'pdf') {
    if (empty($filename) || !preg_match('/^[a-zA-Z0-9_\-\s]+\.' . $extension . '$/', $filename) ||
        strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        return false;
    }
    return true;
}

// Category cache
$categoryCache = null;
function getCategories() {
    global $pdo, $categoryCache;
    if ($categoryCache === null) {
        $stmt = $pdo->query("SELECT id, number, category_name, category_short, payment_frequency, max_amount FROM categories ORDER BY number");
        $categoryCache = $stmt->fetchAll();
    }
    return $categoryCache;
}

// Group cache
$groupCache = null;
function getGroups() {
    global $pdo, $groupCache;
    if ($groupCache === null) {
        $stmt = $pdo->query("SELECT id, group_name FROM Groups ORDER BY group_name");
        $groupCache = $stmt->fetchAll();
    }
    return $groupCache;
}

// Get category name
function getCategoryName($category_id) {
    $categories = getCategories();
    foreach ($categories as $category) {
        if ($category['id'] == $category_id) {
            return $category['category_name'];
        }
    }
    return '';
}

// Get most recent academic year
function getMostRecentAcademicYear() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT year FROM AcademicYears ORDER BY year DESC LIMIT 1");
        $result = $stmt->fetch();
        return $result ? $result['year'] : null;
    } catch (PDOException $e) {
        return null;
    }
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');

    $action = $_POST['action'];
    $current_year = date('Y');

    if ($action === 'update_budget') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $budget = trim($_POST['budget'] ?? '');

        if (!$student_id || !in_array($budget, ['РФ', 'ХМАО'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные данные']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE Students SET budget = ? WHERE id = ?");
            $stmt->execute([$budget === '' ? null : $budget, $student_id]);
            echo json_encode(['status' => 'success', 'message' => 'Бюджет обновлен']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'update_full_name') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $full_name = trim($_POST['full_name'] ?? '');

        if (!$student_id || !$full_name || strlen($full_name) > 255) {
            echo json_encode(['status' => 'error', 'message' => 'Неверное ФИО']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE Students SET full_name = ? WHERE id = ?");
            $stmt->execute([$full_name, $student_id]);
            echo json_encode(['status' => 'success', 'message' => 'ФИО обновлено']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'update_group') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $group_id = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);

        if (!$student_id || !$group_id) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные данные']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM Groups WHERE id = ?");
            $stmt->execute([$group_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Группа не существует']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE Students SET group_id = ? WHERE id = ?");
            $stmt->execute([$group_id, $student_id]);
            echo json_encode(['status' => 'success', 'message' => 'Группа обновлена']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'get_groups') {
        try {
            $stmt = $pdo->query("SELECT id, group_name FROM Groups ORDER BY group_name");
            $groups = $stmt->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $groups]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'save_reason' || $action === 'update_reason') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT);
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT) ?: null;
        $custom_reason = trim($_POST['custom_reason'] ?? '');

        if (!$student_id || !$month || $month < 1 || $month > 12 || ($custom_reason && strlen($custom_reason) > 255)) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные входные данные']);
            exit;
        }

        try {
            if ($action === 'save_reason') {
                $stmt = $pdo->prepare("
                    INSERT INTO StudentReasons (student_id, month, category_id, custom_reason, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE category_id = ?, custom_reason = ?, created_at = NOW()
                ");
                $stmt->execute([$student_id, $month, $category_id, $custom_reason, $category_id, $custom_reason]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE StudentReasons 
                    SET category_id = ?, custom_reason = ?, created_at = NOW()
                    WHERE student_id = ? AND month = ?
                ");
                $stmt->execute([$category_id, $custom_reason, $student_id, $month]);
                if ($stmt->rowCount() === 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Запись не найдена']);
                    exit;
                }
            }
            echo json_encode(['status' => 'success']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'remove_reason') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT);

        if (!$student_id || !$month || $month < 1 || $month > 12) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные данные']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM StudentReasons WHERE student_id = ? AND month = ?");
            $stmt->execute([$student_id, $month]);
            echo json_encode(['status' => 'success']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'add_academic_year') {
        $year = trim($_POST['year'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{4})$/', $year, $matches)) {
            echo json_encode(['status' => 'error', 'message' => 'Неверный формат учебного года (например, 2024-2025)']);
            exit;
        }
        $start_year = (int)$matches[1];
        $end_year = (int)$matches[2];
        if ($end_year - $start_year !== 1) {
            echo json_encode(['status' => 'error', 'message' => 'Учебный год должен охватывать ровно один год']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM AcademicYears WHERE year = ?");
            $stmt->execute([$year]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Учебный год уже существует']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO AcademicYears (year) VALUES (?)");
            $stmt->execute([$year]);
            echo json_encode(['status' => 'success', 'year' => $year]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'add_student_to_default_category') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);

        if (!$student_id) {
            echo json_encode(['status' => 'error', 'message' => 'Неверный ID студента']);
            exit;
        }

        try {
            $academic_year = getMostRecentAcademicYear();
            if (!$academic_year) {
                echo json_encode(['status' => 'error', 'message' => 'Учебный год не найден']);
                exit;
            }

            $stmt = $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1");
            $category = $stmt->fetch();
            if (!$category) {
                echo json_encode(['status' => 'error', 'message' => 'Категории не найдены']);
                exit;
            }
            $category_id = $category['id'];

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO StudentCategories (student_id, category_id, academic_year, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$student_id, $category_id, $academic_year]);
            echo json_encode(['status' => 'success', 'message' => 'Студент добавлен в категорию по умолчанию']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'remove_student_from_category') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $academic_year = trim($_POST['academic_year'] ?? '');

        if (!$student_id || !$category_id || !$academic_year) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные данные']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                DELETE FROM StudentCategories 
                WHERE student_id = ? AND category_id = ? AND academic_year = ?
            ");
            $stmt->execute([$student_id, $category_id, $academic_year]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['status' => 'success', 'message' => 'Студент удален из категории']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Запись не найдена']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'reset_student_categories') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM StudentCategories");
            $stmt->execute();
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Все категории студентов очищены']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'get_students') {
        $schools = json_decode($_POST['schools'] ?? '[]', true);
        $years = json_decode($_POST['years'] ?? '[]', true);
        $regions = json_decode($_POST['regions'] ?? '[]', true);
        $fio = trim($_POST['fio'] ?? '');

        $query = "
            SELECT 
                s.id, 
                s.full_name, 
                s.budget, 
                g.id AS group_id,
                g.group_name,
                d.direction_name,
                sr.month, 
                sr.category_id, 
                sr.custom_reason,
                c.max_amount as amount
            FROM Students s
            JOIN Groups g ON s.group_id = g.id
            JOIN Directions d ON g.direction_id = d.code
            JOIN Schools sc ON d.vsh_code = sc.code
            JOIN StudentCategories scs ON s.id = scs.student_id
            LEFT JOIN StudentReasons sr ON s.id = sr.student_id
            LEFT JOIN categories c ON sr.category_id = c.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($schools) && !in_array('', $schools)) {
            $query .= " AND sc.code IN (" . implode(',', array_fill(0, count($schools), '?')) . ")";
            $params = array_merge($params, $schools);
        }

        if (!empty($years) && !in_array('', $years)) {
            $query .= " AND scs.academic_year IN (" . implode(',', array_fill(0, count($years), '?')) . ")";
            $params = array_merge($params, $years);
        }

        if (!empty($regions) && !in_array('none', $regions)) {
            $query .= " AND s.budget IN (" . implode(',', array_fill(0, count($regions), '?')) . ")";
            $params = array_merge($params, $regions);
        } elseif (in_array('none', $regions)) {
            $query .= " AND s.budget IS NULL";
        }

        if (!empty($fio)) {
            $query .= " AND s.full_name LIKE ?";
            $params[] = "%$fio%";
        }

        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $students = [];
            foreach ($rows as $row) {
                $student_id = $row['id'];
                if (!isset($students[$student_id])) {
                    $students[$student_id] = [
                        'id' => $row['id'],
                        'full_name' => $row['full_name'],
                        'budget' => $row['budget'],
                        'group_id' => $row['group_id'],
                        'group_name' => $row['group_name'],
                        'direction_name' => $row['direction_name'],
                        'reasons' => [],
                        'amount' => $row['amount'] ?? '10000'
                    ];
                }
                if ($row['month']) {
                    $reason_text = $row['custom_reason'] ?: ($row['category_id'] ? getCategoryName($row['category_id']) : '');
                    $students[$student_id]['reasons'][$row['month']] = $reason_text;
                }
            }

            echo json_encode(['status' => 'success', 'data' => array_values($students), 'total' => count($students)]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'get_all_students') {
        $fio = trim($_POST['fio'] ?? '');

        $query = "
            SELECT 
                s.id, 
                s.full_name, 
                s.budget, 
                g.id AS group_id,
                g.group_name
            FROM Students s
            JOIN Groups g ON s.group_id = g.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($fio)) {
            $query .= " AND s.full_name LIKE ?";
            $params[] = "%$fio%";
        }

        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $students = $stmt->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $students, 'total' => count($students)]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'get_category_by_number') {
        $number = trim($_POST['number'] ?? '');
        if (!$number) {
            echo json_encode(['status' => 'error', 'message' => 'Номер обязателен']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, category_short, category_name FROM categories WHERE number = ?");
            $stmt->execute([$number]);
            $category = $stmt->fetch();
            if ($category) {
                echo json_encode(['status' => 'success', 'data' => $category]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Категория не найдена']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    if ($action === 'generate_document') {
        $selected_students = json_decode($_POST['selected_students'] ?? '[]', true);
        $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT);
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

        if (empty($selected_students) || !$month || $month < 1 || $month > 12 || !$category_id) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные данные']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, category_name, category_short, max_amount FROM categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $category = $stmt->fetch();
            if (!$category) {
                echo json_encode(['status' => 'error', 'message' => 'Категория не найдена']);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($selected_students), '?'));
            $query = "
                SELECT s.id, s.full_name, g.group_name
                FROM Students s
                JOIN Groups g ON s.group_id = g.id
                WHERE s.id IN ($placeholders)
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($selected_students);
            $students = $stmt->fetchAll();

            $templateVars = [
                'CATEGORY_NAME' => $category['category_name'],
                'CATEGORY_SHORT' => $category['category_short'],
                'MAX_AMOUNT' => $category['max_amount'],
                'MONTH' => [
                    1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
                    5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
                    9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
                ][$month],
                'ACADEMIC_YEAR' => getMostRecentAcademicYear(),
                'CHAIR_DEGREE' => 'к.т.н., доцент',
                'SIGN_CHAIR' => 'И.И. Иванов',
                'SECRETARY_DEGREE' => 'к.п.н.',
                'SIGN_SECRETARY' => 'П.П. Петров'
            ];

            echo json_encode([
                'status' => 'success',
                'students' => $students,
                'templateVars' => $templateVars
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных']);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Неверное действие']);
    exit;
}

// Load interface data
try {
    $stmt = $pdo->query("SELECT code, name, abbreviation FROM Schools ORDER BY name");
    $schools = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT year FROM AcademicYears ORDER BY year DESC");
    $years = array_column($stmt->fetchAll(), 'year');

    $categories = getCategories();
    $groups = getGroups();
} catch (PDOException $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['status' => 'error', 'message' => 'Ошибка сервера']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система документооборота</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <style>
        :root {
            --ygu-black: #000000;
            --ygu-light-black: #000000;
            --ygu-gray: #eceff1;
            --ygu-dark-gray: #37474f;
            --ygu-blue: rgb(76, 84, 175);
            --ygu-light-blue: rgb(76, 84, 175);
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f5f6fa;
        }

        .container {
            max-width: 1600px;
        }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .filter-group, .school-year-group {
            flex: 1;
            min-width: 200px;
        }

        .fio-search-container {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .fio-search-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .budget-filter-btn {
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background-color: #fff;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 10px;
            font-weight: 500;
        }

        .budget-filter-btn.active {
            background-color: #007bff;
            color: #fff;
            border-color: #007bff;
        }

        .budget-filter-btn:hover {
            background-color: #e6f3ff;
            border-color: #0056b3;
        }

        .current-filters {
            margin-bottom: 15px;
            font-size: 0.95rem;
            color: #495057;
            font-weight: 500;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .data-table {
            width: 100%;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .data-table th {
            position: sticky;
            top: 0;
            background-color: #ffffff;
            color: #000000;
            font-weight: 600;
            padding: 12px;
            border-right: 1px solid #e9ecef;
            text-align: center;
            z-index: 10;
        }

        .data-table th:last-child {
            border-right: none;
        }

        .data-table td {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
            border-right: 1px solid #e9ecef;
            vertical-align: middle;
            text-align: center;
            position: relative;
        }

        .data-table td:last-child {
            border-right: none;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr {
            transition: background-color 0.2s;
        }

        .data-table tr:hover {
            background-color: #f1f3f5;
        }

        .data-table .month-checkbox {
            cursor: pointer;
        }

        .table-responsive {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
        }

        .table-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 20;
        }

        .table-loading.show {
            display: flex;
        }

        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }

        .notification {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background-color: #fff;
            opacity: 0;
            transform: translateX(100%);
            transition: opacity 0.3s, transform 0.3s;
        }

        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }

        .notification.hide {
            opacity: 0;
            transform: translateX(100%);
        }

        .notification.success {
            border-left: 5px solid #28a745;
        }

        .notification.error {
            border-left: 5px solid #dc3545;
        }

        .notification .icon {
            font-size: 1.3rem;
        }

        .notification .message {
            flex: 1;
            font-weight: 500;
        }

        .notification .close-btn {
            background: none;
            border: none;
            font-size: 1.1rem;
            cursor: pointer;
            color: #6c757d;
        }

        .modal-content {
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .modal.no-backdrop {
            background: transparent;
        }

        .modal-dialog {
            margin: 1.75rem auto;
            max-width: 600px;
        }

        .modal-dialog.modal-lg {
            max-width: 800px;
        }

        .error-message {
            color: #dc3545;
            font-size: 0.9rem;
            margin-top: 5px;
            display: none;
        }

        .modal-body label {
            font-weight: 500;
        }

        .modal-body input,
        .modal-body textarea {
            border-radius: 6px;
        }

        .category-search-container, .student-search-container {
            position: relative;
        }

        .category-search-dropdown, .student-search-dropdown, .group-search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .category-search-dropdown.show, .student-search-dropdown.show, .group-search-dropdown.show {
            display: block;
        }

        .category-search-item, .student-search-item, .group-search-item {
            padding: 8px 12px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .category-search-item:hover, .student-search-item:hover, .group-search-item:hover {
            background-color: #f1f3f5;
        }

        .category-search-item .category-number {
            font-weight: 600;
            color: #007bff;
        }

        .category-search-item .category-short {
            color: #495057;
        }

        .category-search-item .category-name {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .student-search-item .student-full-name {
            font-weight: 600;
            color: #007bff;
        }

        .student-search-item .student-group {
            color: #495057;
        }

        .student-search-item .student-budget {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .student-search-item.highlighted {
            background-color: #e6f3ff;
        }

        .group-search-item .group-name {
            font-weight: 600;
            color: #007bff;
        }

        .editable-cell {
            cursor: pointer;
            transition: background-color 0.2s;
            position: relative;
        }

        .editable-cell:hover {
            background-color: #e9ecef;
        }

        .editable-cell:focus {
            outline: none;
            border: 1px solid #007bff;
            border-radius: 4px;
        }

        .preview-container {
            background-color: #ffffff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-top: 20px;
        }

        .preview {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }

        .preview h1, .preview h2, .preview h3 {
            text-align: center;
            margin-bottom: 20px;
        }

        .preview table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .preview th, .preview td {
            border: 1px solid #e0e0e0;
            padding: 10px;
            text-align: center;
        }

        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }

        .signature {
            text-align: center;
            width: 45%;
        }

        .signature-line {
            display: block;
            border-bottom: 1px solid #000;
            margin: 10px 0;
            height: 1px;
        }

        .document-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php
    $header_file = 'header.html';
    if (file_exists($header_file)) {
        include $header_file;
    } else {
        ?>
        <header>
            <nav class="navbar navbar-expand-md navbar-dark bg-primary">
                <div class="container-fluid">
                    <a class="navbar-brand" href="/index.php">Система документооборота</a>
                    <button class="navbar-toggler" type="button" SelectionModal" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav ms-auto">
                            <li class="nav-item">
                                <a class="nav-link" href="/index.php">Список студентов</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="/schools.php">Высшие школы</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </header>
        <?php
    }
    ?>
    <div class="container mt-4">
        <h2>Список студентов</h2>
        <div class="filter-bar">
            <div class="school-year-group">
                <select id="school-filter" class="form-select" aria-label="Выбор школы">
                    <option value="">Все школы</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?php echo htmlspecialchars($school['code']); ?>">
                            <?php echo htmlspecialchars($school['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="year-filter" class="form-select mt-2" aria-label="Выбор учебного года">
                    <option value="">Все годы</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?php echo htmlspecialchars($year); ?>">
                            <?php echo htmlspecialchars($year); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#addYearModal">
                    <i class="bi bi-plus-circle"></i> Добавить учебный год
                </button>
            </div>
            <div class="filter-group">
                <label class="form-label">Сортировка:</label><br>
                <button class="budget-filter-btn" data-value="none">Нет</button>
                <button class="budget-filter-btn" data-value="РФ">РФ</button>
                <button class="budget-filter-btn" data-value="ХМАО">ХМАО</button>
                <button class="btn btn-outline-secondary btn-sm mt-2" id="reset-filters">
                    <i class="bi bi-x-circle"></i> Сбросить
                </button>
            </div>
            <div class="fio-search-container">
                <input type="text" id="fio-search" class="form-control" placeholder="Поиск по ФИО студента" aria-label="Поиск по ФИО студента">
                <i class="bi bi-search fio-search-icon"></i>
            </div>
        </div>
        <div class="current-filters" id="current-filters">
            Фильтры: Нет активных фильтров
        </div>
        <div class="table-header">
            <span id="student-count">Студентов: 0</span>
            <div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#selectStudentsModal">
                    <i class="bi bi-person-plus"></i> Добавить студентов
                </button>
            </div>
        </div>
        <div class="table-responsive position-relative">
            <table class="table data-table" aria-label="Таблица студентов">
                <thead>
                    <tr>
                        <th scope="col"><input type="checkbox" id="select-all-table-students" aria-label="Выбрать всех студентов"></th>
                        <th scope="col">#</th>
                        <th scope="col">ФИО</th>
                        <th scope="col">Группа</th>
                        <th scope="col">Бюджет</th>
                        <th scope="col">Январь</th>
                        <th scope="col">Февраль</th>
                        <th scope="col">Март</th>
                        <th scope="col">Апрель</th>
                        <th scope="col">Май</th>
                        <th scope="col">Июнь</th>
                        <th scope="col">Июль</th>
                        <th scope="col">Август</th>
                        <th scope="col">Сентябрь</th>
                        <th scope="col">Октябрь</th>
                        <th scope="col">Ноябрь</th>
                        <th scope="col">Декабрь</th>
                    </tr>
                </thead>
                <tbody id="student-table-body"></tbody>
            </table>
            <div class="table-loading" id="table-loading">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
            </div>
        </div>

        <!-- Document Preview Section -->
        <div class="preview-container" id="preview-container" style="display: none;">
            <div class="document-controls">
                <button class="btn btn-primary btn-sm" id="generate-pdf">Сохранить как PDF</button>
                <button class="btn btn-secondary btn-sm" id="close-preview">Закрыть предпросмотр</button>
            </div>
            <div class="preview">
                <h2>Приказ</h2>
                <h3>О назначении стипендии</h3>
                <p>В соответствии с <span data-placeholder="CATEGORY_NAME"><?php echo htmlspecialchars($templateVars['CATEGORY_NAME'] ?? '{CATEGORY_NAME}'); ?></span> на <span data-placeholder="MONTH"><?php echo htmlspecialchars($templateVars['MONTH'] ?? '{MONTH}'); ?></span> <span data-placeholder="ACADEMIC_YEAR"><?php echo htmlspecialchars($templateVars['ACADEMIC_YEAR'] ?? '{ACADEMIC_YEAR}'); ?></span> учебный год</p>
                <p><strong>ПРИКАЗЫВАЮ:</strong></p>
                <p>Назначить стипендию в размере <span data-placeholder="MAX_AMOUNT"><?php echo htmlspecialchars($templateVars['MAX_AMOUNT'] ?? '{MAX_AMOUNT}'); ?></span> рублей следующим студентам:</p>
                <table>
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>ФИО</th>
                            <th>Группа</th>
                        </tr>
                    </thead>
                    <tbody id="preview-student-table"></tbody>
                </table>
                <div class="signatures">
                    <div class="signature">
                        Руководитель инженерной школы цифровых технологий,<br>
                        <span data-placeholder="CHAIR_DEGREE"><?php echo htmlspecialchars($templateVars['CHAIR_DEGREE'] ?? '{CHAIR_DEGREE}'); ?></span><br>
                        <span class="signature-line"></span>
                        <span data-placeholder="SIGN_CHAIR"><?php echo htmlspecialchars($templateVars['SIGN_CHAIR'] ?? '{SIGN_CHAIR}'); ?></span>
                    </div>
                    <div class="signature">
                        Заместитель руководителя инженерной школы цифровых технологий по воспитательной работе,<br>
                        <span data-placeholder="SECRETARY_DEGREE"><?php echo htmlspecialchars($templateVars['SECRETARY_DEGREE'] ?? '{SECRETARY_DEGREE}'); ?></span><br>
                        <span class="signature-line"></span>
                        <span data-placeholder="SIGN_SECRETARY"><?php echo htmlspecialchars($templateVars['SIGN_SECRETARY'] ?? '{SIGN_SECRETARY}'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification container -->
    <div class="notification-container">
        <?php foreach (getFlashMessages() as $flash): ?>
            <div class="notification <?php echo htmlspecialchars($flash['type']); ?>" role="alert">
                <i class="bi bi-<?php echo $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'; ?> icon"></i>
                <span class="message"><?php echo htmlspecialchars($flash['message']); ?></span>
                <button class="close-btn" aria-label="Закрыть уведомление">×</button>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Modal for reason input/selection -->
    <div class="modal fade no-backdrop" id="reasonModal" tabindex="-1" aria-labelledby="reasonModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reasonModalLabel">Выбор основания</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 category-search-container">
                        <label for="category-number" class="form-label">№ п/п:</label>
                        <input type="text" id="category-number" class="form-control" placeholder="Введите номер категории" aria-label="Номер категории">
                        <div id="category-search-dropdown" class="category-search-dropdown"></div>
                        <div id="category-error" class="error-message"></div>
                    </div>
                    <div class="mb-3">
                        <label for="customReason" class="form-label">Своё основание:</label>
                        <textarea id="customReason" class="form-control" placeholder="Введите своё основание" aria-label="Своё основание"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="saveReason">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for adding academic year -->
    <div class="modal fade no-backdrop" id="addYearModal" tabindex="-1" aria-labelledby="addYearModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addYearModalLabel">Добавить учебный год</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="academicYearInput" class="form-label">Год (YYYY-YYYY):</label>
                        <input type="text" class="form-control" id="academicYearInput" placeholder="2024-2025" aria-label="Учебный год">
                        <div id="yearError" class="error-message"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="saveAcademicYear">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for selecting students -->
    <div class="modal fade no-backdrop" id="selectStudentsModal" tabindex="-1" aria-labelledby="selectStudentsModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="selectStudentsModalLabel">Добавить студентов</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 student-search-container">
                        <label for="student-search" class="form-label">Поиск по ФИО:</label>
                        <div class="fio-search-container">
                            <input type="text" id="student-search" class="form-control" placeholder="Введите ФИО" aria-label="Поиск по ФИО">
                            <i class="bi bi-search fio-search-icon"></i>
                        </div>
                        <div id="student-search-dropdown" class="student-search-dropdown"></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered" aria-label="Таблица выбора студентов">
                            <thead>
                                <tr>
                                    <th scope="col"><input type="checkbox" id="select-all-students" aria-label="Выбрать всех студентов"></th>
                                    <th scope="col">ФИО</th>
                                    <th scope="col">Группа</th>
                                    <th scope="col">Бюджет</th>
                                </tr>
                            </thead>
                            <tbody id="student-selection-table"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" id="clear-selection">Очистить выбор</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <button type="button" class="btn btn-primary" id="add-selected-students">Добавить выбранных</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for confirming reset -->
    <div class="modal fade no-backdrop" id="confirmResetModal" tabindex="-1" aria-labelledby="confirmResetModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmResetModalLabel">Подтверждение сброса</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите сбросить все параметры и удалить всех студентов из категорий? Это действие нельзя отменить.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-danger" id="confirmReset">Сбросить</button>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg==" crossorigin="anonymous"></script>
    <script>
        let studentCache = null;
        let lastFilterHash = '';
        let selectedSchool = '';
        let selectedYear = '';
        const schools = <?php echo json_encode($schools); ?>;
        const categories = <?php echo json_encode($categories); ?>;
        const groups = <?php echo json_encode($groups); ?>;
        let modalStudentCache = null;
        let activeBudgetFilters = [];
        let selectedStudents = [];

        function showNotification(message, type = 'success') {
            const container = document.querySelector('.notification-container');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.setAttribute('role', 'alert');
            notification.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'} icon"></i>
                <span class="message">${message}</span>
                <button class="close-btn" aria-label="Закрыть уведомление">×</button>
            `;
            container.appendChild(notification);

            setTimeout(() => notification.classList.add('show'), 100);

            const timeout = setTimeout(() => {
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => notification.remove(), 300);
            }, 5000);

            notification.querySelector('.close-btn').addEventListener('click', () => {
                clearTimeout(timeout);
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => notification.remove(), 300);
            });

            notification.addEventListener('click', () => {
                clearTimeout(timeout);
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => notification.remove(), 300);
            });
        }

        function initializeTooltips() {
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltipTriggerList.forEach(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
        }

        function closeAllDropdowns(exceptMenu) {
            const menus = [
                document.getElementById('category-search-dropdown'),
                document.getElementById('student-search-dropdown'),
                document.getElementById('document-category-dropdown'),
                document.getElementById('group-search-dropdown')
            ];
            menus.forEach(menu => {
                if (menu && menu !== exceptMenu && menu.classList.contains('show')) {
                    menu.classList.remove('show');
                }
            });
        }

        function displayCurrentFilters() {
            const schoolFilter = document.getElementById('school-filter').value;
            const yearFilter = document.getElementById('year-filter').value;
            const fio = document.getElementById('fio-search').value.trim();
            const currentFiltersDiv = document.getElementById('current-filters');

            let filterText = 'Фильтры: ';
            let filters = [];

            if (schoolFilter) {
                const school = schools.find(s => s.code === schoolFilter);
                filters.push(`Школа: ${school ? school.name + ' (' + school.code + ')' : schoolFilter}`);
            }
            if (yearFilter) {
                filters.push(`Год: ${yearFilter}`);
            }
            if (activeBudgetFilters.length > 0) {
                filters.push(`Бюджет: ${activeBudgetFilters.join(', ')}`);
            }
            if (fio) {
                filters.push(`ФИО: ${fio}`);
            }

            filterText += filters.length > 0 ? filters.join(', ') : 'Нет активных фильтров';
            currentFiltersDiv.textContent = filterText;
        }

        async function resetFilters(showModal = false) {
            selectedSchool = '';
            selectedYear = '';
            document.getElementById('school-filter').value = '';
            document.getElementById('year-filter').value = '';
            activeBudgetFilters = [];
            document.querySelectorAll('.budget-filter-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('fio-search').value = '';
            studentCache = null;
            modalStudentCache = null;
            lastFilterHash = '';
            selectedStudents = [];
            displayCurrentFilters();

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=reset_student_categories'
                });
                const data = await response.json();

                if (data.status !== 'success') {
                    showNotification(data.message || 'Ошибка сброса категорий студентов', 'error');
                    return;
                }
            } catch (error) {
                showNotification('Ошибка сети: ' + error.message, 'error');
                return;
            }

            const tbody = document.getElementById('student-table-body');
            tbody.innerHTML = `
                <tr>
                    <td colspan="17" class="text-center">
                        Студенты не найдены. 
                        <a href="#" class="add-students-link">Добавить студентов</a>
                    </td>
                </tr>
            `;
            document.getElementById('student-count').textContent = 'Студентов: 0';

            if (!showModal) {
                showNotification('Все параметры и категории студентов сброшены', 'success');
            }

            debouncedUpdateTable();
        }

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        const confirmResetModal = new bootstrap.Modal(document.getElementById('confirmResetModal'));
        document.getElementById('confirmReset').addEventListener('click', async () => {
            await resetFilters(true);
            confirmResetModal.hide();
            setTimeout(() => {
                document.getElementById('reset-filters').focus();
            }, 100);
        });

        document.getElementById('reset-filters').addEventListener('click', (e) => {
            e.preventDefault();
            confirmResetModal.show();
        });

        document.getElementById('school-filter').addEventListener('change', function() {
            selectedSchool = this.value;
            studentCache = null;
            debouncedUpdateTable();
        });

        document.getElementById('year-filter').addEventListener('change', function() {
            selectedYear = this.value;
            studentCache = null;
            debouncedUpdateTable();
        });

        document.querySelectorAll('.budget-filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                const value = this.dataset.value;
                if (activeBudgetFilters.includes(value)) {
                    activeBudgetFilters = activeBudgetFilters.filter(v => v !== value);
                    this.classList.remove('active');
                } else {
                    activeBudgetFilters.push(value);
                    this.classList.add('active');
                }
                studentCache = null;
                displayCurrentFilters();
                debouncedUpdateTable();
            });
        });

        document.addEventListener('click', function(event) {
            const categoryDropdown = document.getElementById('category-search-dropdown');
            const categoryInput = document.getElementById('category-number');
            const studentDropdown = document.getElementById('student-search-dropdown');
            const studentInput = document.getElementById('student-search');
            const docCategoryDropdown = document.getElementById('document-category-dropdown');
            const docCategoryInput = document.getElementById('document-category');
            const groupDropdown = document.getElementById('group-search-dropdown');
            if (categoryDropdown && !categoryInput.contains(event.target) && !categoryDropdown.contains(event.target)) {
                categoryDropdown.classList.remove('show');
            }
            if (studentDropdown && !studentInput.contains(event.target) && !studentDropdown.contains(event.target)) {
                studentDropdown.classList.remove('show');
            }
            if (docCategoryDropdown && !docCategoryInput.contains(event.target) && !docCategoryDropdown.contains(event.target)) {
                docCategoryDropdown.classList.remove('show');
            }
            if (groupDropdown && !groupDropdown.contains(event.target)) {
                groupDropdown.classList.remove('show');
            }
        });

        const reasonModal = new bootstrap.Modal(document.getElementById('reasonModal'));
        let currentCheckbox = null;

        // Category search functionality for reason modal
        const categoryNumberInput = document.getElementById('category-number');
        const categorySearchDropdown = document.getElementById('category-search-dropdown');
        const categoryError = document.getElementById('category-error');

        function populateCategoryDropdown(searchTerm, dropdownId, inputElement) {
            const dropdown = document.getElementById(dropdownId);
            dropdown.innerHTML = '';
            if (!searchTerm) {
                dropdown.classList.remove('show');
                return;
            }

            const filteredCategories = categories.filter(category => 
                category.number.toString().startsWith(searchTerm)
            );

            if (filteredCategories.length === 0) {
                dropdown.innerHTML = '<div class="category-search-item">Категории не найдены</div>';
                dropdown.classList.add('show');
                return;
            }

            filteredCategories.forEach(category => {
                const item = document.createElement('div');
                item.className = 'category-search-item';
                item.innerHTML = `
                    <div class="category-number">${category.number}</div>
                    <div class="category-short">${category.category_short}</div>
                    <div class="category-name">${category.category_name}</div>
                `;
                item.addEventListener('click', () => {
                    inputElement.value = category.number;
                    inputElement.dataset.categoryId = category.id;
                    const errorElement = document.getElementById(dropdownId.replace('dropdown', 'error'));
                    errorElement.textContent = '';
                    errorElement.style.display = 'none';
                    inputElement.classList.remove('is-invalid');
                    dropdown.classList.remove('show');
                });
                dropdown.appendChild(item);
            });

            dropdown.classList.add('show');
        }

        categoryNumberInput.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            categoryError.textContent = '';
            categoryError.style.display = 'none';
            this.classList.remove('is-invalid');
            delete this.dataset.categoryId;

            populateCategoryDropdown(searchTerm, 'category-search-dropdown', this);
        });

        // Validate category number with debounce
        const debouncedValidateCategory = debounce(async function(number, inputElement, errorElementId, dropdownId) {
            const errorElement = document.getElementById(errorElementId);
            if (!number) {
                errorElement.textContent = '';
                errorElement.style.display = 'none';
                inputElement.classList.remove('is-invalid');
                delete inputElement.dataset.categoryId;
                return;
            }

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_category_by_number&number=${encodeURIComponent(number)}`
                });
                const result = await response.json();

                if (result.status === 'success') {
                    inputElement.dataset.categoryId = result.data.id;
                    errorElement.textContent = '';
                    errorElement.style.display = 'none';
                    inputElement.classList.remove('is-invalid');
                } else {
                    inputElement.classList.add('is-invalid');
                    errorElement.textContent = 'Категория с таким номером не существует';
                    errorElement.style.display = 'block';
                }
            } catch (error) {
                inputElement.classList.add('is-invalid');
                errorElement.textContent = 'Ошибка сети. Пожалуйста, попробуйте снова.';
                errorElement.style.display = 'block';
                showNotification('Ошибка сети: ' + error.message, 'error');
            }
        }, 500);

        categoryNumberInput.addEventListener('input', function() {
            debouncedValidateCategory(this.value.trim(), this, 'category-error', 'category-search-dropdown');
        });

        document.getElementById('reasonModal').addEventListener('hidden.bs.modal', function() {
            categoryNumberInput.value = '';
            document.getElementById('customReason').value = '';
            categoryNumberInput.classList.remove('is-invalid');
            document.getElementById('customReason').classList.remove('is-invalid');
            categoryError.textContent = '';
            categoryError.style.display = 'none';
            categorySearchDropdown.classList.remove('show');
            if (currentCheckbox) {
                const existingReason = currentCheckbox.dataset.reason || '';
                currentCheckbox.checked = !!existingReason;
            }
            currentCheckbox = null;
            setTimeout(() => {
                document.querySelector('.data-table').focus();
            }, 100);
        });

        document.getElementById('saveReason').addEventListener('click', async function() {
            const categoryId = categoryNumberInput.dataset.categoryId || null;
            const customReason = document.getElementById('customReason').value.trim();

            if (!categoryId && !customReason) {
                categoryNumberInput.classList.add('is-invalid');
                categoryError.textContent = 'Укажите номер категории или введите своё основание';
                categoryError.style.display = 'block';
                showNotification('Укажите номер категории или введите своё основание', 'error');
                return;
            }

            if (customReason.length > 255) {
                document.getElementById('customReason').classList.add('is-invalid');
                categoryError.textContent = 'Своё основание не должно превышать 255 символов';
                categoryError.style.display = 'block';
                showNotification('Своё основание слишком длинное', 'error');
                return;
            }

            if (currentCheckbox) {
                const student_id = currentCheckbox.dataset.student;
                const month = currentCheckbox.dataset.month;
                const academicYear = selectedYear || <?php echo json_encode(getMostRecentAcademicYear()); ?>;
                const action = currentCheckbox.dataset.reason ? 'update_reason' : 'save_reason';

                if (!academicYear) {
                    showNotification('Учебный год не выбран и не найден в базе данных', 'error');
                    return;
                }

                try {
                    const reasonResponse = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=${action}&student_id=${student_id}&month=${month}&category_id=${categoryId || ''}&custom_reason=${encodeURIComponent(customReason)}`
                    });
                    const reasonData = await reasonResponse.json();

                    if (reasonData.status !== 'success') {
                        categoryNumberInput.classList.add('is-invalid');
                        categoryError.textContent = reasonData.message || 'Ошибка сохранения основания';
                        categoryError.style.display = 'block';
                        showNotification(reasonData.message || 'Ошибка сохранения основания', 'error');
                        return;
                    }

                    const reason = customReason || (categoryId ? <?php echo json_encode(array_column($categories, 'category_name', 'id')); ?>[categoryId] : '');
                    currentCheckbox.setAttribute('data-reason', reason);
                    currentCheckbox.setAttribute('data-bs-toggle', 'tooltip');
                    currentCheckbox.setAttribute('data-bs-title', reason);
                    currentCheckbox.checked = !!reason;
                    new bootstrap.Tooltip(currentCheckbox);
                    reasonModal.hide();
                    showNotification(action === 'save_reason' ? 'Основание сохранено' : 'Основание обновлено', 'success');
                    studentCache = null;
                    modalStudentCache = null;
                    debouncedUpdateTable();
                } catch (error) {
                    categoryNumberInput.classList.add('is-invalid');
                    categoryError.textContent = 'Ошибка сети. Пожалуйста, попробуйте снова.';
                    categoryError.style.display = 'block';
                    showNotification('Ошибка сети: ' + error.message, 'error');
                }
            }
        });

        const addYearModal = new bootstrap.Modal(document.getElementById('addYearModal'));

        document.getElementById('addYearModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('academicYearInput').value = '';
            document.getElementById('yearError').textContent = '';
            document.getElementById('yearError').style.display = 'none';
            document.getElementById('academicYearInput').classList.remove('is-invalid');
            setTimeout(() => {
                document.getElementById('year-filter').focus();
            }, 100);
        });

        document.getElementById('saveAcademicYear').addEventListener('click', async function() {
            const yearInput = document.getElementById('academicYearInput');
            const yearError = document.getElementById('yearError');
            const yearValue = yearInput.value.trim();

            if (!yearValue.match(/^\d{4}-\d{4}$/)) {
                yearInput.classList.add('is-invalid');
                yearError.textContent = 'Формат: YYYY-YYYY (например, 2024-2025)';
                yearError.style.display = 'block';
                showNotification('Некорректный формат учебного года', 'error');
                return;
            }

            const [startYear, endYear] = yearValue.split('-').map(Number);
            if (endYear - startYear !== 1) {
                yearInput.classList.add('is-invalid');
                yearError.textContent = 'Учебный год должен охватывать ровно один год';
                yearError.style.display = 'block';
                showNotification('Некорректный учебный год', 'error');
                return;
            }

            yearInput.classList.remove('is-invalid');
            yearError.style.display = 'none';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=add_academic_year&year=${encodeURIComponent(yearValue)}`
                });
                const data = await response.json();

                if (data.status === 'success') {
                    const yearSelect = document.getElementById('year-filter');
                    const newOption = document.createElement('option');
                    newOption.value = data.year;
                    newOption.textContent = data.year;
                    yearSelect.insertBefore(newOption, yearSelect.children[1]);
                    yearSelect.value = data.year;
                    selectedYear = data.year;
                    addYearModal.hide();
                    showNotification(`Учебный год ${data.year} добавлен`, 'success');
                    studentCache = null;
                    debouncedUpdateTable();
                } else {
                    yearInput.classList.add('is-invalid');
                    yearError.textContent = data.message || 'Ошибка добавления года';
                    yearError.style.display = 'block';
                    showNotification(data.message || 'Ошибка добавления учебного года', 'error');
                }
            } catch (error) {
                yearInput.classList.add('is-invalid');
                yearError.textContent = 'Ошибка сети. Пожалуйста, попробуйте снова.';
                yearError.style.display = 'block';
                showNotification('Ошибка сети: ' + error.message, 'error');
            }
        });

        const selectStudentsModal = new bootstrap.Modal(document.getElementById('selectStudentsModal'));

        document.getElementById('selectStudentsModal').addEventListener('hidden.bs.modal', function() {
            const studentSearchInput = document.getElementById('student-search');
            studentSearchInput.value = '';
            const studentSearchDropdown = document.getElementById('student-search-dropdown');
            studentSearchDropdown.innerHTML = '';
            studentSearchDropdown.classList.remove('show');
            modalStudentCache = null;
            setTimeout(() => {
                document.querySelector('.btn[data-bs-target="#selectStudentsModal"]').focus();
            }, 100);
        });

        // Student search dropdown functionality
        const studentSearchInput = document.getElementById('student-search');
        const studentSearchDropdown = document.getElementById('student-search-dropdown');

        async function populateStudentSearchDropdown(searchTerm) {
            studentSearchDropdown.innerHTML = '';
            if (!searchTerm) {
                studentSearchDropdown.classList.remove('show');
                return;
            }

            if (modalStudentCache && modalStudentCache.fio === searchTerm) {
                modalStudentCache.data.forEach(student => {
                    const item = document.createElement('div');
                    item.className = `student-search-item ${searchTerm.toLowerCase() === student.full_name.toLowerCase() ? 'highlighted' : ''}`;
                    item.innerHTML = `
                        <div class="student-full-name">${student.full_name}</div>
                        <div class="student-group">${student.group_name}</div>
                        <div class="student-budget">${student.budget || '-'}</div>
                    `;
                    item.addEventListener('click', async () => {
                        try {
                            const response = await fetch('', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `action=add_student_to_default_category&student_id=${student.id}`
                            });
                            const addResult = await response.json();

                            if (addResult.status === 'success') {
                                showNotification('Студент добавлен', 'success');
                                studentCache = null;
                                modalStudentCache = null;
                                debouncedUpdateTable();
                                studentSearchInput.value = '';
                                studentSearchDropdown.classList.remove('show');
                                const checkbox = document.querySelector(`.student-checkbox[value="${student.id}"]`);
                                if (checkbox) {
                                    checkbox.checked = true;
                                }
                            } else {
                                showNotification(addResult.message || 'Ошибка добавления студента', 'error');
                            }
                        } catch (error) {
                            showNotification('Ошибка сети: ' + error.message, 'error');
                        }
                    });
                    studentSearchDropdown.appendChild(item);
                });
                studentSearchDropdown.classList.add('show');
                return;
            }

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_all_students&fio=${encodeURIComponent(searchTerm)}`
                });
                const result = await response.json();

                if (result.status !== 'success' || result.data.length === 0) {
                    studentSearchDropdown.innerHTML = '<div class="student-search-item">Студенты не найдены</div>';
                    studentSearchDropdown.classList.add('show');
                    return;
                }

                result.data.forEach(student => {
                    const item = document.createElement('div');
                    item.className = `student-search-item ${searchTerm.toLowerCase() === student.full_name.toLowerCase() ? 'highlighted' : ''}`;
                    item.innerHTML = `
                        <div class="student-full-name">${student.full_name}</div>
                        <div class="student-group">${student.group_name}</div>
                        <div class="student-budget">${student.budget || '-'}</div>
                    `;
                    item.addEventListener('click', async () => {
                        try {
                            const response = await fetch('', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `action=add_student_to_default_category&student_id=${student.id}`
                            });
                            const addResult = await response.json();

                            if (addResult.status === 'success') {
                                showNotification('Студент добавлен', 'success');
                                studentCache = null;
                                modalStudentCache = null;
                                debouncedUpdateTable();
                                studentSearchInput.value = '';
                                studentSearchDropdown.classList.remove('show');
                                const checkbox = document.querySelector(`.student-checkbox[value="${student.id}"]`);
                                if (checkbox) {
                                    checkbox.checked = true;
                                }
                            } else {
                                showNotification(addResult.message || 'Ошибка добавления студента', 'error');
                            }
                        } catch (error) {
                            showNotification('Ошибка сети: ' + error.message, 'error');
                        }
                    });
                    studentSearchDropdown.appendChild(item);
                });

                studentSearchDropdown.classList.add('show');
            } catch (error) {
                studentSearchDropdown.innerHTML = '<div class="student-search-item">Ошибка загрузки</div>';
                studentSearchDropdown.classList.add('show');
                showNotification('Ошибка загрузки студентов: ' + error.message, 'error');
            }
        }

        const debouncedPopulateStudentDropdown = debounce(populateStudentSearchDropdown, 300);

        studentSearchInput.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            debouncedPopulateStudentDropdown(searchTerm);
            debouncedLoadStudents(searchTerm);
        });

        async function loadStudentsToModal(fio = '') {
            const tableBody = document.getElementById('student-selection-table');
            if (modalStudentCache && modalStudentCache.fio === fio && tableBody.children.length > 0) {
                return;
            }

            tableBody.innerHTML = '<tr><td colspan="4" class="text-center">Загрузка...</td></tr>';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_all_students&fio=${encodeURIComponent(fio)}`
                });
                const result = await response.json();

                tableBody.innerHTML = '';
                if (result.status === 'success' && result.data.length > 0) {
                    modalStudentCache = { fio, data: result.data };
                    result.data.forEach(student => {
                        const row = document.createElement('tr');
                        const isHighlighted = fio && student.full_name.toLowerCase().includes(fio.toLowerCase());
                        row.innerHTML = `
                            <td><input type="checkbox" class="student-checkbox" value="${student.id}" ${isHighlighted ? 'checked' : ''} aria-label="Выбрать студента ${student.full_name}"></td>
                            <td>${student.full_name}</td>
                            <td>${student.group_name}</td>
                            <td>${student.budget || '-'}</td>
                        `;
                        if (isHighlighted) {
                            row.classList.add('highlighted');
                        }
                        tableBody.appendChild(row);
                    });
                } else {
                    modalStudentCache = null;
                    tableBody.innerHTML = '<tr><td colspan="4" class="text-center">Студенты не найдены</td></tr>';
                }
            } catch (error) {
                modalStudentCache = null;
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center">Ошибка загрузки</td></tr>';
                showNotification('Ошибка загрузки студентов: ' + error.message, 'error');
            }
        }

        const debouncedLoadStudents = debounce(loadStudentsToModal, 300);

        document.getElementById('add-selected-students').addEventListener('click', async () => {
            const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                showNotification('Выберите хотя бы одного студента', 'error');
                return;
            }

            try {
                for (const checkbox of selectedCheckboxes) {
                    const studentId = checkbox.value;
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=add_student_to_default_category&student_id=${studentId}`
                    });
                    const result = await response.json();

                    if (result.status !== 'success') {
                        showNotification(result.message || `Ошибка добавления студента с ID ${studentId}`, 'error');
                    }
                }
                showNotification('Выбранные студенты добавлены', 'success');
                studentCache = null;
                modalStudentCache = null;
                debouncedUpdateTable();
                selectStudentsModal.hide();
            } catch (error) {
                showNotification('Ошибка сети: ' + error.message, 'error');
            }
        });

        document.getElementById('clear-selection').addEventListener('click', () => {
            document.querySelectorAll('.student-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('select-all-students').checked = false;
            showNotification('Выбор студентов очищен', 'success');
        });

        document.getElementById('selectStudentsModal').addEventListener('shown.bs.modal', function() {
            const studentSearch = document.getElementById('student-search').value.trim();
            if (!modalStudentCache || modalStudentCache.fio !== studentSearch) {
                loadStudentsToModal(studentSearch);
            }
            populateStudentSearchDropdown(studentSearch);
            setTimeout(() => {
                document.getElementById('student-search').focus();
            }, 100);
        });

        document.getElementById('select-all-students').addEventListener('change', function() {
            document.querySelectorAll('.student-checkbox').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        async function updateTable() {
            const tableLoading = document.getElementById('table-loading');
            tableLoading.classList.add('show');

            const school = selectedSchool;
            const year = selectedYear;
            const fio = document.getElementById('fio-search').value.trim();

            const filterHash = JSON.stringify({ year, school, regions: activeBudgetFilters, fio });
            if (studentCache && lastFilterHash === filterHash) {
                renderTable(studentCache);
                tableLoading.classList.remove('show');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'get_students');
            formData.append('schools', JSON.stringify(school ? [school] : []));
            formData.append('years', JSON.stringify(year ? [year] : []));
            formData.append('regions', JSON.stringify(activeBudgetFilters));
            formData.append('fio', fio);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: new URLSearchParams(formData)
                });
                const result = await response.json();
                studentCache = result;
                lastFilterHash = filterHash;
                renderTable(result);
            } catch (error) {
                showNotification('Ошибка загрузки данных: ' + error.message, 'error');
                document.getElementById('student-count').textContent = 'Студентов: 0';
                renderTable({ status: 'error', data: [], total: 0 });
            } finally {
                tableLoading.classList.remove('show');
            }
        }

        const debouncedUpdateTable = debounce(updateTable, 300);

        function renderTable(result) {
            const tbody = document.getElementById('student-table-body');
            tbody.innerHTML = '';

            if (result.status !== 'success' || result.data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="16" class="text-center">
                            Студенты не найдены. 
                            <a href="#" class="add-students-link">Добавить студентов</a>
                        </td>
                    </tr>
                `;
                document.getElementById('student-count').textContent = 'Студентов: 0';
            } else {
                const students = result.data;
                students.forEach((student, index) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td scope="row">${index + 1}</td>
                        <td class="editable-cell" contenteditable="true" data-field="full_name" data-student-id="${student.id}" data-bs-toggle="tooltip" data-bs-title="${student.direction_name || 'Нет направления'}" data-bs-placement="top">${student.full_name}</td>
                        <td class="editable-cell" data-field="group" data-student-id="${student.id}" data-group-id="${student.group_id}">
                            <select class="group-select" data-current="${student.group_id}" aria-label="Выбор группы">
                                ${groups.map(group => `
                                    <option value="${group.id}" ${group.id == student.group_id ? 'selected' : ''}>
                                        ${group.group_name}
                                    </option>
                                `).join('')}
                            </select>
                        </td>
                        <td class="editable-cell" data-field="budget" data-student-id="${student.id}">
                            <select class="budget-select" data-current="${student.budget || '-'}" aria-label="Выбор бюджета">
                                <option value="РФ" ${student.budget === 'РФ' ? 'selected' : ''}>РФ</option>
                                <option value="ХМАО" ${student.budget === 'ХМАО' ? 'selected' : ''}>ХМАО</option>
                            </select>
                        </td>
                        ${Array.from({length: 12}, (_, i) => {
                            const month = i + 1;
                            const reason = student.reasons[month] || '';
                            return `
                                <td>
                                    <input type="checkbox" class="month-checkbox" 
                                           data-student="${student.id}" 
                                           data-month="${month}" 
                                           ${reason ? `checked data-reason="${reason.replace(/"/g, '"')}" data-bs-toggle="tooltip" data-bs-title="${reason.replace(/"/g, '"')}"` : ''}
                                           data-bs-placement="top" data-bs-delay='{"show":100,"hide":100}' aria-label="Основание за ${month} месяц">
                                </td>
                            `;
                        }).join('')}
                    `;
                    tbody.appendChild(row);
                });
                document.getElementById('student-count').textContent = `Студентов: ${result.total}`;
            }

            initializeTooltips();
            displayCurrentFilters();

            // Handle full_name editing
            document.querySelectorAll('.editable-cell[data-field="full_name"]').forEach(cell => {
                cell.addEventListener('blur', async function() {
                    const studentId = this.dataset.studentId;
                    const newFullName = this.textContent.trim();
                    const originalFullName = studentCache.data.find(s => s.id == studentId)?.full_name || '';

                    if (!newFullName || newFullName.length > 255) {
                        showNotification('ФИО не может быть пустым или длиннее 255 символов', 'error');
                        this.textContent = originalFullName;
                        return;
                    }

                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=update_full_name&student_id=${studentId}&full_name=${encodeURIComponent(newFullName)}`
                        });
                        const result = await response.json();

                        if (result.status === 'success') {
                            showNotification('ФИО обновлено', 'success');
                            studentCache = null;
                            modalStudentCache = null;
                            debouncedUpdateTable();
                        } else {
                            showNotification(result.message || 'Ошибка обновления ФИО', 'error');
                            this.textContent = originalFullName;
                        }
                    } catch (error) {
                        showNotification('Ошибка сети: ' + error.message, 'error');
                        this.textContent = originalFullName;
                    }
                });

                cell.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        this.blur();
                    }
                });
            });

            // Handle group editing
            document.querySelectorAll('.editable-cell[data-field="group"] .group-select').forEach(select => {
                select.addEventListener('change', async function() {
                    const studentId = this.parentElement.dataset.studentId;
                    const newGroupId = this.value;
                    const originalGroupId = this.dataset.current;

                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=update_group&student_id=${studentId}&group_id=${newGroupId}`
                        });
                        const result = await response.json();

                        if (result.status === 'success') {
                            showNotification('Группа обновлена', 'success');
                            this.dataset.current = newGroupId;
                            studentCache = null;
                            modalStudentCache = null;
                            debouncedUpdateTable();
                        } else {
                            showNotification(result.message || 'Ошибка обновления группы', 'error');
                            this.value = originalGroupId;
                        }
                    } catch (error) {
                        showNotification('Ошибка сети: ' + error.message, 'error');
                        this.value = originalGroupId;
                    }
                });
            });

            // Handle budget editing
            document.querySelectorAll('.editable-cell[data-field="budget"] .budget-select').forEach(select => {
                select.addEventListener('change', async function() {
                    const studentId = this.parentElement.dataset.studentId;
                    const newBudget = this.value;
                    const originalBudget = this.dataset.current || '-';

                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=update_budget&student_id=${studentId}&budget=${encodeURIComponent(newBudget)}`
                        });
                        const result = await response.json();

                        if (result.status === 'success') {
                            showNotification('Бюджет обновлен', 'success');
                            this.dataset.current = newBudget;
                            studentCache = null;
                            modalStudentCache = null;
                            debouncedUpdateTable();
                        } else {
                            showNotification(result.message || 'Ошибка обновления бюджета', 'error');
                            this.value = originalBudget;
                        }
                    } catch (error) {
                        showNotification('Ошибка сети: ' + error.message, 'error');
                        this.value = originalBudget;
                    }
                });
            });

            // Handle month checkbox clicks
            document.querySelectorAll('.month-checkbox').forEach(checkbox => {
                checkbox.addEventListener('click', function(event) {
                    event.preventDefault();
                    currentCheckbox = this;
                    const existingReason = this.dataset.reason || '';
                    const studentId = this.dataset.student;
                    const month = this.dataset.month;

                    if (existingReason) {
                        if (this.checked) {
                            reasonModal.show();
                            const category = categories.find(cat => cat.category_name === existingReason);
                            if (category) {
                                categoryNumberInput.value = category.number;
                                categoryNumberInput.dataset.categoryId = category.id;
                            } else {
                                document.getElementById('customReason').value = existingReason;
                            }
                        } else {
                            if (confirm('Вы уверены, что хотите удалить основание?')) {
                                fetch('', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: `action=remove_reason&student_id=${studentId}&month=${month}`
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.status === 'success') {
                                        this.removeAttribute('data-reason');
                                        this.removeAttribute('data-bs-toggle');
                                        this.removeAttribute('data-bs-title');
                                        this.checked = false;
                                        bootstrap.Tooltip.getInstance(this)?.dispose();
                                        showNotification('Основание удалено', 'success');
                                        studentCache = null;
                                        modalStudentCache = null;
                                        debouncedUpdateTable();
                                    } else {
                                        showNotification(data.message || 'Ошибка удаления основания', 'error');
                                        this.checked = true;
                                    }
                                })
                                .catch(error => {
                                    showNotification('Ошибка сети: ' + error.message, 'error');
                                    this.checked = true;
                                });
                            } else {
                                this.checked = true;
                            }
                        }
                    } else {
                        reasonModal.show();
                    }
                });
            });

            // Handle add students link
            tbody.addEventListener('click', function(event) {
                if (event.target.classList.contains('add-students-link')) {
                    event.preventDefault();
                    selectStudentsModal.show();
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('fio-search').addEventListener('input', () => {
                studentCache = null;
                debouncedUpdateTable();
            });

            debouncedUpdateTable();
        });
    </script>
</body>
</html>