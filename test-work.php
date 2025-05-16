<?php
require_once 'db_config.php';
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

function extractYear($academicYear, $part = 'second') {
    if (preg_match('/^\d{4}\/\d{4}$/', $academicYear)) {
        $years = explode('/', $academicYear);
        return $part === 'first' ? $years[0] : $years[1];
    }
    return $academicYear;
}

// Database query functions
function getTemplateVariables($pdo, $school_code = 1, $academic_year = '2024/2025') {
    try {
        $stmt = $pdo->prepare("
            SELECT placeholder, value 
            FROM TemplateVariables 
            WHERE school_code = ? AND academic_year = ?
        ");
        $stmt->execute([$school_code, $academic_year]);
        $vars = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Define required placeholders
        $requiredPlaceholders = [
            'UNIVERSITY', 'SCHOOL', 'SCHOOL_CODE', 'PROTOCOL_NUMBER', 'DATE', 'DAY', 
            'MONTH', 'YEAR', 'CITY', 'CHAIRPERSON', 'CHAIR_DEGREE', 'MEMBERS', 
            'SECRETARY', 'SECRETARY_DEGREE', 'AGENDA', 'LISTENED', 
            'SIGN_CHAIR', 'SIGN_SECRETARY'
        ];
        
        $monthMap = [
            'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5,
            'июня' => 6, 'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10,
            'ноября' => 11, 'декабря' => 12
        ];

        // Set default values for date-related placeholders
        $currentDate = new DateTime();
        $defaultDay = $currentDate->format('j');
        $defaultMonthNum = $currentDate->format('n');
        $defaultMonth = array_search($defaultMonthNum, $monthMap) ?: 'января';
        $defaultYear = $currentDate->format('Y');
        $defaultCity = $vars['CITY'] ?? 'Ханты-Мансийск'; // Default city if not set

        foreach ($requiredPlaceholders as $placeholder) {
            if (!isset($vars[$placeholder])) {
                if ($placeholder === 'DAY') {
                    $vars[$placeholder] = $defaultDay;
                } elseif ($placeholder === 'MONTH') {
                    $vars[$placeholder] = $defaultMonth;
                } elseif ($placeholder === 'YEAR') {
                    $vars[$placeholder] = $defaultYear;
                } elseif ($placeholder === 'DATE') {
                    $vars[$placeholder] = "«{$defaultDay}» {$defaultMonth} {$defaultYear} г. г. {$defaultCity}";
                } else {
                    $vars[$placeholder] = '';
                }
            }
        }

        // Ensure DATE is consistent with DAY, MONTH, YEAR
        if (isset($vars['DAY'], $vars['MONTH'], $vars['YEAR'], $vars['CITY'])) {
            $vars['DATE'] = "«{$vars['DAY']}» {$vars['MONTH']} {$vars['YEAR']} г. г. {$vars['CITY']}";
        }

        return $vars;
    } catch (PDOException $e) {
        error_log("Ошибка в getTemplateVariables: " . $e->getMessage());
        return [];
    }
}

function getAcademicYears($pdo) {
    try {
        $stmt = $pdo->query("SELECT year FROM AcademicYears ORDER BY year DESC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Ошибка в getAcademicYears: " . $e->getMessage());
        return ['2024/2025'];
    }
}

function getCategories($pdo) {
    static $cache = null;
    if ($cache === null) {
        try {
            $stmt = $pdo->query("SELECT id, number, category_name, category_short, payment_frequency, max_amount FROM categories ORDER BY number");
            $cache = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Ошибка в getCategories: " . $e->getMessage());
            $cache = [];
        }
    }
    return $cache;
}

function getCategoryName($category_id, $pdo) {
    foreach (getCategories($pdo) as $category) {
        if ($category['id'] == $category_id) return $category['category_short'];
    }
    return '';
}

function getStudentCategories($pdo, $filters = []) {
    global $monthMap;
    $search = $filters['search'] ?? null;
    $month = isset($filters['month']) ? array_search($filters['month'], array_keys($monthMap)) : null;
    $year = $filters['year'] ?? date('Y');

    $year = extractYear($year, 'second');

    $query = "
        SELECT 
            s.id, 
            s.full_name, 
            s.budget, 
            g.group_name,
            sc.name AS school_name,
            sr.category_id,
            c.category_short,
            c.max_amount,
            sr.amount,
            sr.month
        FROM Students s
        JOIN Groups g ON s.group_id = g.id
        JOIN Directions d ON g.direction_id = d.code
        JOIN Schools sc ON d.vsh_code = sc.code
        LEFT JOIN StudentReasons sr ON s.id = sr.student_id
        LEFT JOIN categories c ON sr.category_id = c.id
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $query .= " AND s.full_name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    if ($month !== false && $month !== null) {
        $query .= " AND sr.month = ?";
        $params[] = $month;
    }

    if ($year) {
        $query .= " AND YEAR(sr.created_at) = ?";
        $params[] = $year;
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
                    'category_short' => $row['category_short'] ?? 'Не указано',
                    'amount' => $row['amount'] ?? '10000.00',
                    'month' => $row['month']
                ];
            }
        }

        return array_values($students);
    } catch (PDOException $e) {
        error_log("Ошибка в getStudentCategories: " . $e->getMessage());
        return [];
    }
}

