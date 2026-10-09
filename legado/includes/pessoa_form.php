<?php
/**
 * Formulário de pessoa compartilhado por adicionar_pessoa.php e editar_pessoa.php.
 * Os dois fluxos têm exatamente o mesmo comportamento: dados da pessoa, foto,
 * datas aproximadas (qualificador/precisão/faixa), várias filiações (pai/mãe com tipo
 * de vínculo), várias uniões, eventos (batismo, imigração...) e o atalho
 * "vincular como pai/mãe de um filho". Regras de negócio ficam em genealogia.php.
 */
require_once __DIR__ . '/genealogia.php';

function pf_e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Campos de data (texto do formulário + qualificador/precisão/até) de uma pessoa. */
function pf_campos_data_pessoa() {
    return ['data_nascimento', 'data_falecimento'];
}

/** Estado vazio do formulário (nova pessoa). As datas ficam como texto digitável. */
function pf_estado_vazio() {
    $pessoa = [
        'nome_completo' => '', 'sexo' => 'D', 'foto' => null,
        'data_nascimento' => '', 'local_nascimento' => '',
        'data_falecimento' => '', 'local_falecimento' => '', 'biografia' => '',
    ];
    foreach (pf_campos_data_pessoa() as $c) {
        $pessoa[$c . '_qualificador'] = 'exata';
        $pessoa[$c . '_precisao'] = 'dia';
        $pessoa[$c . '_ate'] = '';
    }
    return [
        'pessoa' => $pessoa,
        'filiacoes' => [],
        'unioes' => [],
        'eventos' => [],
        'vinculo' => ['filho_id' => null, 'papel' => '', 'tipo' => 'biologico'],
    ];
}

/** Uma data do banco (4 colunas) no formato do formulário: ['', '_qualificador', '_precisao', '_ate'] => valores. */
function pf_data_do_banco(array $linha, $campo) {
    $v = gen_data_campo($linha, $campo);
    return [
        $campo => $v['data'] ? gen_data_para_campo($v['data'], $v['precisao']) : '',
        $campo . '_qualificador' => $v['qualificador'],
        $campo . '_precisao' => $v['precisao'],
        $campo . '_ate' => $v['ate'] ? gen_data_para_campo($v['ate'], $v['precisao']) : '',
    ];
}

/** Lê do POST (ou de uma linha do POST) os 4 campos de uma data, como texto. */
function pf_data_do_post(array $src, $campo) {
    $g = function ($k, $padrao) use ($src) {
        return isset($src[$k]) && !is_array($src[$k]) ? trim((string)$src[$k]) : $padrao;
    };
    return [
        $campo => $g($campo, ''),
        $campo . '_qualificador' => $g($campo . '_qualificador', 'exata'),
        $campo . '_precisao' => $g($campo . '_precisao', 'dia'),
        $campo . '_ate' => $g($campo . '_ate', ''),
    ];
}

/** Normaliza uma data do estado do formulário: ['valor' => [data, qualificador, precisao, ate], 'erros' => []]. */
function pf_ler_data(array $src, $campo, $rotulo) {
    return gen_ler_data_aprox([
        'data' => $src[$campo] ?? '', 'qualificador' => $src[$campo . '_qualificador'] ?? 'exata',
        'precisao' => $src[$campo . '_precisao'] ?? 'dia', 'ate' => $src[$campo . '_ate'] ?? '',
    ], $rotulo);
}

/** Converte um valor normalizado em colunas: [campo => data, campo_qualificador => ...]. */
function pf_colunas_data($campo, array $v) {
    return [
        $campo => $v['data'], $campo . '_qualificador' => $v['qualificador'],
        $campo . '_precisao' => $v['precisao'], $campo . '_ate' => $v['ate'],
    ];
}

/** Estado do formulário a partir do banco. */
function pf_estado_do_banco(PDO $pdo, $id) {
    $p = gen_pessoa($pdo, $id);
    if (!$p) return null;
    $e = pf_estado_vazio();
    foreach ($e['pessoa'] as $k => $_) { $e['pessoa'][$k] = $p[$k] ?? ''; }
    foreach (pf_campos_data_pessoa() as $c) { $e['pessoa'] = array_merge($e['pessoa'], pf_data_do_banco($p, $c)); }
    foreach (gen_filiacoes($pdo, $id) as $f) {
        $e['filiacoes'][] = [
            'id' => (int)$f['id'], 'pai_id' => $f['pai_id'] ? (int)$f['pai_id'] : null, 'mae_id' => $f['mae_id'] ? (int)$f['mae_id'] : null,
            'tipo_pai' => $f['tipo_pai'], 'tipo_mae' => $f['tipo_mae'], 'remover' => false,
        ];
    }
    foreach (gen_unioes($pdo, $id) as $u) {
        $e['unioes'][] = array_merge([
            'id' => (int)$u['id'], 'conjuge_id' => (int)$u['conjuge_id'], 'tipo' => $u['tipo'],
            'local_inicio' => $u['local_inicio'] ?? '', 'remover' => false,
        ], pf_data_do_banco($u, 'data_inicio'), pf_data_do_banco($u, 'data_fim'));
    }
    foreach (gen_eventos($pdo, $id) as $ev) {
        $e['eventos'][] = array_merge([
            'id' => (int)$ev['id'], 'tipo' => $ev['tipo'], 'local' => $ev['local'] ?? '', 'descricao' => $ev['descricao'] ?? '',
            'uniao_id' => $ev['uniao_id'] ? (int)$ev['uniao_id'] : null, 'documento_id' => $ev['documento_id'] ? (int)$ev['documento_id'] : null,
            'remover' => false,
        ], pf_data_do_banco($ev, 'data'));
    }
    return $e;
}

