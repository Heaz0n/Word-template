<!-- index.html -->
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Главная страница</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        // Подключение header.html

        document.addEventListener('DOMContentLoaded', function() {
            fetch('header.html')
                .then(response => response.text())
                .then(data => {
                    document.getElementById('header-container').innerHTML = data;
                })
                .catch(error => console.error('Ошибка загрузки header.html:', error));
        });
    </script>
</head>
<body>
    <!-- Контейнер для шапки -->
    <div id="header-container"></div>

    <!-- Главная страница -->
    <div class="container mt-4">
        <h2><i class="bi bi-house-door"></i> Добро пожаловать!</h2>
        <p>Это главная страница системы документооборота университета.</p>
        <p>Используйте меню выше для навигации по разделам.</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>