// Initialize data
$monthMap = [
    'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5,
    'июня' => 6, 'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10,
    'ноября' => 11, 'декабря' => 12
];

$academicYears = getAcademicYears($pdo);
$defaultAcademicYear = !empty($academicYears) ? $academicYears[0] : '2024/2025';
$templateVars = getTemplateVariables($pdo, 1, $defaultAcademicYear);

// Action handlers
$actions = [
    'save_template' => function() use ($pdo, $defaultAcademicYear, $templateVars) {
        if (!isset($_POST['template']) || !is_array($_POST['template'])) {
            header('Content-Type: application/json', true, 400);
            echo json_encode(['status' => 'error', 'message' => 'Неверные данные шаблона']);
            exit;
        }

        $updatedVars = [];
        $school_code = $_POST['template']['SCHOOL_CODE'] ?? 1;
        $academic_year = $_POST['template']['YEAR'] ?? $defaultAcademicYear;

        try {
            $pdo->beginTransaction();

            foreach ($_POST['template'] as $placeholder => $value) {
                if (preg_match('/^[A-Z_]+$/', $placeholder) && strlen($value) <= 1000) {
                    if ($placeholder === 'MEMBERS' || $placeholder === 'LISTENED') {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[\r\n]+/', "\n", $value);
                    }
                    $updatedVars[$placeholder] = $value;

                    $stmt = $pdo->prepare("
                        INSERT INTO TemplateVariables (school_code, academic_year, placeholder, value)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE value = ?, updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$school_code, $academic_year, $placeholder, $value, $value]);
                }
            }

            if (isset($_POST['template']['SCHOOL_CODE']) && is_numeric($_POST['template']['SCHOOL_CODE'])) {
                $stmt = $pdo->prepare("SELECT name FROM Schools WHERE code = ?");
                $stmt->execute([$_POST['template']['SCHOOL_CODE']]);
                $school = $stmt->fetch();
                if ($school) {
                    $updatedVars['SCHOOL'] = $school['name'];
                    $stmt = $pdo->prepare("
                        INSERT INTO TemplateVariables (school_code, academic_year, placeholder, value)
                        VALUES (?, ?, 'SCHOOL', ?)
                        ON DUPLICATE KEY UPDATE value = ?, updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$school_code, $academic_year, $school['name'], $school['name']]);
                }
            }

            // Synchronize DATE with DAY, MONTH, YEAR, CITY
            if (isset($_POST['template']['DAY'], $_POST['template']['MONTH'], $_POST['template']['YEAR'], $_POST['template']['CITY'])) {
                $day = trim($_POST['template']['DAY']);
                $month = trim($_POST['template']['MONTH']);
                $year = trim($_POST['template']['YEAR']);
                $city = trim($_POST['template']['CITY']);
                if ($day && $month && $year && $city) {
                    $date = "«{$day}» {$month} {$year} г. г. {$city}";
                    $updatedVars['DATE'] = $date;
                    $stmt = $pdo->prepare("
                        INSERT INTO TemplateVariables (school_code, academic_year, placeholder, value)
                        VALUES (?, ?, 'DATE', ?)
                        ON DUPLICATE KEY UPDATE value = ?, updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$school_code, $academic_year, $date, $date]);
                }
            }

            $pdo->commit();
            setFlashMessage('Шаблон сохранён');
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'message' => 'Шаблон сохранён',
                'template_vars' => $updatedVars
            ]);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Ошибка в save_template: " . $e->getMessage());
            header('Content-Type: application/json', true, 500);
            echo json_encode(['status' => 'error', 'message' => 'Ошибка сохранения шаблона']);
            exit;
        }
    },
    'generate_protocol' => function() use ($pdo, $defaultAcademicYear, $monthMap) {
        $filename = trim($_POST['filename'] ?? '');
        $format = $_POST['format'] ?? 'pdf';
        $month = trim($_POST['month'] ?? '');
        $year = trim($_POST['year'] ?? $defaultAcademicYear);

        $fileYear = extractYear($year, 'second');
        $extension = $format === 'word' ? 'docx' : 'pdf';
        if ($filename && !validateFilename($filename, $extension)) {
            header('Content-Type: application/json', true, 400);
            echo json_encode(['status' => 'error', 'message' => 'Неверное имя файла']);
            exit;
        }
        $filename = $filename ?: "protocol_7_{$fileYear}.{$extension}";

        $filters = [
            'month' => $month,
            'year' => $year
        ];
        $students = getStudentCategories($pdo, $filters);
        $templateVars = getTemplateVariables($pdo, 1, $year);

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

            $section->addText("ПРОТОКОЛ № {$templateVars['PROTOCOL_NUMBER']}", ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 120]);
            $section->addText('Заседания стипендиальной комиссии', ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 120]);
            $section->addText($templateVars['SCHOOL'], ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 120]);
            $section->addText($templateVars['UNIVERSITY'], ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 240]);

            // Use DATE directly from templateVars
            $section->addText($templateVars['DATE'], ['size' => 12], ['alignment' => 'both', 'spaceAfter' => 240]);
            $section->addTextBreak(1, ['size' => 12], ['spaceAfter' => 240]);

            foreach ([
                ['Председатель комиссии:', $templateVars['CHAIRPERSON']],
                ['Члены комиссии:', $templateVars['MEMBERS'], true],
                ['Секретарь комиссии:', $templateVars['SECRETARY']],
                ['Повестка дня:', $templateVars['AGENDA']],
                ['Слушали:', $templateVars['LISTENED']]
            ] as $item) {
                $section->addText($item[0], ['bold' => true, 'size' => 12], ['spaceAfter' => 60]);
                $text = $item[1];
                if ($item[2] ?? false) {
                    foreach (explode("\n", $text) as $line) {
                        if (trim($line) !== '') {
                            $section->addText(trim($line), ['size' => 12], ['spaceBefore' => 0, 'spaceAfter' => 0]);
                        }
                    }
                    $section->addTextBreak(1, ['size' => 12], ['spaceAfter' => 240]);
                } else {
                    $section->addText($text, ['size' => 12], ['spaceAfter' => 240]);
                }
            }

            $rfStudents = array_filter($students, fn($s) => strtoupper($s['budget'] ?? '-') !== 'ХМАО');
            $hmaoStudents = array_filter($students, fn($s) => strtoupper($s['budget'] ?? '-') === 'ХМАО');

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
                    $table->addCell($widths[$i], ['valign' => 'center'])->addText($header, ['bold' => true, 'size' => 12], ['alignment' => 'center']);
                }

                if (empty($group[1])) {
                    $table->addRow();
                    $table->addCell($widths[0], ['valign' => 'center'])->addText('1', ['size' => 12], ['alignment' => 'center']);
                    $table->addCell($widths[1], ['valign' => 'center'])->addText('Нет данных', ['size' => 12]);
                    $table->addCell($widths[2], ['valign' => 'center'])->addText('-', ['size' => 12], ['alignment' => 'center']);
                    $table->addCell($widths[3], ['valign' => 'center'])->addText('-', ['size' => 12], ['alignment' => 'center']);
                    $table->addCell($widths[4], ['valign' => 'center'])->addText('Нет студентов', ['size' => 12]);
                    $table->addCell($widths[5], ['valign' => 'center'])->addText('0', ['size' => 12], ['alignment' => 'center']);
                } else {
                    foreach (array_slice($group[1], 0, $group[2]) as $index => $student) {
                        $reason = $student['category_short'] ?: 'Не указано';
                        $table->addRow();
                        $table->addCell($widths[0], ['valign' => 'center'])->addText($index + 1, ['size' => 12], ['alignment' => 'center']);
                        $table->addCell($widths[1], ['valign' => 'center'])->addText($student['full_name'], ['size' => 12]);
                        $table->addCell($widths[2], ['valign' => 'center'])->addText($student['budget'] ?: '-', ['size' => 12], ['alignment' => 'center']);
                        $table->addCell($widths[3], ['valign' => 'center'])->addText($student['group_name'], ['size' => 12], ['alignment' => 'center']);
                        $table->addCell($widths[4], ['valign' => 'center'])->addText($reason, ['size' => 12]);
                        $table->addCell($widths[5], ['valign' => 'center'])->addText($student['amount'], ['size' => 12], ['alignment' => 'center']);
                    }
                }
                $section->addTextBreak(1, ['size' => 12], ['spaceAfter' => 240]);
            }

            $section->addText("Руководитель инженерной школы цифровых технологий", ['size' => 12], ['spaceAfter' => 60]);
            $run = $section->addTextRun(['spaceAfter' => 240]);
            $run->addText($templateVars['CHAIR_DEGREE'] . " ", ['size' => 12]);
            $run->addText("____________________ ", ['underline' => 'single', 'size' => 12]);
            $run->addText($templateVars['SIGN_CHAIR'], ['size' => 12]);

            $section->addText("Заместитель руководителя инженерной школы цифровых технологий по воспитательной работе", ['size' => 12], ['spaceAfter' => 60]);
            $run = $section->addTextRun(['spaceAfter' => 240]);
            $run->addText($templateVars['SECRETARY_DEGREE'] . " ", ['size' => 12]);
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
                $reason = $student['category_short'] ?: 'Не указано';
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
                $reason = $student['category_short'] ?: 'Не указано';
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

            $members = $templateVars['MEMBERS'];
            $membersLines = explode("\n", $members);
            $membersLatex = '';
            foreach ($membersLines as $line) {
                if (trim($line) !== '') {
                    $membersLatex .= addslashes(trim($line)) . ' \\\\ ';
                }
            }
            $templateVars['MEMBERS_LATEX'] = $membersLatex ?: 'Нет данных';

            $latexContent = str_replace(['{STUDENT_LIST_RF}', '{STUDENT_LIST_HMAO}', '{MEMBERS_LATEX}'], 
                                       [rtrim($studentListRf, "\n"), rtrim($studentListHmao, "\n"), $templateVars['MEMBERS_LATEX']], 
                                       $templateContent);
            foreach ($templateVars as $placeholder => $value) {
                if ($placeholder !== 'MEMBERS_LATEX') {
                    $latexContent = str_replace("{{$placeholder}}", addslashes($value), $latexContent);
                }
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
    'get_student_categories' => function() use ($pdo, $defaultAcademicYear) {
        $filters = [
            'search' => $_GET['search'] ?? '',
            'month' => $_GET['month'] ?? '',
            'year' => $_GET['year'] ?? $defaultAcademicYear
        ];
        $students = getStudentCategories($pdo, $filters);
        header('Content-Type: application/json');
        echo json_encode($students);
        exit;
    },
    'get_template_vars' => function() use ($pdo, $defaultAcademicYear) {
        $school_code = $_GET['school_code'] ?? 1;
        $academic_year = $_GET['academic_year'] ?? $defaultAcademicYear;
        $templateVars = getTemplateVariables($pdo, $school_code, $academic_year);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'template_vars' => $templateVars]);
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
try {
    $schools = $pdo->query("SELECT code, name FROM Schools ORDER BY name")->fetchAll();
    $years = $academicYears;
} catch (PDOException $e) {
    error_log("Ошибка при загрузке данных интерфейса: " . $e->getMessage());
    $schools = [];
    $years = [$defaultAcademicYear];
}
$categories = getCategories($pdo);