/** Estado do formulário a partir do POST (para reprocessar ou reexibir após erro). */
function pf_estado_do_post(array $post, $foto_atual = null) {
    $e = pf_estado_vazio();
    foreach ($e['pessoa'] as $k => $_) {
        if ($k === 'foto') continue;
        $e['pessoa'][$k] = isset($post[$k]) && !is_array($post[$k]) ? trim((string)$post[$k]) : $e['pessoa'][$k];
    }
    $e['pessoa']['foto'] = $foto_atual;
    if (!isset(gen_sexos()[$e['pessoa']['sexo']])) $e['pessoa']['sexo'] = 'D';

    foreach ((isset($post['filiacoes']) && is_array($post['filiacoes'])) ? $post['filiacoes'] : [] as $f) {
        if (!is_array($f)) continue;
        $e['filiacoes'][] = [
            'id' => gen_id($f['id'] ?? null),
            'pai_id' => gen_id($f['pai_id'] ?? null),
            'mae_id' => gen_id($f['mae_id'] ?? null),
            'tipo_pai' => (string)($f['tipo_pai'] ?? 'biologico'),
            'tipo_mae' => (string)($f['tipo_mae'] ?? 'biologico'),
            'remover' => !empty($f['remover']),
        ];
    }
    foreach ((isset($post['unioes']) && is_array($post['unioes'])) ? $post['unioes'] : [] as $u) {
        if (!is_array($u)) continue;
        $e['unioes'][] = array_merge([
            'id' => gen_id($u['id'] ?? null),
            'conjuge_id' => gen_id($u['conjuge_id'] ?? null),
            'tipo' => (string)($u['tipo'] ?? 'casamento'),
            'local_inicio' => trim((string)($u['local_inicio'] ?? '')),
            'remover' => !empty($u['remover']),
        ], pf_data_do_post($u, 'data_inicio'), pf_data_do_post($u, 'data_fim'));
    }
    foreach ((isset($post['eventos']) && is_array($post['eventos'])) ? $post['eventos'] : [] as $ev) {
        if (!is_array($ev)) continue;
        $e['eventos'][] = array_merge([
            'id' => gen_id($ev['id'] ?? null),
            'tipo' => (string)($ev['tipo'] ?? 'outro'),
            'local' => isset($ev['local']) && !is_array($ev['local']) ? trim((string)$ev['local']) : '',
            'descricao' => isset($ev['descricao']) && !is_array($ev['descricao']) ? trim((string)$ev['descricao']) : '',
            'uniao_id' => gen_id($ev['uniao_id'] ?? null),
            'documento_id' => gen_id($ev['documento_id'] ?? null),
            'remover' => !empty($ev['remover']),
        ], pf_data_do_post($ev, 'data'));
    }
    $e['vinculo'] = [
        'filho_id' => gen_id($post['id_filho'] ?? null),
        'papel' => in_array($post['tipo_pai_mae'] ?? '', ['pai', 'mae'], true) ? $post['tipo_pai_mae'] : '',
        'tipo' => (string)($post['tipo_vinculo_filho'] ?? 'biologico'),
    ];
    return $e;
}

/** Linha de evento sem nada preenchido (descartada no processamento). */
function pf_evento_vazio(array $ev) {
    return trim((string)$ev['data']) === '' && trim((string)$ev['local']) === '' && trim((string)$ev['descricao']) === '' && empty($ev['documento_id']);
}

/** Confere o upload de foto. Devolve ['erro' => string|null, 'ext' => string|null]. */
function pf_validar_upload($arq) {
    if (!$arq || !isset($arq['error']) || $arq['error'] === UPLOAD_ERR_NO_FILE) return ['erro' => null, 'ext' => null];
    if ($arq['error'] !== UPLOAD_ERR_OK) return ['erro' => 'Falha no envio da foto (código ' . (int)$arq['error'] . ').', 'ext' => null];
    $ext = strtolower(pathinfo($arq['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'], true)) {
        return ['erro' => 'A foto deve ser uma imagem (JPG, PNG, GIF ou WEBP).', 'ext' => null];
    }
    if (@getimagesize($arq['tmp_name']) === false) return ['erro' => 'O arquivo enviado não é uma imagem válida.', 'ext' => null];
    return ['erro' => null, 'ext' => $ext];
}

/**
 * Processa o POST do formulário. $id_pessoa null = criar pessoa nova.
 * Tudo (pessoa + vínculos + eventos) é gravado numa transação: qualquer erro desfaz tudo.
 * Devolve ['ok' => bool, 'erros' => [], 'avisos' => [], 'id' => int|null, 'estado' => estado do POST].
 */
