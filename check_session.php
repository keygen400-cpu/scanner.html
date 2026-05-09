<?php
require 'api/config.php';
if ($_GET['set'] === '1') {
    $_SESSION['test'] = 'working';
    echo '✅ Сессия записана. <a href="?check">Проверить</a>';
} elseif ($_GET['check'] === '1') {
    echo $_SESSION['test'] === 'working' ? '✅ Сессии работают! Система будет держать вход.' : '❌ Сессии не сохраняются. Проверьте права на папку `sessions/`';
} else {
    echo '<a href="?set=1">Шаг 1: Записать сессию</a> | <a href="?check=1">Шаг 2: Проверить</a>';