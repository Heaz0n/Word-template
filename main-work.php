
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
        
        // Ensure all required placeholders exist
        $requiredPlaceholders = [
            'UNIVERSITY', 'SCHOOL', 'SCHOOL_CODE', 'PROTOCOL_NUMBER', 'DATE', 'DAY', 
            'MONTH', 'YEAR', 'CITY', 'CHAIRPERSON', 'CHAIR_DEGREE', 'MEMBERS', 
            'SECRETARY', 'SECRETARY_DEGREE', 'AGENDA', 'LISTENED', 'DECISION', 
            'SIGN_CHAIR', 'SIGN_SECRETARY'
        ];
        
        foreach ($requiredPlaceholders as $placeholder) {
            if (!isset($vars[$placeholder])) {
                $vars[$placeholder] = '';
            }
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

function getAllStudents($pdo, $search = '') {
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

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ошибка в getAllStudents: " . $e->getMessage());
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
                    if ($placeholder === 'MEMBERS' || $placeholder === 'LISTENED' || $placeholder === 'DECISION') {
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

            if (isset($_POST['template']['DAY'], $_POST['template']['MONTH'], $_POST['template']['YEAR'])) {
                $day = trim($_POST['template']['DAY']);
                $month = trim($_POST['template']['MONTH']);
                $year = trim($_POST['template']['YEAR']);
                if ($day && $month && $year) {
                    $date = "«{$day}» {$month} {$year} г. г. {$templateVars['CITY']}";
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

 $run = $section->addTextRun(['spaceAfter' => 240, 'alignment' => 'both']);
$run->addText("«{$templateVars['DAY']}» {$templateVars['MONTH']} {$templateVars['YEAR']} г.", ['size' => 12]);
$run->addText("\tг. {$templateVars['CITY']}", ['size' => 12]);
            $section->addTextBreak(1, ['size' => 12], ['spaceAfter' => 240]);

            foreach ([
                ['Председатель комиссии:', $templateVars['CHAIRPERSON']],
                ['Члены комиссии:', $templateVars['MEMBERS'], true],
                ['Секретарь комиссии:', $templateVars['SECRETARY']],
                ['Повестка дня:', $templateVars['AGENDA']],
                ['Слушали:', $templateVars['LISTENED']],
                ['Решили:', "Оказать материальную поддержку следующим нуждающимся студентам:\n" . $templateVars['DECISION']]
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
            $templateVars['DECISION'] = "Оказать материальную поддержку следующим нуждающимся студентам:\n" . $templateVars['DECISION'];

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
    'add_student_category' => function() use ($pdo, $defaultAcademicYear) {
        $student_id = $_POST['student_id'] ?? null;
        $category_id = $_POST['category_id'] ?? null;
        $academic_year = $_POST['academic_year'] ?? $defaultAcademicYear;

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
            $stmt = $pdo->prepare("UPDATE Students SET full_name = ?, budget = ? WHERE id = ?");
            $stmt->execute([$full_name, $budget, $student_id]);

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
    'get_students' => function() use ($pdo) {
        $search = $_GET['search'] ?? '';
        $students = getAllStudents($pdo, $search);
        header('Content-Type: solvapplication/json');
        echo json_encode($students);
        exit;
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
            line-height: 1;
            text-align: justify;
        }

        .preview h1, .preview h2 {
            font-size: 14pt;
            font-weight: bold;
            text-align: center;
            margin: 0;
            padding: 0.5em 0;
            color: #000000;
            line-height: 1;
        }

        .preview p {
            margin: 0;
            padding: 0.5em 0;
            line-height: 1;
            text-align: justify;
        }

        .preview .section-title {
            font-weight: bold;
            padding-top: 1em;
            padding-bottom: 0.2em;
            color: #000000;
            line-height: 1;
        }

        .preview .members {
            white-space: pre-wrap;
            padding-bottom: 0.5em;
            line-height: 1;
        }

.preview .date-city {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 1em 0;
    font-size: 12pt;
    line-height: 1;
}
.preview .date-city .date-part {
    flex: 0 0 auto;
}
.preview .date-city .city-part {
    flex: 0 0 auto;
}

        .preview table {
            width: 100%;
            border-collapse: collapse;
            margin: 1em 0;
            border: 1px solid #000000;
            font-size: 11pt;
            line-height: 1;
        }

        .preview th, .preview td {
            border: 1px solid #000000;
            padding: 8px;
            vertical-align: middle;
            line-height: 1;
        }

        .preview th {
            background: #ECEFF1;
            font-weight: bold;
            text-align: center;
        }

        .preview td.number, .preview td.amount, .preview td.budget, .preview td.group {
            text-align: center;
        }

        .preview .signatures {
            padding-top: 2em;
            line-height: 1;
        }

        .preview .signature {
            padding-bottom: 1.5em;
            display: flex;
            align-items: baseline;
            gap: 10px;
            line-height: 1;
        }

        .preview .signature-line {
            display: inline-block;
            width: 40mm;
            border-bottom: 1px solid #000000;
            vertical-align: middle;
            margin: 0 5px;
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
            const categories = <?php echo json_encode($categories_js); ?>;
            let studentsData = <?php echo json_encode($studentCategories); ?>;
            let selectedMonth = $('#MONTH').val() || '<?php echo htmlspecialchars($templateVars['MONTH'] ?? ''); ?>';
            let selectedYear = $('#YEAR').val() || '<?php echo htmlspecialchars($templateVars['YEAR'] ?? $defaultAcademicYear); ?>';
            const defaultCategoryId = <?php echo json_encode($categories[0]['id'] ?? 1); ?>;
            const defaultAcademicYear = <?php echo json_encode($defaultAcademicYear); ?>;

            $('.card-header').click(function() {
                $(this).closest('.card').toggleClass('collapsed');
            });

            function extractYear(academicYear, part = 'second') {
                if (academicYear.match(/^\d{4}\/\d{4}$/)) {
                    const years = academicYear.split('/');
                    return part === 'first' ? years[0] : years[1];
                }
                return academicYear;
            }

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
                    let value = $(this).val();
                    if (placeholder === 'DECISION') {
                        value = "Оказать материальную поддержку следующим нуждающимся студентам:\n" + value;
                    }
                    if (placeholder === 'MEMBERS' || placeholder === 'DECISION') {
                        $(`.preview [data-placeholder="${placeholder}"]`).html(value.replace(/\n/g, '<br>'));
                    } else {
                        $(`.preview [data-placeholder="${placeholder}"]`).text(value);
                    }
                });
                const day = $('#DAY').val().trim() || 'DAY';
                const month = $('#MONTH').val().trim() || 'MONTH';
                const year = $('#YEAR').val().trim() || 'YEAR';
                const city = $('#CITY').val().trim() || 'CITY';
                $(`.preview [data-placeholder="DATE"]`).html(`<span class="date-day">«${day}»</span> ${month} ${year} г. г. ${city}`);

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
                    },
                    error: function() {
                        showNotification('Ошибка загрузки студентов', 'error');
                    }
                });
            }

            function syncTemplateVars(vars) {
                for (const [placeholder, value] of Object.entries(vars)) {
                    const $input = $(`#${placeholder}`);
                    if ($input.length) {
                        $input.val(value);
                    }
                    if (placeholder === 'MEMBERS' || placeholder === 'DECISION') {
                        let displayValue = value;
                        if (placeholder === 'DECISION') {
                            displayValue = "Оказать материальную поддержку следующим нуждающимся студентам:\n" + value;
                        }
                        $(`.preview [data-placeholder="${placeholder}"]`).html(displayValue.replace(/\n/g, '<br>'));
                    } else {
                        $(`.preview [data-placeholder="${placeholder}"]`).text(value);
                    }
                }
                updatePreview();
            }

            function loadTemplateVars(schoolCode, academicYear) {
                $.ajax({
                    url: '',
                    method: 'GET',
                    data: {
                        action: 'get_template_vars',
                        school_code: schoolCode,
                        academic_year: academicYear
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success' && response.template_vars) {
                            syncTemplateVars(response.template_vars);
                        } else {
                            showNotification('Ошибка загрузки данных шаблона', 'error');
                        }
                    },
                    error: function() {
                        showNotification('Ошибка загрузки данных шаблона', 'error');
                    }
                });
            }

            function updateStudentTables(students) {
                const rfStudents = students.filter(s => (s.budget || '-').toUpperCase() !== 'ХМАО');
                const hmaoStudents = students.filter(s => (s.budget || '-').toUpperCase() === 'ХМАО');

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
                loadTemplateVars(schoolCode, selectedYear);
            });

            $('#YEAR').on('change', function() {
                selectedYear = $(this).val();
                const schoolCode = $('#SCHOOL_CODE').val();
                $('#form-year').val(selectedYear);
                loadTemplateVars(schoolCode, selectedYear);
            });

            $('#MONTH').on('change', function() {
                selectedMonth = $(this).val();
                $('#form-month').val(selectedMonth);
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

            $('#format').on('change', function() {
                updateFilenameExtension();
            });

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

            updateFilenameExtension();

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
                            syncTemplateVars(response.template_vars);
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
                $('#template-form').submit();
            });

            $('.template-input').on('input change', function() {
                const placeholder = $(this).data('placeholder');
                const value = $(this).val();
                if (value.length > 1000) {
                    showNotification('Максимум 1000 символов', 'error');
                    $(this).val(value.substring(0, 1000));
                }
                if (placeholder === 'MEMBERS' || placeholder === 'DECISION') {
                    const lines = value.split('\n');
                    if (lines.length > 50) {
                        showNotification(`Слишком много строк в поле "${placeholder === 'MEMBERS' ? 'Члены комиссии' : 'Решили'}"`, 'error');
                        $(this).val(lines.slice(0, 50).join('\n'));
                    }
                }
                updatePreview();
            });

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
                            $.get('', { action: 'get_student_categories', month: selectedMonth, year: extractYear(selectedYear, 'second') }, function(data) {
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

            updatePreview();
        });
    </script>
</head>
<body>
    <div class="notification-container"></div>
    <div class="container">
        <?php foreach (getFlashMessages() as $flash): ?>
            <div class="notification <?php echo $flash['type']; ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
        <?php endforeach; ?>

        <h2>Система документооборота</h2>

        <div class="main-row">
            <div class="sidebar">
                <div class="card">
                    <div class="card-header"><h4><span class="toggle-icon"></span>Редактирование шаблона</h4></div>
                    <div class="card-body">
                        <form method="POST" class="ajax-form" id="template-form">
                            <?php
                            $fields = [
                                'UNIVERSITY' => 'ВУЗ',
                                'SCHOOL_CODE' => 'Школа',
                                'PROTOCOL_NUMBER' => 'Номер протокола',
                                'CITY' => 'Город',
                                'CHAIRPERSON' => 'Председатель',
                                'CHAIR_DEGREE' => 'Ученая степень председателя',
                                'MEMBERS' => 'Члены комиссии',
                                'SECRETARY' => 'Секретарь',
                                'SECRETARY_DEGREE' => 'Ученая степень секретаря',
                                'AGENDA' => 'Повестка дня',
                                'LISTENED' => 'Слушали',
                                'DECISION' => 'Решили',
                                'SIGN_CHAIR' => 'Подпись председателя',
                                'SIGN_SECRETARY' => 'Подпись секретаря'
                            ];
                            ?>
                            <?php foreach ($fields as $placeholder => $label): ?>
                                <?php if ($placeholder === 'CITY'): ?>
                                    <div class="mb-2">
                                        <label class="form-label">Дата</label>
                                        <div class="row g-2">
                                            <div class="col-4">
                                                <input type="number" id="DAY" name="template[DAY]" class="form-control template-input" data-placeholder="DAY" placeholder="День" min="1" max="31" value="<?php echo htmlspecialchars($templateVars['DAY'] ?? ''); ?>">
                                            </div>
                                            <div class="col-4">
                                                <select id="MONTH" name="template[MONTH]" class="form-select template-input" data-placeholder="MONTH">
                                                    <option value="">Выберите месяц</option>
                                                    <?php foreach ($monthMap as $monthName => $monthNum): ?>
                                                        <option value="<?php echo $monthName; ?>" <?php echo ($templateVars['MONTH'] ?? '') == $monthName ? 'selected' : ''; ?>>
                                                            <?php echo $monthName; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-4">
                                                <select id="YEAR" name="template[YEAR]" class="form-select template-input" data-placeholder="YEAR">
                                                    <option value="">Выберите год</option>
                                                    <?php foreach ($years as $year): ?>
                                                        <option value="<?php echo $year; ?>" <?php echo ($templateVars['YEAR'] ?? $defaultAcademicYear) == $year ? 'selected' : ''; ?>>
                                                            <?php echo $year; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
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
                                    <?php elseif ($placeholder === 'MEMBERS' || $placeholder === 'LISTENED' || $placeholder === 'DECISION'): ?>
                                        <textarea id="<?php echo $placeholder; ?>" name="template[<?php echo $placeholder; ?>]" class="form-control template-input" data-placeholder="<?php echo $placeholder; ?>" rows="4"><?php echo htmlspecialchars($templateVars[$placeholder] ?? ''); ?></textarea>
                                    <?php else: ?>
                                        <input type="text" id="<?php echo $placeholder; ?>" name="template[<?php echo $placeholder; ?>]" class="form-control template-input" data-placeholder="<?php echo $placeholder; ?>" value="<?php echo htmlspecialchars($templateVars[$placeholder] ?? ''); ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <input type="hidden" name="action" value="save_template">
                            <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h4><span class="toggle-icon"></span>Генерация протокола</h4></div>
                    <div class="card-body">
                        <form method="POST" class="ajax-form" id="generate-protocol-form">
                            <div class="mb-2">
                                <label for="format" class="form-label">Формат</label>
                                <select id="format" name="format" class="form-select">
                                    <option value="word">Word (.docx)</option>
                                    <option value="pdf">PDF</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label for="filename" class="form-label">Имя файла</label>
                                <input type="text" id="filename" name="filename" class="form-control" placeholder="protocol_7_<?php echo htmlspecialchars(extractYear($templateVars['YEAR'] ?? $defaultAcademicYear, 'second')); ?>.docx" value="protocol_7_<?php echo htmlspecialchars(extractYear($templateVars['YEAR'] ?? $defaultAcademicYear, 'second')); ?>.docx">
                            </div>
                            <input type="hidden" name="month" id="form-month" value="<?php echo htmlspecialchars($templateVars['MONTH'] ?? ''); ?>">
                            <input type="hidden" name="year" id="form-year" value="<?php echo htmlspecialchars($templateVars['YEAR'] ?? $defaultAcademicYear); ?>">
                            <input type="hidden" name="action" value="generate_protocol">
                            <button type="submit" class="btn btn-primary btn-sm">Сгенерировать</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h4><span class="toggle-icon"></span>Добавить студента</h4></div>
                    <div class="card-body">
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#selectStudentsModal">
                            Выбрать студента
                        </button>
                    </div>
                </div>
            </div>

            <div class="content">
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
                                    <span class="date-part"><span class="date-day">«<?php echo htmlspecialchars($templateVars['DAY'] ?? 'DAY'); ?>»</span> <?php echo htmlspecialchars($templateVars['MONTH'] ?? 'MONTH'); ?> <?php echo htmlspecialchars($templateVars['YEAR'] ?? 'YEAR'); ?> г.</span>
                                    <span class="city-part"><?php echo htmlspecialchars($templateVars['CITY'] ?? '{CITY}'); ?></span>
                                </div>
                                <p class="section-title">Председатель комиссии:</p>
                                <p><span data-placeholder="CHAIRPERSON"><?php echo htmlspecialchars($templateVars['CHAIRPERSON'] ?? '{CHAIRPERSON}'); ?></span></p>
                                <p class="section-title">Члены комиссии:</p>
                                <p class="members"><span data-placeholder="MEMBERS"><?php echo nl2br(htmlspecialchars($templateVars['MEMBERS'] ?? '{MEMBERS}')); ?></span></p>
                                <p class="section-title">Секретарь комиссии:</p>
                                <p><span data-placeholder="SECRETARY"><?php echo htmlspecialchars($templateVars['SECRETARY'] ?? '{SECRETARY}'); ?></span></p>
                                <p class="section-title">Повестка дня:</p>
                                <p><span data-placeholder="AGENDA"><?php echo htmlspecialchars($templateVars['AGENDA'] ?? '{AGENDA}'); ?></span></p>
                                <p class="section-title">Слушали:</p>
                                <p><span data-placeholder="LISTENED"><?php echo htmlspecialchars($templateVars['LISTENED'] ?? '{LISTENED}'); ?></span></p>
                                <p class="section-title">Решили:</p>
                                <p><span data-placeholder="DECISION"><?php echo nl2br(htmlspecialchars("Оказать материальную поддержку следующим нуждающимся студентам:\n" . ($templateVars['DECISION'] ?? '{DECISION}'))); ?></span></p>

                                <p class="section-title">Студенты (РФ):</p>
                                <table class="rf-table">
                                    <thead>
                                        <tr>
                                            <th>№</th>
                                            <th>ФИО</th>
                                            <th>Бюджет</th>
                                            <th>Группа</th>
                                            <th>Основание</th>
                                            <th>Сумма</th>
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

                                <p class="section-title">Студенты (ХМАО):</p>
                                <table class="hmao-table">
                                    <thead>
                                        <tr>
                                            <th>№</th>
                                            <th>ФИО</th>
                                            <th>Бюджет</th>
                                            <th>Группа</th>
                                            <th>Основание</th>
                                            <th>Сумма</th>
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

                                <div class="signatures">
                                    <p>Руководитель инженерной школы цифровых технологий</p>
                                    <div class="signature">
                                        <span><?php echo htmlspecialchars($templateVars['CHAIR_DEGREE'] ?? '{CHAIR_DEGREE}'); ?></span>
                                        <span class="signature-line"></span>
                                        <span data-placeholder="SIGN_CHAIR"><?php echo htmlspecialchars($templateVars['SIGN_CHAIR'] ?? '{SIGN_CHAIR}'); ?></span>
                                    </div>
                                    <p>Заместитель руководителя инженерной школы цифровых технологий по воспитательной работе</p>
                                    <div class="signature">
                                        <span><?php echo htmlspecialchars($templateVars['SECRETARY_DEGREE'] ?? '{SECRETARY_DEGREE}'); ?></span>
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
        <div class="modal fade no-backdrop" id="selectStudentsModal" tabindex="-1" aria-labelledby="selectStudentsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="selectStudentsModalLabel">Выбор студента</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="student-search" class="form-label">Поиск студента</label>
                            <input type="text" id="student-search" class="form-control" placeholder="Введите ФИО">
                        </div>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ФИО</th>
                                    <th>Группа</th>
                                    <th>Бюджет</th>
                                </tr>
                            </thead>
                            <tbody id="student-selection-table">
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Закрыть</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