function pf_processar(PDO $pdo, $id_pessoa, array $post, array $files) {
    $id_pessoa = gen_id($id_pessoa);
    $old = $id_pessoa ? gen_pessoa($pdo, $id_pessoa) : null;
    $estado = pf_estado_do_post($post, $old ? $old['foto'] : null);
    $res = ['ok' => false, 'erros' => [], 'avisos' => [], 'id' => $id_pessoa, 'estado' => $estado];
    if ($id_pessoa && !$old) { $res['erros'][] = 'Pessoa não encontrada.'; return $res; }

    // Datas aproximadas → colunas (AAAA-MM-DD + qualificador/precisão/até)
    $d = $estado['pessoa'];
    $erros = [];
    $dn = $d;
    foreach (['data_nascimento' => 'nascimento', 'data_falecimento' => 'falecimento'] as $campo => $rot) {
        $r = pf_ler_data($d, $campo, $rot);
        $erros = array_merge($erros, $r['erros']);
        $dn = array_merge($dn, pf_colunas_data($campo, $r['valor']));
    }
    if (!$erros) {
        $erros = gen_validar_dados_pessoa($dn);
        $res['avisos'] = array_merge($res['avisos'], gen_validar_datas_pessoa($dn)['avisos']);
    } elseif (trim((string)$d['nome_completo']) === '') {
        $erros[] = 'O nome completo é obrigatório.';
    }
    $up = pf_validar_upload($files['foto_upload'] ?? null);
    if ($up['erro']) $erros[] = $up['erro'];
    if ($erros) { $res['erros'] = $erros; $res['avisos'] = []; return $res; }

    $old_vinculos = $id_pessoa ? gen_snapshot_vinculos($pdo, $id_pessoa) : null;
    $fil_existentes = []; $uni_existentes = []; $ev_existentes = [];
    if ($old_vinculos) {
        foreach ($old_vinculos['filiacoes'] as $f) $fil_existentes[$f['id']] = true;
        foreach ($old_vinculos['unioes'] as $u) $uni_existentes[$u['id']] = true;
        foreach ($old_vinculos['eventos'] as $ev) $ev_existentes[$ev['id']] = true;
    }

    $cols = ['nome_completo', 'sexo', 'data_nascimento', 'data_nascimento_qualificador', 'data_nascimento_precisao', 'data_nascimento_ate',
             'local_nascimento', 'data_falecimento', 'data_falecimento_qualificador', 'data_falecimento_precisao', 'data_falecimento_ate',
             'local_falecimento', 'biografia'];
    $campos = [];
    foreach ($cols as $c) {
        $v = $dn[$c];
        if (in_array($c, ['local_nascimento', 'local_falecimento', 'biografia'], true) && $v === '') $v = null;
        $campos[] = $v;
    }

    $pdo->beginTransaction();
    try {
        if ($id_pessoa) {
            $erros = array_merge($erros, gen_validar_sexo_compativel($pdo, $id_pessoa, $d['sexo']));
            $st = $pdo->prepare("UPDATE pessoas SET " . implode(' = ?, ', $cols) . " = ? WHERE id = ?");
            $st->execute(array_merge($campos, [$id_pessoa]));
        } else {
            $st = $pdo->prepare("INSERT INTO pessoas (" . implode(', ', $cols) . ") VALUES (" . gen_placeholders(count($cols)) . ")");
            $st->execute($campos);
            $id_pessoa = (int)$pdo->lastInsertId();
        }

        // ---- Filiações: remoções, depois alterações, depois inclusões
        $fil = $estado['filiacoes'];
        foreach ($fil as $i => $f) {
            if ($f['id'] && !isset($fil_existentes[$f['id']])) { $erros[] = 'Filiação ' . ($i + 1) . ': vínculo inválido.'; $fil[$i]['ignorar'] = true; continue; }
            if ($f['id'] && ($f['remover'] || (!$f['pai_id'] && !$f['mae_id']))) {
                gen_remover_filiacao($pdo, $f['id'], $id_pessoa);
                $fil[$i]['ignorar'] = true;
            }
        }
        foreach ($fil as $i => $f) {
            if (!empty($f['ignorar']) || !$f['id']) continue;
            foreach (gen_salvar_filiacao($pdo, $id_pessoa, $f['pai_id'], $f['mae_id'], $f['tipo_pai'], $f['tipo_mae'], $f['id']) as $m) {
                $erros[] = 'Filiação ' . ($i + 1) . ': ' . $m;
            }
        }
        foreach ($fil as $i => $f) {
            if (!empty($f['ignorar']) || $f['id'] || $f['remover'] || (!$f['pai_id'] && !$f['mae_id'])) continue;
            foreach (gen_salvar_filiacao($pdo, $id_pessoa, $f['pai_id'], $f['mae_id'], $f['tipo_pai'], $f['tipo_mae']) as $m) {
                $erros[] = 'Filiação ' . ($i + 1) . ': ' . $m;
            }
        }

        // ---- Uniões: remoções, depois alterações, depois inclusões (datas aproximadas)
        $uni = $estado['unioes'];
        foreach ($uni as $i => $u) {
            if ($u['id'] && !isset($uni_existentes[$u['id']])) { $erros[] = 'Cônjuge ' . ($i + 1) . ': vínculo inválido.'; $uni[$i]['ignorar'] = true; continue; }
            if ($u['id'] && ($u['remover'] || !$u['conjuge_id'])) {
                gen_remover_uniao($pdo, $u['id'], $id_pessoa);
                $uni[$i]['ignorar'] = true;
                continue;
            }
            if ($u['remover'] || !$u['conjuge_id']) continue;
            $aprox = [];
            foreach (['data_inicio' => 'início da união', 'data_fim' => 'fim da união'] as $campo => $rot) {
                $r = pf_ler_data($u, $campo, $rot);
                foreach ($r['erros'] as $m) $erros[] = 'Cônjuge ' . ($i + 1) . ': ' . $m;
                $aprox = array_merge($aprox, pf_colunas_data($campo, $r['valor']));
            }
            $uni[$i]['_aprox'] = $aprox;
        }
        foreach ([true, false] as $alteracao) {
            foreach ($uni as $i => $u) {
                if (!empty($u['ignorar']) || $u['remover'] || !$u['conjuge_id'] || !isset($u['_aprox'])) continue;
                if ($alteracao !== (bool)$u['id']) continue;
                $a = $u['_aprox'];
                foreach (gen_salvar_uniao($pdo, $id_pessoa, $u['conjuge_id'], $u['tipo'], $a['data_inicio'], $u['local_inicio'], $a['data_fim'], $u['id'] ?: null, $a) as $m) {
                    $erros[] = 'Cônjuge ' . ($i + 1) . ': ' . $m;
                }
            }
        }

        // ---- Eventos: remoções, alterações e inclusões
        foreach ($estado['eventos'] as $i => $ev) {
            $rot = 'Evento ' . ($i + 1) . ': ';
            if ($ev['id'] && !isset($ev_existentes[$ev['id']])) { $erros[] = $rot . 'evento inválido.'; continue; }
            if ($ev['id'] && $ev['remover']) { gen_remover_evento($pdo, $ev['id'], $id_pessoa); continue; }
            if (!$ev['id'] && ($ev['remover'] || pf_evento_vazio($ev))) continue;
            $lido = gen_ler_evento($ev, 'do evento ' . ($i + 1));
            foreach ($lido['erros'] as $m) $erros[] = $rot . $m;
            if ($lido['erros']) continue;
            foreach (gen_salvar_evento($pdo, $id_pessoa, $lido['valor'], $ev['id']) as $m) $erros[] = $rot . $m;
        }

        // ---- Vincular como pai/mãe de um filho já cadastrado
        $v = $estado['vinculo'];
        if ($v['filho_id']) {
            foreach (gen_vincular_como_genitor($pdo, $id_pessoa, $v['filho_id'], $v['papel'], $v['tipo']) as $m) {
                $erros[] = 'Vincular filho: ' . $m;
            }
        }

        // ---- Filho não nasce antes dos pais (erro com datas exatas; aviso com aproximadas)
        if (!$erros) {
            $chk = gen_verificar_nascimento_pais($pdo, $id_pessoa);
            $erros = array_merge($erros, $chk['erros']);
            $res['avisos'] = array_merge($res['avisos'], $chk['avisos']);
        }

        if ($erros) {
            $pdo->rollBack();
            $res['erros'] = $erros;
            $res['avisos'] = [];
            $res['id'] = $old ? $old['id'] : null;
            return $res;
        }
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $res['erros'][] = 'Erro ao gravar no banco: ' . $ex->getMessage();
        $res['avisos'] = [];
        $res['id'] = $old ? $old['id'] : null;
        return $res;
    }

    // ---- Foto (depois do commit; arquivo só é movido se tudo deu certo)
    if ($up['ext']) {
        $dir = dirname(__DIR__) . '/uploads/avatar';
        if (!file_exists($dir)) { mkdir($dir, 0755, true); }
        $nome_arq = 'pessoa_' . $id_pessoa . '_' . time() . '.' . $up['ext'];
        if (move_uploaded_file($files['foto_upload']['tmp_name'], $dir . '/' . $nome_arq)) {
            $pdo->prepare("UPDATE pessoas SET foto = ? WHERE id = ?")->execute(['uploads/avatar/' . $nome_arq, $id_pessoa]);
        } else {
            $res['avisos'][] = 'Os dados foram salvos, mas não foi possível gravar a foto.';
        }
    }

    // ---- Log
    $nome = $d['nome_completo'];
    if ($old) {
        $old['vinculos'] = $old_vinculos;
        registrarLog($pdo, "Editou dados de: $nome", "Dados antigos: " . json_encode($old, JSON_UNESCAPED_UNICODE));
    } else {
        registrarLog($pdo, "Cadastrou novo membro", "Nome: $nome (ID: $id_pessoa)");
    }
    if ($estado['vinculo']['filho_id']) {
        registrarLog($pdo, "Vinculou paternidade/maternidade",
            "Pessoa: $nome (ID: $id_pessoa) definida como {$estado['vinculo']['papel']} ({$estado['vinculo']['tipo']}) de ID: {$estado['vinculo']['filho_id']}");
    }

    $res['ok'] = true;
    $res['id'] = $id_pessoa;
    return $res;
}

