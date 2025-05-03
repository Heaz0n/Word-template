<?php
// Подключение к базе данных
$host = 'localhost';
$dbname = 'SystemDocument';
$username = 'root';
$password = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Инициализация переменной для уведомлений
$notification = '';

// Обработка экспорта в Excel
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export_excel'])) {
    try {
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY CAST(number AS UNSIGNED)");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        require 'vendor/autoload.php';
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', '№ п/п');
        $sheet->setCellValue('C1', 'Категория');
        $sheet->setCellValue('D1', 'Категория (сокращенно)');
        $sheet->setCellValue('E1', 'Перечень подтверждающих документов');
        $sheet->setCellValue('F1', 'Периодичность выплат');
        $sheet->setCellValue('G1', 'Максимальная сумма (руб.)');
        
        $row = 2;
        foreach ($categories as $category) {
            $sheet->setCellValue('A' . $row, $category['id'] ?? '');
            $sheet->setCellValue('B' . $row, $category['number'] ?? '');
            $sheet->setCellValue('C' . $row, $category['category_name'] ?? '');
            $sheet->setCellValue('D' . $row, $category['category_short'] ?? '');
            $sheet->setCellValue('E' . $row, str_replace("\n", ", ", $category['documents_list'] ?? ''));
            $sheet->setCellValue('F' . $row, $category['payment_frequency'] ?? '');
            $sheet->setCellValue('G' . $row, $category['max_amount'] ?? '');
            $row++;
        }
        
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="categories_export.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
        
    } catch (PDOException $e) {
        die("Ошибка при экспорте данных: " . $e->getMessage());
    }
}

// Функция для проверки последовательности ID
function checkSequentialIds($pdo) {
    try {
        $stmt = $pdo->query("SELECT id FROM categories ORDER BY id");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($ids)) {
            $expectedId = 1;
            foreach ($ids as $id) {
                if ($id != $expectedId) {
                    return false;
                }
                $expectedId++;
            }
        }
        return true;
    } catch (PDOException $e) {
        die("Ошибка при проверке последовательности ID: " . $e->getMessage());
    }
}

// Добавление категории
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    try {
        if (!checkSequentialIds($pdo)) {
            die("Ошибка: В таблице есть пропущенные значения ID. Пожалуйста, исправьте это.");
        }

        $number = isset($_POST['number']) ? trim($_POST['number']) : '';
        $categoryName = isset($_POST['category_name']) ? trim($_POST['category_name']) : '';
        $categoryShort = isset($_POST['category_short']) ? trim($_POST['category_short']) : '';
        $documentsList = isset($_POST['documents_list']) ? trim($_POST['documents_list']) : '';
        $paymentFrequency = isset($_POST['payment_frequency']) ? trim($_POST['payment_frequency']) : '';
        $maxAmount = isset($_POST['max_amount']) ? (float)$_POST['max_amount'] : 0;

        if (empty($number) || empty($categoryName) || empty($categoryShort) || empty($documentsList) || empty($paymentFrequency)) {
            die("Ошибка: Все обязательные поля должны быть заполнены.");
        }

        if (!preg_match('/^[\d]+(\.[\d]+)*$/', $number)) {
            die("Ошибка: Поле '№ п/п' должно содержать только цифры и точки (например, 5.2.1).");
        }

        $stmt = $pdo->prepare("INSERT INTO categories (number, category_name, category_short, documents_list, payment_frequency, max_amount) 
                               VALUES (:number, :category_name, :category_short, :documents_list, :payment_frequency, :max_amount)");
        $stmt->execute([
            ':number' => $number,
            ':category_name' => $categoryName,
            ':category_short' => $categoryShort,
            ':documents_list' => $documentsList,
            ':payment_frequency' => $paymentFrequency,
            ':max_amount' => $maxAmount
        ]);

        $notification = 'Категория успешно добавлена!';
        echo '<script>localStorage.removeItem("formData");</script>';
        header("Location: " . filter_var($_SERVER['PHP_SELF'], FILTER_SANITIZE_URL));
        exit();
    } catch (PDOException $e) {
        die("Ошибка при добавлении категории: " . $e->getMessage());
    }
}

