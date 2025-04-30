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

// Подключение PhpSpreadsheet
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// Временная метка последнего обновления
$lastUpdateTimestamp = $_SESSION['last_update_timestamp'] ?? 0;

// Функция для обработки действий с группами
function handleGroupAction($pdo, $action, $data) {
    $group_name = trim($data['group_name'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if ($action === 'add') {
        $direction_id = trim($data['direction_id'] ?? '');
        if (empty($group_name)) {
            return ['type' => 'error', 'message' => 'Наименование группы обязательно'];
        }

        // Проверка существования направления
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Directions WHERE code = ?");
        $stmt->execute([$direction_id]);
        if ($stmt->fetchColumn() == 0) {
            return ['type' => 'error', 'message' => 'Выбранное направление не существует'];
        }

        // Проверка на уникальность имени группы в пределах направления
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `Groups` WHERE direction_id = ? AND group_name = ?");
        $stmt->execute([$direction_id, $group_name]);
        if ($stmt->fetchColumn() > 0) {
            return ['type' => 'error', 'message' => "Группа с названием '$group_name' уже существует в этом направлении"];
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO `Groups` (direction_id, group_name, notes) VALUES (?, ?, ?)");
            $stmt->execute([$direction_id, $group_name, $notes]);
            $newGroupId = $pdo->lastInsertId();

            // Получение данных новой группы для ответа
            $stmt = $pdo->prepare("
                SELECT g.id, g.direction_id, g.group_name, g.notes, 
                       d.code AS direction_code, d.direction_name, d.vsh_code,
                       UNIX_TIMESTAMP(g.updated_at) AS updated_at
                FROM `Groups` g
                LEFT JOIN Directions d ON g.direction_id = d.code
                WHERE g.id = ?
            ");
            $stmt->execute([$newGroupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'type' => 'success',
                'message' => "Группа '$group_name' успешно добавлена",
                'group' => $group
            ];
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                return ['type' => 'error', 'message' => "Группа с названием '$group_name' уже существует"];
            }
            return ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    } elseif ($action === 'edit') {
        $id = $data['id'] ?? '';
        if (empty($id) || empty($group_name)) {
            return ['type' => 'error', 'message' => 'ID и наименование группы обязательны'];
        }

        // Получение текущего direction_id для группы
        $stmt = $pdo->prepare("SELECT direction_id FROM `Groups` WHERE id = ?");
        $stmt->execute([$id]);
        $current_group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current_group) {
            return ['type' => 'error', 'message' => 'Группа не найдена'];
        }
        $direction_id = $current_group['direction_id'];

        // Проверка на уникальность имени группы в пределах направления
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `Groups` WHERE direction_id = ? AND group_name = ? AND id != ?");
        $stmt->execute([$direction_id, $group_name, $id]);
        if ($stmt->fetchColumn() > 0) {
            return ['type' => 'error', 'message' => "Группа с названием '$group_name' уже существует в этом направлении"];
        }

        try {
            $stmt = $pdo->prepare("UPDATE `Groups` SET group_name = ?, notes = ? WHERE id = ?");
            $stmt->execute([$group_name, $notes, $id]);
            return ['type' => 'success', 'message' => "Группа '$group_name' успешно обновлена"];
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                return ['type' => 'error', 'message' => "Группа с названием '$group_name' уже существует"];
            }
            return ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    } elseif ($action === 'delete') {
        $id = $data['id'] ?? '';
        if (empty($id)) {
            return ['type' => 'error', 'message' => 'ID группы обязателен'];
        }

        try {
            // Проверка существования группы
            $stmt = $pdo->prepare("SELECT group_name FROM `Groups` WHERE id = ?");
            $stmt->execute([$id]);
            $groupName = $stmt->fetchColumn();
            if (!$groupName) {
                return ['type' => 'error', 'message' => 'Группа не найдена'];
            }

            // Проверка наличия студентов в группе
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Students WHERE group_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                return ['type' => 'error', 'message' => 'Нельзя удалить группу, в которой есть студенты'];
            }

            $stmt = $pdo->prepare("DELETE FROM `Groups` WHERE id = ?");
            $stmt->execute([$id]);
            return ['type' => 'success', 'message' => "Группа '$groupName' успешно удалена"];
        } catch (PDOException $e) {
            return ['type' => 'error', 'message' => 'Ошибка при удалении группы: ' . $e->getMessage()];
        }
    }
    return ['type' => 'error', 'message' => 'Неизвестное действие'];
}

// Функция для обработки действий со студентами
function handleStudentAction($pdo, $action, $data) {
    $full_name = trim($data['full_name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $telegram = trim($data['telegram'] ?? '');
    $group_id = trim($data['group_id'] ?? '');

    // Если group_id не передан, но есть selected_group_id в сессии, используем его
    if (empty($group_id) && !empty($_SESSION['selected_group_id']) && ($action === 'add' || $action === 'upload')) {
        $group_id = $_SESSION['selected_group_id'];
    }

    if ($action !== 'delete' && $action !== 'delete_all' && $action !== 'upload') {
        // Проверка существования группы, если group_id указан
        if (!empty($group_id)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `Groups` WHERE id = ?");
            $stmt->execute([$group_id]);
            if ($stmt->fetchColumn() == 0) {
                return ['type' => 'error', 'message' => 'Выбранная группа не существует'];
            }
        }

        // Проверка на уникальность ФИО в пределах группы, если group_id и full_name указаны
        if (!empty($group_id) && !empty($full_name)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Students WHERE group_id = ? AND full_name = ?");
            if ($action === 'edit') {
                $id = $data['id'] ?? '';
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM Students WHERE group_id = ? AND full_name = ? AND id != ?");
                $stmt->execute([$group_id, $full_name, $id]);
            } else {
                $stmt->execute([$group_id, $full_name]);
            }
            if ($stmt->fetchColumn() > 0) {
                return ['type' => 'error', 'message' => "Студент с ФИО '$full_name' уже существует в этой группе"];
            }
        }
    }

    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO Students (group_id, full_name, phone, telegram) VALUES (?, ?, ?, ?)");
            $stmt->execute([$group_id ?: null, $full_name ?: null, $phone ?: null, $telegram ?: null]);
            $newStudentId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                SELECT id, group_id, full_name, phone, telegram, 
                       UNIX_TIMESTAMP(updated_at) AS updated_at
                FROM Students 
                WHERE id = ?
            ");
            $stmt->execute([$newStudentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'type' => 'success',
                'message' => "Студент успешно добавлен",
                'group_id' => $group_id,
                'student' => $student
            ];
        } elseif ($action === 'edit') {
            $id = $data['id'] ?? '';
            if (empty($id)) {
                return ['type' => 'error', 'message' => 'ID студента обязателен'];
            }

            // Получение текущей группы студента
            $stmt = $pdo->prepare("SELECT group_id, full_name FROM Students WHERE id = ?");
            $stmt->execute([$id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                return ['type' => 'error', 'message' => 'Студент не найден'];
            }
            $group_id = $student['group_id'];

            $stmt = $pdo->prepare("UPDATE Students SET full_name = ?, phone = ?, telegram = ? WHERE id = ?");
            $stmt->execute([$full_name ?: null, $phone ?: null, $telegram ?: null, $id]);

            // Получение обновленных данных студента
            $stmt = $pdo->prepare("
                SELECT id, group_id, full_name, phone, telegram, 
                       UNIX_TIMESTAMP(updated_at) AS updated_at
                FROM Students 
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $updatedStudent = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'type' => 'success',
                'message' => "Студент успешно обновлен",
                'group_id' => $group_id,
                'student' => $updatedStudent
            ];
        } elseif ($action === 'delete') {
            $id = $data['id'] ?? 0;
            $group_id = $data['group_id'] ?? 0;

            if ($id <= 0 || $group_id <= 0) {
                return ['type' => 'error', 'message' => 'Недействительный ID студента или группы'];
            }

            // Проверка существования студента
            $stmt = $pdo->prepare("SELECT full_name FROM Students WHERE id = ? AND group_id = ?");
            $stmt->execute([$id, $group_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                return ['type' => 'error', 'message' => 'Студент не найден'];
            }

            $stmt = $pdo->prepare("DELETE FROM Students WHERE id = ? AND group_id = ?");
            $stmt->execute([$id, $group_id]);
            return [
                'type' => 'success',
                'message' => "Студент успешно удален",
                'group_id' => $group_id
            ];
        } elseif ($action === 'delete_all') {
            $group_id = $data['group_id'] ?? 0;

            if ($group_id <= 0) {
                return ['type' => 'error', 'message' => 'Недействительный ID группы'];
            }

            // Проверка существования группы
            $stmt = $pdo->prepare("SELECT group_name FROM `Groups` WHERE id = ?");
            $stmt->execute([$group_id]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                return ['type' => 'error', 'message' => 'Группа не найдена'];
            }

            // Проверка наличия студентов в группе
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Students WHERE group_id = ?");
            $stmt->execute([$group_id]);
            if ($stmt->fetchColumn() == 0) {
                return ['type' => 'error', 'message' => 'В группе нет студентов для удаления'];
            }

            $stmt = $pdo->prepare("DELETE FROM Students WHERE group_id = ?");
            $stmt->execute([$group_id]);
            return [
                'type' => 'success',
                'message' => "Все студенты группы '{$group['group_name']}' успешно удалены",
                'group_id' => $group_id
            ];
        } elseif ($action === 'upload') {
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] != UPLOAD_ERR_OK) {
                return ['type' => 'error', 'message' => 'Ошибка загрузки файла'];
            }

            if (empty($group_id)) {
                return ['type' => 'error', 'message' => 'Не выбрана группа для добавления студентов'];
            }

            // Проверка существования группы
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `Groups` WHERE id = ?");
            $stmt->execute([$group_id]);
            if ($stmt->fetchColumn() == 0) {
                return ['type' => 'error', 'message' => 'Выбранная группа не существует'];
            }

            $file = $_FILES['excel_file']['tmp_name'];
            $fileType = $_FILES['excel_file']['type'];
            $allowedTypes = [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];

            if (!in_array($fileType, $allowedTypes)) {
                return ['type' => 'error', 'message' => 'Недопустимый формат файла. Поддерживаются только .xls и .xlsx'];
            }

            try {
                $spreadsheet = IOFactory::load($file);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                // Поиск столбца с заголовком "ФИО"
                $fioColumn = null;
                $headerRow = 1; // Предполагаем, что заголовки в первой строке
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $headerRow;
                    $header = trim($worksheet->getCell($cellCoordinate)->getValue());
                    if ($header === 'ФИО') {
                        $fioColumn = $col;
                        break;
                    }
                }

                if ($fioColumn === null) {
                    return ['type' => 'error', 'message' => 'Столбец с заголовком "ФИО" не найден'];
                }

                $addedStudents = [];
                $errors = [];
                $existingNames = [];

                // Получение существующих ФИО в группе
                $stmt = $pdo->prepare("SELECT full_name FROM Students WHERE group_id = ?");
                $stmt->execute([$group_id]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $existingNames[] = $row['full_name'];
                }

                // Чтение данных начиная со второй строки
                for ($row = 2; $row <= $highestRow; $row++) {
                    $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($fioColumn) . $row;
                    $full_name = trim($worksheet->getCell($cellCoordinate)->getValue());
                    if (empty($full_name)) {
                        continue; // Пропускаем пустые строки
                    }

                    // Проверка на дубликат в файле
                    if (in_array($full_name, $addedStudents)) {
                        $errors[] = "Студент '$full_name' уже добавлен из файла";
                        continue;
                    }

                    // Проверка на существование в базе
                    if (in_array($full_name, $existingNames)) {
                        $errors[] = "Студент '$full_name' уже существует в группе";
                        continue;
                    }

                    // Добавление студента
                    $stmt = $pdo->prepare("INSERT INTO Students (group_id, full_name, phone, telegram) VALUES (?, ?, NULL, NULL)");
                    $stmt->execute([$group_id, $full_name]);

                    $addedStudents[] = $full_name;
                    $existingNames[] = $full_name; // Добавляем в список для проверки дубликатов
                }

                $message = count($addedStudents) > 0
                    ? "Успешно добавлено " . count($addedStudents) . " студентов"
                    : "Ни один студент не был добавлен";

                if (!empty($errors)) {
                    $message .= ". Ошибки: " . implode("; ", $errors);
                }

                return [
                    'type' => count($addedStudents) > 0 ? 'success' : 'error',
                    'message' => $message,
                    'group_id' => $group_id
                ];
            } catch (Exception $e) {
                return ['type' => 'error', 'message' => 'Ошибка обработки файла: ' . $e->getMessage()];
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            return ['type' => 'error', 'message' => 'Ошибка: нарушение ограничений базы данных'];
        }
        return ['type' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()];
    }
    return ['type' => 'error', 'message' => 'Неизвестное действие'];
}

// Обработка AJAX-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['type' => 'error', 'message' => 'Недействительный CSRF-токен']);
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'check_updates') {
        try {
            // Получение всех групп
            $stmt = $pdo->query("
                SELECT g.id, g.direction_id, g.group_name, g.notes, 
                       d.code AS direction_code, d.direction_name, d.vsh_code, 
                       s.code AS school_code, s.name AS school_name,
                       UNIX_TIMESTAMP(g.updated_at) AS updated_at
                FROM `Groups` g
                LEFT JOIN Directions d ON g.direction_id = d.code
                LEFT JOIN Schools s ON d.vsh_code = s.code
                ORDER BY g.id
            ");
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Получение студентов
            $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : null;
            $students = [];
            if ($group_id) {
                $stmt = $pdo->prepare("
                    SELECT id, group_id, full_name, phone, telegram, 
                           UNIX_TIMESTAMP(updated_at) AS updated_at
                    FROM Students 
                    WHERE group_id = ?
                    ORDER BY full_name
                ");
                $stmt->execute([$group_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Обновление временной метки
            $currentTimestamp = time();
            $_SESSION['last_update_timestamp'] = $currentTimestamp;

            echo json_encode([
                'type' => 'success',
                'groups' => $groups,
                'students' => $students,
                'timestamp' => $currentTimestamp
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['type' => 'error', 'message' => 'Ошибка при проверке обновлений: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'get_directions') {
        $school_code = isset($_POST['school_code']) ? (int)$_POST['school_code'] : null;
        try {
            $sql = "SELECT code, direction_name, vsh_code FROM Directions";
            $params = [];
            if ($school_code) {
                $sql .= " WHERE vsh_code = ?";
                $params[] = $school_code;
            }
            $sql .= " ORDER BY code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $directions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($directions);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['type' => 'error', 'message' => 'Ошибка при получении направлений: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'lock_direction') {
        if (isset($_POST['direction_id'])) {
            $_SESSION['locked_direction_id'] = $_POST['direction_id'];
            echo json_encode(['type' => 'success']);
            exit;
        } else {
            echo json_encode(['type' => 'error', 'message' => 'Направление не указано']);
            exit;
        }
    } elseif ($action === 'get_students') {
        $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        try {
            if ($group_id) {
                $stmt = $pdo->prepare("SELECT id, group_id, full_name, phone, telegram FROM Students WHERE group_id = ? ORDER BY full_name");
                $stmt->execute([$group_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($students);
            } else {
                echo json_encode([]);
            }
            exit;
        } catch (PDOException $e) {
            echo json_encode(['type' => 'error', 'message' => 'Ошибка при получении студентов: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'get_groups') {
        try {
            $stmt = $pdo->query("SELECT id, group_name FROM `Groups` ORDER BY group_name");
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($groups);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['type' => 'error', 'message' => 'Ошибка при получении групп: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'add_group') {
        $result = handleGroupAction($pdo, 'add', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'edit_group') {
        $result = handleGroupAction($pdo, 'edit', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'delete_group') {
        $result = handleGroupAction($pdo, 'delete', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'add_student') {
        $result = handleStudentAction($pdo, 'add', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'edit_student') {
        $result = handleStudentAction($pdo, 'edit', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'delete_student') {
        $result = handleStudentAction($pdo, 'delete', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'delete_all_students') {
        $result = handleStudentAction($pdo, 'delete_all', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'upload_students') {
        $result = handleStudentAction($pdo, 'upload', $_POST);
        echo json_encode($result);
        exit;
    } elseif ($action === 'select_group') {
        if (isset($_POST['group_id']) && $_POST['group_id'] !== '') {
            $_SESSION['selected_group_id'] = (int)$_POST['group_id'];
            echo json_encode(['type' => 'success']);
        } else {
            unset($_SESSION['selected_group_id']);
            echo json_encode(['type' => 'success']);
        }
        exit;
    }

    echo json_encode(['type' => 'error', 'message' => 'Неизвестное действие']);
    exit;
}

// Получение данных групп
try {
    $query = $pdo->query("
        SELECT g.id, g.direction_id, g.group_name, g.notes, 
               d.code AS direction_code, d.direction_name, d.vsh_code, 
               s.code AS school_code, s.name AS school_name
        FROM `Groups` g
        LEFT JOIN Directions d ON g.direction_id = d.code
        LEFT JOIN Schools s ON d.vsh_code = s.code
        ORDER BY g.id
    ");
    $groups = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка выполнения запроса: ' . $e->getMessage()];
    header('Location: main-group.php');
    exit;
}

// Получение списка школ
try {
    $schools_query = $pdo->query("SELECT code, name FROM Schools ORDER BY code");
    $schools = $schools_query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка получения школ: ' . $e->getMessage()];
    header('Location: main-group.php');
    exit;
}

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

.students-list .button-container {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
    flex-wrap: wrap;
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
    flex: 1;
}

button.delete-all-button {
    background-color: #d32f2f;
    color: white;
    flex: 1;
}

button.upload-button {
    background-color: #0288d1;
    color: white;
    flex: 1;
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

form input[type="text"], form input[type="file"], form textarea, form select {
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
    cursor: not-allowed;
    font-weight: 500;
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

.groups-list tr.new, .students-list tr.new {
    animation: fadeInRow 0.5s ease;
}

@keyframes fadeInRow {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
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

    .actions {
        flex-wrap: wrap;
    }

    .filter-select, .add-button, .reset-button, .delete-all-button, .upload-button {
        width: 100%;
        max-width: none;
    }

    .students-list .button-container {
        flex-direction: column;
        gap: 10px;
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
                    <tbody id="groups-table-body">
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
                <h3>Список студентов <span id="selected-group-name"></span></h3>
                <div class="button-container">
                    <button id="add-student-button" class="add-button" type="button" onclick="openModal('add-student-modal')"><i class="fas fa-circle-plus"></i> Добавить студента</button>
                    <button id="delete-all-students-button" class="delete-all-button" type="button" onclick="confirmDeleteAllStudents()"><i class="fas fa-trash-can"></i> Удалить всех</button>
                    <button id="upload-students-button" class="upload-button" type="button" onclick="openModal('upload-students-modal')"><i class="fas fa-upload"></i> Загрузить из Excel</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>ФИО</th>
                            <th>Телефон</th>
                            <th>Telegram</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="students-table-body"></tbody>
                </table>
            </aside>
        </div>
    </div>

    <div id="add-group-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('add-group-modal')">×</span>
            <h3>Добавить новую группу</h3>
            <form id="add-group-form" method="POST" onsubmit="submitGroupForm(event)">
                <input type="hidden" name="action" value="add_group">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="direction_id" name="direction_id" value="">
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
            <form id="edit-group-form" method="POST" onsubmit="submitEditGroupForm(event)">
                <input type="hidden" name="action" value="edit_group">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="edit-group-id" name="id">
                <label for="edit-group-name">Наименование:</label>
                <input type="text" id="edit-group-name" name="group_name" placeholder="Введите наименование группы" required>
                <label for="edit-group-notes">Примечание:</label>
                <textarea id="edit-group-notes" name="notes" placeholder="Введите дополнительную информацию"></textarea>
                <button type="submit" class="save-button"><i class="fas fa-floppy-disk"></i> Сохранить</button>
            </form>
        </div>
    </div>

    <div id="add-student-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('add-student-modal')">×</span>
            <h3>Добавить нового студента</h3>
            <form id="add-student-form" method="POST" onsubmit="submitStudentForm(event)">
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="group_id">Группа:</label>
                <select id="group_id" name="group_id">
                    <option value="">Выберите группу (необязательно)</option>
                </select>
                <label for="full_name">ФИО:</label>
                <input type="text" id="full_name" name="full_name" placeholder="Введите ФИО студента (необязательно)">
                <label for="phone">Телефон:</label>
                <input type="text" id="phone" name="phone" placeholder="Введите номер телефона (необязательно)">
                <label for="telegram">Telegram-никнейм:</label>
                <input type="text" id="telegram" name="telegram" placeholder="Введите Telegram-никнейм (необязательно)">
                <button type="submit" class="add-button"><i class="fas fa-circle-plus"></i> Добавить</button>
                <button type="button" class="reset-button" onclick="resetStudentForm('add-student-modal')"><i class="fas fa-undo"></i> Сбросить</button>
            </form>
        </div>
    </div>

    <div id="edit-student-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('edit-student-modal')">×</span>
            <h3>Редактировать студента</h3>
            <form id="edit-student-form" method="POST" onsubmit="submitEditStudentForm(event)">
                <input type="hidden" name="action" value="edit_student">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="edit-student-id" name="id">
                <label for="edit-full-name">ФИО:</label>
                <input type="text" id="edit-full-name" name="full_name" placeholder="Введите ФИО студента (необязательно)">
                <label for="edit-phone">Телефон:</label>
                <input type="text" id="edit-phone" name="phone" placeholder="Введите номер телефона (необязательно)">
                <label for="edit-telegram">Telegram-никнейм:</label>
                <input type="text" id="edit-telegram" name="telegram" placeholder="Введите Telegram-никнейм (необязательно)">
                <button type="submit" class="save-button"><i class="fas fa-floppy-disk"></i> Сохранить</button>
                <button type="button" class="reset-button" onclick="resetStudentForm('edit-student-modal')"><i class="fas fa-undo"></i> Сбросить</button>
            </form>
        </div>
    </div>

    <div id="upload-students-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('upload-students-modal')">×</span>
            <h3>Загрузить студентов из Excel</h3>
            <form id="upload-students-form" method="POST" enctype="multipart/form-data" onsubmit="submitUploadStudentsForm(event)">
                <input type="hidden" name="action" value="upload_students">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="excel_file">Выберите Excel-файл (.xls, .xlsx):</label>
                <input type="file" id="excel_file" name="excel_file" accept=".xls,.xlsx" required>
                <button type="submit" class="upload-button"><i class="fas fa-upload"></i> Загрузить</button>
            </form>
        </div>
    </div>

    <script>
let lockedDirectionId = '<?php echo isset($_SESSION['locked_direction_id']) ? htmlspecialchars($_SESSION['locked_direction_id']) : ''; ?>';
let selectedSchoolCode = '';
let selectedGroupId = '<?php echo isset($_SESSION['selected_group_id']) ? htmlspecialchars($_SESSION['selected_group_id']) : ''; ?>';
let directionsData = [];

function showLoadingSpinner() {
    document.getElementById('loading-spinner').style.display = 'block';
}

function hideLoadingSpinner() {
    document.getElementById('loading-spinner').style.display = 'none';
}

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
    isModalOpen = true;
    const modal = document.getElementById(modalId);
    if (!modal) {
        showNotification('Ошибка: модальное окно не найдено', 'error');
        return;
    }
    modal.style.display = 'block';
    if (modalId === 'add-group-modal') {
        const directionInput = document.getElementById('direction_id');
        if (lockedDirectionId) {
            directionInput.value = lockedDirectionId;
        }
    } else if (modalId === 'add-student-modal') {
        updateGroupSelect('group_id').then((groups) => {
            const groupSelect = document.getElementById('group_id');
            if (groups.length === 0) {
                groupSelect.innerHTML = '<option value="">Нет доступных групп</option>';
            }
            if (selectedGroupId && groupSelect) {
                groupSelect.value = selectedGroupId;
                groupSelect.disabled = true;
            } else {
                groupSelect.disabled = false;
            }
        }).catch(error => {
            showNotification('Ошибка при загрузке групп: ' + error.message, 'error');
        });
    }
}

function closeModal(modalId) {
    isModalOpen = false;
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        if (modalId === 'add-group-modal') {
            document.getElementById('add-group-form').reset();
        } else if (modalId === 'add-student-modal' || modalId === 'edit-student-modal') {
            resetStudentForm(modalId);
        } else if (modalId === 'upload-students-modal') {
            document.getElementById('upload-students-form').reset();
        }
    }
}

function validateGroupForm(modalId) {
    const groupName = document.getElementById('group_name')?.value.trim();
    if (!groupName) {
        showNotification('Наименование группы обязательно', 'error');
        return false;
    }
    return true;
}

function validateStudentForm(modalId) {
    return true;
}

function submitGroupForm(event) {
    event.preventDefault();
    if (!validateGroupForm('add-group-modal')) return;

    const form = event.target;
    const data = new FormData(form);
    data.append('action', 'add_group');
    data.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');

    fetch('main-group.php', {
        method: 'POST',
        body: new URLSearchParams(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
        } else {
            showNotification(data.message, 'success');
            closeModal('add-group-modal');
            fetchGroups(); // Refresh groups after adding
            updateGroupSelect('group_id');
        }
    })
    .catch(error => {
        showNotification('Ошибка при добавлении группы: ' + error.message, 'error');
    });
}

function submitEditGroupForm(event) {
    event.preventDefault();
    const form = event.target;
    const data = new FormData(form);
    data.append('action', 'edit_group');
    data.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');

    fetch('main-group.php', {
        method: 'POST',
        body: new URLSearchParams(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
        } else {
            showNotification(data.message, 'success');
            closeModal('edit-group-modal');
            fetchGroups(); // Refresh groups after editing
            updateGroupSelect('group_id');
        }
    })
    .catch(error => {
        showNotification('Ошибка при редактировании группы: ' + error.message, 'error');
    });
}

function submitStudentForm(event) {
    event.preventDefault();
    if (!validateStudentForm('add-student-modal')) return;

    const form = event.target;
    const data = new FormData(form);
    data.append('action', 'add_student');
    data.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
    if (selectedGroupId) {
        data.append('group_id', selectedGroupId);
    }

    fetch('main-group.php', {
        method: 'POST',
        body: new URLSearchParams(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
        } else {
            showNotification(data.message, 'success');
            closeModal('add-student-modal');
            if (selectedGroupId) {
                fetchStudents(selectedGroupId); // Refresh students for selected group
            }
            resetStudentForm('add-student-modal');
        }
    })
    .catch(error => {
        showNotification('Ошибка при добавлении студента: ' + error.message, 'error');
    });
}

function submitEditStudentForm(event) {
    event.preventDefault();
    if (!validateStudentForm('edit-student-modal')) return;

    const form = event.target;
    const data = new FormData(form);
    data.append('action', 'edit_student');
    data.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');

    fetch('main-group.php', {
        method: 'POST',
        body: new URLSearchParams(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
        } else {
            showNotification(data.message, 'success');
            closeModal('edit-student-modal');
            if (selectedGroupId) {
                fetchStudents(selectedGroupId); // Refresh students for selected group
            }
        }
    })
    .catch(error => {
        showNotification('Ошибка при редактировании студента: ' + error.message, 'error');
    });
}

function submitUploadStudentsForm(event) {
    event.preventDefault();
    if (!selectedGroupId) {
        showNotification('Пожалуйста, выберите группу для загрузки студентов', 'error');
        return;
    }

    const form = event.target;
    const data = new FormData(form);
    data.append('action', 'upload_students');
    data.append('group_id', selectedGroupId);
    data.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');

    fetch('main-group.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
        } else {
            showNotification(data.message, 'success');
            closeModal('upload-students-modal');
            if (selectedGroupId) {
                fetchStudents(selectedGroupId); // Refresh students for selected group
            }
            form.reset();
        }
    })
    .catch(error => {
        showNotification('Ошибка при загрузке студентов: ' + error.message, 'error');
    });
}

function openEditGroupModal(id, direction_id, group_name, notes) {
    document.getElementById('edit-group-id').value = id;
    document.getElementById('edit-group-name').value = group_name;
    document.getElementById('edit-group-notes').value = notes;
    openModal('edit-group-modal');
}

function openEditStudentModal(id, group_id, full_name, phone, telegram) {
    fetch('main-group.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_students&group_id=${encodeURIComponent(group_id)}&csrf_token=${encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
            return;
        }
        const student = data.find(s => s.id == id);
        if (!student) {
            showNotification('Студент не найден', 'error');
            return;
        }
        document.getElementById('edit-student-id').value = student.id;
        document.getElementById('edit-full-name').value = student.full_name || '';
        document.getElementById('edit-phone').value = student.phone || '';
        document.getElementById('edit-telegram').value = student.telegram || '';
        openModal('edit-student-modal');
    })
    .catch(error => {
        showNotification('Ошибка при получении данных студента: ' + error.message, 'error');
    });
}

function confirmDeleteGroup(id) {
    if (confirm('Вы уверены, что хотите удалить эту группу?')) {
        fetch('main-group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete_group&id=${encodeURIComponent(id)}&csrf_token=${encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.type === 'error') {
                showNotification(data.message, 'error');
            } else {
                showNotification(data.message, 'success');
                fetchGroups(); // Refresh groups after deletion
                if (selectedGroupId === id) {
                    selectedGroupId = '';
                    document.getElementById('selected-group-name').textContent = '';
                    fetchStudents(null); // Clear students table
                }
                updateGroupSelect('group_id');
            }
        })
        .catch(error => {
            showNotification('Ошибка при удалении группы: ' + error.message, 'error');
        });
    }
}

function confirmDeleteStudent(id, group_id) {
    if (confirm('Вы уверены, что хотите удалить этого студента?')) {
        fetch('main-group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete_student&id=${encodeURIComponent(id)}&group_id=${encodeURIComponent(group_id)}&csrf_token=${encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.type === 'error') {
                showNotification(data.message, 'error');
            } else {
                showNotification(data.message, 'success');
                if (group_id === selectedGroupId) {
                    // Найти и удалить строку студента из таблицы
                    const row = document.querySelector(`#students-table-body tr td button[onclick*="confirmDeleteStudent('${id}', '${group_id}')"]`)?.closest('tr');
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300); // Удалить после анимации
                    }
                }
            }
        })
        .catch(error => {
            showNotification('Ошибка при удалении студента: ' + error.message, 'error');
        });
    }
}

function confirmDeleteAllStudents() {
    if (!selectedGroupId) {
        showNotification('Пожалуйста, выберите группу для удаления студентов', 'error');
        return;
    }
    if (confirm('Вы уверены, что хотите удалить всех студентов в этой группе?')) {
        fetch('main-group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete_all_students&group_id=${encodeURIComponent(selectedGroupId)}&csrf_token=${encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.type === 'error') {
                showNotification(data.message, 'error');
            } else {
                showNotification(data.message, 'success');
                fetchStudents(selectedGroupId); // Refresh students for selected group
            }
        })
        .catch(error => {
            showNotification('Ошибка при удалении всех студентов: ' + error.message, 'error');
        });
    }
}

function updateDirectionSelect(selectId) {
    return new Promise((resolve) => {
        const select = document.getElementById(selectId);
        if (!select) {
            resolve();
            return;
        }
        select.innerHTML = '<option value="">Выберите направление</option>';
        directionsData.forEach(direction => {
            if (!selectedSchoolCode || direction.vsh_code == selectedSchoolCode) {
                const option = document.createElement('option');
                option.value = direction.code;
                option.text = `${direction.code} - ${direction.direction_name}`;
                select.appendChild(option);
            }
        });
        resolve();
    });
}

function updateGroupSelect(selectId) {
    return new Promise((resolve, reject) => {
        const select = document.getElementById(selectId);
        if (!select) {
            reject(new Error('Select element not found'));
            return;
        }
        fetch('main-group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_groups&csrf_token=' + encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')
        })
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Выберите группу (необязательно)</option>';
            data.forEach(group => {
                const option = document.createElement('option');
                option.value = group.id;
                option.text = group.group_name;
                select.appendChild(option);
            });
            resolve(data);
        })
        .catch(error => {
            showNotification('Ошибка при загрузке групп: ' + error.message, 'error');
            reject(error);
        });
    });
}

function fetchDirections(schoolCode) {
    showLoadingSpinner();
    return fetch('main-group.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_directions${schoolCode ? '&school_code=' + encodeURIComponent(schoolCode) : ''}&csrf_token=${encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')}`
    })
    .then(response => response.json())
    .then(data => {
        hideLoadingSpinner();
        if (data.type === 'error') {
            showNotification(data.message, 'error');
            document.getElementById('direction-filter').classList.add('hidden');
            document.getElementById('add-group-button').classList.add('hidden');
            directionsData = [];
            return Promise.resolve();
        }
        directionsData = data;
        return Promise.all([
            updateDirectionSelect('direction-filter'),
            updateDirectionSelect('direction_id')
        ]).then(() => {
            const directionFilter = document.getElementById('direction-filter');
            const addButton = document.getElementById('add-group-button');
            if (data.length > 0) {
                directionFilter.classList.remove('hidden');
                addButton.classList.remove('hidden');
            } else {
                directionFilter.classList.add('hidden');
                addButton.classList.add('hidden');
                showNotification('Для выбранной школы нет доступных направлений', 'error');
            }
            return Promise.resolve();
        });
    })
    .catch(error => {
        hideLoadingSpinner();
        showNotification('Ошибка при загрузке направлений: ' + error.message, 'error');
        document.getElementById('direction-filter').classList.add('hidden');
        document.getElementById('add-group-button').classList.add('hidden');
        directionsData = [];
        return Promise.resolve();
    });
}

function fetchStudents(groupId) {
    const studentsTableBody = document.getElementById('students-table-body');
    if (!studentsTableBody) return;

    studentsTableBody.innerHTML = '';

    if (!groupId) {
        studentsTableBody.innerHTML = '<tr><td colspan="5">Выберите группу для отображения студентов</td></tr>';
        document.getElementById('selected-group-name').textContent = '';
        return;
    }

    fetch('main-group.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_students&group_id=' + encodeURIComponent(groupId) + '&csrf_token=' + encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
            return;
        }
        updateStudentsTable(data);
    })
    .catch(error => {
        showNotification('Ошибка при загрузке студентов: ' + error.message, 'error');
    });
}

function fetchGroups() {
    const data = new FormData();
    data.append('action', 'check_updates');
    data.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');

    fetch('main-group.php', {
        method: 'POST',
        body: new URLSearchParams(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
            return;
        }
        updateGroupsTable(data.groups);
    })
    .catch(error => {
        showNotification('Ошибка при загрузке групп: ' + error.message, 'error');
    });
}

function updateGroupsTable(groups) {
    const tableBody = document.getElementById('groups-table-body');
    tableBody.innerHTML = '';

    if (!groups || groups.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="4">Группы не найдены</td></tr>';
        return;
    }

    groups.forEach(group => {
        const newRow = document.createElement('tr');
        newRow.classList.add('new');
        newRow.setAttribute('data-group-id', group.id);
        newRow.setAttribute('data-direction-id', group.direction_id);
        newRow.setAttribute('data-vsh-code', group.vsh_code);
        newRow.innerHTML = `
            <td>${group.direction_id || 'Не указано'}</td>
            <td><span class="clickable-name" data-group='${JSON.stringify(group)}'>${group.group_name || 'Не указано'}</span></td>
            <td>${group.notes || ''}</td>
            <td>
                <div class="actions">
                    <button class="edit-button" onclick="openEditGroupModal('${group.id}', '${group.direction_id}', '${group.group_name.replace(/'/g, "\\'")}', '${group.notes ? group.notes.replace(/'/g, "\\'") : ''}')"><i class="fas fa-pen"></i></button>
                    <button class="delete-button" onclick="confirmDeleteGroup('${group.id}')"><i class="fas fa-trash-can"></i></button>
                </div>
            </td>
        `;
        tableBody.appendChild(newRow);
        setTimeout(() => newRow.classList.remove('new'), 1000);
        newRow.querySelector('.clickable-name').addEventListener('click', handleGroupClick);
    });

    filterGroups();
}

function updateStudentsTable(students) {
    const tableBody = document.getElementById('students-table-body');
    tableBody.innerHTML = '';

    if (!students || students.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="5">Студенты не найдены</td></tr>';
        return;
    }

    students.forEach((student, index) => {
        const row = document.createElement('tr');
        row.classList.add('new');
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>${student.full_name || 'Не указано'}</td>
            <td>${student.phone || 'Не указано'}</td>
            <td>${student.telegram || 'Не указано'}</td>
            <td>
                <div class="actions">
                    <button class="edit-button" onclick="openEditStudentModal('${student.id}', '${student.group_id}', '${student.full_name ? student.full_name.replace(/'/g, "\\'") : ''}', '${student.phone || ''}', '${student.telegram || ''}')"><i class="fas fa-pen"></i></button>
                    <button class="delete-button" onclick="confirmDeleteStudent('${student.id}', '${student.group_id}')"><i class="fas fa-trash-can"></i></button>
                </div>
            </td>
        `;
        tableBody.appendChild(row);
        setTimeout(() => row.classList.remove('new'), 1000);
    });
}

function filterGroups() {
    const schoolFilterValue = document.getElementById('school-filter').value;
    const directionFilterValue = document.getElementById('direction-filter').value;
    const rows = document.querySelectorAll('#groups-table-body tr');

    rows.forEach(row => {
        const directionId = row.getAttribute('data-direction-id');
        const vshCode = row.getAttribute('data-vsh-code');
        const matchesSchool = !schoolFilterValue || vshCode === schoolFilterValue;
        const matchesDirection = !directionFilterValue || directionId === directionFilterValue;
        row.style.display = matchesSchool && matchesDirection ? '' : 'none';
    });
}

function resetStudentForm(modalId) {
    const formId = modalId === 'add-student-modal' ? 'add-student-form' : 'edit-student-form';
    const groupSelectId = modalId === 'add-student-modal' ? 'group_id' : null;
    const form = document.getElementById(formId);
    if (form) {
        form.reset();
        if (groupSelectId) {
            updateGroupSelect(groupSelectId).then(() => {
                const groupSelect = document.getElementById(groupSelectId);
                if (selectedGroupId && groupSelect) {
                    groupSelect.value = selectedGroupId;
                    groupSelect.disabled = true;
                } else {
                    groupSelect.disabled = false;
                }
            });
        }
    }
}

function handleGroupClick(event) {
    const groupData = JSON.parse(event.target.getAttribute('data-group'));
    selectedGroupId = groupData.id;
    document.getElementById('selected-group-name').textContent = groupData.group_name;

    fetch('main-group.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=select_group&group_id=${encodeURIComponent(selectedGroupId)}&csrf_token=${encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.type === 'error') {
            showNotification(data.message, 'error');
            return;
        }
        fetchStudents(selectedGroupId); // Fetch students for selected group
    })
    .catch(error => {
        showNotification('Ошибка при выборе группы: ' + error.message, 'error');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const schoolFilter = document.getElementById('school-filter');
    const directionFilter = document.getElementById('direction-filter');
    const groupsList = document.getElementById('groups-list');

    if (schoolFilter && directionFilter && groupsList) {
        schoolFilter.addEventListener('change', function() {
            selectedSchoolCode = this.value;
            fetchDirections(selectedSchoolCode).then(() => {
                filterGroups();
                if (!selectedSchoolCode) {
                    directionFilter.classList.add('hidden');
                    document.getElementById('add-group-button').classList.add('hidden');
                }
            });
        });

        directionFilter.addEventListener('change', function() {
            lockedDirectionId = this.value;
            fetch('main-group.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=lock_direction&direction_id=${encodeURIComponent(lockedDirectionId)}&csrf_token=${encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>')}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.type === 'error') {
                    showNotification(data.message, 'error');
                }
                filterGroups();
            })
            .catch(error => {
                showNotification('Ошибка при блокировке направления: ' + error.message, 'error');
            });
        });

        // Initialize filters and load initial groups
        if (schoolFilter.value) {
            selectedSchoolCode = schoolFilter.value;
            fetchDirections(selectedSchoolCode).then(() => {
                directionFilter.value = lockedDirectionId || '';
                filterGroups();
                groupsList.classList.remove('hidden');
            });
        } else {
            fetchDirections().then(() => {
                directionFilter.value = lockedDirectionId || '';
                filterGroups();
                groupsList.classList.remove('hidden');
            });
        }

        // Restore selected group if exists
        if (selectedGroupId) {
            const selectedGroupRow = document.querySelector(`#groups-table-body tr[data-group-id="${selectedGroupId}"]`);
            if (selectedGroupRow) {
                const groupData = JSON.parse(selectedGroupRow.querySelector('.clickable-name').getAttribute('data-group'));
                document.getElementById('selected-group-name').textContent = groupData.group_name;
                fetchStudents(selectedGroupId);
            }
        }
    }

    // Removed setInterval(checkForUpdates, 5000); to disable periodic updates

    // Initialize clickable-name event listeners
    document.querySelectorAll('.clickable-name').forEach(element => {
        element.addEventListener('click', handleGroupClick);
    });
});

// Close modals when clicking outside
window.addEventListener('click', function(event) {
    document.querySelectorAll('.modal').forEach(modal => {
        if (event.target === modal) {
            closeModal(modal.id);
        }
    });
});

// Prevent form submission on Enter key except in textareas
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>