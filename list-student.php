<?php
require_once 'db_config.php';

// Include PhpWord for document generation
require_once 'vendor/autoload.php';
use PhpOffice\PhpWord\TemplateProcessor;

// Helper functions
function setFlashMessage($message, $type = 'success') {
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function getFlashMessages() {
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

// Category cache
$categoryCache = null;
function getCategories() {
    global $pdo, $categoryCache;
    if ($categoryCache === null) {
        $stmt = $pdo->query("SELECT id, number, category_name, category_short, max_amount FROM categories ORDER BY number");
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

// Get category name (strip the number if present)
function getCategoryName($category_id) {
    $categories = getCategories();
    foreach ($categories as $category) {
        if ($category['id'] == $category_id) {
            $pattern = '/^' . preg_quote($category['number'], '/') . '\s*[-–—:]\s*/';
            return preg_replace($pattern, '', $category['category_name']);
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

// Get default category
function getDefaultCategoryId() {
    $categories = getCategories();
    return !empty($categories) ? $categories[0]['id'] : null;
}

// Fetch document metadata
function getDocumentMetadata() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT * FROM DocumentMetadata WHERE id = 1");
        return $stmt->fetch();
    } catch (PDOException $e) {
        return [
            'protocol_number' => '001',
            'school' => 'Инженерная школа цифровых технологий',
            'university' => 'Название университета',
            'city' => 'Город',
            'chairperson' => 'Иванов И.И.',
            'members' => "Петров П.П.\nСидоров С.С.",
            'secretary' => 'Смирнова А.А.',
            'chair_degree' => 'д.т.н., профессор',
            'secretary_degree' => 'к.т.н.',
            'agenda' => 'Рассмотрение заявлений на материальную помощь',
            'listened' => 'Заявления студентов о предоставлении материальной помощи',
            'decision' => 'Предоставить материальную помощь следующим студентам:'
        ];
    }
}

// Generate Word document
function generateDocument($students, $month, $metadata) {
    $templatePath = __DIR__ . '/generated/Protocol_template.docx';
    if (!file_exists($templatePath)) {
        throw new Exception('Шаблон документа не найден по пути: ' . $templatePath);
    }

    try {
        $templateProcessor = new TemplateProcessor($templatePath);

        // Set metadata
        $templateProcessor->setValue('PROTOCOL_NUMBER', htmlspecialchars($metadata['protocol_number']));
        $templateProcessor->setValue('SCHOOL', htmlspecialchars($metadata['school']));
        $templateProcessor->setValue('UNIVERSITY', htmlspecialchars($metadata['university']));
        $templateProcessor->setValue('DATE', htmlspecialchars(date('d.m.Y')));
        $templateProcessor->setValue('CITY', htmlspecialchars($metadata['city']));
        $templateProcessor->setValue('CHAIRPERSON', htmlspecialchars($metadata['chairperson']));
        $templateProcessor->setValue('MEMBERS', htmlspecialchars($metadata['members']));
        $templateProcessor->setValue('SECRETARY', htmlspecialchars($metadata['secretary']));
        $templateProcessor->setValue('AGENDA', htmlspecialchars($metadata['agenda']));
        $templateProcessor->setValue('LISTENED', htmlspecialchars($metadata['listened']));
        $templateProcessor->setValue('DECISION', htmlspecialchars($metadata['decision']));
        $templateProcessor->setValue('CHAIR_DEGREE', htmlspecialchars($metadata['chair_degree']));
        $templateProcessor->setValue('SIGN_CHAIR', htmlspecialchars($metadata['chairperson']));
        $templateProcessor->setValue('SECRETART_DEGREE', htmlspecialchars($metadata['secretary_degree']));
        $templateProcessor->setValue('SIGN_SECRETARY', htmlspecialchars($metadata['secretary']));

        if (empty($students)) {
            throw new Exception('Нет данных о студентах для генерации документа');
        }

        $rowData = array_map(function($student, $index) {
            return [
                'Number' => $index + 1,
                'ФИО' => htmlspecialchars($student['full_name']),
                'Бюджет' => htmlspecialchars($student['budget'] ?? '-'),
                'Группа' => htmlspecialchars($student['group_name']),
                'Основание' => htmlspecialchars($student['reason']),
                'Сумма' => htmlspecialchars($student['amount'] ?? '0.00')
            ];
        }, $students, array_keys($students));

        $templateProcessor->cloneRowAndSetValues('Number', $rowData);

        $filename = 'Protocol_' . date('Ymd') . '.docx';
        $templateProcessor->saveAs($filename);
        return $filename;
    } catch (Exception $e) {
        throw new Exception('Ошибка обработки шаблона: ' . $e->getMessage());
    }
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

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
                sr.amount,
                scs.academic_year
            FROM Students s
            JOIN Groups g ON s.group_id = g.id
            JOIN Directions d ON g.direction_id = d.code
            JOIN Schools sc ON d.vsh_code = sc.code
            JOIN StudentCategories scs ON s.id = scs.student_id
            LEFT JOIN StudentReasons sr ON s.id = sr.student_id AND sr.academic_year = scs.academic_year
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
                        'budget' => $row['budget'] ?? '-',
                        'group_id' => $row['group_id'],
                        'group_name' => $row['group_name'],
                        'direction_name' => $row['direction_name'],
                        'reasons' => [],
                        'academic_year' => $row['academic_year']
                    ];
                }
                if ($row['month']) {
                    $reason_text = $row['category_id'] ? getCategoryName($row['category_id']) : '';
                    $students[$student_id]['reasons'][$row['month']] = [
                        'reason' => $reason_text,
                        'amount' => $row['amount']
                    ];
                }
            }

            echo json_encode(['status' => 'success', 'data' => array_values($students), 'total' => count($students)]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'save_reason') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT);
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $academic_year = trim($_POST['academic_year'] ?? getMostRecentAcademicYear());

        if (!$student_id || !$month || $month < 1 || $month > 12 || !$category_id || $amount === false || !$academic_year) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные входные данные']);
            exit;
        }

        // Determine calendar year
        list($start_year, $end_year) = explode('/', $academic_year);
        $calendar_year = ($month >= 9 && $month <= 12) ? $start_year : $end_year;

        try {
            $stmt = $pdo->prepare("SELECT id, max_amount FROM categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $category = $stmt->fetch();
            if (!$category) {
                echo json_encode(['status' => 'error', 'message' => 'Категория не найдена']);
                exit;
            }
            if ($amount > ($category['max_amount'] ?? 0) && $category['max_amount'] > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Сумма превышает максимальную для категории']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO StudentReasons (student_id, month, year, category_id, amount, created_at, academic_year)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE category_id = ?, amount = ?, created_at = NOW(), academic_year = ?
            ");
            $stmt->execute([$student_id, $month, $calendar_year, $category_id, $amount, $academic_year, $category_id, $amount, $academic_year]);

            echo json_encode(['status' => 'success']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'remove_reason') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT);
        $academic_year = trim($_POST['academic_year'] ?? getMostRecentAcademicYear());

        if (!$student_id || !$month || $month < 1 || $month > 12 || !$academic_year) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные входные данные']);
            exit;
        }

        list($start_year, $end_year) = explode('/', $academic_year);
        $calendar_year = ($month >= 9 && $month <= 12) ? $start_year : $end_year;

        try {
            $stmt = $pdo->prepare("DELETE FROM StudentReasons WHERE student_id = ? AND month = ? AND year = ? AND academic_year = ?");
            $stmt->execute([$student_id, $month, $calendar_year, $academic_year]);
            echo json_encode(['status' => 'success']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'update_student') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $field = trim($_POST['field'] ?? '');
        $value = trim($_POST['value'] ?? '');

        if (!$student_id || !in_array($field, ['full_name', 'group_id', 'budget']) || ($field === 'full_name' && empty($value))) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные входные данные']);
            exit;
        }

        try {
            if ($field === 'group_id') {
                $stmt = $pdo->prepare("SELECT id FROM Groups WHERE id = ?");
                $stmt->execute([$value]);
                if (!$stmt->fetch()) {
                    echo json_encode(['status' => 'error', 'message' => 'Группа не найдена']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE Students SET group_id = ? WHERE id = ?");
                $stmt->execute([$value, $student_id]);
            } elseif ($field === 'budget') {
                $stmt = $pdo->prepare("UPDATE Students SET budget = ? WHERE id = ?");
                $stmt->execute([$value ?: null, $student_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE Students SET full_name = ? WHERE id = ?");
                $stmt->execute([$value, $student_id]);
            }

            global $groupCache;
            $groupCache = null;
            getGroups();

            echo json_encode(['status' => 'success']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'add_category') {
        $category_name = trim($_POST['category_name'] ?? '');
        $max_amount = filter_input(INPUT_POST, 'max_amount', FILTER_VALIDATE_FLOAT) ?: 0.00;

        if (empty($category_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Название категории не указано']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE category_name = ?");
            $stmt->execute([$category_name]);
            $existing = $stmt->fetch();
            if ($existing) {
                echo json_encode(['status' => 'success', 'category_id' => $existing['id']]);
                exit;
            }

            $stmt = $pdo->query("SELECT COUNT(*) AS count FROM categories");
            $number = '5.2.' . ($stmt->fetch()['count'] + 1);

            $stmt = $pdo->prepare("
                INSERT INTO categories (number, category_name, category_short, documents_list, payment_frequency, max_amount, created_at)
                VALUES (?, ?, ?, '', '', ?, NOW())
            ");
            $stmt->execute([$number, $category_name, substr($category_name, 0, 50), $max_amount]);
            $category_id = $pdo->lastInsertId();

            global $categoryCache;
            $categoryCache = null;
            getCategories();

            echo json_encode(['status' => 'success', 'category_id' => $category_id]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'reset_reasons') {
        try {
            $stmt = $pdo->prepare("DELETE FROM StudentReasons");
            $stmt->execute();
            echo json_encode(['status' => 'success']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'get_all_students') {
        $fio = trim($_POST['fio'] ?? '');

        $query = "
            SELECT s.id, s.full_name, g.group_name, s.budget
            FROM Students s
            JOIN Groups g ON s.group_id = g.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($fio)) {
            $query .= " AND (s.full_name LIKE ? OR g.group_name LIKE ?)";
            $params[] = "%$fio%";
            $params[] = "%$fio%";
        }

        $query .= " ORDER BY s.full_name";

        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $students = $stmt->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $students, 'total' => count($students)]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'add_student_category') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $academic_year = trim($_POST['academic_year'] ?? getMostRecentAcademicYear());

        if (!$student_id || !$academic_year) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные входные данные']);
            exit;
        }

        $category_id = getDefaultCategoryId();
        if (!$category_id) {
            echo json_encode(['status' => 'error', 'message' => 'Не удалось определить категорию']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO StudentCategories (student_id, category_id, academic_year, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE category_id = ?, created_at = NOW()
            ");
            $stmt->execute([$student_id, $category_id, $academic_year, $category_id]);
            echo json_encode(['status' => 'success']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'remove_student_category') {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $academic_year = trim($_POST['academic_year'] ?? getMostRecentAcademicYear());

        if (!$student_id || !$academic_year) {
            echo json_encode(['status' => 'error', 'message' => 'Неверные входные данные']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM StudentCategories WHERE student_id = ? AND academic_year = ?");
            $stmt->execute([$student_id, $academic_year]);
            echo json_encode(['status' => 'success']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'add_academic_year') {
        $year = trim($_POST['year'] ?? '');

        // Validate format and years
        if (empty($year) || !preg_match('/^\d{4}\/\d{4}$/', $year)) {
            echo json_encode(['status' => 'error', 'message' => 'Неверный формат учебного года (ожидается ГГГГ/ГГГГ)']);
            exit;
        }

        // Check if years are consecutive
        list($start, $end) = explode('/', $year);
        if ((int)$end !== (int)$start + 1) {
            echo json_encode(['status' => 'error', 'message' => 'Учебный год должен состоять из двух последовательных годов (например, 2023/2024)']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO AcademicYears (year, created_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE created_at = NOW()");
            $stmt->execute([$year]);
            echo json_encode(['status' => 'success']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
            exit;
        }
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
    echo json_encode(['status' => 'error', 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
    exit;
}

// Set default year if not selected
$defaultYear = getMostRecentAcademicYear();
if (empty($_POST) && !isset($_GET['year'])) {
    if ($defaultYear) {
        $_SESSION['selected_year'] = $defaultYear;
    }
} elseif (isset($_GET['year'])) {
    $_SESSION['selected_year'] = $_GET['year'];
}

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <?php include 'header.html'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система документооборота</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <style>
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
        .search-loading {
            position: absolute;
            right: 35px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }
        .search-loading.show {
            display: block;
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
        .editable-cell {
            cursor: pointer;
            display: inline-block;
            width: 100%;
            height: 100%;
            padding: 5px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        .editable-cell:hover {
            background-color: #e9ecef;
        }
        .editable-cell.editing {
            background-color: #fff;
            border: 1px solid #007bff;
        }
        .editable-cell input, .editable-cell select {
            width: 100%;
            border: none;
            outline: none;
            padding: 2px;
            font-size: 0.9rem;
        }
        .month-checkbox {
            cursor: pointer;
        }
        .table-responsive {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
        }
        #year-header th {
            text-align: center;
            font-weight: 600;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
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
        .student-table-container {
            max-height: 400px;
            overflow-y: auto;
        }
        .category-select-container {
            margin-bottom: 15px;
        }
        .autocomplete-suggestions {
            position: absolute;
            z-index: 1000;
            width: 300px;
            max-height: 200px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: none;
        }
        .autocomplete-suggestion {
            padding: 8px 12px;
            cursor: pointer;
        }
        .autocomplete-suggestion:hover {
            background-color: #f1f3f5;
        }
        .modal-backdrop {
            display: none !important;
        }
        .modal.fade .modal-dialog {
            transition: none;
        }
        .modal-footer .btn {
            position: relative;
            z-index: 1050;
        }
        .year-selector-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .year-selector-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
            width: 100%;
        }
    </style>
</head>
<body>
    <?php if (!isset($_SESSION['selected_year']) && !empty($years)): ?>
        <div class="year-selector-modal">
            <div class="year-selector-content">
                <h3>Выберите учебный год</h3>
                <p>Перед началом работы необходимо выбрать учебный год:</p>
                <select id="year-selector" class="form-select mb-3">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= $year === $defaultYear ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button id="confirm-year-btn" class="btn btn-primary">Подтвердить</button>
            </div>
        </div>
    <?php endif; ?>

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
                        <option value="<?php echo htmlspecialchars($year); ?>" <?= ($year === ($_SESSION['selected_year'] ?? '')) ? 'selected' : '' ?>>
                            <?php echo htmlspecialchars($year); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addAcademicYearModal">
                    <i class="bi bi-calendar"></i> Учебный год
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
                <div class="spinner-border spinner-border-sm search-loading" id="search-loading" role="status">
                    <span class="visually-hidden">Поиск...</span>
                </div>
            </div>
        </div>
        <div class="current-filters" id="current-filters">
            Фильтры: Нет активных фильтров
        </div>
        <div class="table-header">
            <span id="student-count">Студентов: 0</span>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                    <i class="bi bi-person-plus"></i> Добавить студентов
                </button>
                <a href="#" class="btn btn-success" id="generateDocumentLink" data-bs-toggle="modal" data-bs-target="#generateDocumentModal">
                    <i class="bi bi-file-word"></i> Сгенерировать протокол
                </a>
            </div>
        </div>
        <div class="table-responsive position-relative">
            <table class="table data-table" aria-label="Таблица студентов">
                <thead>
                    <tr id="year-header"></tr>
                    <tr id="month-header">
                        <th scope="col">#</th>
                        <th scope="col">ФИО</th>
                        <th scope="col">Группа</th>
                        <th scope="col">Бюджет</th>
                        <th scope="col">Сентябрь</th>
                        <th scope="col">Октябрь</th>
                        <th scope="col">Ноябрь</th>
                        <th scope="col">Декабрь</th>
                        <th scope="col">Январь</th>
                        <th scope="col">Февраль</th>
                        <th scope="col">Март</th>
                        <th scope="col">Апрель</th>
                        <th scope="col">Май</th>
                        <th scope="col">Июнь</th>
                        <th scope="col">Июль</th>
                        <th scope="col">Август</th>
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
    </div>

    <!-- Category Selection Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalLabel">Выбрать основание</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="category-select-container position-relative">
                        <label for="categoryInput" class="form-label">Введите или выберите категорию:</label>
                        <input type="text" id="categoryInput" class="form-control" placeholder="Введите категорию" aria-label="Введите категорию">
                        <input type="hidden" id="categoryId" value="">
                        <div id="autocompleteSuggestions" class="autocomplete-suggestions"></div>
                    </div>
                    <div class="mb-3">
                        <label for="amountInput" class="form-label">Сумма (руб.):</label>
                        <input type="number" id="amountInput" class="form-control" step="0.01" min="0" aria-label="Сумма материальной помощи">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-danger" id="clearCategoryBtn" style="display: none;">Очистить</button>
                    <button type="button" class="btn btn-primary" id="saveCategoryBtn">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addStudentModalLabel">Добавить студентов</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 position-relative">
                        <label for="studentSearch" class="form-label">Поиск по ФИО или группе:</label>
                        <input type="text" id="studentSearch" class="form-control" placeholder="Введите ФИО или группу" aria-label="Поиск по ФИО или группе">
                        <i class="bi bi-search fio-search-icon"></i>
                        <div class="spinner-border spinner-border-sm search-loading" id="student-search-loading" role="status">
                            <span class="visually-hidden">Поиск...</span>
                        </div>
                    </div>
                    <div class="student-table-container">
                        <table class="table table-hover" id="studentListTable" aria-label="Список студентов">
                            <thead>
                                <tr>
                                    <th scope="col">ФИО</th>
                                    <th scope="col">Группа</th>
                                    <th scope="col">Бюджет</th>
                                    <th scope="col">Выбрать</th>
                                </tr>
                            </thead>
                            <tbody id="student-list-body"></tbody>
                        </table>
                        <div class="table-loading" id="student-list-loading">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Document Generation Modal -->
    <div class="modal fade" id="generateDocumentModal" tabindex="-1" aria-labelledby="generateDocumentModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="generateDocumentModalLabel">Генерация протокола</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="documentMonth" class="form-label">Выберите месяц:</label>
                        <select id="documentMonth" class="form-select" aria-label="Выбор месяца">
                            <option value="9">Сентябрь</option>
                            <option value="10">Октябрь</option>
                            <option value="11">Ноябрь</option>
                            <option value="12">Декабрь</option>
                            <option value="1">Январь</option>
                            <option value="2">Февраль</option>
                            <option value="3">Март</option>
                            <option value="4">Апрель</option>
                            <option value="5">Май</option>
                            <option value="6">Июнь</option>
                            <option value="7">Июль</option>
                            <option value="8">Август</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="confirmGenerateBtn">Сгенерировать</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Confirmation Modal -->
    <div class="modal fade" id="resetConfirmModal" tabindex="-1" aria-labelledby="resetConfirmModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resetConfirmModalLabel">Подтверждение сброса</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите сбросить все фильтры и очистить все данные об основаниях? Это действие нельзя отменить.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-danger" id="confirmResetBtn">Сбросить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Academic Year Modal -->
    <div class="modal fade" id="addAcademicYearModal" tabindex="-1" aria-labelledby="addAcademicYearModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addAcademicYearModalLabel">Добавить учебный год</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="academicYearInput" class="form-label">Учебный год (ГГГГ/ГГГГ):</label>
                        <input type="text" id="academicYearInput" class="form-control" placeholder="Например: 2024/2025" aria-label="Учебный год">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="saveAcademicYearBtn">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <div class="notification-container">
        <?php foreach (getFlashMessages() as $flash): ?>
            <div class="notification <?php echo htmlspecialchars($flash['type']); ?>" role="alert">
                <i class="bi bi-<?php echo $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'; ?> icon"></i>
                <span class="message"><?php echo htmlspecialchars($flash['message']); ?></span>
                <button class="close-btn" aria-label="Закрыть уведомление">×</button>
            </div>
        <?php endforeach; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
        let studentCache = null;
        let lastFilterHash = '';
        let selectedSchool = '';
        let selectedYear = '<?php echo isset($_SESSION['selected_year']) ? htmlspecialchars($_SESSION['selected_year']) : (getMostRecentAcademicYear() ?? ''); ?>';
        const schools = <?php echo json_encode($schools); ?>;
        const categories = <?php echo json_encode($categories); ?>;
        const groups = <?php echo json_encode($groups); ?>;
        const years = <?php echo json_encode($years); ?>;
        let activeBudgetFilters = [];
        let currentCheckbox = null;
        let studentSearchCache = {};

        document.addEventListener('DOMContentLoaded', () => {
            // Year selector modal
            const yearSelectorModal = document.querySelector('.year-selector-modal');
            if (yearSelectorModal) {
                document.getElementById('confirm-year-btn').addEventListener('click', () => {
                    const selectedYear = document.getElementById('year-selector').value;
                    fetch('?year=' + encodeURIComponent(selectedYear))
                        .then(() => {
                            window.location.reload();
                        });
                });
            }

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
            }

            function getCategoryName(categoryId) {
                const category = categories.find(cat => cat.id == categoryId);
                if (!category) return '';
                const pattern = new RegExp(`^${category.number}\\s*[-–—:]\\s*`);
                return category.category_name.replace(pattern, '');
            }

            function displayCurrentFilters() {
                const schoolFilter = document.getElementById('school-filter').value;
                const yearFilter = document.getElementById('year-filter').value;
                const fio = document.getElementById('fio-search').value.trim();
                const currentFiltersDiv = document.getElementById('current-filters');

                let filterText = 'Фильтры: ';
                let filters = [];

                if (schoolFilter) {
                    const school = schools.find(s => s.code === parseInt(schoolFilter));
                    filters.push(`Школа: ${school ? school.name : schoolFilter}`);
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

            async function resetFilters() {
                const modal = new bootstrap.Modal(document.getElementById('resetConfirmModal'));
                modal.show();

                const confirmBtn = document.getElementById('confirmResetBtn');
                confirmBtn.replaceWith(confirmBtn.cloneNode(true));
                const newConfirmBtn = document.getElementById('confirmResetBtn');

                newConfirmBtn.addEventListener('click', async () => {
                    try {
                        const formData = new FormData();
                        formData.append('action', 'reset_reasons');

                        const response = await fetch('', { method: 'POST', body: formData });
                        if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                        const result = await response.json();
                        if (result.status === 'success') {
                            selectedSchool = '';
                            selectedYear = '<?php echo htmlspecialchars(getMostRecentAcademicYear() ?? ''); ?>';
                            document.getElementById('school-filter').value = '';
                            document.getElementById('year-filter').value = selectedYear;
                            activeBudgetFilters = [];
                            document.querySelectorAll('.budget-filter-btn').forEach(btn => btn.classList.remove('active'));
                            document.getElementById('fio-search').value = '';
                            studentCache = null;
                            lastFilterHash = '';
                            displayCurrentFilters();
                            debouncedUpdateTable();
                            showNotification('Фильтры и данные об основаниях сброшены', 'success');
                        } else {
                            showNotification(result.message || 'Ошибка при сбросе данных', 'error');
                        }
                    } catch (error) {
                        showNotification('Ошибка сети: ' + error.message, 'error');
                    } finally {
                        modal.hide();
                    }
                }, { once: true });
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

            document.getElementById('reset-filters').addEventListener('click', resetFilters);
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
                formData.append('schools', JSON.stringify(school ? [parseInt(school)] : []));
                formData.append('years', JSON.stringify(year ? [year] : []));
                formData.append('regions', JSON.stringify(activeBudgetFilters));
                formData.append('fio', fio);

                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                    const result = await response.json();
                    studentCache = result;
                    lastFilterHash = filterHash;
                    renderTable(result);
                } catch (error) {
                    showNotification('Ошибка загрузки данных: ' + error.message, 'error');
                    renderTable({ status: 'error', data: [], total: 0 });
                } finally {
                    tableLoading.classList.remove('show');
                }
            }

            const debouncedUpdateTable = debounce(updateTable, 150);

            function renderTable(result) {
                const tbody = document.getElementById('student-table-body');
                const yearHeader = document.getElementById('year-header');
                tbody.innerHTML = '';
                yearHeader.innerHTML = '';

                const academicYear = selectedYear || years[0] || '';
                let startYear = '', endYear = '';
                if (academicYear && academicYear.includes('/')) {
                    [startYear, endYear] = academicYear.split('/');
                } else {
                    const currentYear = new Date().getFullYear();
                    startYear = currentYear;
                    endYear = currentYear + 1;
                }

                yearHeader.innerHTML = `
                    <th scope="col" colspan="4"></th>
                    <th scope="col" colspan="4">${startYear}</th>
                    <th scope="col" colspan="8">${endYear}</th>
                `;

                if (result.status !== 'success' || result.data.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="16" class="text-center">
                                Студенты не найдены.
                            </td>
                        </tr>
                    `;
                    document.getElementById('student-count').textContent = 'Студентов: 0';
                } else {
                    result.data.forEach((student, index) => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td scope="row">${index + 1}</td>
                            <td>
                                <span class="editable-cell" 
                                      data-student="${student.id}" 
                                      data-field="full_name" 
                                      data-value="${student.full_name.replace(/"/g, '&quot;')}"
                                      data-bs-toggle="tooltip" 
                                      data-bs-title="${student.full_name.replace(/"/g, '&quot;')}"
                                      aria-label="ФИО студента">${student.full_name}</span>
                            </td>
                            <td>
                                <span class="editable-cell" 
                                      data-student="${student.id}" 
                                      data-field="group_id" 
                                      data-value="${student.group_id}"
                                      data-bs-toggle="tooltip" 
                                      data-bs-title="${student.group_name.replace(/"/g, '&quot;')}"
                                      aria-label="Группа студента">${student.group_name}</span>
                            </td>
                            <td>
                                <span class="editable-cell" 
                                      data-student="${student.id}" 
                                      data-field="budget" 
                                      data-value="${student.budget || ''}"
                                      data-bs-toggle="tooltip" 
                                      data-bs-title="${student.budget || 'Нет бюджета'}"
                                      aria-label="Бюджет студента">${student.budget || '-'}</span>
                            </td>
                            ${[9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8].map(month => {
                                const reasonData = student.reasons[month] || {};
                                const reason = reasonData.reason || '';
                                const amount = reasonData.amount || '';
                                const categoryId = reason ? categories.find(cat => getCategoryName(cat.id) === reason)?.id : '';
                                return `
                                    <td>
                                        <input type="checkbox" class="month-checkbox" 
                                               data-student="${student.id}" 
                                               data-month="${month}" 
                                               data-category="${categoryId || ''}"
                                               data-amount="${amount || ''}"
                                               ${reason ? `checked data-reason="${reason.replace(/"/g, '&quot;')}" data-bs-toggle="tooltip" data-bs-title="${reason.replace(/"/g, '&quot;')}${amount ? ' (' + amount + ' руб.)' : ''}"` : ''}
                                               aria-label="Основание за ${month} месяц">
                                    </td>
                                `;
                            }).join('')}
                        `;
                        tbody.appendChild(row);
                    });
                    document.getElementById('student-count').textContent = `Студентов: ${result.total}`;
                }

                const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                tooltipTriggerList.forEach(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

                document.querySelectorAll('.editable-cell').forEach(cell => {
                    cell.addEventListener('click', function() {
                        if (this.classList.contains('editing')) return;

                        const studentId = this.dataset.student;
                        const field = this.dataset.field;
                        const currentValue = this.dataset.value;

                        this.classList.add('editing');

                        if (field === 'group_id') {
                            this.innerHTML = `
                                <select class="cell-input" aria-label="Выбор группы">
                                    ${groups.map(group => `
                                        <option value="${group.id}" ${group.id == currentValue ? 'selected' : ''}>
                                            ${group.group_name}
                                        </option>
                                    `).join('')}
                                </select>
                            `;
                        } else {
                            this.innerHTML = `
                                <input type="text" value="${currentValue}" class="cell-input" aria-label="Редактировать ${field}">
                            `;
                        }

                        const input = this.querySelector('.cell-input');
                        input.focus();

                        const saveCell = async () => {
                            const newValue = input.value.trim();
                            if (newValue === currentValue || (field === 'full_name' && !newValue)) {
                                cell.classList.remove('editing');
                                cell.textContent = currentValue || (field === 'budget' ? '-' : currentValue);
                                return;
                            }

                            try {
                                const formData = new FormData();
                                formData.append('action', 'update_student');
                                formData.append('student_id', studentId);
                                formData.append('field', field);
                                formData.append('value', newValue);

                                const response = await fetch('', { method: 'POST', body: formData });
                                if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                                const result = await response.json();
                                if (result.status === 'success') {
                                    cell.textContent = field === 'group_id' ? groups.find(g => g.id == newValue).group_name : newValue || '-';
                                    cell.dataset.value = newValue;
                                    cell.dataset.bsTitle = newValue || 'Нет бюджета';
                                    new bootstrap.Tooltip(cell).dispose();
                                    new bootstrap.Tooltip(cell);
                                    showNotification('Данные обновлены', 'success');
                                    studentCache = null;
                                    debouncedUpdateTable();
                                } else {
                                    showNotification(`Ошибка: ${result.message}`, 'error');
                                    cell.textContent = currentValue || (field === 'budget' ? '-' : currentValue);
                                }
                            } catch (error) {
                                showNotification(`Ошибка сети: ${error.message}`, 'error');
                                cell.textContent = currentValue || (field === 'budget' ? '-' : currentValue);
                            }
                            cell.classList.remove('editing');
                        };

                        input.addEventListener('blur', saveCell);
                        input.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                input.blur();
                            } else if (e.key === 'Escape') {
                                cell.classList.remove('editing');
                                cell.textContent = currentValue || (field === 'budget' ? '-' : currentValue);
                            }
                        });
                    });
                });

                document.querySelectorAll('.month-checkbox').forEach(checkbox => {
                    checkbox.addEventListener('click', function(event) {
                        event.preventDefault();
                        currentCheckbox = this;
                        const studentId = this.dataset.student;
                        const month = this.dataset.month;
                        const currentCategoryId = this.dataset.category || '';
                        const currentReason = this.dataset.reason || '';
                        const currentAmount = this.dataset.amount || '';

                        if (!selectedYear) {
                            showNotification('Учебный год не выбран', 'error');
                            this.checked = false;
                            return;
                        }

                        const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
                        const categoryInput = document.getElementById('categoryInput');
                        const categoryIdInput = document.getElementById('categoryId');
                        const amountInput = document.getElementById('amountInput');
                        const clearCategoryBtn = document.getElementById('clearCategoryBtn');

                        categoryInput.value = currentReason;
                        categoryIdInput.value = currentCategoryId;
                        amountInput.value = currentAmount;
                        clearCategoryBtn.style.display = currentReason ? 'block' : 'none';

                        modal.show();
                    });
                });
            }

            async function loadStudents(fio = '') {
                const tableLoading = document.getElementById('student-list-loading');
                const searchLoading = document.getElementById('student-search-loading');
                tableLoading.classList.add('show');
                searchLoading.classList.add('show');

                if (studentSearchCache[fio]) {
                    renderStudentList(studentSearchCache[fio]);
                    tableLoading.classList.remove('show');
                    searchLoading.classList.remove('show');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'get_all_students');
                formData.append('fio', fio);

                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                    const result = await response.json();
                    studentSearchCache[fio] = result;
                    renderStudentList(result);
                } catch (error) {
                    showNotification('Ошибка загрузки студентов: ' + error.message, 'error');
                    renderStudentList({ status: 'error', data: [], total: 0 });
                } finally {
                    tableLoading.classList.remove('show');
                    searchLoading.classList.remove('show');
                }
            }

            function renderStudentList(result) {
                const tbody = document.getElementById('student-list-body');
                tbody.innerHTML = '';

                if (result.status !== 'success' || result.data.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="4" class="text-center">
                                Студенты не найдены.
                            </td>
                        </tr>
                    `;
                } else {
                    result.data.forEach(student => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${student.full_name}</td>
                            <td>${student.group_name}</td>
                            <td>${student.budget || '-'}</td>
                            <td>
                                <input type="checkbox" class="student-select-checkbox" 
                                       data-student-id="${student.id}">
                            </td>
                        `;
                        tbody.appendChild(row);
                    });

                    document.querySelectorAll('.student-select-checkbox').forEach(checkbox => {
                        checkbox.addEventListener('change', async function() {
                            const studentId = parseInt(this.dataset.studentId);
                            const action = this.checked ? 'add_student_category' : 'remove_student_category';
                            const message = this.checked ? 'Студент добавлен' : 'Студент удален';

                            try {
                                const formData = new FormData();
                                formData.append('action', action);
                                formData.append('student_id', studentId);
                                formData.append('academic_year', selectedYear);

                                const response = await fetch('', { method: 'POST', body: formData });
                                if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                                const result = await response.json();
                                if (result.status === 'success') {
                                    showNotification(message, 'success');
                                    studentCache = null;
                                    debouncedUpdateTable();
                                } else {
                                    showNotification(`Ошибка: ${result.message}`, 'error');
                                    this.checked = !this.checked;
                                }
                            } catch (error) {
                                showNotification('Ошибка сети: ' + error.message, 'error');
                                this.checked = !this.checked;
                            }
                        });
                    });
                }
            }

            function setupAutocomplete() {
                const categoryInput = document.getElementById('categoryInput');
                const suggestionsContainer = document.getElementById('autocompleteSuggestions');
                const categoryIdInput = document.getElementById('categoryId');
                const amountInput = document.getElementById('amountInput');

                categoryInput.addEventListener('input', function() {
                    const query = this.value.toLowerCase();
                    suggestionsContainer.innerHTML = '';
                    suggestionsContainer.style.display = 'none';

                    if (query.length < 1) return;

                    const matches = categories.filter(category => 
                        (category.number + ' - ' + category.category_name).toLowerCase().includes(query)
                    );

                    if (matches.length > 0) {
                        matches.forEach(category => {
                            const suggestion = document.createElement('div');
                            suggestion.className = 'autocomplete-suggestion';
                            suggestion.textContent = `${category.number} - ${category.category_name}`;
                            suggestion.dataset.id = category.id;
                            suggestion.addEventListener('click', () => {
                                categoryInput.value = getCategoryName(category.id);
                                categoryIdInput.value = category.id;
                                amountInput.value = category.max_amount || '';
                                suggestionsContainer.innerHTML = '';
                                suggestionsContainer.style.display = 'none';
                            });
                            suggestionsContainer.appendChild(suggestion);
                        });
                        suggestionsContainer.style.display = 'block';
                    }
                });

                categoryInput.addEventListener('blur', () => {
                    setTimeout(() => {
                        suggestionsContainer.innerHTML = '';
                        suggestionsContainer.style.display = 'none';
                    }, 200);
                });

                categoryInput.addEventListener('change', () => {
                    if (!categoryIdInput.value && categoryInput.value) {
                        categoryIdInput.value = '';
                    }
                });
            }

            document.getElementById('fio-search').addEventListener('input', () => {
                studentCache = null;
                debouncedUpdateTable();
            });

            document.getElementById('categoryModal').addEventListener('hidden.bs.modal', () => {
                document.getElementById('categoryInput').value = '';
                document.getElementById('categoryId').value = '';
                document.getElementById('amountInput').value = '';
                document.getElementById('autocompleteSuggestions').innerHTML = '';
                document.getElementById('autocompleteSuggestions').style.display = 'none';
                if (currentCheckbox && !currentCheckbox.dataset.reason) {
                    currentCheckbox.checked = false;
                }
                currentCheckbox = null;
            });

            document.getElementById('saveCategoryBtn').addEventListener('click', async () => {
                const categoryInput = document.getElementById('categoryInput').value.trim();
                let categoryId = document.getElementById('categoryId').value;
                const amount = document.getElementById('amountInput').value.trim();
                const studentId = currentCheckbox?.dataset.student;
                const month = currentCheckbox?.dataset.month;
                const modal = bootstrap.Modal.getInstance(document.getElementById('categoryModal'));

                if (!studentId || !month) {
                    showNotification('Ошибка: Неверные данные студента или месяца', 'error');
                    modal.hide();
                    return;
                }

                if (!categoryInput) {
                    showNotification('Ошибка: Категория не указана', 'error');
                    return;
                }

                if (!amount || parseFloat(amount) <= 0) {
                    showNotification('Ошибка: Укажите корректную сумму', 'error');
                    return;
                }

                let reason = '';

                if (!categoryId && categoryInput) {
                    try {
                        const formData = new FormData();
                        formData.append('action', 'add_category');
                        formData.append('category_name', categoryInput);
                        formData.append('max_amount', amount);

                        const response = await fetch('', { method: 'POST', body: formData });
                        if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                        const result = await response.json();
                        if (result.status === 'success') {
                            categoryId = result.category_id;
                            reason = categoryInput;
                            categories.push({
                                id: categoryId,
                                number: '5.2.' + (categories.length + 1),
                                category_name: categoryInput,
                                category_short: categoryInput.substring(0, 50),
                                max_amount: parseFloat(amount)
                            });
                        } else {
                            showNotification(`Ошибка добавления категории: ${result.message}`, 'error');
                            return;
                        }
                    } catch (error) {
                        showNotification(`Ошибка сети: ${error.message}`, 'error');
                        return;
                    }
                } else if (categoryId) {
                    reason = getCategoryName(categoryId);
                } else {
                    showNotification('Ошибка: Категория не выбрана', 'error');
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'save_reason');
                    formData.append('student_id', studentId);
                    formData.append('month', month);
                    formData.append('category_id', categoryId);
                    formData.append('amount', amount);
                    formData.append('academic_year', selectedYear);

                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                    const result = await response.json();
                    if (result.status === 'success') {
                        currentCheckbox.setAttribute('data-reason', reason);
                        currentCheckbox.setAttribute('data-category', categoryId);
                        currentCheckbox.setAttribute('data-amount', amount);
                        currentCheckbox.setAttribute('data-bs-toggle', 'tooltip');
                        currentCheckbox.setAttribute('data-bs-title', `${reason} (${amount} руб.)`);
                        currentCheckbox.checked = true;
                        new bootstrap.Tooltip(currentCheckbox);
                        showNotification('Основание и сумма сохранены', 'success');
                        studentCache = null;
                        debouncedUpdateTable();
                    } else {
                        showNotification(`Ошибка: ${result.message}`, 'error');
                    }
                } catch (error) {
                    showNotification('Ошибка сети: ' + error.message, 'error');
                }
                modal.hide();
            });

            document.getElementById('clearCategoryBtn').addEventListener('click', async () => {
                const studentId = currentCheckbox?.dataset.student;
                const month = currentCheckbox?.dataset.month;
                const modal = bootstrap.Modal.getInstance(document.getElementById('categoryModal'));

                if (!studentId || !month) {
                    showNotification('Ошибка: Неверные данные студента или месяца', 'error');
                    modal.hide();
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'remove_reason');
                    formData.append('student_id', studentId);
                    formData.append('month', month);
                    formData.append('academic_year', selectedYear);

                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                    const result = await response.json();
                    if (result.status === 'success') {
                        currentCheckbox.removeAttribute('data-reason');
                        currentCheckbox.removeAttribute('data-category');
                        currentCheckbox.removeAttribute('data-amount');
                        currentCheckbox.removeAttribute('data-bs-toggle');
                        currentCheckbox.removeAttribute('data-bs-title');
                        currentCheckbox.checked = false;
                        new bootstrap.Tooltip(currentCheckbox).dispose();
                        showNotification('Основание удалено', 'success');
                        studentCache = null;
                        debouncedUpdateTable();
                    } else {
                        showNotification(`Ошибка: ${result.message}`, 'error');
                    }
                } catch (error) {
                    showNotification('Ошибка сети: ' + error.message, 'error');
                }
                modal.hide();
            });

            document.getElementById('studentSearch').addEventListener('input', debounce(() => {
                loadStudents(document.getElementById('studentSearch').value.trim());
            }, 300));

            document.getElementById('addStudentModal').addEventListener('shown.bs.modal', () => {
                loadStudents();
            });

            document.getElementById('saveAcademicYearBtn').addEventListener('click', async () => {
                const yearInput = document.getElementById('academicYearInput').value.trim();
                const modal = bootstrap.Modal.getInstance(document.getElementById('addAcademicYearModal'));

                // Validate format
                if (!yearInput || !/^\d{4}\/\d{4}$/.test(yearInput)) {
                    showNotification('Ошибка: Укажите учебный год в формате ГГГГ/ГГГГ', 'error');
                    return;
                }

                // Check if years are consecutive
                const [startYear, endYear] = yearInput.split('/');
                if (parseInt(endYear) !== parseInt(startYear) + 1) {
                    showNotification('Ошибка: Учебный год должен состоять из двух последовательных годов (например, 2023/2024)', 'error');
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'add_academic_year');
                    formData.append('year', yearInput);

                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

                    const result = await response.json();
                    if (result.status === 'success') {
                        if (!years.includes(yearInput)) {
                            years.push(yearInput);
                            years.sort((a, b) => b.localeCompare(a)); // Sort descending
                            
                            // Update year filter dropdown
                            const yearSelect = document.getElementById('year-filter');
                            yearSelect.innerHTML = '<option value="">Все годы</option>';
                            years.forEach(year => {
                                const option = document.createElement('option');
                                option.value = year;
                                option.textContent = year;
                                yearSelect.appendChild(option);
                            });
                            
                            // Select the newly added year
                            yearSelect.value = yearInput;
                            selectedYear = yearInput;
                            studentCache = null;
                            debouncedUpdateTable();
                        }
                        showNotification('Учебный год добавлен', 'success');
                        modal.hide();
                    } else {
                        showNotification(`Ошибка: ${result.message}`, 'error');
                    }
                } catch (error) {
                    showNotification('Ошибка сети: ' + error.message, 'error');
                }
            });

            setupAutocomplete();
            debouncedUpdateTable();
        });
    </script>
</body>
</html>