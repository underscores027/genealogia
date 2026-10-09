<?php
/**
 * Modelo de configuração. Copie para includes/config.php e preencha;
 * o config.php não vai para o repositório (.gitignore).
 * Lido por app_config() em includes/app.php.
 */
return [
    // Nome exibido no menu e no título das páginas
    'app_nome' => 'Árvore Genealógica',

    'db' => [
        'host' => 'localhost',
        'nome' => 'genealogia',
        'usuario' => 'root',
        'senha' => '',
        'charset' => 'utf8mb4',
    ],
];
