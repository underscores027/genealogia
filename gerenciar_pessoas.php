<?php
include 'includes/auth.php';
include 'includes/db.php';
require_once 'includes/genealogia.php';

$msg = '';

// Edição rápida de filiação principal + inclusão de cônjuge via modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_filiacao') {
    $id_pessoa = gen_id($_POST['id_pessoa'] ?? null);
    $pessoa = $id_pessoa ? gen_pessoa($pdo, $id_pessoa) : null;
    $erros = [];
    if (!$pessoa) {
        $erros[] = 'Pessoa não encontrada.';
    } else {
        $pai_id = gen_id($_POST['pai_id'] ?? null);
        $mae_id = gen_id($_POST['mae_id'] ?? null);
        $tipo_pai = (string)($_POST['tipo_pai'] ?? 'biologico');
        $tipo_mae = (string)($_POST['tipo_mae'] ?? 'biologico');
        $novo_conjuge = gen_id($_POST['conjuge_id'] ?? null);
        $tipo_uniao = (string)($_POST['tipo_uniao'] ?? 'casamento');

        // A filiação editada aqui é a principal (a primeira); as demais ficam em editar_pessoa.php
        $filiacoes = gen_filiacoes($pdo, $id_pessoa);
        $principal = $filiacoes ? $filiacoes[0] : null;
        $antes = gen_snapshot_vinculos($pdo, $id_pessoa);

        $pdo->beginTransaction();
        try {
            if ($principal && !$pai_id && !$mae_id) {
                gen_remover_filiacao($pdo, $principal['id'], $id_pessoa);
            } elseif ($pai_id || $mae_id) {
                $erros = gen_salvar_filiacao($pdo, $id_pessoa, $pai_id, $mae_id, $tipo_pai, $tipo_mae, $principal ? $principal['id'] : null);
            }
            if (!$erros && $novo_conjuge) {
                $erros = gen_salvar_uniao($pdo, $id_pessoa, $novo_conjuge, $tipo_uniao);
            }
            if ($erros) { $pdo->rollBack(); } else { $pdo->commit(); }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erros[] = 'Erro ao gravar no banco: ' . $ex->getMessage();
        }
        if (!$erros) {
            registrarLog($pdo, "Editou filiações de: " . $pessoa['nome_completo'], "Vínculos antigos: " . json_encode($antes, JSON_UNESCAPED_UNICODE));
        }
    }
    if ($erros) {
        $msg = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i> <strong>Nada foi gravado.</strong><ul class="mb-0 mt-2">';
        foreach ($erros as $e) $msg .= '<li>' . htmlspecialchars($e) . '</li>';
        $msg .= '</ul></div>';
    } else {
        $msg = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Filiações atualizadas com sucesso!</div>';
    }
}

// Busca todos os membros e os vínculos de uma vez
$grafo = gen_carregar_grafo($pdo);
$membros = array_values($grafo['pessoas']);
usort($membros, function ($a, $b) { return strcasecmp($a['nome_completo'], $b['nome_completo']); });
$lookup = $grafo['pessoas'];

// Filiação principal (id e tipos) por pessoa, para o modal
$fil_principal = [];
foreach ($grafo['filiacoes'] as $f) {
    if (!isset($fil_principal[$f['filho_id']])) $fil_principal[$f['filho_id']] = $f;
}

function gp_nomes(array $ids, array $lookup) {
    if (!$ids) return '<span class="text-muted">-</span>';
    $out = [];
    foreach ($ids as $id) {
        if (isset($lookup[$id])) $out[] = '<a href="perfil.php?id=' . (int)$id . '" class="text-decoration-none text-dark">' . htmlspecialchars($lookup[$id]['nome_completo']) . '</a>';
    }
    return implode('<br>', $out);
}

$lista_js = [];
foreach ($membros as $m) { $lista_js[] = ['id' => (int)$m['id'], 'nome' => $m['nome_completo'], 'sexo' => $m['sexo']]; }