// ---------------------------------------------------------------------
// Renderização
// ---------------------------------------------------------------------

/** <option>s de pessoas. $filtro: null | 'pai' (exclui F) | 'mae' (exclui M). */
function pf_options_pessoas(array $lista, $id_excluir, $selecionado, $filtro = null) {
    $h = '';
    foreach ($lista as $ps) {
        $pid = (int)$ps['id'];
        if ($id_excluir && $pid === (int)$id_excluir) continue;
        if ($filtro === 'pai' && $ps['sexo'] === 'F' && $pid !== (int)$selecionado) continue;
        if ($filtro === 'mae' && $ps['sexo'] === 'M' && $pid !== (int)$selecionado) continue;
        $sel = ($selecionado && $pid === (int)$selecionado) ? ' selected' : '';
        $h .= '<option value="' . $pid . '"' . $sel . '>' . pf_e($ps['nome_completo']) . '</option>';
    }
    return $h;
}

function pf_options_tipos(array $tipos, $selecionado) {
    $h = '';
    foreach ($tipos as $k => $rot) {
        $h .= '<option value="' . pf_e($k) . '"' . ((string)$k === (string)$selecionado ? ' selected' : '') . '>' . pf_e($rot) . '</option>';
    }
    return $h;
}

/**
 * Campo de data aproximada: qualificador + data (DD/MM/AAAA, MM/AAAA ou AAAA) + fim da faixa
 * (só com "Entre") + precisão. $nome = nome base do input (ex.: "data_nascimento" ou
 * "unioes[0][data_inicio]"); $v = valores [base, base_qualificador, base_precisao, base_ate].
 */
