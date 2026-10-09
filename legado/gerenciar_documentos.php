<?php
include 'includes/auth.php';
include 'includes/db.php';

 $msg = '';
 $pessoa_selecionada = null;
 $documentos = [];

// Excluir documento: só por POST (um link/imagem forjada não apaga nada)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc'])) {
    $id_doc = (int)$_POST['delete_doc'];
    $stmt_doc = $pdo->prepare("SELECT * FROM documentos WHERE id = ?");
    $stmt_doc->execute([$id_doc]);
    $doc_del = $stmt_doc->fetch();

    if ($doc_del) {
        if (file_exists(__DIR__ . '/' . $doc_del['caminho_arquivo'])) {
            unlink(__DIR__ . '/' . $doc_del['caminho_arquivo']);
        }
        $pdo->prepare("DELETE FROM documentos WHERE id = ?")->execute([$id_doc]);
        registrarLog($pdo, "Excluiu documento", "ID Doc: $id_doc, Pessoa ID: " . $doc_del['id_pessoa']);
        
        header("Location: gerenciar_documentos.php?pessoa_id=" . $doc_del['id_pessoa'] . "&msg=doc_deletado");
        exit();
    }
}

if (isset($_GET['msg']) && $_GET['msg'] == 'doc_deletado') {
    $msg = '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i> Documento excluído com sucesso!</div>';
}
if (isset($_GET['msg']) && $_GET['msg'] == 'tipo_invalido') {
    $msg = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i> Tipo de arquivo não permitido. Envie PDF, JPG, PNG ou GIF.</div>';
}
if (isset($_GET['msg']) && $_GET['msg'] == 'upload_ok') {
    $msg = '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i> Documento enviado e salvo com sucesso!</div>';
}

// Se uma pessoa foi selecionada (via busca)
if (isset($_GET['pessoa_id']) && !empty($_GET['pessoa_id'])) {
    $id_sel = $_GET['pessoa_id'];
    $stmt = $pdo->prepare("SELECT * FROM pessoas WHERE id = ?");
    $stmt->execute([$id_sel]);
    $pessoa_selecionada = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pessoa_selecionada) {
        $doc_stmt = $pdo->prepare("SELECT * FROM documentos WHERE id_pessoa = ? ORDER BY data_upload DESC");
        $doc_stmt->execute([$id_sel]);
        $documentos = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

 $pessoas_lista = $pdo->query("SELECT id, nome_completo FROM pessoas ORDER BY nome_completo")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="container-fluid p-4">
    <h2 class="fw-bolder mb-4"><i class="fas fa-photo-film me-2"></i>Central de Mídia e Documentos</h2>
    <?php if($msg) echo $msg; ?>

    <div class="row g-4">
        <!-- Coluna Esquerda: Busca e Upload -->
        <div class="col-md-4">
            <div class="card p-4 mb-4">
                <h5 class="fw-bold mb-3"><i class="fas fa-search me-2 text-primary"></i>1. Buscar Pessoa</h5>
                <form method="GET">
                    <div class="form-floating mb-3">
                        <select name="pessoa_id" class="form-select" required onchange="this.form.submit()">
                            <option value="">Selecione...</option>
                            <?php foreach($pessoas_lista as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>" <?php echo (isset($_GET['pessoa_id']) && $_GET['pessoa_id'] == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['nome_completo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label>Pessoa</label>
                    </div>
                </form>
            </div>

            <?php if($pessoa_selecionada): ?>
            <div class="card p-4">
                <h5 class="fw-bold mb-3"><i class="fas fa-upload me-2 text-success"></i>2. Anexar Arquivo</h5>
                <form action="salvar_documento.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="id_pessoa" value="<?php echo (int)$pessoa_selecionada['id']; ?>">
                    <div class="mb-3">
                        <label class="form-label small text-muted">Tipo</label>
                        <select name="tipo_documento" class="form-select form-select-sm" required>
                            <option value="Certidão de Nascimento">Certidão de Nascimento</option>
                            <option value="Certidão de Casamento">Certidão de Casamento</option>
                            <option value="Foto Antiga">Foto Antiga</option>
                            <option value="Passaporte">Passaporte</option>
                            <option value="Outro">Outro</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Descrição</label>
                        <input type="text" name="descricao" class="form-control form-control-sm" placeholder="Ex: Foto do navio">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Arquivo (PDF/JPG)</label>
                        <input type="file" name="arquivo_doc" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Enviar Arquivo</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Coluna Direita: Galeria e Gestão -->
        <div class="col-md-8">
            <div class="card p-4">
                <?php if(!$pessoa_selecionada): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-arrow-left fa-3x mb-3 opacity-50"></i>
                        <h5>Selecione uma pessoa para gerenciar os arquivos</h5>
                        <p>Após selecionar, você poderá ver, adicionar e excluir documentos.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold mb-0">Arquivos de: <?php echo htmlspecialchars($pessoa_selecionada['nome_completo']); ?></h5>
                        <a href="perfil.php?id=<?php echo (int)$pessoa_selecionada['id']; ?>" class="btn btn-sm btn-outline-secondary">Ver Perfil</a>
                    </div>

                    <?php if(count($documentos) > 0): ?>
                        <div class="row g-3">
                            <?php foreach($documentos as $doc): 
                                $ext = pathinfo($doc['caminho_arquivo'], PATHINFO_EXTENSION);
                                $is_img = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif']);
                            ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card h-100 border shadow-sm">
                                        <?php if($is_img): ?>
                                            <a href="<?php echo htmlspecialchars($doc['caminho_arquivo']); ?>" target="_blank">
                                                <img src="<?php echo htmlspecialchars($doc['caminho_arquivo']); ?>" class="card-img-top" style="height: 150px; object-fit: cover;">
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo htmlspecialchars($doc['caminho_arquivo']); ?>" target="_blank" class="d-flex align-items-center justify-content-center" style="height: 150px; background: #f8f9fa;">
                                                <i class="fas fa-file-pdf fa-4x text-danger"></i>
                                            </a>
                                        <?php endif; ?>
                                        <div class="card-body p-2">
                                            <h6 class="card-title mb-0" style="font-size: 14px;"><?php echo htmlspecialchars($doc['tipo_documento']); ?></h6>
                                            <small class="text-muted d-block mb-2"><?php echo htmlspecialchars($doc['descricao'] ?? 'Sem descrição'); ?></small>
                                            <form method="POST" action="gerenciar_documentos.php" onsubmit="return confirm('Excluir este documento?')">
                                                <input type="hidden" name="delete_doc" value="<?php echo (int)$doc['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="fas fa-trash"></i> Excluir</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
                            <p>Nenhum documento anexado para esta pessoa ainda.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>