include 'includes/header.php';
?>

<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bolder mb-0">Gerenciar Pessoas</h2>
            <p class="text-muted mb-0">Total de <?php echo count($membros); ?> membros cadastrados.</p>
        </div>
        <a href="adicionar_pessoa.php" class="btn btn-primary">
            <i class="fas fa-user-plus me-2"></i>Adicionar Novo
        </a>
    </div>

    <?php if($msg) echo $msg; ?>

    <div class="card p-4">
        <!-- Busca rápida -->
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="searchInput" class="form-control border-0 bg-light" placeholder="Filtrar por nome na tabela..." onkeyup="filterTable()">
                </div>
            </div>
        </div>

        <!-- Tabela Responsiva -->
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="tabelaPessoas">
                <thead class="table-light">
                    <tr>
                        <th>Foto</th>
                        <th>Nome</th>
                        <th>Nascimento</th>
                        <th>Pai</th>
                        <th>Mãe</th>
                        <th>Cônjuge(s)</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($membros as $p):
                        $pid = (int)$p['id'];
                        $border = gen_sexo_cor($p['sexo']);
                        $icon = gen_sexo_icone($p['sexo']);
                        $pais_ids = []; $maes_ids = [];
                        foreach ($grafo['pais'][$pid] ?? [] as $f) {
                            if ($f['pai_id'] && !in_array($f['pai_id'], $pais_ids, true)) $pais_ids[] = $f['pai_id'];
                            if ($f['mae_id'] && !in_array($f['mae_id'], $maes_ids, true)) $maes_ids[] = $f['mae_id'];
                        }
                        $fp = $fil_principal[$pid] ?? null;
                    ?>
                        <tr>
                            <td>
                                <?php if(!empty($p['foto'])): ?>
                                    <img src="<?php echo htmlspecialchars($p['foto']); ?>" alt="" style="width:35px; height:35px; border-radius:50%; object-fit:cover; border:2px solid <?php echo $border; ?>;">
                                <?php else: ?>
                                    <div style="width:35px; height:35px; border-radius:50%; background:#f0f4ff; border:2px solid <?php echo $border; ?>; display:flex; align-items:center; justify-content:center;">
                                        <i class="fas <?php echo $icon; ?>" style="color: <?php echo $border; ?>; font-size:12px;"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($p['nome_completo']); ?></strong></td>
                            <td><?php $dn = gen_data_texto($p, 'data_nascimento'); echo $dn !== '' ? htmlspecialchars($dn) : '-'; ?></td>
                            <td><?php echo gp_nomes($pais_ids, $lookup); ?></td>
                            <td><?php echo gp_nomes($maes_ids, $lookup); ?></td>
                            <td><?php echo gp_nomes($grafo['conjuges'][$pid] ?? [], $lookup); ?></td>
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-filiacao" data-bs-toggle="modal" data-bs-target="#modalFiliacao"
                                        data-id="<?php echo $pid; ?>"
                                        data-nome="<?php echo htmlspecialchars($p['nome_completo'], ENT_QUOTES); ?>"
                                        data-pai="<?php echo $fp && $fp['pai_id'] ? (int)$fp['pai_id'] : ''; ?>"
                                        data-mae="<?php echo $fp && $fp['mae_id'] ? (int)$fp['mae_id'] : ''; ?>"
                                        data-tipo-pai="<?php echo htmlspecialchars($fp ? $fp['tipo_pai'] : 'biologico'); ?>"
                                        data-tipo-mae="<?php echo htmlspecialchars($fp ? $fp['tipo_mae'] : 'biologico'); ?>">
                                    <i class="fas fa-link"></i> Filiações
                                </button>
                                <a href="editar_pessoa.php?id=<?php echo $pid; ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="fas fa-pen"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de Edição Rápida de Filiações -->