// Редактирование категории
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    try {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $number = isset($_POST['number']) ? trim($_POST['number']) : '';
        $categoryName = isset($_POST['category_name']) ? trim($_POST['category_name']) : '';
        $categoryShort = isset($_POST['category_short']) ? trim($_POST['category_short']) : '';
        $documentsList = isset($_POST['documents_list']) ? trim($_POST['documents_list']) : '';
        $paymentFrequency = isset($_POST['payment_frequency']) ? trim($_POST['payment_frequency']) : '';
        $maxAmount = isset($_POST['max_amount']) ? (float)$_POST['max_amount'] : 0;

        if ($id <= 0 || empty($number) || empty($categoryName) || empty($categoryShort) || empty($documentsList) || empty($paymentFrequency)) {
            die("Ошибка: Неверные данные для редактирования.");
        }

        if (!preg_match('/^[\d]+(\.[\d]+)*$/', $number)) {
            die("Ошибка: Поле '№ п/п' должно содержать только цифры и точки (например, 5.2.1).");
        }

        $stmt = $pdo->prepare("UPDATE categories SET 
                               number = :number, 
                               category_name = :category_name, 
                               category_short = :category_short, 
                               documents_list = :documents_list, 
                               payment_frequency = :payment_frequency, 
                               max_amount = :max_amount 
                               WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':number' => $number,
            ':category_name' => $categoryName,
            ':category_short' => $categoryShort,
            ':documents_list' => $documentsList,
            ':payment_frequency' => $paymentFrequency,
            ':max_amount' => $maxAmount
        ]);

        $notification = 'Категория успешно обновлена!';
        header("Location: " . filter_var($_SERVER['PHP_SELF'], FILTER_SANITIZE_URL));
        exit();
    } catch (PDOException $e) {
        die("Ошибка при редактировании категории: " . $e->getMessage());
    }
}

// Удаление категории
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_id'])) {
    try {
        $id = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;
        if ($id <= 0) {
            die("Ошибка: Неверный ID для удаления.");
        }

        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $notification = 'Категория успешно удалена!';
        header("Location: " . filter_var($_SERVER['PHP_SELF'], FILTER_SANITIZE_URL));
        exit();
    } catch (PDOException $e) {
        die("Ошибка при удалении категории: " . $e->getMessage());
    }
}

