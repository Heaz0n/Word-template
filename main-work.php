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
    echo json_encode(['status' => 'error', 'message' => 'Ошибка подключения к базе данных']);
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

// Default template variables
$defaultTemplateVars = [
    'UNIVERSITY' => 'ФГБОУ ВО «Югорский государственный университет»',
    'SCHOOL' => 'Инженерная школа цифровых технологий',
    'SCHOOL_CODE' => 1,
    'PROTOCOL_NUMBER' => '7',
    'DATE' => '«13» января 2025 г.',
    'DAY' => '13',
    'MONTH' => 'января',
    'YEAR' => '2025',
    'CITY' => 'Ханты-Мансийск',
    'CHAIRPERSON' => 'Самарина О.В. – руководитель инженерной школы цифровых технологий, доцент инженерной школы цифровых технологий',
    'CHAIR_DEGREE' => 'к.ф.-м.н., доцент',
    'MEMBERS' => "Самарин В.А. – руководитель образовательной программы 09.03.01 «Информатика и вычислительная техника», доцент инженерной школы цифровых технологий\nПронькина Т.В. – руководитель образовательной программы 09.03.04 «Программная инженерия», доцент инженерной школы цифровых технологий\nТукмачева Ю.А. – преподаватель инженерной школы цифровых технологий, куратор направления 10.03.01 Информационная безопасность\nЛисимов Артём Андреевич – старший преподаватель инженерной школы цифровых технологий, куратор направлений 09.03.01 Информатика и вычислительная техника и 09.03.04 Программная инженерия\nСуфиянов Денис Илфатович – студент группы ПИ31б, представитель совета обучающихся",
    'SECRETARY' => 'Шевченко А.С. – заместитель руководителя инженерной школы цифровых технологий по воспитательной работе, доцент инженерной школы цифровых технологий',
    'SECRETARY_DEGREE' => 'канд. физ.-мат. наук, доцент',
    'AGENDA' => 'Оказание материальной поддержки нуждающимся студентам инженерной школы цифровых технологий',
    'LISTENED' => 'Шевченко А.С. – заместитель руководителя инженерной школы цифровых технологий по воспитательной работе, доцент инженерной школы цифровых технологий',
    'DECISION' => 'Оказать материальную поддержку следующим нуждающимся студентам',
    'SIGN_CHAIR' => 'Самарина О.В.',
    'SIGN_SECRETARY' => 'Шевченко А.С.'
];

$_SESSION['template_vars'] = $_SESSION['template_vars'] ?? $defaultTemplateVars;

function getTemplateVariables() {
    return $_SESSION['template_vars'];
}

// Category cache
function getCategories() {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $stmt = $pdo->query("SELECT id, number, category_name, category_short, payment_frequency, max_amount FROM categories ORDER BY number");
        $cache = $stmt->fetchAll();
    }
    return $cache;
}

function getCategoryName($category_id) {
    foreach (getCategories() as $category) {
        if ($category['id'] == $category_id) return $category['category_short'];
    }
    return '';
}

// Map Russian month names to numeric values
$monthMap = [
    'января' => 1,
    'февраля' => 2,
    'марта' => 3,
    'апреля' => 4,
    'мая' => 5,
    'июня' => 6,
    'июля' => 7,
    'августа' => 8,
    'сентября' => 9,
    'октября' => 10,
    'ноября' => 11,
    'декабря' => 12
];

// Fetch students from StudentCategories for preview
function getStudentCategories($filters = []) {
    global $pdo;
    $search = $filters['search'] ?? null;

    $query = "
        SELECT 
            s.id, 
            s.full_name, 
            s.budget, 
            g.group_name,
            sc.name AS school_name,
            scs.category_id,
            c.max_amount
        FROM Students s
        JOIN Groups g ON s.group_id = g.id
        JOIN Directions d ON g.direction_id = d.code
        JOIN Schools sc ON d.vsh_code = sc.code
        JOIN StudentCategories scs ON s.id = scs.student_id
        JOIN categories c ON scs.category_id = c.id
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $query .= " AND s.full_name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $query .= " ORDER BY s.full_name";

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
                    'group_name' => $row['group_name'],
                    'school_name' => $row['school_name'],
                    'category_id' => $row['category_id'],
                    'amount' => $row['max_amount'] ?? '10000'
                ];
            }
        }

        return array_values($students);
    } catch (PDOException $e) {
        return [];
    }
}