function pf_campo_data($nome, $chave, array $v, $rotulo, $icone = 'fa-calendar') {
    $q = $v[$chave . '_qualificador'] ?? 'exata';
    $p = $v[$chave . '_precisao'] ?? 'dia';
    $entre = $q === 'entre';
    $n = function ($suf) use ($nome) {
        // "unioes[0][data_inicio]" + "_qualificador" → "unioes[0][data_inicio_qualificador]"
        if (substr($nome, -1) === ']') return substr($nome, 0, -1) . $suf . ']';
        return $nome . $suf;
    };
    ob_start(); ?>
    <div class="pf-data">
        <label class="form-label small text-muted mb-1"><i class="fas <?php echo pf_e($icone); ?> me-1"></i><?php echo pf_e($rotulo); ?></label>
        <div class="input-group input-group-sm">
            <select name="<?php echo pf_e($n('_qualificador')); ?>" class="form-select pf-q" style="max-width: 8.5rem;" aria-label="Qualificador da data"><?php echo pf_options_tipos(gen_qualificadores(), $q); ?></select>
            <input type="text" name="<?php echo pf_e($nome); ?>" class="form-control pf-d" value="<?php echo pf_e($v[$chave] ?? ''); ?>" placeholder="DD/MM/AAAA" inputmode="numeric" maxlength="10" aria-label="<?php echo pf_e($rotulo); ?>">
            <span class="input-group-text pf-ate-rot"<?php echo $entre ? '' : ' hidden'; ?>>e</span>
            <input type="text" name="<?php echo pf_e($n('_ate')); ?>" class="form-control pf-ate" value="<?php echo pf_e($v[$chave . '_ate'] ?? ''); ?>" placeholder="fim da faixa" inputmode="numeric" maxlength="10" aria-label="Fim da faixa"<?php echo $entre ? '' : ' hidden'; ?>>
            <select name="<?php echo pf_e($n('_precisao')); ?>" class="form-select pf-p" style="max-width: 5.5rem;" title="Precisão (dia, mês ou só o ano)" aria-label="Precisão da data"><?php echo pf_options_tipos(gen_precisoes(), $p); ?></select>
        </div>
    </div>
    <?php return ob_get_clean();
}

