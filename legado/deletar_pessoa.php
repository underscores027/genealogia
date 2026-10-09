<?php
include 'includes/auth.php';
include 'includes/db.php';
require_once 'includes/genealogia.php';

// Só aceita POST (evita exclusão por link/imagem forjada).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: gerenciar_pessoas.php");
    exit();
}

$id = gen_id($_POST['id'] ?? null);
$pessoa_del = $id ? gen_pessoa($pdo, $id) : null;

if ($pessoa_del) {
    // Registra Log de exclusão antes de apagar (inclui os vínculos)
    $pessoa_del['vinculos'] = gen_snapshot_vinculos($pdo, $id);
    $detalhes_log = "Excluído: " . json_encode($pessoa_del, JSON_UNESCAPED_UNICODE);
    registrarLog($pdo, "Excluiu pessoa: " . $pessoa_del['nome_completo'], $detalhes_log);

    $docs = $pdo->prepare("SELECT caminho_arquivo FROM documentos WHERE id_pessoa = ?");
    $docs->execute([$id]);
    $arquivos = $docs->fetchAll(PDO::FETCH_COLUMN);

    // Deleta do banco. Por FK: documentos, uniões e filiações desta pessoa (como filho)
    // são apagados em cascata; nas filiações em que ela era pai/mãe o campo vira NULL.
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM pessoas WHERE id = ?")->execute([$id]);
    gen_limpar_filiacoes_vazias($pdo);
    $pdo->commit();

    // Remove arquivos do disco (avatar e documentos), só dentro de uploads/
    $base = realpath(__DIR__ . '/uploads');
    $caminhos = $arquivos;
    if (!empty($pessoa_del['foto'])) $caminhos[] = $pessoa_del['foto'];
    foreach ($caminhos as $rel) {
        $abs = realpath(__DIR__ . '/' . $rel);
        if ($abs && $base && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
            unlink($abs);
        }
    }

    header("Location: gerenciar_pessoas.php");
    exit();
}
header("Location: gerenciar_pessoas.php");