<div class="modal fade" id="modalFiliacao" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="gerenciar_pessoas.php">
                <input type="hidden" name="action" value="update_filiacao">
                <input type="hidden" name="id_pessoa" id="modal_id_pessoa">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-project-diagram me-2"></i>Editar Filiações</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Editando vinculações de: <strong id="modal_nome_pessoa"></strong></p>
                    <p class="small text-muted">Aqui você edita a filiação principal e pode acrescentar um cônjuge. Para várias filiações ou para remover uniões, use <i class="fas fa-pen"></i> Editar.</p>

                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <div class="form-floating">
                                <select name="pai_id" id="select_pai" class="form-select"></select>
                                <label><i class="fas fa-male me-2 text-muted"></i>Pai</label>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-floating">
                                <select name="tipo_pai" id="select_tipo_pai" class="form-select">
                                    <?php foreach (gen_tipos_vinculo() as $k => $rot): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars(gen_rotulo_vinculo($k, 'pai')); ?></option><?php endforeach; ?>
                                </select>
                                <label>Vínculo</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <div class="form-floating">
                                <select name="mae_id" id="select_mae" class="form-select"></select>
                                <label><i class="fas fa-female me-2 text-muted"></i>Mãe</label>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-floating">
                                <select name="tipo_mae" id="select_tipo_mae" class="form-select">
                                    <?php foreach (gen_tipos_vinculo() as $k => $rot): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars(gen_rotulo_vinculo($k, 'mae')); ?></option><?php endforeach; ?>
                                </select>
                                <label>Vínculo</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <div class="form-floating">
                                <select name="conjuge_id" id="select_conjuge" class="form-select"></select>
                                <label><i class="fas fa-heart me-2 text-muted"></i>Adicionar cônjuge</label>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-floating">
                                <select name="tipo_uniao" class="form-select">
                                    <?php foreach (gen_tipos_uniao() as $k => $rot): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($rot); ?></option><?php endforeach; ?>
                                </select>
                                <label>Tipo</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Salvar Vínculos</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Dados das pessoas para preencher os selects do modal (sem innerHTML com dados do banco)
const pessoasLista = <?php echo json_encode($lista_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function preencherSelect(sel, vazio, sexoExcluido, idExcluir, valor) {
    sel.innerHTML = '';
    sel.add(new Option(vazio, ''));
    pessoasLista.forEach(p => {
        if (p.id === idExcluir) return; // Não pode ser pai/mãe/cônjuge de si mesmo
        if (sexoExcluido && p.sexo === sexoExcluido && String(p.id) !== String(valor)) return;
        sel.add(new Option(p.nome, p.id));
    });
    sel.value = valor || '';
}

document.querySelectorAll('.btn-filiacao').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = parseInt(btn.dataset.id, 10);
        document.getElementById('modal_id_pessoa').value = id;
        document.getElementById('modal_nome_pessoa').textContent = btn.dataset.nome;
        preencherSelect(document.getElementById('select_pai'), 'Nenhum', 'F', id, btn.dataset.pai);
        preencherSelect(document.getElementById('select_mae'), 'Nenhuma', 'M', id, btn.dataset.mae);
        preencherSelect(document.getElementById('select_conjuge'), 'Nenhum (manter uniões atuais)', null, id, '');
        document.getElementById('select_tipo_pai').value = btn.dataset.tipoPai || 'biologico';
        document.getElementById('select_tipo_mae').value = btn.dataset.tipoMae || 'biologico';
    });
});

// Função para filtrar a tabela em tempo real
function filterTable() {
    let input = document.getElementById('searchInput');
    let filter = input.value.toUpperCase();
    let table = document.getElementById('tabelaPessoas');
    let tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) { // Começa em 1 para pular o cabeçalho
        let td = tr[i].getElementsByTagName('td')[1]; // Coluna do Nome
        if (td) {
            let txtValue = td.textContent || td.innerText;
            if (txtValue.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
}
</script>

<?php include 'includes/footer.php'; ?>