function pf_linha_filiacao($idx, array $f, array $lista, $id_pessoa) {
    $tipos = gen_tipos_vinculo();
    $tipos_mae = $tipos; $tipos_mae['padrasto'] = 'Madrasta';
    $tipos_pai = $tipos; $tipos_pai['padrasto'] = 'Padrasto';
    $n = 'filiacoes[' . $idx . ']';
    ob_start(); ?>
    <div class="border rounded-3 p-3 mb-2 linha-vinculo">
        <input type="hidden" name="<?php echo $n; ?>[id]" value="<?php echo $f['id'] ? (int)$f['id'] : ''; ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1"><i class="fas fa-male me-1"></i>Pai</label>
                <select name="<?php echo $n; ?>[pai_id]" class="form-select form-select-sm">
                    <option value="">Nenhum / desconhecido</option>
                    <?php echo pf_options_pessoas($lista, $id_pessoa, $f['pai_id'], 'pai'); ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Vínculo</label>
                <select name="<?php echo $n; ?>[tipo_pai]" class="form-select form-select-sm"><?php echo pf_options_tipos($tipos_pai, $f['tipo_pai']); ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1"><i class="fas fa-female me-1"></i>Mãe</label>
                <select name="<?php echo $n; ?>[mae_id]" class="form-select form-select-sm">
                    <option value="">Nenhuma / desconhecida</option>
                    <?php echo pf_options_pessoas($lista, $id_pessoa, $f['mae_id'], 'mae'); ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Vínculo</label>
                <select name="<?php echo $n; ?>[tipo_mae]" class="form-select form-select-sm"><?php echo pf_options_tipos($tipos_mae, $f['tipo_mae']); ?></select>
            </div>
        </div>
        <div class="text-end mt-2">
            <?php if ($f['id']): ?>
                <div class="form-check form-check-inline text-danger small mb-0">
                    <input class="form-check-input" type="checkbox" name="<?php echo $n; ?>[remover]" value="1" id="rmf<?php echo pf_e($idx); ?>" <?php echo !empty($f['remover']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="rmf<?php echo pf_e($idx); ?>"><i class="fas fa-unlink me-1"></i>Remover esta filiação</label>
                </div>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remover-linha"><i class="fas fa-times me-1"></i>Descartar</button>
            <?php endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

function pf_linha_uniao($idx, array $u, array $lista, $id_pessoa) {
    $n = 'unioes[' . $idx . ']';
    ob_start(); ?>
    <div class="border rounded-3 p-3 mb-2 linha-vinculo">
        <input type="hidden" name="<?php echo $n; ?>[id]" value="<?php echo $u['id'] ? (int)$u['id'] : ''; ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1"><i class="fas fa-heart me-1"></i>Cônjuge</label>
                <select name="<?php echo $n; ?>[conjuge_id]" class="form-select form-select-sm">
                    <option value="">Selecione...</option>
                    <?php echo pf_options_pessoas($lista, $id_pessoa, $u['conjuge_id']); ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Tipo de união</label>
                <select name="<?php echo $n; ?>[tipo]" class="form-select form-select-sm"><?php echo pf_options_tipos(gen_tipos_uniao(), $u['tipo']); ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Local</label>
                <input type="text" name="<?php echo $n; ?>[local_inicio]" class="form-control form-control-sm" value="<?php echo pf_e($u['local_inicio']); ?>">
            </div>
            <div class="col-md-6"><?php echo pf_campo_data($n . '[data_inicio]', 'data_inicio', $u, 'Início', 'fa-ring'); ?></div>
            <div class="col-md-6"><?php echo pf_campo_data($n . '[data_fim]', 'data_fim', $u, 'Fim', 'fa-calendar-xmark'); ?></div>
            <div class="col-12 text-end">
                <?php if ($u['id']): ?>
                    <div class="form-check form-check-inline text-danger small mb-0">
                        <input class="form-check-input" type="checkbox" name="<?php echo $n; ?>[remover]" value="1" id="rmu<?php echo pf_e($idx); ?>" <?php echo !empty($u['remover']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="rmu<?php echo pf_e($idx); ?>"><i class="fas fa-unlink me-1"></i>Remover esta união</label>
                    </div>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remover-linha"><i class="fas fa-times me-1"></i>Descartar</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

/**
 * Linha de evento. $docs = [id => rótulo] (documentos da pessoa, fonte opcional);
 * $unioes = [id => rótulo] (uniões já gravadas da pessoa).
 */
function pf_linha_evento($idx, array $ev, array $docs, array $unioes) {
    $n = 'eventos[' . $idx . ']';
    ob_start(); ?>
    <div class="border rounded-3 p-3 mb-2 linha-vinculo">
        <input type="hidden" name="<?php echo $n; ?>[id]" value="<?php echo $ev['id'] ? (int)$ev['id'] : ''; ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1"><i class="fas fa-tag me-1"></i>Tipo</label>
                <select name="<?php echo $n; ?>[tipo]" class="form-select form-select-sm"><?php echo pf_options_tipos(gen_tipos_evento(), $ev['tipo']); ?></select>
            </div>
            <div class="col-md-5"><?php echo pf_campo_data($n . '[data]', 'data', $ev, 'Data'); ?></div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1"><i class="fas fa-map-marker-alt me-1"></i>Local</label>
                <input type="text" name="<?php echo $n; ?>[local]" class="form-control form-control-sm" value="<?php echo pf_e($ev['local']); ?>">
            </div>
            <div class="col-md-<?php echo $unioes ? '4' : '8'; ?>">
                <label class="form-label small text-muted mb-1">Descrição</label>
                <input type="text" name="<?php echo $n; ?>[descricao]" class="form-control form-control-sm" value="<?php echo pf_e($ev['descricao']); ?>" placeholder="Ex.: navio, paróquia, profissão">
            </div>
            <?php if ($unioes): ?>
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1"><i class="fas fa-ring me-1"></i>União (opcional)</label>
                    <select name="<?php echo $n; ?>[uniao_id]" class="form-select form-select-sm">
                        <option value="">—</option>
                        <?php echo pf_options_tipos($unioes, $ev['uniao_id']); ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1"><i class="fas fa-paperclip me-1"></i>Fonte (documento)</label>
                <select name="<?php echo $n; ?>[documento_id]" class="form-select form-select-sm">
                    <option value=""><?php echo $docs ? 'Nenhuma' : 'Nenhum documento anexado'; ?></option>
                    <?php echo pf_options_tipos($docs, $ev['documento_id']); ?>
                </select>
            </div>
            <div class="col-12 text-end">
                <?php if ($ev['id']): ?>
                    <div class="form-check form-check-inline text-danger small mb-0">
                        <input class="form-check-input" type="checkbox" name="<?php echo $n; ?>[remover]" value="1" id="rme<?php echo pf_e($idx); ?>" <?php echo !empty($ev['remover']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="rme<?php echo pf_e($idx); ?>"><i class="fas fa-trash me-1"></i>Remover este evento</label>
                    </div>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remover-linha"><i class="fas fa-times me-1"></i>Descartar</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

/** Mensagens de erro/aviso/sucesso (texto puro, escapado aqui). */
function pf_render_mensagens(array $erros, array $avisos = [], $sucesso = '') {
    $h = '';
    if ($sucesso !== '') $h .= '<div class="alert alert-success d-flex align-items-center"><i class="fas fa-check-circle me-2"></i> ' . pf_e($sucesso) . '</div>';
    if ($erros) {
        $h .= '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Nada foi gravado.</strong><ul class="mb-0 mt-2">';
        foreach ($erros as $m) $h .= '<li>' . pf_e($m) . '</li>';
        $h .= '</ul></div>';
    }
    foreach ($avisos as $m) $h .= '<div class="alert alert-warning"><i class="fas fa-exclamation-circle me-2"></i>' . pf_e($m) . '</div>';
    return $h;
}

/** Renderiza o formulário completo. $id_pessoa null = cadastro novo. */
function pf_render_form(PDO $pdo, array $estado, $id_pessoa) {
    $lista = gen_lista_pessoas($pdo);
    $nomes = [];
    foreach ($lista as $ps) $nomes[(int)$ps['id']] = $ps['nome_completo'];
    $p = $estado['pessoa'];
    $cor = gen_sexo_cor($p['sexo']);
    $data_vazia = ['_qualificador' => 'exata', '_precisao' => 'dia', '_ate' => '', '' => ''];
    $dv = function ($campo) use ($data_vazia) { $o = []; foreach ($data_vazia as $s => $v) $o[$campo . $s] = $v; return $o; };
    $tpl_f = ['id' => null, 'pai_id' => null, 'mae_id' => null, 'tipo_pai' => 'biologico', 'tipo_mae' => 'biologico', 'remover' => false];
    $tpl_u = array_merge(['id' => null, 'conjuge_id' => null, 'tipo' => 'casamento', 'local_inicio' => '', 'remover' => false], $dv('data_inicio'), $dv('data_fim'));
    $tpl_e = array_merge(['id' => null, 'tipo' => 'imigracao', 'local' => '', 'descricao' => '', 'uniao_id' => null, 'documento_id' => null, 'remover' => false], $dv('data'));
    $filiacoes = $estado['filiacoes'] ?: [$tpl_f];
    $unioes = $estado['unioes'] ?: [$tpl_u];
    $eventos = $estado['eventos'];
    $v = $estado['vinculo'];
    $papel_padrao = $v['papel'] ?: ($p['sexo'] === 'F' ? 'mae' : 'pai');

    // fontes (documentos desta pessoa) e uniões já gravadas, para os eventos
    $docs = [];
    if ($id_pessoa) { foreach (gen_documentos_pessoa($pdo, $id_pessoa) as $doc) $docs[(int)$doc['id']] = gen_rotulo_documento($doc); }
    $unioes_gravadas = [];
    if ($id_pessoa) { foreach (gen_unioes($pdo, $id_pessoa) as $u) $unioes_gravadas[(int)$u['id']] = 'Com ' . $u['conjuge_nome']; }
    ?>
    <style>.pf-data .pf-d, .pf-data .pf-ate { min-width: 7.5rem; } .pf-data .pf-q { min-width: 6.5rem; } .pf-data .pf-p { min-width: 4.8rem; }</style>
    <form method="POST" enctype="multipart/form-data" id="formPessoa">
        <div class="row g-3 mb-3">
            <div class="col-md-8">
                <div class="form-floating">
                    <input type="text" name="nome_completo" class="form-control" value="<?php echo pf_e($p['nome_completo']); ?>" required>
                    <label>Nome Completo</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-floating">
                    <select name="sexo" id="campoSexo" class="form-select">
                        <?php echo pf_options_tipos(gen_sexos(), $p['sexo']); ?>
                    </select>
                    <label>Sexo</label>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label text-muted small"><i class="fas fa-image me-1"></i> Foto do Perfil</label>
            <div class="d-flex align-items-center gap-3">
                <?php if (!empty($p['foto'])): ?>
                    <img src="<?php echo pf_e($p['foto']); ?>" alt="" style="width:60px; height:60px; border-radius:50%; object-fit:cover; border:3px solid <?php echo pf_e($cor); ?>;">
                <?php else: ?>
                    <div style="width:60px; height:60px; border-radius:50%; background:#f0f4ff; border:3px solid <?php echo pf_e($cor); ?>; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-image text-muted"></i>
                    </div>
                <?php endif; ?>
                <input type="file" name="foto_upload" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.jfif,image/*">
            </div>
        </div>

        <p class="text-muted small mb-2"><i class="fas fa-info-circle me-1"></i>Datas: digite DD/MM/AAAA, MM/AAAA ou só o ano. Use "Cerca de", "Antes de", "Depois de" ou "Entre" quando a data for aproximada.</p>
        <div class="row g-3 mb-3">
            <div class="col-md-7"><?php echo pf_campo_data('data_nascimento', 'data_nascimento', $p, 'Nascimento', 'fa-baby'); ?></div>
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1"><i class="fas fa-map-marker-alt me-1"></i>Local de Nascimento</label>
                <input type="text" name="local_nascimento" class="form-control form-control-sm" value="<?php echo pf_e($p['local_nascimento']); ?>">
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-7"><?php echo pf_campo_data('data_falecimento', 'data_falecimento', $p, 'Falecimento', 'fa-cross'); ?></div>
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1"><i class="fas fa-map-marker-alt me-1"></i>Local de Falecimento</label>
                <input type="text" name="local_falecimento" class="form-control form-control-sm" value="<?php echo pf_e($p['local_falecimento']); ?>">
            </div>
        </div>

        <div class="form-floating mb-3">
            <textarea name="biografia" class="form-control" style="height: 100px;"><?php echo pf_e($p['biografia']); ?></textarea>
            <label>Biografia / História</label>
        </div>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fas fa-users me-2"></i>Pais (filiação)</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" data-adicionar="filiacao"><i class="fas fa-plus me-1"></i>Adicionar filiação</button>
        </div>
        <p class="text-muted small">Uma filiação = pai e/ou mãe desta pessoa. Use mais de uma para registrar, por exemplo, pais biológicos e pais adotivos.</p>
        <div id="listaFiliacoes">
            <?php foreach ($filiacoes as $i => $f) echo pf_linha_filiacao($i, $f, $lista, $id_pessoa); ?>
        </div>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fas fa-heart me-2"></i>Cônjuges / uniões</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" data-adicionar="uniao"><i class="fas fa-plus me-1"></i>Adicionar cônjuge</button>
        </div>
        <p class="text-muted small">Cada união vale para os dois cônjuges automaticamente.</p>
        <div id="listaUnioes">
            <?php foreach ($unioes as $i => $u) echo pf_linha_uniao($i, $u, $lista, $id_pessoa); ?>
        </div>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fas fa-calendar-alt me-2"></i>Eventos</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" data-adicionar="evento"><i class="fas fa-plus me-1"></i>Adicionar evento</button>
        </div>
        <p class="text-muted small">Batismo, imigração, naturalização, residência, sepultamento, ocupação... Nascimento e falecimento ficam nos campos acima; casamento, nas uniões.<?php if ($id_pessoa && !$docs): ?> Para indicar um documento como fonte, anexe-o primeiro no perfil.<?php elseif (!$id_pessoa): ?> Documentos podem ser anexados depois do cadastro e escolhidos como fonte.<?php endif; ?></p>
        <div id="listaEventos">
            <?php foreach ($eventos as $i => $ev) echo pf_linha_evento($i, $ev, $docs, $unioes_gravadas); ?>
        </div>

        <hr class="my-4">
        <h6 class="fw-bold mb-2"><i class="fas fa-child me-2"></i>Vincular como Pai/Mãe de...</h6>
        <p class="text-muted small">Selecione um filho já cadastrado para vincular esta pessoa como pai ou mãe dele(a). Se o filho já tiver só a mãe (ou só o pai), o vínculo completa essa filiação.</p>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="form-floating">
                    <select name="id_filho" class="form-select">
                        <option value="">Nenhum filho selecionado</option>
                        <?php echo pf_options_pessoas($lista, $id_pessoa, $v['filho_id']); ?>
                    </select>
                    <label>Filho(a)</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-floating">
                    <select name="tipo_pai_mae" id="campoPapel" class="form-select">
                        <option value="pai" <?php echo $papel_padrao === 'pai' ? 'selected' : ''; ?>>É o Pai</option>
                        <option value="mae" <?php echo $papel_padrao === 'mae' ? 'selected' : ''; ?>>É a Mãe</option>
                    </select>
                    <label>Relação</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-floating">
                    <select name="tipo_vinculo_filho" class="form-select"><?php echo pf_options_tipos(gen_tipos_vinculo(), $v['tipo']); ?></select>
                    <label>Vínculo</label>
                </div>
            </div>
        </div>

        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-2"></i><?php echo $id_pessoa ? 'Salvar Alterações' : 'Cadastrar Pessoa'; ?>
            </button>
        </div>
    </form>

    <template id="tplFiliacao"><?php echo pf_linha_filiacao('__I__', $tpl_f, $lista, $id_pessoa); ?></template>
    <template id="tplUniao"><?php echo pf_linha_uniao('__I__', $tpl_u, $lista, $id_pessoa); ?></template>
    <template id="tplEvento"><?php echo pf_linha_evento('__I__', $tpl_e, $docs, $unioes_gravadas); ?></template>

    <script>
    (function () {
        var contador = 1000;
        var listas = { filiacao: ['tplFiliacao', 'listaFiliacoes'], uniao: ['tplUniao', 'listaUnioes'], evento: ['tplEvento', 'listaEventos'] };
        function adicionar(tipo) {
            var cfg = listas[tipo];
            var html = document.getElementById(cfg[0]).innerHTML.replace(/__I__/g, 'n' + (contador++));
            document.getElementById(cfg[1]).insertAdjacentHTML('beforeend', html);
        }
        document.querySelectorAll('[data-adicionar]').forEach(function (b) {
            b.addEventListener('click', function () { adicionar(b.getAttribute('data-adicionar')); });
        });
        var form = document.getElementById('formPessoa');
        form.addEventListener('click', function (e) {
            var b = e.target.closest('.btn-remover-linha');
            if (b) b.closest('.linha-vinculo').remove();
        });
        // Datas aproximadas: "Entre" mostra o fim da faixa; a precisão acompanha o que foi digitado
        form.addEventListener('change', function (e) {
            if (!e.target.classList.contains('pf-q')) return;
            var box = e.target.closest('.pf-data'), entre = e.target.value === 'entre';
            box.querySelector('.pf-ate').hidden = !entre;
            box.querySelector('.pf-ate-rot').hidden = !entre;
            if (entre) box.querySelector('.pf-ate').focus();
        });
        form.addEventListener('input', function (e) {
            if (!e.target.classList.contains('pf-d')) return;
            var t = e.target.value.trim(), p = null;
            if (/^\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4}$/.test(t) || /^\d{4}-\d{1,2}-\d{1,2}$/.test(t)) p = 'dia';
            else if (/^\d{1,2}[\/.\-]\d{4}$/.test(t) || /^\d{4}-\d{1,2}$/.test(t)) p = 'mes';
            else if (/^\d{4}$/.test(t)) p = 'ano';
            if (p) e.target.closest('.pf-data').querySelector('.pf-p').value = p;
        });
        var sexo = document.getElementById('campoSexo'), papel = document.getElementById('campoPapel');
        sexo.addEventListener('change', function () {
            if (sexo.value === 'M') papel.value = 'pai';
            if (sexo.value === 'F') papel.value = 'mae';
        });
    })();
    </script>
    <?php
}
