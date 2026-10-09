<?php
// Fuso horário do projeto (o php.ini local tem date.timezone inválido, "GMT-3").
// Todas as páginas incluem este arquivo, então date()/DateTime ficam corretos e sem Warning.
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/app.php';

// Conexão: as credenciais ficam em includes/config.php (fora do repositório).
$cfg_db = app_config('db');
$pdo = new PDO(
    "mysql:host={$cfg_db['host']};dbname={$cfg_db['nome']};charset={$cfg_db['charset']}",
    $cfg_db['usuario'],
    $cfg_db['senha'],
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
unset($cfg_db);

// Função global para registrar ações no sistema
function registrarLog($pdo, $acao, $detalhes = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Pega o IP do usuário (funciona em localhost e em servidores reais)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Desconhecido';
    if ($ip == '::1') { $ip = '127.0.0.1 (Local)'; }
    
    $id_user = $_SESSION['user_id'] ?? 0; // 0 se não estiver logado (ex: tentativa de login falha)
    
    try {
        $stmt = $pdo->prepare("INSERT INTO logs_sistema (id_usuario_autor, acao, ip, detalhes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id_user, $acao, $ip, $detalhes]);
    } catch (Exception $e) {
        // Silencioso para não quebrar a página se o log falhar
    }
}
