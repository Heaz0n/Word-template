<!-- main-group.html -->
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Основная группа</title>
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

    <!-- Основная группа -->
    <div class="container mt-4">
        <div class="card mt-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-people-fill"></i> Основная группа
            </div>
            <div class="card-body">
                <table class="table table-striped table-hover">
                    <thead class="table-primary">
                        <tr>
                            <th>№</th>
                            <th>Наименование</th>
                            <th>Направление</th>
                            <th>Уровень подготовки</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>ИВТ41б</td>
                            <td>Информатика и ВТ</td>
                            <td>Бакалавриат</td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>ПИ41б</td>
                            <td>Программная инженерия</td>
                            <td>Бакалавриат</td>
                        </tr>
                        <tr>
                            <td>3</td>
                            <td>ИБ41</td>
                            <td>Информационная безопасность</td>
                            <td>Бакалавриат</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>