<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/db.php';
require_once 'includes/genealogia.php';

$id_pessoa = gen_id($_GET['id'] ?? null);
$p = $id_pessoa ? gen_pessoa($pdo, $id_pessoa) : null;
if (!$p) { header("Location: arvore.php"); exit(); }

$filiacoes = gen_filiacoes($pdo, $id_pessoa);
$familias = gen_familias($pdo, $id_pessoa);
$irmaos = gen_irmaos($pdo, $id_pessoa);
$eventos = gen_eventos($pdo, $id_pessoa);
$nasc_texto = gen_data_texto($p, 'data_nascimento');
$fal_texto = gen_data_texto($p, 'data_falecimento');
$total_filhos = 0;
foreach ($familias as $fam) { $total_filhos += count($fam['filhos']); }

// Busca documentos e separa em Fotos e Documentos (PDFs)
$docs_stmt = $pdo->prepare("SELECT * FROM documentos WHERE id_pessoa=? ORDER BY data_upload DESC");
$docs_stmt->execute([$id_pessoa]);
$todos_docs = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

$fotos = [];
$documentos = [];
foreach ($todos_docs as $doc) {
    $ext = strtolower(pathinfo($doc['caminho_arquivo'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
        $fotos[] = $doc;
    } else {
        $documentos[] = $doc;
    }
}

$border_color = gen_sexo_cor($p['sexo']);
$icon = gen_sexo_icone($p['sexo']);
$is_falecido = !empty($p['data_falecimento']);

function perfil_link_pessoa($id, $nome, $classe = 'text-dark text-decoration-none fw-bold fs-5') {
    return '<a href="perfil.php?id=' . (int)$id . '" class="' . $classe . '">' . htmlspecialchars($nome) . '</a>';
}
function perfil_botao_pessoa($pes, $extra = '') {
    $cor = gen_sexo_cor($pes['sexo']);
    return '<a href="perfil.php?id=' . (int)$pes['id'] . '" class="btn btn-outline-primary btn-sm" style="color: ' . $cor . '; border-color: ' . $cor . ';">'
         . '<i class="fas fa-child me-1"></i>' . htmlspecialchars($pes['nome_completo']) . $extra . '</a>';
}

include 'includes/header.php';
$is_admin = isset($_SESSION['user_id']);
?>

<div class="container my-5 py-5">
    <!-- Cabeçalho do Perfil -->
    <div class="card p-5 mb-4">
        <div class="d-flex flex-column flex-md-row align-items-center">
            <?php if(!empty($p['foto'])): ?>
                <img src="<?php echo htmlspecialchars($p['foto']); ?>" style="width:150px; height:150px; border-radius:50%; object-fit:cover; border:5px solid <?php echo $border_color; ?>; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
            <?php else: ?>
                <div style="width:150px; height:150px; border-radius:50%; background:#fff; border:5px solid <?php echo $border_color; ?>; display:flex; align-items:center; justify-content:center;">
                    <i class="fas <?php echo $icon; ?>" style="font-size:60px; color:<?php echo $border_color; ?>;"></i>
                </div>
            <?php endif; ?>

            <div class="ms-md-5 mt-4 mt-md-0 text-center text-md-start">
                <span class="badge bg-secondary mb-2"><?php echo htmlspecialchars(gen_sexo_rotulo($p['sexo'])); ?></span>
                
                <h1 class="fw-bolder mb-2">
                    <?php echo htmlspecialchars($p['nome_completo']); ?> <?php if($is_falecido) echo '<span style="color:#666;" title="Falecido">†</span>'; ?>
                </h1>
                
                <p class="text-muted mb-1"><i class="fas fa-baby me-2"></i> Nascimento: <?php echo $nasc_texto !== '' ? htmlspecialchars($nasc_texto) : '?'; ?><?php echo !empty($p['local_nascimento']) ? ', em ' . htmlspecialchars($p['local_nascimento']) : ''; ?></p>

                <?php if($is_falecido): ?>
                    <p class="text-muted mb-1"><i class="fas fa-cross me-2"></i> Falecimento: <?php echo htmlspecialchars($fal_texto); ?><?php echo !empty($p['local_falecimento']) ? ', em ' . htmlspecialchars($p['local_falecimento']) : ''; ?></p>
                <?php endif; ?>

                <div class="d-flex gap-2 justify-content-center justify-content-md-start mt-3">
                    <a href="arvore.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-sitemap me-1"></i> Ver na árvore</a>
                </div>

                <?php if($is_admin): ?>
                    <div class="d-flex gap-2 justify-content-center justify-content-md-start mt-3">
                        <a href="editar_pessoa.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-pen me-1"></i> Editar</a>
                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="fas fa-trash me-1"></i> Excluir</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SEÇÕES EMPILHADAS (COL-12) -->
    
    <!-- 1. Biografia -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card p-4 h-100">
                <h4 class="fw-bold mb-3"><i class="fas fa-book me-2"></i>Biografia</h4>
                <p class="text-muted mb-0" style="line-height: 1.8;">
                    <?php echo !empty($p['biografia']) ? nl2br(htmlspecialchars($p['biografia'])) : 'Nenhuma biografia cadastrada para esta pessoa.'; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- 1b. Eventos (imigração, batismo...) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card p-4 h-100">
                <h4 class="fw-bold mb-3"><i class="fas fa-calendar-alt me-2"></i>Eventos</h4>
                <?php if (!$eventos): ?>
                    <p class="text-muted mb-0">Nenhum evento registrado<?php echo $is_admin ? ' (adicione em Editar).' : '.'; ?></p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($eventos as $ev): $dt = gen_data_texto($ev, 'data'); ?>
                            <li class="d-flex mb-3">
                                <i class="fas <?php echo htmlspecialchars(gen_evento_icone($ev['tipo'])); ?> fa-fw mt-1 me-2"></i>
                                <div>
                                    <strong><?php echo htmlspecialchars(gen_rotulo_evento($ev['tipo'])); ?></strong><?php
                                        if ($dt !== '') echo ' — ' . htmlspecialchars($dt);
                                        if (!empty($ev['local'])) echo ', ' . htmlspecialchars($ev['local']);
                                        if (!empty($ev['conjuge_id']) && $ev['uniao_id']) echo ' <small class="text-muted">(com ' . perfil_link_pessoa($ev['conjuge_id'], $ev['conjuge_nome'], 'text-muted') . ')</small>';
                                    ?>
                                    <?php if (!empty($ev['descricao'])): ?><div class="text-muted small"><?php echo nl2br(htmlspecialchars($ev['descricao'])); ?></div><?php endif; ?>
                                    <?php if (!empty($ev['documento_id'])): ?>
                                        <div class="small"><i class="fas fa-paperclip me-1 text-muted"></i>Fonte:
                                            <a href="<?php echo htmlspecialchars($ev['documento_caminho']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(gen_rotulo_documento(['id' => $ev['documento_id'], 'tipo_documento' => $ev['documento_tipo'], 'descricao' => $ev['documento_descricao']])); ?></a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 2. Família Direta -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card p-4 h-100">
                <h4 class="fw-bold mb-3"><i class="fas fa-users me-2"></i>Família Direta</h4>
                <div class="row g-4">
                    <!-- Pais (uma linha por filiação) -->
                    <?php if (count($filiacoes) === 0): ?>
                        <div class="col-md-4">
                            <small class="text-muted d-block mb-1"><i class="fas fa-male me-1"></i> Pai</small>
                            <span class="text-muted fs-5">Desconhecido</span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block mb-1"><i class="fas fa-female me-1"></i> Mãe</small>
                            <span class="text-muted fs-5">Desconhecida</span>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($filiacoes as $f): ?>
                        <div class="col-md-4">
                            <small class="text-muted d-block mb-1"><i class="fas fa-male me-1"></i> Pai
                                <?php if ($f['pai_id'] && $f['tipo_pai'] !== 'biologico'): ?><span class="badge bg-light text-secondary border ms-1"><?php echo htmlspecialchars(gen_rotulo_vinculo($f['tipo_pai'], 'pai')); ?></span><?php endif; ?>
                            </small>
                            <?php echo $f['pai_id'] ? perfil_link_pessoa($f['pai_id'], $f['pai_nome']) : '<span class="text-muted fs-5">Desconhecido</span>'; ?>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block mb-1"><i class="fas fa-female me-1"></i> Mãe
                                <?php if ($f['mae_id'] && $f['tipo_mae'] !== 'biologico'): ?><span class="badge bg-light text-secondary border ms-1"><?php echo htmlspecialchars(gen_rotulo_vinculo($f['tipo_mae'], 'mae')); ?></span><?php endif; ?>
                            </small>
                            <?php echo $f['mae_id'] ? perfil_link_pessoa($f['mae_id'], $f['mae_nome']) : '<span class="text-muted fs-5">Desconhecida</span>'; ?>
                        </div>
                        <div class="col-md-4 d-none d-md-block"></div>
                    <?php endforeach; ?>

                    <!-- Cônjuges e filhos por união -->
                    <div class="col-12 mt-2">
                        <small class="text-muted d-block mb-2"><i class="fas fa-heart me-1"></i> Cônjuges e filhos (<?php echo $total_filhos; ?> <?php echo $total_filhos == 1 ? 'filho' : 'filhos'; ?>)</small>
                        <?php if (count($familias) === 0): ?>
                            <span class="text-muted">Nenhum cônjuge ou filho cadastrado</span>
                        <?php endif; ?>
                        <?php foreach ($familias as $fam): ?>
                            <div class="mb-3 ps-3" style="border-left: 3px solid #eee;">
                                <div class="mb-2">
                                    <?php if ($fam['uniao']): $u = $fam['uniao']; ?>
                                        <i class="fas fa-heart me-1"></i>
                                        <?php echo perfil_link_pessoa($u['conjuge_id'], $u['conjuge_nome'], 'text-dark text-decoration-none fw-bold'); ?>
                                        <small class="text-muted ms-1">
                                            (<?php echo htmlspecialchars(gen_rotulo_uniao($u['tipo'])); ?><?php
                                            if (!empty($u['data_inicio'])) echo ' em ' . htmlspecialchars(gen_data_texto($u, 'data_inicio'));
                                            if (!empty($u['local_inicio'])) echo ', ' . htmlspecialchars($u['local_inicio']);
                                            if (!empty($u['data_fim'])) echo ' — até ' . htmlspecialchars(gen_data_texto($u, 'data_fim'));
                                            ?>)
                                        </small>
                                    <?php elseif ($fam['conjuge_id']): ?>
                                        <i class="fas fa-user-friends me-1 text-muted"></i> Filhos com
                                        <?php echo perfil_link_pessoa($fam['conjuge_id'], $fam['conjuge_nome'] ?? '?', 'text-dark text-decoration-none fw-bold'); ?>
                                        <small class="text-muted">(sem união registrada)</small>
                                    <?php else: ?>
                                        <i class="fas fa-question-circle me-1 text-muted"></i> <span class="text-muted">Filhos sem o outro genitor registrado</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (count($fam['filhos']) > 0): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($fam['filhos'] as $fi):
                                            $extra = $fi['tipo'] !== 'biologico' ? ' <small>(' . htmlspecialchars(gen_rotulo_vinculo($fi['tipo'], $fi['papel'])) . ')</small>' : '';
                                            echo perfil_botao_pessoa($fi, $extra);
                                        endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">Sem filhos cadastrados nesta união</small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Irmãos -->
                    <div class="col-12">
                        <small class="text-muted d-block mb-2"><i class="fas fa-people-arrows me-1"></i> Irmãos (<?php echo count($irmaos); ?>)</small>
                        <?php if (count($irmaos) > 0): ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($irmaos as $ir) echo perfil_botao_pessoa($ir, $ir['tipo'] === 'meio' ? ' <small>(meio-irmão)</small>' : ''); ?>
                            </div>
                        <?php else: echo '<span class="text-muted">Nenhum irmão cadastrado</span>'; endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Mídia (Com Abas e Altura Limitada) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="fw-bold mb-0"><i class="fas fa-photo-film me-2"></i>Mídia</h4>
                    <?php if($is_admin): ?>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#docModal"><i class="fas fa-plus me-1"></i> Anexar</button>
                    <?php endif; ?>
                </div>

                <!-- Menu de Abas (Tabs) -->
                <ul class="nav nav-tabs mb-3" id="mediaTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="fotos-tab" data-bs-toggle="tab" data-bs-target="#fotos" type="button" role="tab">
                            <i class="fas fa-images me-1"></i> Galeria de Fotos
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="docs-tab" data-bs-toggle="tab" data-bs-target="#docs" type="button" role="tab">
                            <i class="fas fa-file-alt me-1"></i> Documentos
                        </button>
                    </li>
                </ul>

                <!-- Conteúdo das Abas com altura máxima e rolagem -->
                <div class="tab-content" id="mediaTabsContent" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                    
                    <!-- Aba Galeria de Fotos -->
                    <div class="tab-pane fade show active" id="fotos" role="tabpanel">
                        <?php if(count($fotos) > 0): ?>
                            <div class="row g-3">
                                <?php foreach($fotos as $foto): ?>
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <div class="card h-100 border-0 shadow-sm rounded-3">
                                            <a href="<?php echo htmlspecialchars($foto['caminho_arquivo']); ?>" target="_blank">
                                                <img src="<?php echo htmlspecialchars($foto['caminho_arquivo']); ?>" class="card-img-top rounded-top-3" style="height: 150px; object-fit: cover;">
                                            </a>
                                            <div class="card-body p-2">
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($foto['tipo_documento']); ?></small>
                                                <small class="text-muted d-block" style="font-size: 10px;">
                                                    <i class="fas fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($foto['data_upload'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-image fa-3x mb-3 opacity-50"></i>
                                <p>Nenhuma foto cadastrada.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Aba Documentos -->
                    <div class="tab-pane fade" id="docs" role="tabpanel">
                        <?php if(count($documentos) > 0): ?>
                            <div class="list-group">
                                <?php foreach($documentos as $doc): ?>
                                    <a href="<?php echo htmlspecialchars($doc['caminho_arquivo']); ?>" target="_blank" class="list-group-item list-group-item-action d-flex align-items-center">
                                        <i class="fas fa-file-pdf fa-2x me-3 text-danger"></i>
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($doc['tipo_documento']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($doc['descricao'] ?? 'Ver arquivo'); ?></small>
                                            <small class="text-muted d-block" style="font-size: 10px;">
                                                <i class="fas fa-clock me-1"></i> Adicionado em: <?php echo date('d/m/Y H:i', strtotime($doc['data_upload'])); ?>
                                            </small>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
                                <p>Nenhum documento (PDF) anexado.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
    </div>

</div>

<?php if($is_admin): ?>
<div class="modal fade" id="docModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="salvar_documento.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Anexar Arquivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_pessoa" value="<?php echo (int)$p['id']; ?>">
                    <div class="mb-3">
                        <label class="form-label">Tipo de Arquivo</label>
                        <select name="tipo_documento" class="form-select" required>
                            <option value="Certidão de Nascimento">Certidão de Nascimento</option>
                            <option value="Certidão de Casamento">Certidão de Casamento</option>
                            <option value="Foto Antiga">Foto Antiga</option>
                            <option value="Passaporte">Passaporte</option>
                            <option value="Outro">Outro</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição (Opcional)</label>
                        <input type="text" name="descricao" class="form-control" placeholder="Ex: Foto do navio em 1889">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Arquivo (PDF, JPG, PNG)</label>
                        <input type="file" name="arquivo_doc" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-save me-1"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirmar Exclusão</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Você tem certeza que deseja excluir <strong><?php echo htmlspecialchars($p['nome_completo']); ?></strong>?</p>
                <p class="text-danger small">Esta ação não pode ser desfeita. Todos os documentos vinculados também serão apagados.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" action="deletar_pessoa.php" class="d-inline"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i> Excluir Definitivamente</button></form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>