// Fetch all students for modal
function getAllStudents($search = '') {
    global $pdo;
    $query = "
        SELECT 
            s.id,
            s.full_name,
            s.budget,
            g.group_name
        FROM Students s
        JOIN Groups g ON s.group_id = g.id
        WHERE 1=1
    ";
    $params = [];

    if ($search) {
        $query .= " AND s.full_name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $query .= " ORDER BY s.full_name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Unified POST handler with JSON responses
$actions = [
    'save_template' => function() {
        foreach ($_POST['template'] as $placeholder => $value) {
            if (preg_match('/^[A-Z_]+$/', $placeholder) && strlen($value) <= 1000) {
                $_SESSION['template_vars'][$placeholder] = trim($value);
            }
        }
        if (isset($_POST['template']['SCHOOL_CODE']) && is_numeric($_POST['template']['SCHOOL_CODE'])) {
            $_SESSION['template_vars']['SCHOOL_CODE'] = (int)$_POST['template']['SCHOOL_CODE'];
            global $pdo;
            $stmt = $pdo->prepare("SELECT name FROM Schools WHERE code = ?");
            $stmt->execute([$_SESSION['template_vars']['SCHOOL_CODE']]);
            $school = $stmt->fetch();
            if ($school) {
                $_SESSION['template_vars']['SCHOOL'] = $school['name'];
            }
        }
        // Combine day, month, and year into DATE
        if (isset($_POST['template']['DAY'], $_POST['template']['MONTH'], $_POST['template']['YEAR'])) {
            $day = trim($_POST['template']['DAY']);
            $month = trim($_POST['template']['MONTH']);
            $year = trim($_POST['template']['YEAR']);
            $_SESSION['template_vars']['DATE'] = "«{$day}» {$month} {$year} г.";
        }
        setFlashMessage('Шаблон сохранён');
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Шаблон сохранён']);
        exit;
    },
    'generate_protocol' => function() use ($pdo) {
        $filename = trim($_POST['filename'] ?? '');
        $format = $_POST['format'] ?? 'pdf';
        $filters = [
            'month' => trim($_POST['month'] ?? '')
        ];

        $extension = $format === 'word' ? 'docx' : 'pdf';
        if ($filename && !validateFilename($filename, $extension)) {
            header('Content-Type: application/json', true, 400);
            echo json_encode(['status' => 'error', 'message' => 'Неверное имя файла']);
            exit;
        }
        $filename = $filename ?: "protocol_7_2025.{$extension}";

        // Fetch students
        $students = getStudentCategories( $filters);
        $templateVars = getTemplateVariables();

        if ($format === 'word') {
            require_once 'vendor/autoload.php';
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $phpWord->setDefaultFontName('Times New Roman');
            $phpWord->setDefaultFontSize(12);
            $section = $phpWord->addSection([
                'marginTop' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
                'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
                'marginLeft' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(3),
                'marginRight' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(1.5)
            ]);

            // Header
            $section->addText("ПРОТОКОЛ № {$templateVars['PROTOCOL_NUMBER']}", ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 120]);
            $section->addText('Заседания стипендиальной комиссии', ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 120]);
            $section->addText($templateVars['SCHOOL'], ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 120]);
            $section->addText($templateVars['UNIVERSITY'], ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 240]);

            // Date and City
            $table = $section->addTable(['width' => 100 * 50]);
            $table->addRow();
            $dateCell = $table->addCell(50 * 50);
            $dateRun = $dateCell->addTextRun();
            $dateRun->addText("«{$templateVars['DAY']}»", ['underline' => 'single']);
            $dateRun->addText(" {$templateVars['MONTH']} {$templateVars['YEAR']} г.", ['size' => 12]);
            $table->addCell(50 * 50)->addText("г. {$templateVars['CITY']}", ['size' => 12], ['alignment' => 'right']);
            $section->addTextBreak(1, ['size' => 12], ['spaceAfter' => 240]);

            // Sections
            foreach ([
                ['Председатель комиссии:', $templateVars['CHAIRPERSON']],
                ['Члены комиссии:', $templateVars['MEMBERS'], true],
                ['Секретарь комиссии:', $templateVars['SECRETARY']],
                ['Повестка дня:', $templateVars['AGENDA']],
                ['Слушали:', $templateVars['LISTENED']],
                ['Решили:', $templateVars['DECISION']]
            ] as $item) {
                $section->addText($item[0], ['bold' => true, 'size' => 12], ['spaceAfter' => 60]);
                $text = $item[1];
                if ($item[2] ?? false) {
                    foreach (explode("\n", $text) as $line) {
                        $section->addText($line, ['size' => 12], ['spaceBefore' => 0, 'spaceAfter' => 0]);
                    }
                    $section->addTextBreak(1, ['size' => 12], ['spaceAfter' => 240]);
                } else {
                    $section->addText($text, ['size' => 12], ['spaceAfter' => 240]);
                }
            }

            $rfStudents = array_filter($students, fn($s) => strtoupper($s['budget'] ?? '-') !== 'ХМАО');
            $hmaoStudents = array_filter($students, fn($s) => strtoupper($s['budget'] ?? '-') === 'ХМАО');

            // Tables
            foreach ([['РФ', $rfStudents, 30], ['ХМАО', $hmaoStudents, 3]] as $group) {
                $table = $section->addTable([
                    'borderSize' => 6,
                    'width' => 100 * 50,
                    'unit' => 'pct'
                ]);
                $table->addRow();
                $widths = [
                    \PhpOffice\PhpWord\Shared\Converter::cmToTwip(0.5),
                    \PhpOffice\PhpWord\Shared\Converter::cmToTwip(5),
                    \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
                    \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
                    \PhpOffice\PhpWord\Shared\Converter::cmToTwip(5),
                    \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2)
                ];
                foreach (['№', 'ФИО', 'Бюджет', 'Группа', 'Основание', 'Сумма'] as $i => $header) {
                    $table->addCell($widths[$i], ['valign' => 'center'])->addText($header, ['bold' => true, 'size' => 11], ['alignment' => 'center']);
                }

                if (empty($group[1])) {
                    $table->addRow();
                    $table->addCell($widths[0], ['valign' => 'center'])->addText('1', ['size' => 11], ['alignment' => 'center']);
                    $table->addCell($widths[1], ['valign' => 'center'])->addText('Нет данных', ['size' => 11]);
                    $table->addCell($widths[2], ['valign' => 'center'])->addText('-', ['size' => 11], ['alignment' => 'center']);
                    $table->addCell($widths[3], ['valign' => 'center'])->addText('-', ['size' => 11], ['alignment' => 'center']);
                    $table->addCell($widths[4], ['valign' => 'center'])->addText('Нет студентов', ['size' => 11]);
                    $table->addCell($widths[5], ['valign' => 'center'])->addText('0', ['size' => 11], ['alignment' => 'center']);
                } else {
                    foreach (array_slice($group[1], 0, $group[2]) as $index => $student) {
                        $reason = getCategoryName($student['category_id']) ?: 'Не указано';
                        $table->addRow();
                        $table->addCell($widths[0], ['valign' => 'center'])->addText($index + 1, ['size' => 11], ['alignment' => 'center']);
                        $table->addCell($widths[1], ['valign' => 'center'])->addText($student['full_name'], ['size' => 11]);
                        $table->addCell($widths[2], ['valign' => 'center'])->addText($student['budget'] ?: '-', ['size' => 11], ['alignment' => 'center']);
                        $table->addCell($widths[3], ['valign' => 'center'])->addText($student['group_name'], ['size' => 11], ['alignment' => 'center']);
                        $table->addCell($widths[4], ['valign' => 'center'])->addText($reason, ['size' => 11]);
                        $table->addCell($widths[5], ['valign' => 'center'])->addText($student['amount'], ['size' => 11], ['alignment' => 'center']);
                    }
                }
                $section->addTextBreak(1, ['size' => 12], ['spaceAfter' => 240]);
            }

            // Signatures
            $section->addText("Руководитель инженерной школы цифровых технологий", ['size' => 12], ['spaceAfter' => 60]);
            $section->addText($templateVars['CHAIR_DEGREE'], ['size' => 12], ['spaceAfter' => 60]);
            $run = $section->addTextRun(['spaceAfter' => 240]);
            $run->addText("____________________ ", ['underline' => 'single', 'size' => 12]);
            $run->addText($templateVars['SIGN_CHAIR'], ['size' => 12]);

            $section->addText("Заместитель руководителя инженерной школы цифровых технологий по воспитательной работе", ['size' => 12], ['spaceAfter' => 60]);
            $section->addText($templateVars['SECRETARY_DEGREE'], ['size' => 12], ['spaceAfter' => 60]);
            $run = $section->addTextRun(['spaceAfter' => 240]);
            $run->addText("____________________ ", ['underline' => 'single', 'size' => 12]);
            $run->addText($templateVars['SIGN_SECRETARY'], ['size' => 12]);

            $tempDir = 'generated/';
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            $outputPath = $tempDir . $filename;
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($outputPath);

            $fileContent = base64_encode(file_get_contents($outputPath));
            unlink($outputPath);

            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'message' => 'Протокол сгенерирован',
                'file' => $fileContent,
                'filename' => $filename,
                'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ]);
            exit;
        } else {
            $templatePath = 'generated/template.tex';
            if (!file_exists($templatePath)) {
                header('Content-Type: application/json', true, 404);
                echo json_encode(['status' => 'error', 'message' => 'Шаблон LaTeX не найден']);
                exit;
            }
            $templateContent = file_get_contents($templatePath);

            $rfStudents = array_filter($students, fn($s) => strtoupper($s['budget'] ?? '-') !== 'ХМАО');
            $hmaoStudents = array_filter($students, fn($s) => strtoupper($s['budget'] ?? '-') === 'ХМАО');

            $studentListRf = '';
            foreach (array_slice($rfStudents, 0, 30) as $index => $student) {
                $reason = getCategoryName($student['category_id']) ?: 'Не указано';
                $row = [
                    'number' => $index + 1,
                    'fio' => addslashes($student['full_name']),
                    'budget' => addslashes($student['budget'] ?: '-'),
                    'group' => addslashes($student['group_name']),
                    'reason' => addslashes($reason),
                    'amount' => $student['amount']
                ];
                $studentListRf .= "{$row['number']} & {$row['fio']} & {$row['budget']} & {$row['group']} & {$row['reason']} & {$row['amount']} \\\\\n";
            }
            if (empty($studentListRf)) {
                $studentListRf = "1 & Нет данных & - & - & Нет студентов & 0 \\\\\n";
            }

            $studentListHmao = '';
            foreach (array_slice($hmaoStudents, 0, 3) as $index => $student) {
                $reason = getCategoryName($student['category_id']) ?: 'Не указано';
                $row = [
                    'number' => $index + 1,
                    'fio' => addslashes($student['full_name']),
                    'budget' => addslashes($student['budget'] ?: '-'),
                    'group' => addslashes($student['group_name']),
                    'reason' => addslashes($reason),
                    'amount' => $student['amount']
                ];
                $studentListHmao .= "{$row['number']} & {$row['fio']} & {$row['budget']} & {$row['group']} & {$row['reason']} & {$row['amount']} \\\\\n";
            }
            if (empty($studentListHmao)) {
                $studentListHmao = "1 & Нет данных & - & - & Нет студентов & 0 \\\\\n";
            }

            $latexContent = str_replace(['{STUDENT_LIST_RF}', '{STUDENT_LIST_HMAO}'], [rtrim($studentListRf, "\n"), rtrim($studentListHmao, "\n")], $templateContent);
            foreach ($templateVars as $placeholder => $value) {
                $latexContent = str_replace("{{$placeholder}}", addslashes($value), $latexContent);
            }

            $tempDir = 'generated/';
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            $tempTexPath = $tempDir . 'temp_protocol.tex';
            file_put_contents($tempTexPath, $latexContent);

            $outputPath = $tempDir . $filename;
            $command = "latexmk -pdf -output-directory=$tempDir $tempTexPath 2>&1";
            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputPath)) {
                header('Content-Type: application/json', true, 500);
                echo json_encode(['status' => 'error', 'message' => 'Ошибка компиляции LaTeX']);
                exit;
            }

            $fileContent = base64_encode(file_get_contents($outputPath));
            unlink($tempTexPath);
            unlink($outputPath);
            array_map('unlink', glob($tempDir . 'temp_protocol.*'));

            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'message' => 'Протокол сгенерирован',
                'file' => $fileContent,
                'filename' => $filename,
                'contentType' => 'application/pdf'
            ]);
            exit;
        }
    },
    'add_student_category' => function() use ($pdo) {
        $student_id = $_POST['student_id'] ?? null;
        $category_id = $_POST['category_id'] ?? null;
        $academic_year = $_POST['academic_year'] ?? null;

        if (!$student_id || !$category_id || !$academic_year) {
            header('Content-Type: application/json', true, 400);
            echo json_encode(['status' => 'error', 'message' => 'Недостаточно данных']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO StudentCategories (student_id, category_id, academic_year) VALUES (?, ?, ?)");
            $stmt->execute([$student_id, $category_id, $academic_year]);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Студент добавлен в категорию']);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['status' => 'error', 'message' => 'Ошибка добавления студента']);
            exit;
        }
    },
    'update_student' => function() use ($pdo) {
        $student_id = $_POST['student_id'] ?? null;
        $full_name = trim($_POST['full_name'] ?? '');
        $budget = trim($_POST['budget'] ?? '');
        $group_name = trim($_POST['group_name'] ?? '');

        if (!$student_id || !$full_name || !$group_name) {
            header('Content-Type: application/json', true, 400);
            echo json_encode(['status' => 'error', 'message' => 'Недостаточно данных']);
            exit;
        }

        try {
            // Update student details
            $stmt = $pdo->prepare("UPDATE Students SET full_name = ?, budget = ? WHERE id = ?");
            $stmt->execute([$full_name, $budget, $student_id]);

            // Update group
            $stmt = $pdo->prepare("SELECT id FROM Groups WHERE group_name = ?");
            $stmt->execute([$group_name]);
            $group = $stmt->fetch();

            if ($group) {
                $stmt = $pdo->prepare("UPDATE Students SET group_id = ? WHERE id = ?");
                $stmt->execute([$group['id'], $student_id]);
            } else {
                header('Content-Type: application/json', true, 400);
                echo json_encode(['status' => 'error', 'message' => 'Группа не найдена']);
                exit;
            }

            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Данные студента обновлены']);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['status' => 'error', 'message' => 'Ошибка обновления данных']);
            exit;
        }
    },
    'get_students' => function() {
        $search = $_GET['search'] ?? '';
        $students = getAllStudents($search);
        header('Content-Type: application/json');
        echo json_encode($students);
        exit;
    },
    'get_student_categories' => function() {
        $filters = [
            'search' => $_GET['search'] ?? ''
        ];
        $students = getStudentCategories($filters);
        header('Content-Type: application/json');
        echo json_encode($students);
        exit;
    }
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($actions[$_POST['action']])) {
    $actions[$_POST['action']]();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && isset($actions[$_GET['action']])) {
    $actions[$_GET['action']]();
}

