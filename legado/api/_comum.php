<?php
/**
 * Infraestrutura comum dos endpoints JSON em api/ (somente leitura, públicos; a exceção
 * são os de gravação, que definem API_ESCRITA antes do require e exigem POST + login).
 *
 *   require __DIR__ . '/_comum.php';   // define $pdo e as funções api_*
 *
 * - Sempre responde application/json; charset=utf-8, com acentos sem escape.
 * - Warnings/Notices nunca vazam no corpo: viram exceção e uma resposta 500 em JSON
 *   (o detalhe vai só para o error_log do servidor).
 * - Só aceita GET/HEAD (POST nos endpoints de gravação).
 * Compatível com PHP 7.4.
 */

ini_set('display_errors', '0');
// O php.ini local tem date.timezone = "GMT-3" (inválido): qualquer função de data
// emitiria um Warning. Fixar o fuso aqui evita isso.
date_default_timezone_set('America/Sao_Paulo');
error_reporting(E_ALL);
ob_start(); // descarta qualquer saída acidental antes do JSON

/** Envia o JSON e encerra. */
function api_responder($dados, $status = 200) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $status = 500;
        $json = '{"erro":"Falha ao gerar a resposta.","status":500}';
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $json;
    exit;
}

/** Resposta de erro padronizada: {"erro": "...", "status": N}. */
function api_erro($status, $mensagem) {
    api_responder(['erro' => $mensagem, 'status' => (int)$status], $status);
}

set_error_handler(function ($nivel, $msg, $arquivo, $linha) {
    if (!(error_reporting() & $nivel)) return false;
    throw new ErrorException($msg, 0, $nivel, $arquivo, $linha);
});
set_exception_handler(function ($e) {
    restore_error_handler(); // um aviso dentro do próprio handler não pode virar fatal
    @error_log('[api genealogia] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    api_erro(500, 'Erro interno ao consultar a árvore.');
});

// Acesso direto a este arquivo não é um endpoint.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    api_erro(404, 'Endpoint inexistente.');
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (defined('API_ESCRITA')) {
    // Endpoint de gravação (define API_ESCRITA antes do require): só POST e só com login.
    if ($metodo !== 'POST') {
        header('Allow: POST');
        api_erro(405, 'Método não permitido. Use POST.');
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) api_erro(401, 'Faça login para alterar a árvore.');
} elseif ($metodo !== 'GET' && $metodo !== 'HEAD') {
    header('Allow: GET, HEAD');
    api_erro(405, 'Método não permitido. Esta API é somente leitura.');
}

require_once __DIR__ . '/../includes/db.php';          // define $pdo (lança exceção se falhar)
require_once __DIR__ . '/../includes/genealogia.php';

/**
 * Lê um parâmetro inteiro da query string.
 * Ausente → $padrao (ou 400 se $obrigatorio). Não inteiro ou fora de [$min, $max] → 400.
 */
function api_param_int($nome, $padrao = null, $min = 1, $max = PHP_INT_MAX, $obrigatorio = false) {
    if (!isset($_GET[$nome]) || $_GET[$nome] === '') {
        if ($obrigatorio) api_erro(400, "Parâmetro '$nome' é obrigatório.");
        return $padrao;
    }
    $v = $_GET[$nome];
    if (is_array($v) || !preg_match('/^\d+$/', (string)$v)) {
        api_erro(400, "Parâmetro '$nome' deve ser um número inteiro.");
    }
    $v = ltrim((string)$v, '0');
    $v = strlen($v) > 15 ? PHP_INT_MAX : (int)$v;   // evita estouro de inteiro
    if ($v < $min || $v > $max) {
        api_erro(400, "Parâmetro '$nome' deve estar entre $min e $max.");
    }
    return $v;
}

/** 'id' obrigatório e existente; devolve a linha completa da pessoa (404 se não existir). */
function api_pessoa_obrigatoria(PDO $pdo) {
    $id = api_param_int('id', null, 1, 999999999, true);
    $p = gen_pessoa($pdo, $id);
    if (!$p) api_erro(404, 'Pessoa não encontrada.');
    return $p;
}

/** Monta a resposta padrão de grafo: listas normalizadas por id. */
function api_grafo(array $sub) {
    return [
        'pessoas' => array_values($sub['pessoas']),
        'unioes' => $sub['unioes'],
        'filiacoes' => $sub['filiacoes'],
    ];
}
