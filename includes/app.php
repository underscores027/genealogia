<?php
/**
 * Configuração da instalação (includes/config.php, fora do repositório).
 *
 *   require_once __DIR__ . '/includes/app.php';
 *   app_config('app_nome');   app_config('db')['host'];
 */

/** Valor de uma chave do config.php (sem chave: o array inteiro). Carrega o arquivo uma vez só. */
function app_config($chave = null) {
    static $config = null;
    if ($config === null) {
        $arquivo = __DIR__ . '/config.php';
        if (!is_file($arquivo)) {
            http_response_code(500);
            exit('Configuração ausente: copie includes/config.exemplo.php para includes/config.php e preencha os dados do banco.');
        }
        $config = require $arquivo;
    }
    if ($chave === null) return $config;
    return isset($config[$chave]) ? $config[$chave] : null;
}

/** Nome do sistema para o menu e os títulos. */
function app_nome() {
    $nome = app_config('app_nome');
    return is_string($nome) && $nome !== '' ? $nome : 'Árvore Genealógica';
}