// Load interface data
$schools = $pdo->query("SELECT code, name FROM Schools ORDER BY name")->fetchAll();
$years = $pdo->query("SELECT year FROM AcademicYears ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
$categories = getCategories();
$templateVars = getTemplateVariables();

// Get filter values
$filters = [
    'search' => trim($_GET['search'] ?? '')
];
$studentCategories = getStudentCategories($filters);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <?php include 'header.html'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система документооборота - ЮГУ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
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
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(to bottom, var(--ygu-gray), #ffffff);
            color: var(--ygu-dark-gray);
        }

        .container {
            max-width: 1600px;
            padding: 20px;
            margin-top: 20px;
            margin-left: 10px;
        }

        .main-row {
            display: flex;
            gap: 20px;
        }

        .sidebar {
            flex: 0 0 300px;
        }

        .content {
            flex: 1;
        }

        .card {
            border: none;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 15px;
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            background: var(--ygu-blue);
            color: #ffffff;
            padding: 10px 15px;
            border-radius: 10px 10px 0 0;
            font-weight: 500;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .card-header h4 {
            margin: 0;
            font-size: 1rem;
            font-weight: 500;
        }

        .card-body {
            padding: 15px;
            border-radius: 0 0 10px 10px;
        }

        .card.collapsed .card-body {
            display: none;
        }

        .toggle-icon::before {
            content: '▼';
            font-size: 0.9rem;
        }

        .card.collapsed .toggle-icon::before {
            content: '▶';
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            background: #ffffff;
            z-index: 1000;
            font-size: 0.9rem;
        }

        .notification.success {
            border-left: 4px solid var(--ygu-light-blue);
        }

        .notification.error {
            border-left: 4px solid #d32f2f;
        }

        .form-select, .form-control {
            border-radius: 6px;
            padding: 8px;
            font-size: 0.85rem;
            border: 1px solid #b0bec5;
        }

        .form-select:focus, .form-control:focus {
            border-color: var(--ygu-light-black);
            box-shadow: 0 0 0 0.2rem rgba(0, 0, 0, 0);
        }

        .btn-primary {
            background: var(--ygu-black);
            border-color: var(--ygu-black);
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: var(--ygu-light-black);
            border-color: var(--ygu-light-black);
        }

        .btn-outline-secondary {
            border-color: var(--ygu-dark-gray);
            color: var(--ygu-dark-gray);
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 0.85rem;
        }

        .btn-outline-secondary:hover {
            background: var(--ygu-gray);
            border-color: var(--ygu-dark-gray);
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 0.8rem;
        }

        .preview-container {
            background: #ffffff;
            border: 1px solid #000000;
            width: 210mm;
            min-height: 297mm;
            margin: 15px auto;
            padding: 20mm 15mm;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            font-family: 'Times New Roman', serif;
            font-size: 12pt;
            line-height: 1.5;
        }

        .preview h1, .preview h2 {
            font-size: 14pt;
            font-weight: bold;
            text-align: center;
            margin: 0;
            padding: 0.5em 0;
            color: #000000;
        }

        .preview p {
            margin: 0;
            padding: 0.5em 0;
            line-height: 1.5;
        }

        .preview .section-title {
            font-weight: bold;
            padding-top: 1em;
            padding-bottom: 0.2em;
            color: #000000;
        }

        .preview .members {
            white-space: pre-wrap;
            padding-bottom: 0.5em;
        }

        .preview .date-city {
            display: flex;
            justify-content: space-between;
            padding: 1em 0;
            font-size: 12pt;
        }

        .preview .date-day {
            text-decoration: underline;
        }

        .preview .city {
            text-align: right;
        }

        .preview table {
            width: 100%;
            border-collapse: collapse;
            margin: 1em 0;
            border: 1px solid #000000;
            font-size: 11pt;
        }

        .preview th, .preview td {
            border: 1px solid #000000;
            padding: 8px;
            vertical-align: middle;
        }

        .preview th {
            background: #eceff1;
            font-weight: bold;
            text-align: center;
        }

        .preview td.number, .preview td.amount, .preview td.budget, .preview td.group {
            text-align: center;
        }

        .preview .signatures {
            padding-top: 2em;
        }

        .preview .signature {
            padding-bottom: 1.5em;
        }

        .preview .signature-line {
            display: inline-block;
            width: 40mm;
            border-bottom: 1px solid #000000;
            vertical-align: bottom;
            margin-left: 10px;
        }

        .sidebar .card {
            margin-bottom: 12px;
        }

        .sidebar .card-header {
            padding: 8px 12px;
        }

        .sidebar .card-body {
            padding: 12px;
        }

        .sidebar .form-label {
            font-size: 0.85rem;
            color: var(--ygu-dark-gray);
        }

        .sidebar .form-control, .sidebar .form-select {
            font-size: 0.8rem;
        }

        h2 {
            color: var(--ygu-blue);
            font-size: 1.5rem;
            margin-bottom: 20px;
            font-weight: 500;
        }

        @media (max-width: 1200px) {
            .main-row {
                flex-direction: column;
            }
            .sidebar {
                flex: 0 0 100%;
            }
            .preview-container {
                width: 100%;
                padding: 15mm;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
                margin-left: 0;
            }
        }

        /* Styles for student table and modal */
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

        .filter-group {
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
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background-color: #fff;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 8px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .budget-filter-btn.active {
            background-color: var(--ygu-blue);
            color: #fff;
            border-color: var(--ygu-blue);
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

        .student-search-container {
            position: relative;
        }

        .student-search-dropdown {
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

        .student-search-dropdown.show {
            display: block;
        }

        .student-search-item {
            padding: 8px 12px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .student-search-item:hover {
            background-color: #f1f3f5;
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

        .student-row {
            cursor: pointer;
        }

        .student-row:hover {
            background-color: #f1f3f5;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            const monthMap = <?php echo json_encode($monthMap); ?>;
            let studentsData = <?php echo json_encode($studentCategories); ?>;
            let selectedMonth = $('#MONTH').val();
            const defaultCategoryId = <?php echo json_encode($categories[0]['id'] ?? 1); ?>;
            const defaultAcademicYear = <?php echo json_encode($years[0] ?? '2024-2025'); ?>;

            $('.card-header').click(function() {
                $(this).closest('.card').toggleClass('collapsed');
            });

            function showNotification(message, type) {
                const $notification = $(`
                    <div class="notification ${type} show">
                        <span class="icon">${type === 'success' ? '✅' : '❌'}</span>
                        <span class="message">${message}</span>
                        <button class="close-btn">×</button>
                    </div>
                `);
                $('.notification-container').append($notification);
                setTimeout(() => {
                    $notification.addClass('hide');
                    setTimeout(() => $notification.remove(), 300);
                }, 5000);
                $notification.find('.close-btn').click(() => {
                    $notification.addClass('hide');
                    setTimeout(() => $notification.remove(), 300);
                });
            }

            function updatePreview() {
                $('.template-input').each(function() {
                    const placeholder = $(this).data('placeholder');
                    const value = $(this).val().trim() || `{${placeholder}}`;
                    $(`.preview [data-placeholder="${placeholder}"]`).text(value);
                });
                const day = $('#DAY').val().trim() || 'DAY';
                const month = $('#MONTH').val().trim() || 'MONTH';
                const year = $('#YEAR').val().trim() || 'YEAR';
                $(`.preview [data-placeholder="DATE"]`).html(`<span class="date-day">«${day}»</span> ${month} ${year} г.`);
                updateStudentTables(studentsData);
            }

            function updateStudentTables(students) {
                const rfStudents = students.filter(s => (s.budget || '-').toUpperCase() !== 'ХМАО');
                const hmaoStudents = students.filter(s => (s.budget || '-').toUpperCase() === 'ХМАО');

                // Update RF table
                const $rfTableBody = $('.preview .rf-table tbody');
                $rfTableBody.empty();
                if (rfStudents.length === 0) {
                    $rfTableBody.append(`
                        <tr>
                            <td class="number">1</td>
                            <td>Нет данных</td>
                            <td class="budget">-</td>
                            <td class="group">-</td>
                            <td>Нет студентов</td>
                            <td class="amount">0</td>
                        </tr>
                    `);
                } else {
                    rfStudents.slice(0, 30).forEach((student, index) => {
                        const reason = student.category_id ? '<?php echo addslashes(getCategoryName(' + student.category_id + ')); ?>' : 'Не указано';
                        $rfTableBody.append(`
                            <tr>
                                <td class="number">${index + 1}</td>
                                <td>${$('<div/>').text(student.full_name).html()}</td>
                                <td class="budget">${$('<div/>').text(student.budget || '-').html()}</td>
                                <td class="group">${$('<div/>').text(student.group_name).html()}</td>
                                <td>${$('<div/>').text(reason).html()}</td>
                                <td class="amount">${$('<div/>').text(student.amount).html()}</td>
                            </tr>
                        `);
                    });
                }

                // Update HMAO table
                const $hmaoTableBody = $('.preview .hmao-table tbody');
                $hmaoTableBody.empty();
                if (hmaoStudents.length === 0) {
                    $hmaoTableBody.append(`
                        <tr>
                            <td class="number">1</td>
                            <td>Нет данных</td>
                            <td class="budget">-</td>
                            <td class="group">-</td>
                            <td>Нет студентов</td>
                            <td class="amount">0</td>
                        </tr>
                    `);
                } else {
                    hmaoStudents.slice(0, 3).forEach((student, index) => {
                        const reason = student.category_id ? '<?php echo addslashes(getCategoryName(' + student.category_id + ')); ?>' : 'Не указано';
                        $hmaoTableBody.append(`
                            <tr>
                                <td class="number">${index + 1}</td>
                                <td>${$('<div/>').text(student.full_name).html()}</td>
                                <td class="budget">${$('<div/>').text(student.budget || '-').html()}</td>
                                <td class="group">${$('<div/>').text(student.group_name).html()}</td>
                                <td>${$('<div/>').text(reason).html()}</td>
                                <td class="amount">${$('<div/>').text(student.amount).html()}</td>
                            </tr>
                        `);
                    });
                }
            }

            $('#SCHOOL_CODE').on('change', function() {
                const schoolName = $(this).find('option:selected').text();
                $('#SCHOOL').val(schoolName);
                updatePreview();
            });

            $('#MONTH').on('change', function() {
                selectedMonth = $(this).val();
                updatePreview();
            });

            $('#format').change(function() {
                const format = $(this).val();
                $('#filename').attr('placeholder', `protocol_7_2025.${format === 'word' ? 'docx' : 'pdf'}`);
            });

            $('.ajax-form').on('submit', function(e) {
                e.preventDefault();
                const $form = $(this);
                const action = $form.find('input[name="action"]').val();
                const formData = $form.serializeArray();
                const filters = {
                    month: $('#MONTH').val() || ''
                };
                formData.push({ name: 'month', value: filters.month });

                $.ajax({
                    url: '',
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        showNotification(response.message, response.status);
                        if (action === 'generate_protocol' && response.file) {
                            const link = document.createElement('a');
                            link.href = `data:${response.contentType};base64,${response.file}`;
                            link.download = response.filename;
                            link.click();
                        }
                    },
                    error: function(xhr) {
                        const response = xhr.responseJSON || { message: 'Ошибка сервера', status: 'error' };
                        showNotification(response.message, response.status);
                    }
                });
            });

            $('.template-input').on('input change', function() {
                const placeholder = $(this).data('placeholder');
                const value = $(this).val().trim();
                if (value.length > 1000) {
                    showNotification('Максимум 1000 символов', 'error');
                    $(this).val(value.substring(0, 1000));
                }
                updatePreview();
            });

            // Load students in modal
            function loadStudents(search = '') {
                $.ajax({
                    url: '',
                    method: 'GET',
                    data: { action: 'get_students', search: search },
                    dataType: 'json',
                    success: function(students) {
                        const $tbody = $('#student-selection-table');
                        $tbody.empty();
                        students.forEach(student => {
                            $tbody.append(`
                                <tr class="student-row" data-student-id="${student.id}">
                                    <td>${$('<div/>').text(student.full_name).html()}</td>
                                    <td>${$('<div/>').text(student.group_name).html()}</td>
                                    <td>${$('<div/>').text(student.budget || '-').html()}</td>
                                </tr>
                            `);
                        });
                    },
                    error: function() {
                        showNotification('Ошибка загрузки студентов', 'error');
                    }
                });
            }

            $('#selectStudentsModal').on('shown.bs.modal', function() {
                loadStudents();
            });

            $('#student-search').on('input', function() {
                const search = $(this).val().trim();
                loadStudents(search);
            });

            $(document).on('click', '.student-row', function() {
                const studentId = $(this).data('student-id');
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: {
                        action: 'add_student_category',
                        student_id: studentId,
                        category_id: defaultCategoryId,
                        academic_year: defaultAcademicYear
                    },
                    dataType: 'json',
                    success: function(response) {
                        showNotification(response.message, response.status);
                        if (response.status === 'success') {
                            $.get('', { action: 'get_student_categories' }, function(data) {
                                studentsData = data;
                                updatePreview();
                            }, 'json');
                            $('#selectStudentsModal').modal('hide');
                        }
                    },
                    error: function() {
                        showNotification('Ошибка добавления студента', 'error');
                    }
                });
            });

            // Initial setup
            updatePreview();
        });
    </script>
</head>
<body>
    <div class="container">
        <?php foreach (getFlashMessages() as $flash): ?>
            <div class="notification <?php echo $flash['type']; ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
        <?php endforeach; ?>

        <h2>Система документооборота</h2>

        <div class="main-row">
            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Template Editor -->
                <div class="card">
                    <div class="card-header"><h4><span class="toggle-icon"></span>Редактирование шаблона</h4></div>
                    <div class="card-body">
                        <form method="POST" class="ajax-form">
                            <?php
                            $fields = [
                                'UNIVERSITY' => 'ВУЗ',
                                'SCHOOL_CODE' => 'Школа',
                                'PROTOCOL_NUMBER' => 'Номер протокола',
                                'CITY' => 'Город',
                                'CHAIRPERSON' => 'Председатель',
                                'CHAIR_DEGREE' => 'Ученая степень председателя',
                                'SECRETARY' => 'Секретарь',
                                'SECRETARY_DEGREE' => 'Ученая степень секретаря',
                                'AGENDA' => 'Повестка дня'
                            ];
                            $months = array_keys($monthMap);
                            foreach ($fields as $placeholder => $label): ?>
                                <?php if ($placeholder === 'CITY'): ?>
                                    <div class="mb-2">
                                        <label class="form-label">Месяц</label>
                                        <div class="row g-2">
                                            <div class="col-4">
                                                <input type="number" id="DAY" name="template[DAY]" class="form-control template-input" data-placeholder="DAY" placeholder="День" min="1" max="31" value="<?php echo htmlspecialchars($templateVars['DAY'] ?? ''); ?>">
                                            </div>
                                            <div class="col-4">
                                                <select id="MONTH" name="template[MONTH]" class="form-select template-input" data-placeholder="MONTH">
                                                    <option value="">Выберите месяц</option>
                                                    <?php foreach ($months as $month): ?>
                                                        <option value="<?php echo $month; ?>" <?php echo ($templateVars['MONTH'] ?? '') == $month ? 'selected' : ''; ?>>
                                                            <?php echo $month; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-4">
                                                <input type="number" id="YEAR" name="template[YEAR]" class="form-control template-input" data-placeholder="YEAR" placeholder="Год" min="2000" max="2099" value="<?php echo htmlspecialchars($templateVars['YEAR'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-2">
                                    <label for="<?php echo $placeholder; ?>" class="form-label"><?php echo $label; ?></label>
                                    <?php if ($placeholder === 'SCHOOL_CODE'): ?>
                                        <select id="<?php echo $placeholder; ?>" name="template[<?php echo $placeholder; ?>]" class="form-select template-input" data-placeholder="<?php echo $placeholder; ?>">
                                            <?php foreach ($schools as $school): ?>
                                                <option value="<?php echo $school['code']; ?>" <?php echo ($templateVars['SCHOOL_CODE'] ?? 1) == $school['code'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($school['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" id="SCHOOL" name="template[SCHOOL]" class="form-control template-input" data-placeholder="SCHOOL" value="<?php echo htmlspecialchars($templateVars['SCHOOL'] ?? ''); ?>">
                                    <?php else: ?>
                                        <input type="text" id="<?php echo $placeholder; ?>" name="template[<?php echo $placeholder; ?>]" class="form-control template-input" data-placeholder="<?php echo $placeholder; ?>" value="<?php echo htmlspecialchars($templateVars[$placeholder] ?? ''); ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="mb-2">
                                <label for="MEMBERS" class="form-label">Члены комиссии</label>
                                <textarea id="MEMBERS" name="template[MEMBERS]" class="form-control template-input" data-placeholder="MEMBERS" rows="4"><?php echo htmlspecialchars($templateVars['MEMBERS'] ?? ''); ?></textarea>
                            </div>
                            <input type="hidden" name="action" value="save_template">
                            <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                        </form>
                    </div>
                </div>

                <!-- Protocol Generation -->
                <div class="card">
                    <div class="card-header"><h4><span class="toggle-icon"></span>Генерация протокола</h4></div>
                    <div class="card-body">
                        <form method="POST" class="ajax-form">
                            <div class="mb-2">
                                <label for="format" class="form-label">Формат</label>
                                <select id="format" name="format" class="form-select">
                                    <option value="word">Word (.docx)</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label for="filename" class="form-label">Имя файла</label>
                                <input type="text" id="filename" name="filename" class="form-control" placeholder="protocol_7_2025.pdf">
                            </div>
                            <input type="hidden" name="action" value="generate_protocol">
                            <button type="submit" class="btn btn-primary btn-sm">Сгенерировать</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="content">
                <!-- Preview -->
                <div class="card collapsed">
                    <div class="card-header"><h4><span class="toggle-icon"></span>Предпросмотр протокола</h4></div>
                    <div class="card-body">
                        <div class="preview-container">
                            <div class="preview">
                                <h1>ПРОТОКОЛ № <span data-placeholder="PROTOCOL_NUMBER"><?php echo htmlspecialchars($templateVars['PROTOCOL_NUMBER'] ?? '{PROTOCOL_NUMBER}'); ?></span></h1>
                                <h2>Заседания стипендиальной комиссии</h2>
                                <h2><span data-placeholder="SCHOOL"><?php echo htmlspecialchars($templateVars['SCHOOL'] ?? '{SCHOOL}'); ?></span></h2>
                                <h2><span data-placeholder="UNIVERSITY"><?php echo htmlspecialchars($templateVars['UNIVERSITY'] ?? '{UNIVERSITY}'); ?></span></h2>
                                <div class="date-city">
                                    <span data-placeholder="DATE"><span class="date-day">«<?php echo htmlspecialchars($templateVars['DAY'] ?? 'DAY'); ?>»</span> <?php echo htmlspecialchars($templateVars['MONTH'] ?? 'MONTH'); ?> <?php echo htmlspecialchars($templateVars['YEAR'] ?? 'YEAR'); ?> г.</span>
                                    <span class="city">г. <span data-placeholder="CITY"><?php echo htmlspecialchars($templateVars['CITY'] ?? '{CITY}'); ?></span></span>
                                </div>
                                <p class="section-title">Председатель комиссии:</p>
                                <p><span data-placeholder="CHAIRPERSON"><?php echo htmlspecialchars($templateVars['CHAIRPERSON'] ?? '{CHAIRPERSON}'); ?></span></p>
                                <p class="section-title">Члены комиссии:</p>
                                <p class="members"><span data-placeholder="MEMBERS"><?php echo htmlspecialchars($templateVars['MEMBERS'] ?? '{MEMBERS}'); ?></span></p>
                                <p class="section-title">Секретарь комиссии:</p>
                                <p><span data-placeholder="SECRETARY"><?php echo htmlspecialchars($templateVars['SECRETARY'] ?? '{SECRETARY}'); ?></span></p>
                                <p class="section-title">Повестка дня:</p>
                                <p><span data-placeholder="AGENDA"><?php echo htmlspecialchars($templateVars['AGENDA'] ?? '{AGENDA}'); ?></span></p>
                                <p class="section-title">Слушали:</p>
                                <p><span data-placeholder="LISTENED"><?php echo htmlspecialchars($templateVars['LISTENED'] ?? '{LISTENED}'); ?></span></p>
                                <p class="section-title">Решили:</p>
                                <p><span data-placeholder="DECISION"><?php echo htmlspecialchars($templateVars['DECISION'] ?? '{DECISION}'); ?></span></p>
                                <?php
                                $rfStudents = array_filter($studentCategories, fn($s) => strtoupper($s['budget'] ?? '-') !== 'ХМАО');
                                $hmaoStudents = array_filter($studentCategories, fn($s) => strtoupper($s['budget'] ?? '-') === 'ХМАО');
                                ?>
                                <table class="rf-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 0.5cm;">№</th>
                                            <th style="width: 5cm;">ФИО</th>
                                            <th style="width: 2cm;">Бюджет</th>
                                            <th style="width: 2cm;">Группа</th>
                                            <th style="width: 5cm;">Основание</th>
                                            <th style="width: 2cm;">Сумма</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($rfStudents)): ?>
                                            <tr><td class="number">1</td><td>Нет данных</td><td class="budget">-</td><td class="group">-</td><td>Нет студентов</td><td class="amount">0</td></tr>
                                        <?php else: ?>
                                            <?php foreach (array_slice($rfStudents, 0, 30) as $index => $student): ?>
                                                <tr>
                                                    <td class="number"><?php echo $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                    <td class="budget"><?php echo htmlspecialchars($student['budget'] ?: '-'); ?></td>
                                                    <td class="group"><?php echo htmlspecialchars($student['group_name']); ?></td>
                                                    <td><?php echo htmlspecialchars(getCategoryName($student['category_id']) ?: 'Не указано'); ?></td>
                                                    <td class="amount"><?php echo htmlspecialchars($student['amount']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <table class="hmao-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 0.5cm;">№</th>
                                            <th style="width: 5cm;">ФИО</th>
                                            <th style="width: 2cm;">Бюджет</th>
                                            <th style="width: 2cm;">Группа</th>
                                            <th style="width: 5cm;">Основание</th>
                                            <th style="width: 2cm;">Сумма</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($hmaoStudents)): ?>
                                            <tr><td class="number">1</td><td>Нет данных</td><td class="budget">-</td><td class="group">-</td><td>Нет студентов</td><td class="amount">0</td></tr>
                                        <?php else: ?>
                                            <?php foreach (array_slice($hmaoStudents, 0, 3) as $index => $student): ?>
                                                <tr>
                                                    <td class="number"><?php echo $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                    <td class="budget"><?php echo htmlspecialchars($student['budget'] ?: '-'); ?></td>
                                                    <td class="group"><?php echo htmlspecialchars($student['group_name']); ?></td>
                                                    <td><?php echo htmlspecialchars(getCategoryName($student['category_id']) ?: 'Не указано'); ?></td>
                                                    <td class="amount"><?php echo htmlspecialchars($student['amount']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
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
                </div>
            </div>
        </div>

        <!-- Modal for Selecting Students -->
        <div class="modal no-backdrop fade" id="selectStudentsModal" tabindex="-1" aria-labelledby="selectStudentsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="selectStudentsModalLabel">Выбор студентов</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3 student-search-container">
                            <input type="text" id="student-search" class="form-control" placeholder="Поиск по ФИО" aria-label="Поиск по ФИО">
                            <div class="student-search-dropdown" id="student-search-dropdown"></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table data-table" aria-label="Таблица выбора студентов">
                                <thead>
                                    <tr>
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
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Закрыть</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notification Container -->
        <div class="notification-container"></div>

    </div>
</body>
</html>