$categories_js = array_map(function($category) {
    return [
        'id' => $category['id'],
        'category_short' => $category['category_short']
    ];
}, $categories ?: []);

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'month' => trim($_GET['month'] ?? ($templateVars['MONTH'] ?? '')),
    'year' => trim($_GET['year'] ?? ($templateVars['YEAR'] ?? $defaultAcademicYear))
];
$studentCategories = getStudentCategories($pdo, $filters);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система документооборота - ЮГУ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-color);
            line-height: 1.6;
        }

        .app-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }

        .app-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .app-header h1 {
            color: var(--primary-color);
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .app-header h1 i {
            font-size: 1.8rem;
            color: var(--accent-color);
        }

        .app-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 25px;
        }

        @media (max-width: 1200px) {
            .app-layout {
                grid-template-columns: 1fr;
            }
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border: none;
            overflow: hidden;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            background-color: var(--primary-color);
            color: white;
            padding: 15px 20px;
            border-bottom: none;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .card-header .toggle-icon {
            transition: var(--transition);
        }

        .card.collapsed .card-header .toggle-icon {
            transform: rotate(-90deg);
        }

        .card-body {
            padding: 20px;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--secondary-color);
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            border: 1px solid #ddd;
            padding: 10px 12px;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .btn {
            border-radius: var(--border-radius);
            padding: 10px 20px;
            font-weight: 500;
            transition: var(--transition);
        }

        .btn-primary {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }

        .btn-primary:hover {
            background-color: #2980b9;
            border-color: #2980b9;
        }

        .btn-outline-secondary {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-outline-secondary:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }

        .date-inputs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            align-items: center;
        }

        .preview-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 30px;
            margin-top: 20px;
            overflow: auto;
        }

        .protocol-preview {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 20mm;
            background: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            font-family: 'Times New Roman', serif;
            font-size: 14pt;
            line-height: 1.5;
        }

        .protocol-title {
            text-align: center;
            font-weight: bold;
            margin-bottom: 20pt;
            line-height: 1.2;
        }

        .protocol-subtitle {
            text-align: center;
            font-weight: bold;
            margin-bottom: 15pt;
        }

        .protocol-date {
            text-align: justify;
            margin-bottom: 20pt;
        }

        .protocol-section {
            margin-bottom: 15pt;
        }

        .protocol-section-title {
            font-weight: bold;
            margin-bottom: 5pt;
        }

        .protocol-members {
            white-space: pre-line;
        }

        .protocol-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15pt 0;
            font-size: 12pt;
        }

        .protocol-table th, .protocol-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }

        .protocol-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }

        .protocol-table .number, .protocol-table .amount {
            text-align: center;
            width: 5%;
        }

        .protocol-table .budget, .protocol-table .group {
            width: 10%;
            text-align: center;
        }

        .protocol-signature {
            margin-top: 30pt;
        }

        .protocol-signature-line {
            display: inline-block;
            width: 150px;
            border-bottom: 1px solid #000;
            margin: 0 10px;
        }

        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notification {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            background: white;
            box-shadow: var(--box-shadow);
            opacity: 0;
            transform: translateX(100%);
            transition: var(--transition);
            max-width: 350px;
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
            border-left: 4px solid var(--success-color);
        }

        .notification.error {
            border-left: 4px solid var(--danger-color);
        }

        .notification-icon {
            margin-right: 15px;
            font-size: 1.5rem;
        }

        .notification-content {
            flex: 1;
        }

        .notification-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: #7f8c8d;
            margin-left: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #bdc3c7;
        }

        .badge-counter {
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <div class="notification-container"></div>
    
    <div class="app-container">
        <div class="app-header">
            <h1>
                <i class="bi bi-file-earmark-text"></i>
                Система документооборота
            </h1>
        </div>

        <div class="app-layout">
            <div class="sidebar">
                <div class="card">
                    <div class="card-header" data-bs-toggle="collapse" data-bs-target="#templateEditor">
                        <h3><i class="bi bi-pencil-square"></i> Редактирование шаблона</h3>
                        <span class="toggle-icon"><i class="bi bi-chevron-down"></i></span>
                    </div>
                    <div class="card-body collapse show" id="templateEditor">
                        <form method="POST" class="ajax-form" id="template-form">
                            <div class="mb-3">
                                <label for="SCHOOL_CODE" class="form-label">Школа</label>
                                <select id="SCHOOL_CODE" name="template[SCHOOL_CODE]" class="form-select template-input" data-placeholder="SCHOOL_CODE">
                                    <?php foreach ($schools as $school): ?>
                                        <option value="<?php echo $school['code']; ?>" <?php echo ($templateVars['SCHOOL_CODE'] ?? 1) == $school['code'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($school['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="SCHOOL" name="template[SCHOOL]" class="form-control template-input" data-placeholder="SCHOOL" value="<?php echo htmlspecialchars($templateVars['SCHOOL'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Дата</label>
                                <div class="date-inputs">
                                    <input type="number" id="DAY" name="template[DAY]" class="form-control template-input" data-placeholder="DAY" placeholder="День" min="1" max="31" value="<?php echo htmlspecialchars($templateVars['DAY'] ?? ''); ?>">
                                    <select id="MONTH" name="template[MONTH]" class="form-select template-input" data-placeholder="MONTH">
                                        <option value="">Месяц</option>
                                        <?php foreach ($monthMap as $monthName => $monthNum): ?>
                                            <option value="<?php echo $monthName; ?>" <?php echo ($templateVars['MONTH'] ?? '') == $monthName ? 'selected' : ''; ?>>
                                                <?php echo $monthName; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="YEAR" name="template[YEAR]" class="form-select template-input" data-placeholder="YEAR">
                                        <option value="">Год</option>
                                        <?php foreach ($years as $year): ?>
                                            <option value="<?php echo $year; ?>" <?php echo ($templateVars['YEAR'] ?? $defaultAcademicYear) == $year ? 'selected' : ''; ?>>
                                                <?php echo $year; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="set-current-date" class="btn btn-outline-secondary" title="Установить текущую дату">
                                        <i class="bi bi-calendar-check"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="UNIVERSITY" class="form-label">ВУЗ</label>
                                <input type="text" id="UNIVERSITY" name="template[UNIVERSITY]" class="form-control template-input" data-placeholder="UNIVERSITY" value="<?php echo htmlspecialchars($templateVars['UNIVERSITY'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="PROTOCOL_NUMBER" class="form-label">Номер протокола</label>
                                <input type="text" id="PROTOCOL_NUMBER" name="template[PROTOCOL_NUMBER]" class="form-control template-input" data-placeholder="PROTOCOL_NUMBER" value="<?php echo htmlspecialchars($templateVars['PROTOCOL_NUMBER'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="CITY" class="form-label">Город</label>
                                <input type="text" id="CITY" name="template[CITY]" class="form-control template-input" data-placeholder="CITY" value="<?php echo htmlspecialchars($templateVars['CITY'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="CHAIRPERSON" class="form-label">Председатель</label>
                                <input type="text" id="CHAIRPERSON" name="template[CHAIRPERSON]" class="form-control template-input" data-placeholder="CHAIRPERSON" value="<?php echo htmlspecialchars($templateVars['CHAIRPERSON'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="CHAIR_DEGREE" class="form-label">Ученая степень председателя</label>
                                <input type="text" id="CHAIR_DEGREE" name="template[CHAIR_DEGREE]" class="form-control template-input" data-placeholder="CHAIR_DEGREE" value="<?php echo htmlspecialchars($templateVars['CHAIR_DEGREE'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="MEMBERS" class="form-label">Члены комиссии</label>
                                <textarea id="MEMBERS" name="template[MEMBERS]" class="form-control template-input" data-placeholder="MEMBERS" rows="4"><?php echo htmlspecialchars($templateVars['MEMBERS'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="SECRETARY" class="form-label">Секретарь</label>
                                <input type="text" id="SECRETARY" name="template[SECRETARY]" class="form-control template-input" data-placeholder="SECRETARY" value="<?php echo htmlspecialchars($templateVars['SECRETARY'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="SECRETARY_DEGREE" class="form-label">Ученая степень секретаря</label>
                                <input type="text" id="SECRETARY_DEGREE" name="template[SECRETARY_DEGREE]" class="form-control template-input" data-placeholder="SECRETARY_DEGREE" value="<?php echo htmlspecialchars($templateVars['SECRETARY_DEGREE'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="AGENDA" class="form-label">Повестка дня</label>
                                <input type="text" id="AGENDA" name="template[AGENDA]" class="form-control template-input" data-placeholder="AGENDA" value="<?php echo htmlspecialchars($templateVars['AGENDA'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="LISTENED" class="form-label">Слушали</label>
                                <textarea id="LISTENED" name="template[LISTENED]" class="form-control template-input" data-placeholder="LISTENED" rows="3"><?php echo htmlspecialchars($templateVars['LISTENED'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="SIGN_CHAIR" class="form-label">Подпись председателя</label>
                                <input type="text" id="SIGN_CHAIR" name="template[SIGN_CHAIR]" class="form-control template-input" data-placeholder="SIGN_CHAIR" value="<?php echo htmlspecialchars($templateVars['SIGN_CHAIR'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="SIGN_SECRETARY" class="form-label">Подпись секретаря</label>
                                <input type="text" id="SIGN_SECRETARY" name="template[SIGN_SECRETARY]" class="form-control template-input" data-placeholder="SIGN_SECRETARY" value="<?php echo htmlspecialchars($templateVars['SIGN_SECRETARY'] ?? ''); ?>">
                            </div>

                            <input type="hidden" name="action" value="save_template">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-save"></i> Сохранить шаблон
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header" data-bs-toggle="collapse" data-bs-target="#generatorPanel">
                        <h3><i class="bi bi-file-earmark-arrow-down"></i> Генерация протокола</h3>
                        <span class="toggle-icon"><i class="bi bi-chevron-down"></i></span>
                    </div>
                    <div class="card-body collapse show" id="generatorPanel">
                        <form method="POST" class="ajax-form" id="generate-protocol-form">
                            <div class="mb-3">
                                <label for="format" class="form-label">Формат документа</label>
                                <select id="format" name="format" class="form-select">
                                    <option value="word">Microsoft Word (.docx)</option>
                                    <option value="pdf">PDF</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="filename" class="form-label">Имя файла</label>
                                <input type="text" id="filename" name="filename" class="form-control" placeholder="protocol_7_<?php echo htmlspecialchars(extractYear($templateVars['YEAR'] ?? $defaultAcademicYear, 'second')); ?>.docx" value="protocol_7_<?php echo htmlspecialchars(extractYear($templateVars['YEAR'] ?? $defaultAcademicYear, 'second')); ?>.docx">
                            </div>

                            <input type="hidden" name="month" id="form-month" value="<?php echo htmlspecialchars($templateVars['MONTH'] ?? ''); ?>">
                            <input type="hidden" name="year" id="form-year" value="<?php echo htmlspecialchars($templateVars['YEAR'] ?? $defaultAcademicYear); ?>">
                            <input type="hidden" name="action" value="generate_protocol">
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-download"></i> Сгенерировать протокол
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="content">
                <div class="card">
                    <div class="card-header" data-bs-toggle="collapse" data-bs-target="#protocolPreview">
                        <h3>
                            <i class="bi bi-eye"></i> Предпросмотр протокола
                            <span class="badge-counter"><?php echo count($studentCategories); ?></span>
                        </h3>
                        <span class="toggle-icon"><i class="bi bi-chevron-down"></i></span>
                    </div>
                    <div class="card-body collapse show" id="protocolPreview">
                        <div class="preview-container">
                            <div class="protocol-preview">
                                <div class="protocol-title">ПРОТОКОЛ № <span data-placeholder="PROTOCOL_NUMBER"><?php echo htmlspecialchars($templateVars['PROTOCOL_NUMBER'] ?? '{PROTOCOL_NUMBER}'); ?></span></div>
                                <div class="protocol-subtitle">Заседания стипендиальной комиссии</div>
                                <div class="protocol-subtitle"><span data-placeholder="SCHOOL"><?php echo htmlspecialchars($templateVars['SCHOOL'] ?? '{SCHOOL}'); ?></span></div>
                                <div class="protocol-subtitle"><span data-placeholder="UNIVERSITY"><?php echo htmlspecialchars($templateVars['UNIVERSITY'] ?? '{UNIVERSITY}'); ?></span></div>
                                
                                <div class="protocol-date"><span data-placeholder="DATE"><?php echo htmlspecialchars($templateVars['DATE'] ?? '«DAY» MONTH YEAR г. г. CITY'); ?></span></div>
                                
                                <div class="protocol-section">
                                    <div class="protocol-section-title">Председатель комиссии:</div>
                                    <div><span data-placeholder="CHAIRPERSON"><?php echo htmlspecialchars($templateVars['CHAIRPERSON'] ?? '{CHAIRPERSON}'); ?></span></div>
                                </div>
                                
                                <div class="protocol-section">
                                    <div class="protocol-section-title">Члены комиссии:</div>
                                    <div class="protocol-members"><span data-placeholder="MEMBERS"><?php echo nl2br(htmlspecialchars($templateVars['MEMBERS'] ?? '{MEMBERS}')); ?></span></div>
                                </div>
                                
                                <div class="protocol-section">
                                    <div class="protocol-section-title">Секретарь комиссии:</div>
                                    <div><span data-placeholder="SECRETARY"><?php echo htmlspecialchars($templateVars['SECRETARY'] ?? '{SECRETARY}'); ?></span></div>
                                </div>
                                
                                <div class="protocol-section">
                                    <div class="protocol-section-title">Повестка дня:</div>
                                    <div><span data-placeholder="AGENDA"><?php echo htmlspecialchars($templateVars['AGENDA'] ?? '{AGENDA}'); ?></span></div>
                                </div>
                                
                                <div class="protocol-section">
                                    <div class="protocol-section-title">Слушали:</div>
                                    <div><span data-placeholder="LISTENED"><?php echo htmlspecialchars($templateVars['LISTENED'] ?? '{LISTENED}'); ?></span></div>
                                </div>
                                
                                <div class="protocol-section">
                                    <div class="protocol-section-title">Студенты (РФ):</div>
                                    <table class="protocol-table rf-table">
                                        <thead>
                                            <tr>
                                                <th class="number">№</th>
                                                <th>ФИО</th>
                                                <th class="budget">Бюджет</th>
                                                <th class="group">Группа</th>
                                                <th>Основание</th>
                                                <th class="amount">Сумма</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $rfStudents = array_filter($studentCategories, fn($s) => strtoupper($s['budget'] ?? '-') !== 'ХМАО');
                                            if (empty($rfStudents)): ?>
                                                <tr>
                                                    <td class="number">1</td>
                                                    <td>Нет данных</td>
                                                    <td class="budget">-</td>
                                                    <td class="group">-</td>
                                                    <td>Нет студентов</td>
                                                    <td class="amount">0</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach (array_slice($rfStudents, 0, 30) as $index => $student): ?>
                                                    <tr>
                                                        <td class="number"><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                        <td class="budget"><?php echo htmlspecialchars($student['budget'] ?? '-'); ?></td>
                                                        <td class="group"><?php echo htmlspecialchars($student['group_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($student['category_short'] ?? 'Не указано'); ?></td>
                                                        <td class="amount"><?php echo htmlspecialchars($student['amount']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="protocol-section">
                                    <div class="protocol-section-title">Студенты (ХМАО):</div>
                                    <table class="protocol-table hmao-table">
                                        <thead>
                                            <tr>
                                                <th class="number">№</th>
                                                <th>ФИО</th>
                                                <th class="budget">Бюджет</th>
                                                <th class="group">Группа</th>
                                                <th>Основание</th>
                                                <th class="amount">Сумма</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $hmaoStudents = array_filter($studentCategories, fn($s) => strtoupper($s['budget'] ?? '-') === 'ХМАО');
                                            if (empty($hmaoStudents)): ?>
                                                <tr>
                                                    <td class="number">1</td>
                                                    <td>Нет данных</td>
                                                    <td class="budget">-</td>
                                                    <td class="group">-</td>
                                                    <td>Нет студентов</td>
                                                    <td class="amount">0</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach (array_slice($hmaoStudents, 0, 3) as $index => $student): ?>
                                                    <tr>
                                                        <td class="number"><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                        <td class="budget"><?php echo htmlspecialchars($student['budget'] ?? '-'); ?></td>
                                                        <td class="group"><?php echo htmlspecialchars($student['group_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($student['category_short'] ?? 'Не указано'); ?></td>
                                                        <td class="amount"><?php echo htmlspecialchars($student['amount']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="protocol-signature">
                                    <div>Руководитель инженерной школы цифровых технологий</div>
                                    <div>
                                        <span><?php echo htmlspecialchars($templateVars['CHAIR_DEGREE'] ?? '{CHAIR_DEGREE}'); ?></span>
                                        <span class="protocol-signature-line"></span>
                                        <span data-placeholder="SIGN_CHAIR"><?php echo htmlspecialchars($templateVars['SIGN_CHAIR'] ?? '{SIGN_CHAIR}'); ?></span>
                                    </div>
                                </div>
                                
                                <div class="protocol-signature">
                                    <div>Заместитель руководителя инженерной школы цифровых технологий по воспитательной работе</div>
                                    <div>
                                        <span><?php echo htmlspecialchars($templateVars['SECRETARY_DEGREE'] ?? '{SECRETARY_DEGREE}'); ?></span>
                                        <span class="protocol-signature-line"></span>
                                        <span data-placeholder="SIGN_SECRETARY"><?php echo htmlspecialchars($templateVars['SIGN_SECRETARY'] ?? '{SIGN_SECRETARY}'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            const monthMap = <?php echo json_encode($monthMap); ?>;
            const categories = <?php echo json_encode($categories_js); ?>;
            let studentsData = <?php echo json_encode($studentCategories); ?>;
            let selectedMonth = $('#MONTH').val() || '<?php echo htmlspecialchars($templateVars['MONTH'] ?? ''); ?>';
            let selectedYear = $('#YEAR').val() || '<?php echo htmlspecialchars($templateVars['YEAR'] ?? $defaultAcademicYear); ?>';

            // Initialize Bootstrap collapse with animation
            $('.card-header').click(function() {
                const target = $(this).data('bs-target');
                $(target).collapse('toggle');
            });

            function extractYear(academicYear, part = 'second') {
                if (academicYear.match(/^\d{4}\/\d{4}$/)) {
                    const years = academicYear.split('/');
                    return part === 'first' ? years[0] : years[1];
                }
                return academicYear;
            }

            function showNotification(message, type) {
                const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
                const $notification = $(`
                    <div class="notification ${type}">
                        <div class="notification-icon">
                            <i class="bi ${icon}"></i>
                        </div>
                        <div class="notification-content">${message}</div>
                        <button class="notification-close">&times;</button>
                    </div>
                `);
                
                $('.notification-container').append($notification);
                setTimeout(() => $notification.addClass('show'), 10);
                
                setTimeout(() => {
                    $notification.removeClass('show');
                    setTimeout(() => $notification.remove(), 300);
                }, 5000);
                
                $notification.find('.notification-close').click(() => {
                    $notification.removeClass('show');
                    setTimeout(() => $notification.remove(), 300);
                });
            }

            function updatePreview() {
                $('.template-input').each(function() {
                    const placeholder = $(this).data('placeholder');
                    const value = $(this).val();
                    if (placeholder === 'MEMBERS') {
                        $(`.protocol-preview [data-placeholder="${placeholder}"]`).html(value.replace(/\n/g, '<br>'));
                    } else {
                        $(`.protocol-preview [data-placeholder="${placeholder}"]`).text(value);
                    }
                });
                
                const day = $('#DAY').val().trim() || 'DAY';
                const month = $('#MONTH').val().trim() || 'MONTH';
                const year = $('#YEAR').val().trim() || 'YEAR';
                const city = $('#CITY').val().trim() || 'CITY';
                $(`.protocol-preview [data-placeholder="DATE"]`).text(`«${day}» ${month} ${year} г. г. ${city}`);

                const filterYear = extractYear(year, 'second');

                $.ajax({
                    url: '',
                    method: 'GET',
                    data: {
                        action: 'get_student_categories',
                        search: '',
                        month: month,
                        year: filterYear
                    },
                    dataType: 'json',
                    success: function(data) {
                        studentsData = data;
                        updateStudentTables(studentsData);
                        
                        // Update counter badge
                        $('.badge-counter').text(data.length);
                    },
                    error: function() {
                        showNotification('Ошибка загрузки студентов', 'error');
                    }
                });
            }

            function updateStudentTables(students) {
                const rfStudents = students.filter(s => (s.budget || '-').toUpperCase() !== 'ХМАО');
                const hmaoStudents = students.filter(s => (s.budget || '-').toUpperCase() === 'ХМАО');

                const $rfTableBody = $('.protocol-preview .rf-table tbody');
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
                        const reason = student.category_short || 'Не указано';
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

                const $hmaoTableBody = $('.protocol-preview .hmao-table tbody');
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
                        const reason = student.category_short || 'Не указано';
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
                const schoolCode = $(this).val();
                const schoolName = $(this).find('option:selected').text();
                $('#SCHOOL').val(schoolName);
                
                $.ajax({
                    url: '',
                    method: 'GET',
                    data: {
                        action: 'get_template_vars',
                        school_code: schoolCode,
                        academic_year: selectedYear
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success' && response.template_vars) {
                            for (const [placeholder, value] of Object.entries(response.template_vars)) {
                                const $input = $(`#${placeholder}`);
                                if ($input.length) {
                                    $input.val(value);
                                }
                            }
                            updatePreview();
                        }
                    },
                    error: function() {
                        showNotification('Ошибка загрузки данных шаблона', 'error');
                    }
                });
            });

            $('#YEAR').on('change', function() {
                selectedYear = $(this).val();
                const schoolCode = $('#SCHOOL_CODE').val();
                $('#form-year').val(selectedYear);
                updatePreview();
            });

            $('#MONTH').on('change', function() {
                selectedMonth = $(this).val();
                $('#form-month').val(selectedMonth);
                updatePreview();
            });

            $('#set-current-date').on('click', function() {
                const today = new Date();
                const day = today.getDate();
                const monthNames = <?php echo json_encode(array_keys($monthMap)); ?>;
                const month = monthNames[today.getMonth()];
                const year = today.getFullYear().toString();

                $('#DAY').val(day);
                $('#MONTH').val(month);
                $('#YEAR').val(year);

                selectedMonth = month;
                selectedYear = year;
                $('#form-month').val(month);
                $('#form-year').val(year);

                updatePreview();
            });

            function updateFilenameExtension() {
                const format = $('#format').val();
                const $filenameInput = $('#filename');
                let filename = $filenameInput.val().trim();
                const extension = format === 'word' ? '.docx' : '.pdf';
                filename = filename.replace(/\.(docx|pdf)$/i, '');
                const year = extractYear(selectedYear, 'second');
                $filenameInput.val(filename + extension);
                $('#filename').attr('placeholder', `protocol_7_${year}.${extension}`);
            }

            $('#format').on('change', updateFilenameExtension);

            $('#filename').on('input', function() {
                const format = $('#format').val();
                const extension = format === 'word' ? '.docx' : '.pdf';
                let value = $(this).val().trim();
                value = value.replace(/\.(docx|pdf)$/i, '').replace(/[^a-zA-Z0-9_\-\s]/g, '');
                const year = extractYear(selectedYear, 'second');
                if (value === '') {
                    value = `protocol_7_${year}`;
                }
                $(this).val(value + extension);
            });

            $('.ajax-form').on('submit', function(e) {
                e.preventDefault();
                const $form = $(this);
                const action = $form.find('input[name="action"]').val();
                const formData = $form.serializeArray();
                
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        showNotification(response.message, response.status);
                        
                        if (action === 'save_template' && response.template_vars) {
                            for (const [placeholder, value] of Object.entries(response.template_vars)) {
                                const $input = $(`#${placeholder}`);
                                if ($input.length) {
                                    $input.val(value);
                                }
                            }
                            updatePreview();
                        }
                        
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

            $('.template-input').on('change', function() {
                updatePreview();
            });

            $('.template-input').on('input change', function() {
                const placeholder = $(this).data('placeholder');
                const value = $(this).val();
                
                if (value.length > 1000) {
                    showNotification('Максимум 1000 символов', 'error');
                    $(this).val(value.substring(0, 1000));
                    return;
                }
                
                if (placeholder === 'MEMBERS') {
                    const lines = value.split('\n');
                    if (lines.length > 50) {
                        showNotification('Слишком много строк в поле "Члены комиссии"', 'error');
                        $(this).val(lines.slice(0, 50).join('\n'));
                        return;
                    }
                }
                
                updatePreview();
            });

            // Initialize with current values
            updateFilenameExtension();
            updatePreview();
            
            <?php foreach (getFlashMessages() as $flash): ?>
                showNotification('<?php echo addslashes($flash['message']); ?>', '<?php echo $flash['type']; ?>');
            <?php endforeach; ?>
        });
    </script>
</body>
</html>