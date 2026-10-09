<?php
include 'includes/auth.php';
include 'includes/db.php';
require_once 'includes/genealogia.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_pessoa = gen_id($_POST['id_pessoa'] ?? null);
    $descricao = $_POST['descricao'] ?? '';

    // O formulário usa rótulos diferentes dos valores do ENUM da tabela; sem este mapa
    // o tipo era gravado vazio (ou dava erro com o modo estrito do MySQL).
    $mapa_tipos = [
        'Certidão de Nascimento' => 'Certidão Nascimento',
        'Certidão de Casamento'  => 'Certidão Casamento',
        'Foto Antiga'            => 'Foto',
        'Passaporte'             => 'Passaporte',
        'Outro'                  => 'Outro',
    ];
    $tipo_form = $_POST['tipo_documento'] ?? 'Outro';
    $tipo = $mapa_tipos[$tipo_form] ?? (in_array($tipo_form, $mapa_tipos, true) ? $tipo_form : 'Outro');

    if (!$id_pessoa || !gen_pessoa($pdo, $id_pessoa)) {
        header("Location: gerenciar_documentos.php");
        exit();
    }

    $dir_docs = __DIR__ . '/uploads/documentos';
    if (!file_exists($dir_docs)) {
        mkdir($dir_docs, 0755, true);
    }

    if (isset($_FILES['arquivo_doc']) && $_FILES['arquivo_doc']['error'] == 0) {
        // Só documentos e imagens: impede envio de .php e afins para a pasta pública.
        $ext = strtolower(pathinfo($_FILES['arquivo_doc']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif'], true)) {
            header("Location: gerenciar_documentos.php?pessoa_id=" . $id_pessoa . "&msg=tipo_invalido");
            exit();
        }
        $novo_nome = 'doc_' . $id_pessoa . '_' . time() . '.' . $ext;

        $caminho_fisico = $dir_docs . '/' . $novo_nome;
        $caminho_banco = 'uploads/documentos/' . $novo_nome;

        if (move_uploaded_file($_FILES['arquivo_doc']['tmp_name'], $caminho_fisico)) {
            $stmt = $pdo->prepare("INSERT INTO documentos (id_pessoa, tipo_documento, caminho_arquivo, descricao) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id_pessoa, $tipo, $caminho_banco, $descricao]);

            // Registra o Log salvando o caminho do arquivo em formato JSON
            $detalhes_log = json_encode([
                'pessoa_id' => $id_pessoa,
                'tipo' => $tipo,
                'caminho_arquivo' => $caminho_banco
            ]);
            registrarLog($pdo, "Anexou documento", $detalhes_log);
        }
    }
    header("Location: gerenciar_documentos.php?pessoa_id=" . $id_pessoa . "&msg=upload_ok");
    exit();
}
header("Location: gerenciar_documentos.php");