// Получение всех категорий
try {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY CAST(number AS UNSIGNED)");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Ошибка при получении списка категорий: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Основания для оказания материальной поддержки</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --yugu-dark-blue: #002856;
            --yugu-blue: #003366;
            --yugu-light-blue: #0066cc;
            --yugu-gold: #cc9900;
            --yugu-light-gold: #e6c229;
            --yugu-white: #ffffff;
            --yugu-light-gray: #f8f9fa;
            --transition-fast: 0.2s ease;
            --transition-medium: 0.3s ease;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f5f7fa;
        }

        /* Стили для навбара (из header.html) */
        .navbar {
            background: linear-gradient(135deg, var(--yugu-dark-blue) 0%, var(--yugu-blue) 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            padding: 0;
            position: relative;
            z-index: 1000;
        }

        .navbar-container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            padding: 0 20px;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            transition: var(--transition-medium);
        }

        .brand-logo {
            height: 42px;
            width: auto;
            transition: var(--transition-medium);
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        }

        .brand-text {
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--yugu-white);
            letter-spacing: 0.5px;
            transition: var(--transition-medium);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .brand-subtext {
            display: block;
            font-size: 0.78rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.85);
            margin-top: 2px;
        }

        .navbar-brand:hover .brand-text {
            color: var(--yugu-light-gold);
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .navbar-brand:hover .brand-logo {
            transform: scale(1.05);
            filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.15));
        }

        .nav-item {
            position: relative;
            margin: 0 5px;
        }

        .nav-link {
            font-weight: 600;
            font-size: 0.97rem;
            color: var(--yugu-white) !important;
            padding: 16px 18px !important;
            display: flex;
            align-items: center;
            transition: var(--transition-fast);
            position: relative;
            border-radius: 6px;
        }

        .nav-link i {
            font-size: 1.15rem;
            margin-right: 10px;
            color: var(--yugu-light-gold);
            transition: var(--transition-fast);
        }

        .nav-link:hover, 
        .nav-link:focus {
            color: var(--yugu-light-gold) !important;
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-1px);
        }

        .nav-link:hover i {
            transform: scale(1.15);
            color: var(--yugu-gold);
        }

        .nav-link.active {
            color: var(--yugu-light-gold) !important;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 18px;
            right: 18px;
            height: 3px;
            background: var(--yugu-light-gold);
            border-radius: 3px 3px 0 0;
            animation: underlineGrow 0.3s ease-out;
            box-shadow: 0 2px 4px rgba(204, 153, 0, 0.2);
        }

        @keyframes underlineGrow {
            from { transform: scaleX(0); opacity: 0; }
            to { transform: scaleX(1); opacity: 1; }
        }

        .dropdown-menu {
            background: var(--yugu-white);
            border: none;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            padding: 8px 0;
            margin-top: 10px !important;
            animation: fadeInDropdown 0.25s ease-out;
            min-width: 250px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            display: none;
            position: absolute;
        }

        @keyframes fadeInDropdown {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-item {
            font-weight: 500;
            color: var(--yugu-blue);
            padding: 10px 22px;
            transition: var(--transition-fast);
            display: flex;
            align-items: center;
            border-radius: 6px;
            margin: 0 8px;
            width: auto;
        }

        .dropdown-item i {
            color: var(--yugu-light-blue);
            font-size: 1rem;
            margin-right: 12px;
            width: 20px;
            text-align: center;
            transition: var(--transition-fast);
        }

        .dropdown-item:hover, 
        .dropdown-item:focus {
            background: rgba(0, 102, 204, 0.1);
            color: var(--yugu-light-blue);
            transform: translateX(3px);
        }

        .dropdown-item:hover i {
            transform: scale(1.15);
            color: var(--yugu-gold);
        }

        .dropdown-divider {
            border-color: rgba(0, 0, 0, 0.08);
            margin: 8px 0;
        }

        .dropdown-toggle::after {
            display: none;
        }

        @media (max-width: 1199.98px) {
            .nav-link {
                padding: 14px 16px !important;
            }
        }

        @media (max-width: 991.98px) {
            .navbar-brand {
                padding: 10px 0;
            }
            .brand-logo {
                height: 38px;
            }
            .brand-text {
                font-size: 1.15rem;
            }
            .nav-link {
                padding: 12px 16px !important;
            }
            .dropdown-menu {
                box-shadow: none;
                background: rgba(0, 0, 0, 0.08);
                margin-top: 0 !important;
                border-radius: 0;
                border: none;
                display: none;
            }
            .dropdown-toggle::after {
                display: inline-block;
            }
            .dropdown-item {
                color: var(--yugu-white);
                padding: 8px 16px 8px 45px;
            }
            .dropdown-item i {
                color: var(--yugu-light-gold);
            }
            .dropdown-item:hover {
                background: rgba(255, 255, 255, 0.1);
                transform: none;
            }
        }

        @media (max-width: 767.98px) {
            .navbar-container {
                padding: 0 16px;
            }
            .brand-text {
                font-size: 1.05rem;
            }
            .brand-subtext {
                font-size: 0.72rem;
            }
            .nav-link {
                padding: 10px 14px !important;
            }
        }

        /* Стили из mathelp.php */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        .nav-category {
            position: relative;
        }
        .submenu {
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
            animation: fadeIn 0.3s ease-out;
        }
        .nav-category:hover .submenu {
            display: block;
        }
        .submenu a {
            display: block;
            padding: 8px 15px;
            color: #333;
            text-decoration: none;
            white-space: nowrap;
        }
        .submenu a:hover {
            background: #f5f5f5;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table thead th {
            background-color: #f8f9fa;
            position: sticky;
            top: 0;
        }
        .table tbody tr:hover {
            background-color: #f1f1f1;
        }
        .table-active {
            background-color: #e9ecef !important;
        }
        .error-message {
            color: #dc3545;
            margin-top: 5px;
            font-size: 0.875em;
        }
        .auto-save-notice {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #28a745;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1050;
            display: none;
            animation: fadeIn 0.3s ease-out;
        }
        .action-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #28a745;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1050;
            display: none;
            animation: fadeIn 0.3s ease-out;
        }
        .export-buttons {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Навигационное меню (перенесено из header.html) -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid navbar-container">
            <a class="navbar-brand" href="#">
                <img src="8.svg" alt="ЮГУ" class="brand-logo">
                <span class="brand-text">
                    Генератор документов
                    <span class="brand-subtext">Югорский государственный университет</span>
                </span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" 
                    aria-controls="mainNavbar" aria-expanded="false" aria-label="Переключить навигацию">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="referencesDropdown" 
                           aria-expanded="false">
                            <i class="bi bi-journal-bookmark-fill"></i> Справочники
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="referencesDropdown">
                            <li><a class="dropdown-item" href="main-group.php"><i class="bi bi-people-fill"></i> Группы и студенты</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="mathelp.php"><i class="bi bi-tags-fill"></i> Категории</a></li>
                            <li><a class="dropdown-item" href="schools.php"><i class="bi bi-building-gear"></i> Высшие школы и направления</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-person-badge"></i> ..</a></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-heart-fill"></i> ..</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="main-work.php">
                            <i class="bi bi-file-earmark-richtext"></i> Основная работа
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.html">
                            <i class="bi bi-bar-chart-steps"></i> Отчёты
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Уведомление об автосохранении -->
    <div class="auto-save-notice" id="autoSaveNotice">
        Данные формы автоматически сохранены
    </div>

    <!-- Уведомление о действиях -->
    <div class="action-notification" id="actionNotification">
        <?= htmlspecialchars($notification) ?>
    </div>

    <!-- Основной контент -->
    <div class="container mt-4">
        <div class="d-flex align-items-center mb-3">
            <h2 class="mb-0"><i class="bi bi-cash-stack"></i> Категории для оказания материальной поддержки</h2>
            <div class="ms-auto">
                <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-circle"></i> Добавить
                </button>
            </div>
        </div>
        
        <div class="export-buttons mb-4">
            <a href="?export_excel=1" class="btn btn-primary">
                <i class="bi bi-file-earmark-excel"></i> Экспорт в Excel
            </a>
        </div>
        
        <p class="mb-4">Ниже представлен перечень категорий и документов, необходимых для получения материальной поддержки.</p>
        <?php if (empty($categories)): ?>
            <div class="alert alert-info">Нет данных для отображения. Добавьте первую категорию.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-primary">
                    <tr>
                        <th>ID</th>
                        <th>№ п/п</th>
                        <th>Категория</th>
                        <th>Категория (сокращенно)</th>
                        <th>Перечень подтверждающих документов</th>
                        <th>Периодичность выплат</th>
                        <th>Максимальная сумма</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><?= htmlspecialchars($category['id'] ?? '') ?></td>
                        <td><?= htmlspecialchars($category['number'] ?? '') ?></td>
                        <td><?= htmlspecialchars($category['category_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($category['category_short'] ?? '') ?></td>
                        <td>
                            <ul class="mb-0">
                                <?php 
                                $documents = isset($category['documents_list']) ? explode("\n", $category['documents_list']) : [];
                                foreach ($documents as $doc): 
                                    if (!empty(trim($doc))): ?>
                                        <li><?= htmlspecialchars($doc) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td><?= htmlspecialchars($category['payment_frequency'] ?? '') ?></td>
                        <td><?= htmlspecialchars($category['max_amount'] ?? '') ?> руб.</td>
                        <td>
                            <button class="btn btn-warning btn-sm edit-btn" data-bs-toggle="modal" data-bs-target="#editModal"
                               data-id="<?= $category['id'] ?? '' ?>"
                               data-number="<?= htmlspecialchars($category['number'] ?? '') ?>"
                               data-category-name="<?= htmlspecialchars($category['category_name'] ?? '') ?>"
                               data-category-short="<?= htmlspecialchars($category['category_short'] ?? '') ?>"
                               data-documents-list="<?= htmlspecialchars($category['documents_list'] ?? '') ?>"
                               data-payment-frequency="<?= htmlspecialchars($category['payment_frequency'] ?? '') ?>"
                               data-max-amount="<?= htmlspecialchars($category['max_amount'] ?? '') ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <a href="?delete_id=<?= $category['id'] ?? '' ?>" class="btn btn-danger btn-sm" onclick="return confirm('Вы уверены, что хотите удалить эту категорию?');">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно добавления -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">Добавление категории</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addForm">
                    <div class="modal-body">
                        <input type="hidden" name="add_category" value="1">
                        <div class="mb-3">
                            <label for="newNumber" class="form-label">№ п/п</label>
                            <input type="text" class="form-control" id="newNumber" name="number" required>
                            <div class="error-message" id="numberError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="newCategoryName" class="form-label">Категория</label>
                            <input type="text" class="form-control" id="newCategoryName" name="category_name" required>
                            <div class="error-message" id="nameError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="newCategoryShort" class="form-label">Категория (сокращенно)</label>
                            <input type="text" class="form-control" id="newCategoryShort" name="category_short" required>
                            <div class="error-message" id="shortError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="newDocumentsList" class="form-label">Перечень подтверждающих документов (каждый документ с новой строки)</label>
                            <textarea class="form-control" id="newDocumentsList" name="documents_list" rows="5" required></textarea>
                            <div class="error-message" id="docsError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="newPaymentFrequency" class="form-label">Периодичность выплат</label>
                            <input type="text" class="form-control" id="newPaymentFrequency" name="payment_frequency" required>
                            <div class="error-message" id="frequencyError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="newMaxAmount" class="form-label">Максимальная сумма (руб.)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="newMaxAmount" name="max_amount" required>
                            <div class="error-message" id="amountError"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Добавить категорию</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно редактирования -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Редактирование категории</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="edit_category" value="1">
                        <input type="hidden" id="editId" name="id">
                        <div class="mb-3">
                            <label for="editNumber" class="form-label">№ п/п</label>
                            <input type="text" class="form-control" id="editNumber" name="number" required>
                            <div class="error-message" id="editNumberError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="editCategoryName" class="form-label">Категория</label>
                            <input type="text" class="form-control" id="editCategoryName" name="category_name" required>
                            <div class="error-message" id="editNameError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="editCategoryShort" class="form-label">Категория (сокращенно)</label>
                            <input type="text" class="form-control" id="editCategoryShort" name="category_short" required>
                            <div class="error-message" id="editShortError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="editDocumentsList" class="form-label">Перечень подтверждающих документов (каждый документ с новой строки)</label>
                            <textarea class="form-control" id="editDocumentsList" name="documents_list" rows="5" required></textarea>
                            <div class="error-message" id="editDocsError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="editPaymentFrequency" class="form-label">Периодичность выплат</label>
                            <input type="text" class="form-control" id="editPaymentFrequency" name="payment_frequency" required>
                            <div class="error-message" id="editFrequencyError"></div>
                        </div>
                        <div class="mb-3">
                            <label for="editMaxAmount" class="form-label">Максимальная сумма (руб.)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="editMaxAmount" name="max_amount" required>
                            <div class="error-message" id="editAmountError"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Управление выпадающим меню (из header.html)
            const dropdownToggle = document.querySelector('#referencesDropdown');
            const dropdownMenu = document.querySelector('.dropdown-menu');
            let isMenuOpen = false;

            if (dropdownToggle && dropdownMenu) {
                dropdownToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    isMenuOpen = !isMenuOpen;
                    dropdownMenu.classList.toggle('show', isMenuOpen);
                });

                document.addEventListener('click', function(e) {
                    if (!dropdownToggle.contains(e.target) && !dropdownMenu.contains(e.target)) {
                        isMenuOpen = false;
                        dropdownMenu.classList.remove('show');
                    }
                });

                dropdownMenu.querySelectorAll('.dropdown-item').forEach(item => {
                    item.addEventListener('click', function() {
                        isMenuOpen = false;
                        dropdownMenu.classList.remove('show');
                    });
                });
            }

            // Установка активного пункта меню (из header.html)
            function setActiveNavItem() {
                const currentPath = window.location.pathname.split('/').pop().split('?')[0];
                const allNavItems = document.querySelectorAll('.nav-link, .dropdown-item');
                
                allNavItems.forEach(item => item.classList.remove('active'));
                
                allNavItems.forEach(item => {
                    const itemHref = item.getAttribute('href');
                    if (itemHref) {
                        const itemPath = itemHref.split('/').pop().split('?')[0];
                        
                        if (currentPath === itemPath || 
                            (currentPath === '' && itemPath === 'index.html') ||
                            (itemPath === currentPath + '.html')) {
                            
                            item.classList.add('active');
                            
                            if (item.classList.contains('dropdown-item')) {
                                const dropdownToggle = item.closest('.dropdown-menu').previousElementSibling;
                                if (dropdownToggle) {
                                    dropdownToggle.classList.add('active');
                                }
                            }
                        }
                    }
                });
                
                if (currentPath === '' || currentPath === 'index.html') {
                    document.querySelector('.navbar-brand').classList.add('active-brand');
                }
            }

            setActiveNavItem();
            window.addEventListener('popstate', setActiveNavItem);
            document.addEventListener('click', function(e) {
                if (e.target.closest('a')) {
                    setTimeout(setActiveNavItem, 50);
                }
            });
            window.addEventListener('load', setActiveNavItem);

            // Показ уведомления
            const notification = document.getElementById('actionNotification');
            if (notification.textContent.trim() !== '') {
                notification.style.display = 'block';
                setTimeout(() => {
                    notification.style.animation = 'fadeOut 0.5s ease-out';
                    setTimeout(() => {
                        notification.style.display = 'none';
                    }, 500);
                }, 3000);
            }

            // Обработчики для кнопок редактирования
            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id') || '';
                    const number = this.getAttribute('data-number') || '';
                    const categoryName = this.getAttribute('data-category-name') || '';
                    const categoryShort = this.getAttribute('data-category-short') || '';
                    const documentsList = this.getAttribute('data-documents-list') || '';
                    const paymentFrequency = this.getAttribute('data-payment-frequency') || '';
                    const maxAmount = this.getAttribute('data-max-amount') || '';
                    document.getElementById('editId').value = id;
                    document.getElementById('editNumber').value = number;
                    document.getElementById('editCategoryName').value = categoryName;
                    document.getElementById('editCategoryShort').value = categoryShort;
                    document.getElementById('editDocumentsList').value = documentsList;
                    document.getElementById('editPaymentFrequency').value = paymentFrequency;
                    document.getElementById('editMaxAmount').value = maxAmount;
                });
            });

            // Очистка формы добавления
            document.getElementById('addModal').addEventListener('hidden.bs.modal', function() {
                this.querySelector('form').reset();
                document.querySelectorAll('#addForm .error-message').forEach(el => {
                    el.textContent = '';
                });
            });

            // Очистка формы редактирования
            document.getElementById('editModal').addEventListener('hidden.bs.modal', function() {
                document.querySelectorAll('#editForm .error-message').forEach(el => {
                    el.textContent = '';
                });
            });

            // Валидация формы добавления
            document.getElementById('addForm').addEventListener('submit', function(e) {
                let isValid = true;
                const number = document.getElementById('newNumber').value.trim();
                const numberPattern = /^[\d]+(\.[\d]+)*$/;
                if (!number || !numberPattern.test(number)) {
                    document.getElementById('numberError').textContent = 'Введите корректный номер (например, 5.2.1)';
                    isValid = false;
                } else {
                    document.getElementById('numberError').textContent = '';
                }

                const name = document.getElementById('newCategoryName').value.trim();
                if (!name) {
                    document.getElementById('nameError').textContent = 'Введите название категории';
                    isValid = false;
                } else {
                    document.getElementById('nameError').textContent = '';
                }

                const short = document.getElementById('newCategoryShort').value.trim();
                if (!short) {
                    document.getElementById('shortError').textContent = 'Введите сокращенное название';
                    isValid = false;
                } else {
                    document.getElementById('shortError').textContent = '';
                }

                const docs = document.getElementById('newDocumentsList').value.trim();
                if (!docs) {
                    document.getElementById('docsError').textContent = 'Введите список документов';
                    isValid = false;
                } else {
                    document.getElementById('docsError').textContent = '';
                }

                const frequency = document.getElementById('newPaymentFrequency').value.trim();
                if (!frequency) {
                    document.getElementById('frequencyError').textContent = 'Введите периодичность выплат';
                    isValid = false;
                } else {
                    document.getElementById('frequencyError').textContent = '';
                }

                const amount = document.getElementById('newMaxAmount').value;
                if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
                    document.getElementById('amountError').textContent = 'Введите корректную сумму (положительное число)';
                    isValid = false;
                } else {
                    document.getElementById('amountError').textContent = '';
                }

                if (!isValid) {
                    e.preventDefault();
                } else {
                    localStorage.removeItem('formData');
                }
            });

            // Валидация формы редактирования
            document.getElementById('editForm').addEventListener('submit', function(e) {
                let isValid = true;
                const number = document.getElementById('editNumber').value.trim();
                const numberPattern = /^[\d]+(\.[\d]+)*$/;
                if (!number || !numberPattern.test(number)) {
                    document.getElementById('editNumberError').textContent = 'Введите корректный номер (например, 5.2.1)';
                    isValid = false;
                } else {
                    document.getElementById('editNumberError').textContent = '';
                }

                const name = document.getElementById('editCategoryName').value.trim();
                if (!name) {
                    document.getElementById('editNameError').textContent = 'Введите название категории';
                    isValid = false;
                } else {
                    document.getElementById('editNameError').textContent = '';
                }

                const short = document.getElementById('editCategoryShort').value.trim();
                if (!short) {
                    document.getElementById('editShortError').textContent = 'Введите сокращенное название';
                    isValid = false;
                } else {
                    document.getElementById('editShortError').textContent = '';
                }

                const docs = document.getElementById('editDocumentsList').value.trim();
                if (!docs) {
                    document.getElementById('editDocsError').textContent = 'Введите список документов';
                    isValid = false;
                } else {
                    document.getElementById('editDocsError').textContent = '';
                }

                const frequency = document.getElementById('editPaymentFrequency').value.trim();
                if (!frequency) {
                    document.getElementById('editFrequencyError').textContent = 'Введите периодичность выплат';
                    isValid = false;
                } else {
                    document.getElementById('editFrequencyError').textContent = '';
                }

                const amount = document.getElementById('editMaxAmount').value;
                if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
                    document.getElementById('editAmountError').textContent = 'Введите корректную сумму (положительное число)';
                    isValid = false;
                } else {
                    document.getElementById('editAmountError').textContent = '';
                }

                if (!isValid) {
                    e.preventDefault();
                }
            });

            // Автосохранение формы
            function saveFormData() {
                const formData = {
                    number: document.getElementById('newNumber').value,
                    categoryName: document.getElementById('newCategoryName').value,
                    categoryShort: document.getElementById('newCategoryShort').value,
                    documentsList: document.getElementById('newDocumentsList').value,
                    paymentFrequency: document.getElementById('newPaymentFrequency').value,
                    maxAmount: document.getElementById('newMaxAmount').value
                };
                localStorage.setItem('formData', JSON.stringify(formData));
                const notice = document.getElementById('autoSaveNotice');
                notice.style.display = 'block';
                setTimeout(() => {
                    notice.style.display = 'none';
                }, 3000);
            }

            // Загрузка сохраненных данных
            function loadFormData() {
                const savedData = localStorage.getItem('formData');
                if (savedData) {
                    const formData = JSON.parse(savedData);
                    document.getElementById('newNumber').value = formData.number || '';
                    document.getElementById('newCategoryName').value = formData.categoryName || '';
                    document.getElementById('newCategoryShort').value = formData.categoryShort || '';
                    document.getElementById('newDocumentsList').value = formData.documentsList || '';
                    document.getElementById('newPaymentFrequency').value = formData.paymentFrequency || '';
                    document.getElementById('newMaxAmount').value = formData.maxAmount || '';
                }
            }

            document.getElementById('newNumber').addEventListener('input', saveFormData);
            document.getElementById('newCategoryName').addEventListener('input', saveFormData);
            document.getElementById('newCategoryShort').addEventListener('input', saveFormData);
            document.getElementById('newDocumentsList').addEventListener('input', saveFormData);
            document.getElementById('newPaymentFrequency').addEventListener('input', saveFormData);
            document.getElementById('newMaxAmount').addEventListener('input', saveFormData);

            loadFormData();

            document.getElementById('addModal').addEventListener('hidden.bs.modal', function() {
                if (!this.querySelector('form').classList.contains('submitted')) {
                    localStorage.removeItem('formData');
                }
            });

            document.getElementById('addForm').addEventListener('submit', function() {
                this.classList.add('submitted');
            });
        });
    </script>
</body>
</html>