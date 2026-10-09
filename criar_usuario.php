<?php
/**
 * Cria um usuário ou troca a senha de um existente. Só pela linha de comando
 * (não há cadastro público):
 *
 *   php criar_usuario.php <usuario> <senha> ["Nome completo"]
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}
if ($argc < 3) {
    exit("Uso: php criar_usuario.php <usuario> <senha> [\"Nome completo\"]\n");
}
include __DIR__ . '/includes/db.php';

$username = trim($argv[1]);
$senha = $argv[2];
$nome = isset($argv[3]) ? trim($argv[3]) : null;
if ($username === '' || strlen($senha) < 6) {
    exit("Informe um usuário e uma senha de pelo menos 6 caracteres.\n");
}
$hash = password_hash($senha, PASSWORD_DEFAULT);

$st = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
$st->execute([$username]);
if ($st->fetch()) {
    $pdo->prepare("UPDATE usuarios SET senha = ?, nome_completo = COALESCE(?, nome_completo) WHERE username = ?")->execute([$hash, $nome, $username]);
    echo "Senha do usuário '$username' atualizada.\n";
} else {
    $pdo->prepare("INSERT INTO usuarios (username, nome_completo, senha) VALUES (?, ?, ?)")->execute([$username, $nome, $hash]);
    echo "Usuário '$username' criado.\n";
}
