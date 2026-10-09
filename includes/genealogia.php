<?php
/**
 * Camada compartilhada de genealogia (modelo `unioes` + `filiacoes`).
 *
 * Funções puras sobre PDO, sem HTML. Toda página que lê ou grava vínculos
 * familiares deve usar estas funções em vez de montar SQL próprio.
 *
 *   require_once __DIR__ . '/includes/genealogia.php';
 *
 * Convenções:
 *   - IDs inválidos/vazios viram null (gen_id()).
 *   - Funções de validação/gravação devolvem um array de mensagens de erro
 *     em português; array vazio = sucesso.
 *   - Nenhuma função abre/fecha transação: quem chama decide.
 *
 * Compatível com PHP 7.4.
 */

// ---------------------------------------------------------------------
// Catálogos e utilitários
// ---------------------------------------------------------------------

function gen_tipos_vinculo() {
    return [
        'biologico' => 'Biológico',
        'adotivo'   => 'Adotivo',
        'padrasto'  => 'Padrasto/Madrasta',
        'tutela'    => 'Tutela',
    ];
}

function gen_tipos_uniao() {
    return [
        'casamento'     => 'Casamento',
        'uniao_estavel' => 'União estável',
        'outro'         => 'Outro',
    ];
}

function gen_sexos() {
    return ['M' => 'Masculino', 'F' => 'Feminino', 'D' => 'Desconhecido'];
}

function gen_sexo_rotulo($sexo) {
    $s = gen_sexos();
    return isset($s[$sexo]) ? $s[$sexo] : $s['D'];
}

/** Cor do card/borda por sexo (azul, vermelho, cinza para desconhecido). */
function gen_sexo_cor($sexo) {
    if ($sexo === 'F') return '#B22222';
    if ($sexo === 'M') return '#002776';
    return '#6c757d';
}

/** Classe Font Awesome do ícone por sexo. */
function gen_sexo_icone($sexo) {
    if ($sexo === 'F') return 'fa-female';
    if ($sexo === 'M') return 'fa-male';
    return 'fa-user';
}

/** Rótulo do vínculo considerando o papel (ex.: padrasto + mae = "Madrasta"). */
function gen_rotulo_vinculo($tipo, $papel) {
    if ($tipo === 'padrasto') return $papel === 'mae' ? 'Madrasta' : 'Padrasto';
    $t = gen_tipos_vinculo();
    return isset($t[$tipo]) ? $t[$tipo] : $t['biologico'];
}

function gen_rotulo_uniao($tipo) {
    $t = gen_tipos_uniao();
    return isset($t[$tipo]) ? $t[$tipo] : $t['casamento'];
}

/** Normaliza um ID vindo de formulário/URL: inteiro positivo ou null. */
function gen_id($v) {
    if ($v === null || $v === '' || is_array($v)) return null;
    if (!preg_match('/^\d+$/', (string)$v)) return null;
    $i = (int)$v;
    return $i > 0 ? $i : null;
}

/** Data AAAA-MM-DD válida ou null. */
function gen_data($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

// ---------------------------------------------------------------------
// Datas aproximadas (Rec. 8)
// ---------------------------------------------------------------------
// Cada data tem 4 colunas: <campo> (DATE, continua sendo o valor usado para ordenar),
// <campo>_qualificador (exata|cerca|antes|depois|entre), <campo>_precisao (dia|mes|ano)
// e <campo>_ate (fim da faixa quando o qualificador é 'entre').
// Precisão 'mes' grava AAAA-MM-01; precisão 'ano' grava AAAA-01-01.
// gen_formatar_data() é a ÚNICA função que transforma isso em texto: todas as páginas
// usam ela, a API devolve o texto pronto ("texto"/"ano") e a árvore (arvore.js) só exibe.

function gen_qualificadores() {
    return ['exata' => 'Exata', 'cerca' => 'Cerca de', 'antes' => 'Antes de', 'depois' => 'Depois de', 'entre' => 'Entre'];
}

function gen_precisoes() {
    return ['dia' => 'Dia', 'mes' => 'Mês', 'ano' => 'Ano'];
}

function gen_meses_abrev() {
    return [1 => 'jan.', 'fev.', 'mar.', 'abr.', 'maio', 'jun.', 'jul.', 'ago.', 'set.', 'out.', 'nov.', 'dez.'];
}

/** Colunas extras de data de `pessoas` para um SELECT (com alias opcional). */
function gen_cols_datas_pessoa($alias = '') {
    $a = $alias !== '' ? $alias . '.' : '';
    $c = [];
    foreach (['data_nascimento', 'data_falecimento'] as $campo) {
        foreach (['qualificador', 'precisao', 'ate'] as $suf) $c[] = $a . $campo . '_' . $suf;
    }
    return implode(', ', $c);
}

/** Colunas extras de data de `unioes` para um SELECT (com alias opcional). */
function gen_cols_datas_uniao($alias = '') {
    $a = $alias !== '' ? $alias . '.' : '';
    $c = [];
    foreach (['data_inicio', 'data_fim'] as $campo) {
        foreach (['qualificador', 'precisao', 'ate'] as $suf) $c[] = $a . $campo . '_' . $suf;
    }
    return implode(', ', $c);
}

/** Só a data conforme a precisão: "22/02/1882", "fev. 1882" ou "1882". */
function gen_formatar_data_base($data, $precisao = 'dia') {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$data, $m)) return '';
    $ano = (string)(int)$m[1];
    if ($precisao === 'ano') return $ano;
    if ($precisao === 'mes') {
        $meses = gen_meses_abrev();
        $mes = (int)$m[2];
        return (isset($meses[$mes]) ? $meses[$mes] : $m[2]) . ' ' . $ano;
    }
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/**
 * Formatação única de datas (genealogia): "22/02/1882", "fev. 1882", "1882", "c. 1824",
 * "antes de 1890", "depois de 1890", "entre 1880 e 1885". Sem data → ''.
 */
function gen_formatar_data($data, $qualificador = 'exata', $precisao = 'dia', $ate = null) {
    $precisao = isset(gen_precisoes()[$precisao]) ? $precisao : 'dia';
    $base = gen_formatar_data_base($data, $precisao);
    if ($base === '') return '';
    switch ($qualificador) {
        case 'cerca':  return 'c. ' . $base;
        case 'antes':  return 'antes de ' . $base;
        case 'depois': return 'depois de ' . $base;
        case 'entre':
            $fim = gen_formatar_data_base($ate, $precisao);
            return $fim !== '' ? 'entre ' . $base . ' e ' . $fim : $base;
    }
    return $base;
}

/** Forma curta (só o ano) para cards e listas: "1824", "c. 1824", "ant. 1890", "dep. 1890", "1880/1885". */
function gen_formatar_ano($data, $qualificador = 'exata', $ate = null) {
    if (!preg_match('/^(\d{4})-/', (string)$data, $m)) return '';
    $ano = (string)(int)$m[1];
    switch ($qualificador) {
        case 'cerca':  return 'c. ' . $ano;
        case 'antes':  return 'ant. ' . $ano;
        case 'depois': return 'dep. ' . $ano;
        case 'entre':
            if (preg_match('/^(\d{4})-/', (string)$ate, $m2) && (int)$m2[1] !== (int)$m[1]) return $ano . '/' . (int)$m2[1];
            return $ano;
    }
    return $ano;
}

/**
 * As 4 colunas de uma data numa linha (do banco ou de formulário já normalizado):
 * ['data' => 'AAAA-MM-DD'|null, 'qualificador' => , 'precisao' => , 'ate' => 'AAAA-MM-DD'|null].
 */
function gen_data_campo(array $linha, $campo) {
    $q = (string)($linha[$campo . '_qualificador'] ?? 'exata');
    $p = (string)($linha[$campo . '_precisao'] ?? 'dia');
    if (!isset(gen_qualificadores()[$q])) $q = 'exata';
    if (!isset(gen_precisoes()[$p])) $p = 'dia';
    $data = gen_data($linha[$campo] ?? null);
    $ate = ($q === 'entre') ? gen_data($linha[$campo . '_ate'] ?? null) : null;
    return ['data' => $data, 'qualificador' => $q, 'precisao' => $p, 'ate' => $ate];
}

/** Texto de um valor devolvido por gen_data_campo(). */
function gen_formatar_data_campo(array $v) {
    return gen_formatar_data($v['data'], $v['qualificador'], $v['precisao'], $v['ate']);
}

/** Texto da data $campo de uma linha: gen_data_texto($pessoa, 'data_nascimento') → "c. 1824". */
function gen_data_texto(array $linha, $campo) {
    return gen_formatar_data_campo(gen_data_campo($linha, $campo));
}

/** Ano curto da data $campo de uma linha ("c. 1824"). */
function gen_data_ano(array $linha, $campo) {
    $v = gen_data_campo($linha, $campo);
    return gen_formatar_ano($v['data'], $v['qualificador'], $v['ate']);
}

/**
 * Data estruturada para a API: {data, local, qualificador, precisao, ate, texto, ano}.
 * texto/ano = null quando não há data.
 */
function gen_data_json(array $linha, $campo, $local = null) {
    $v = gen_data_campo($linha, $campo);
    $texto = gen_formatar_data_campo($v);
    return [
        'data' => $v['data'],
        'local' => $local,
        'qualificador' => $v['qualificador'],
        'precisao' => $v['precisao'],
        'ate' => $v['ate'],
        'texto' => $texto !== '' ? $texto : null,
        'ano' => $texto !== '' ? gen_formatar_ano($v['data'], $v['qualificador'], $v['ate']) : null,
    ];
}

/** "c. 1824 – 1882", "1871 –", "– dep. 1890" ou "" a partir de uma linha de `pessoas`. */
function gen_anos_vida_linha(array $r) {
    $n = gen_data_ano($r, 'data_nascimento');
    $f = gen_data_ano($r, 'data_falecimento');
    if ($n === '' && $f === '') return '';
    return trim($n . ' – ' . $f);
}

/** Primeiro e último dia do período de uma data com a precisão dada. */
function gen_periodo($data, $precisao) {
    $y = substr($data, 0, 4); $m = substr($data, 5, 2);
    if ($precisao === 'ano') return [$y . '-01-01', $y . '-12-31'];
    if ($precisao === 'mes') {
        $d = DateTime::createFromFormat('!Y-m-d', $y . '-' . $m . '-01');
        return [$y . '-' . $m . '-01', $y . '-' . $m . '-' . $d->format('t')];
    }
    return [$data, $data];
}

/**
 * Intervalo [mínimo, máximo] (AAAA-MM-DD) em que a data pode estar.
 * null = não comparável (sem data, ou "cerca de", que não tem limites).
 */
function gen_data_intervalo(array $v) {
    if (empty($v['data'])) return null;
    list($ini, $fim) = gen_periodo($v['data'], $v['precisao']);
    switch ($v['qualificador']) {
        case 'cerca':  return null;
        case 'antes':  return ['0000-01-01', $ini];
        case 'depois': return [$fim, '9999-12-31'];
        case 'entre':
            if (!empty($v['ate'])) { $p = gen_periodo($v['ate'], $v['precisao']); $fim = $p[1]; }
            return [$ini, $fim];
    }
    return [$ini, $fim];
}

/**
 * A data $a é anterior à data $b? (valores de gen_data_campo)
 *   'antes'        certamente anterior (intervalos comparáveis e sem sobreposição)
 *   'talvez_antes' parece anterior (alguma é "cerca de" ou os intervalos se sobrepõem,
 *                  mas o valor gravado de $a é menor)
 *   null           não é anterior, ou falta data
 */
function gen_comparar_datas(array $a, array $b) {
    if (empty($a['data']) || empty($b['data'])) return null;
    $ia = gen_data_intervalo($a); $ib = gen_data_intervalo($b);
    if ($ia && $ib && $ia[1] < $ib[0]) return 'antes';
    if ($a['data'] < $b['data']) return 'talvez_antes';
    return null;
}

/**
 * Interpreta o texto digitado: "22/02/1882", "02/1882", "1882", "1882-02-22" ou "1882-02".
 * Devolve ['data' => 'AAAA-MM-DD'|null, 'precisao' => 'dia'|'mes'|'ano'|null, 'erro' => bool].
 */
function gen_ler_data_texto($t) {
    $t = trim((string)$t);
    $vazio = ['data' => null, 'precisao' => null, 'erro' => false];
    if ($t === '') return $vazio;
    $y = $mo = $d = null; $p = null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t, $m)) { $y = $m[1]; $mo = $m[2]; $d = $m[3]; $p = 'dia'; }
    elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $t, $m)) { $d = $m[1]; $mo = $m[2]; $y = $m[3]; $p = 'dia'; }
    elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $t, $m)) { $y = $m[1]; $mo = $m[2]; $d = 1; $p = 'mes'; }
    elseif (preg_match('#^(\d{1,2})[/.-](\d{4})$#', $t, $m)) { $mo = $m[1]; $y = $m[2]; $d = 1; $p = 'mes'; }
    elseif (preg_match('/^(\d{4})$/', $t, $m)) { $y = $m[1]; $mo = 1; $d = 1; $p = 'ano'; }
    else return ['data' => null, 'precisao' => null, 'erro' => true];
    $y = (int)$y; $mo = (int)$mo; $d = (int)$d;
    if ($y < 1000 || $y > 9999 || !checkdate($mo, $d, $y)) return ['data' => null, 'precisao' => null, 'erro' => true];
    return ['data' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'precisao' => $p, 'erro' => false];
}

/** Trunca AAAA-MM-DD para a precisão (mes → dia 01; ano → 01-01). */
function gen_truncar_data($data, $precisao) {
    if (!$data) return null;
    if ($precisao === 'ano') return substr($data, 0, 4) . '-01-01';
    if ($precisao === 'mes') return substr($data, 0, 7) . '-01';
    return $data;
}

/** Valor da data no formato do campo de texto do formulário ("22/02/1882", "02/1882", "1882"). */
function gen_data_para_campo($data, $precisao) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$data, $m)) return (string)$data;
    if ($precisao === 'ano') return $m[1];
    if ($precisao === 'mes') return $m[2] . '/' . $m[1];
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/**
 * Normaliza uma data aproximada vinda de formulário.
 * $in = ['data' => texto, 'qualificador' => , 'precisao' => , 'ate' => texto]
 * Precisão gravada = a mais grosseira entre a escolhida e a digitada (digitar só o ano
 * grava precisão 'ano'; escolher 'ano' e digitar a data completa guarda só o ano).
 * 'entre' exige o fim da faixa, posterior ao início. Sem data, qualificador ≠ exata é erro.
 * Devolve ['valor' => ['data','qualificador','precisao','ate'], 'erros' => [...]].
 */
function gen_ler_data_aprox(array $in, $rotulo) {
    $erros = [];
    $q = (string)($in['qualificador'] ?? 'exata');
    $p = (string)($in['precisao'] ?? 'dia');
    if (!isset(gen_qualificadores()[$q])) { $erros[] = "Qualificador da data de $rotulo inválido."; $q = 'exata'; }
    if (!isset(gen_precisoes()[$p])) { $erros[] = "Precisão da data de $rotulo inválida."; $p = 'dia'; }
    $vazio = ['data' => null, 'qualificador' => 'exata', 'precisao' => 'dia', 'ate' => null];
    $lido = gen_ler_data_texto($in['data'] ?? '');
    if ($lido['erro']) return ['valor' => $vazio, 'erros' => array_merge($erros, ["Data de $rotulo inválida (use DD/MM/AAAA, MM/AAAA ou AAAA)."])];
    if (!$lido['data']) {
        $ate_txt = trim((string)($in['ate'] ?? ''));
        if ($q !== 'exata' || ($ate_txt !== '' && $q === 'entre')) $erros[] = "Informe a data de $rotulo (ou deixe o qualificador como \"Exata\").";
        return ['valor' => $vazio, 'erros' => $erros];
    }
    $ordem = ['dia' => 0, 'mes' => 1, 'ano' => 2];
    $prec = $ordem[$lido['precisao']] > $ordem[$p] ? $lido['precisao'] : $p;
    $ate = null;
    if ($q === 'entre') {
        $l2 = gen_ler_data_texto($in['ate'] ?? '');
        if ($l2['erro']) $erros[] = "Fim da faixa da data de $rotulo inválido.";
        elseif (!$l2['data']) $erros[] = "Com \"Entre\", informe também o fim da faixa da data de $rotulo.";
        else {
            if ($ordem[$l2['precisao']] > $ordem[$prec]) $prec = $l2['precisao'];
            $ate = gen_truncar_data($l2['data'], $prec);
        }
    }
    $data = gen_truncar_data($lido['data'], $prec);
    if ($q === 'entre' && $ate !== null && $ate <= $data) {
        $erros[] = "Na data de $rotulo, o fim da faixa (" . gen_formatar_data_base($ate, $prec) . ") deve ser posterior ao início (" . gen_formatar_data_base($data, $prec) . ").";
    }
    return ['valor' => ['data' => $data, 'qualificador' => $q, 'precisao' => $prec, 'ate' => $ate], 'erros' => $erros];
}

// ---------------------------------------------------------------------
// Leitura
// ---------------------------------------------------------------------

function gen_pessoa(PDO $pdo, $id) {
    $id = gen_id($id);
    if (!$id) return null;
    $st = $pdo->prepare("SELECT * FROM pessoas WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Lista simples (id, nome, sexo) de todas as pessoas, para selects. */
function gen_lista_pessoas(PDO $pdo) {
    return $pdo->query("SELECT id, nome_completo, sexo FROM pessoas ORDER BY nome_completo")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Filiações (relações filho-e-pais) de uma pessoa, com nome/sexo/foto de cada genitor.
 * Biológicas primeiro.
 */
function gen_filiacoes(PDO $pdo, $filho_id) {
    $st = $pdo->prepare(
        "SELECT f.id, f.filho_id, f.pai_id, f.mae_id, f.tipo_pai, f.tipo_mae,
                pai.nome_completo AS pai_nome, pai.sexo AS pai_sexo, pai.foto AS pai_foto,
                mae.nome_completo AS mae_nome, mae.sexo AS mae_sexo, mae.foto AS mae_foto
           FROM filiacoes f
           LEFT JOIN pessoas pai ON pai.id = f.pai_id
           LEFT JOIN pessoas mae ON mae.id = f.mae_id
          WHERE f.filho_id = ?
          ORDER BY (f.tipo_pai = 'biologico' AND f.tipo_mae = 'biologico') DESC, f.id"
    );
    $st->execute([(int)$filho_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Pais de uma pessoa, achatados (um item por genitor), sem repetir pessoa.
 * Cada item: id, nome_completo, sexo, foto, papel ('pai'|'mae'), tipo, filiacao_id.
 */
function gen_pais(PDO $pdo, $id) {
    $out = [];
    $vistos = [];
    foreach (gen_filiacoes($pdo, $id) as $f) {
        foreach (['pai', 'mae'] as $papel) {
            $pid = $f[$papel . '_id'];
            if (!$pid || isset($vistos[$pid])) continue;
            $vistos[$pid] = true;
            $out[] = [
                'id' => (int)$pid,
                'nome_completo' => $f[$papel . '_nome'],
                'sexo' => $f[$papel . '_sexo'],
                'foto' => $f[$papel . '_foto'],
                'papel' => $papel,
                'tipo' => $f['tipo_' . $papel],
                'filiacao_id' => (int)$f['id'],
            ];
        }
    }
    return $out;
}

/**
 * Uniões de uma pessoa, cada uma com os dados do outro cônjuge
 * (conjuge_id, conjuge_nome, conjuge_sexo, conjuge_foto, conjuge_nascimento).
 */
function gen_unioes(PDO $pdo, $id) {
    $id = (int)$id;
    $st = $pdo->prepare(
        "SELECT u.id, u.pessoa1_id, u.pessoa2_id, u.tipo, u.data_inicio, u.local_inicio, u.data_fim,
                " . gen_cols_datas_uniao('u') . ",
                c.id AS conjuge_id, c.nome_completo AS conjuge_nome, c.sexo AS conjuge_sexo,
                c.foto AS conjuge_foto, c.data_nascimento AS conjuge_nascimento
           FROM unioes u
           JOIN pessoas c ON c.id = CASE WHEN u.pessoa1_id = ? THEN u.pessoa2_id ELSE u.pessoa1_id END
          WHERE u.pessoa1_id = ? OR u.pessoa2_id = ?
          ORDER BY u.data_inicio IS NULL, u.data_inicio, u.id"
    );
    $st->execute([$id, $id, $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Filhos de uma pessoa (de qualquer filiação em que ela seja pai ou mãe).
 * Cada item: id, nome_completo, sexo, foto, data_nascimento, filiacao_id,
 * papel (papel da pessoa: 'pai'|'mae'), tipo (vínculo), outro_id (o outro genitor ou null).
 */
function gen_filhos(PDO $pdo, $id) {
    $id = (int)$id;
    $st = $pdo->prepare(
        "SELECT p.id, p.nome_completo, p.sexo, p.foto, p.data_nascimento,
                f.id AS filiacao_id, f.pai_id, f.mae_id, f.tipo_pai, f.tipo_mae
           FROM filiacoes f
           JOIN pessoas p ON p.id = f.filho_id
          WHERE f.pai_id = ? OR f.mae_id = ?
          ORDER BY p.data_nascimento IS NULL, p.data_nascimento, p.id, f.id"
    );
    $st->execute([$id, $id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($out[$r['id']])) continue; // mesmo filho em duas filiações com a mesma pessoa
        $papel = ((int)$r['pai_id'] === $id) ? 'pai' : 'mae';
        $outro = $papel === 'pai' ? $r['mae_id'] : $r['pai_id'];
        $out[$r['id']] = [
            'id' => (int)$r['id'],
            'nome_completo' => $r['nome_completo'],
            'sexo' => $r['sexo'],
            'foto' => $r['foto'],
            'data_nascimento' => $r['data_nascimento'],
            'filiacao_id' => (int)$r['filiacao_id'],
            'papel' => $papel,
            'tipo' => $r['tipo_' . $papel],
            'outro_id' => $outro ? (int)$outro : null,
        ];
    }
    return array_values($out);
}

/**
 * Filhos agrupados por união ("famílias" da pessoa), no estilo FamilySearch.
 * Devolve uma lista de grupos:
 *   ['uniao' => linha de gen_unioes()|null, 'conjuge_id' => int|null, 'conjuge_nome' => string|null, 'filhos' => [...]]
 * Ordem: uniões (mesmo sem filhos); depois filhos com outro genitor que não é cônjuge
 * registrado (um grupo por genitor); por fim filhos sem o outro genitor (conjuge_id null).
 */
function gen_familias(PDO $pdo, $id) {
    $grupos = [];
    $por_conjuge = [];
    foreach (gen_unioes($pdo, $id) as $u) {
        $cid = (int)$u['conjuge_id'];
        $por_conjuge[$cid] = count($grupos);
        $grupos[] = ['uniao' => $u, 'conjuge_id' => $cid, 'conjuge_nome' => $u['conjuge_nome'], 'filhos' => []];
    }
    $sem_outro = [];
    foreach (gen_filhos($pdo, $id) as $f) {
        $o = $f['outro_id'];
        if ($o === null) { $sem_outro[] = $f; continue; }
        if (!isset($por_conjuge[$o])) {
            $outro = gen_pessoa($pdo, $o);
            $por_conjuge[$o] = count($grupos);
            $grupos[] = ['uniao' => null, 'conjuge_id' => $o, 'conjuge_nome' => $outro ? $outro['nome_completo'] : null, 'filhos' => []];
        }
        $grupos[$por_conjuge[$o]]['filhos'][] = $f;
    }
    if ($sem_outro) {
        $grupos[] = ['uniao' => null, 'conjuge_id' => null, 'conjuge_nome' => null, 'filhos' => $sem_outro];
    }
    return $grupos;
}

/**
 * Irmãos: pessoas que compartilham ao menos um genitor (em qualquer filiação).
 * Cada item: id, nome_completo, sexo, foto, data_nascimento,
 * tipo ('completo' = mesmo pai e mesma mãe; 'meio' = só um em comum).
 */
function gen_irmaos(PDO $pdo, $id) {
    $id = (int)$id;
    $st = $pdo->prepare(
        "SELECT p.id, p.nome_completo, p.sexo, p.foto, p.data_nascimento,
                f1.pai_id AS meu_pai, f1.mae_id AS minha_mae, f2.pai_id, f2.mae_id
           FROM filiacoes f1
           JOIN filiacoes f2 ON f2.filho_id <> f1.filho_id
                            AND ((f1.pai_id IS NOT NULL AND f2.pai_id = f1.pai_id)
                              OR (f1.mae_id IS NOT NULL AND f2.mae_id = f1.mae_id))
           JOIN pessoas p ON p.id = f2.filho_id
          WHERE f1.filho_id = ?
          ORDER BY p.data_nascimento IS NULL, p.data_nascimento, p.id"
    );
    $st->execute([$id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $completo = $r['meu_pai'] && $r['minha_mae']
                 && (int)$r['meu_pai'] === (int)$r['pai_id'] && (int)$r['minha_mae'] === (int)$r['mae_id'];
        if (!isset($out[$r['id']])) {
            $out[$r['id']] = [
                'id' => (int)$r['id'],
                'nome_completo' => $r['nome_completo'],
                'sexo' => $r['sexo'],
                'foto' => $r['foto'],
                'data_nascimento' => $r['data_nascimento'],
                'tipo' => $completo ? 'completo' : 'meio',
            ];
        } elseif ($completo) {
            $out[$r['id']]['tipo'] = 'completo';
        }
    }
    return array_values($out);
}

// ---------------------------------------------------------------------
// Grafo completo em memória (para as páginas que desenham a família toda)
// ---------------------------------------------------------------------

/**
 * Carrega pessoas, filiações e uniões de uma vez e monta índices:
 *   'pessoas'   => [id => linha]  (ordenadas por nascimento, sem data por último)
 *   'filiacoes' => [linhas]
 *   'unioes'    => [linhas]
 *   'pais'      => [filho_id => [ ['pai_id'=>, 'mae_id'=>, 'tipo_pai'=>, 'tipo_mae'=>], ... ]]  (biológicas primeiro)
 *   'filhos'    => [genitor_id => [filho_id, ...]]
 *   'conjuges'  => [pessoa_id => [conjuge_id, ...]]
 */
function gen_carregar_grafo(PDO $pdo) {
    $g = ['pessoas' => [], 'filiacoes' => [], 'unioes' => [], 'pais' => [], 'filhos' => [], 'conjuges' => []];
    $rs = $pdo->query(
        "SELECT id, nome_completo, sexo, foto, data_nascimento, local_nascimento,
                data_falecimento, local_falecimento, biografia, " . gen_cols_datas_pessoa('') . "
           FROM pessoas ORDER BY data_nascimento IS NULL, data_nascimento, id"
    );
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $p) { $g['pessoas'][(int)$p['id']] = $p; }

    $rs = $pdo->query(
        "SELECT id, filho_id, pai_id, mae_id, tipo_pai, tipo_mae FROM filiacoes
          ORDER BY (tipo_pai = 'biologico' AND tipo_mae = 'biologico') DESC, id"
    );
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $g['filiacoes'][] = $f;
        $fid = (int)$f['filho_id'];
        $g['pais'][$fid][] = [
            'pai_id' => $f['pai_id'] ? (int)$f['pai_id'] : null,
            'mae_id' => $f['mae_id'] ? (int)$f['mae_id'] : null,
            'tipo_pai' => $f['tipo_pai'],
            'tipo_mae' => $f['tipo_mae'],
        ];
        foreach (['pai_id', 'mae_id'] as $k) {
            if ($f[$k] && !in_array($fid, $g['filhos'][(int)$f[$k]] ?? [], true)) {
                $g['filhos'][(int)$f[$k]][] = $fid;
            }
        }
    }
    // Filhos em ordem de nascimento
    $ordem = array_flip(array_keys($g['pessoas']));
    foreach ($g['filhos'] as &$lista) {
        usort($lista, function ($a, $b) use ($ordem) { return ($ordem[$a] ?? 0) <=> ($ordem[$b] ?? 0); });
    }
    unset($lista);

    $rs = $pdo->query("SELECT id, pessoa1_id, pessoa2_id, tipo, data_inicio, local_inicio, data_fim, " . gen_cols_datas_uniao('') . " FROM unioes ORDER BY data_inicio IS NULL, data_inicio, id");
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $g['unioes'][] = $u;
        $a = (int)$u['pessoa1_id']; $b = (int)$u['pessoa2_id'];
        $g['conjuges'][$a][] = $b;
        $g['conjuges'][$b][] = $a;
    }
    return $g;
}

// ---------------------------------------------------------------------
// Validações (Rec. 7)
// ---------------------------------------------------------------------

/**
 * true se $pessoa_id descende de $ancestral_id (ou é a mesma pessoa).
 * Sobe pelas filiações (todos os genitores) em largura, com proteção a ciclos.
 * Substitui o antigo is_descendant($pdo, $ancestral, $descendente) duplicado nas páginas.
 */
function gen_descende_de(PDO $pdo, $pessoa_id, $ancestral_id) {
    $pessoa_id = (int)$pessoa_id; $ancestral_id = (int)$ancestral_id;
    if ($pessoa_id === $ancestral_id) return true;
    $visitados = [$pessoa_id => true];
    $fronteira = [$pessoa_id];
    while ($fronteira) {
        $ph = implode(',', array_fill(0, count($fronteira), '?'));
        $st = $pdo->prepare("SELECT pai_id, mae_id FROM filiacoes WHERE filho_id IN ($ph)");
        $st->execute($fronteira);
        $prox = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach ([$r['pai_id'], $r['mae_id']] as $g) {
                if (!$g) continue;
                $g = (int)$g;
                if ($g === $ancestral_id) return true;
                if (!isset($visitados[$g])) { $visitados[$g] = true; $prox[] = $g; }
            }
        }
        $fronteira = $prox;
    }
    return false;
}

/**
 * Valida os campos básicos de uma pessoa. As datas vêm em AAAA-MM-DD (já normalizadas
 * por gen_ler_data_aprox); qualificador/precisão/até são opcionais em $d
 * (data_nascimento_qualificador etc.; padrão exata/dia).
 * Falecimento antes do nascimento: erro quando as duas datas são comparáveis
 * (exatas ou faixas que não se sobrepõem); com "cerca de" vira aviso (gen_validar_datas_pessoa).
 */
function gen_validar_dados_pessoa(array $d) {
    $erros = [];
    if (trim((string)($d['nome_completo'] ?? '')) === '') $erros[] = 'O nome completo é obrigatório.';
    if (!isset(gen_sexos()[$d['sexo'] ?? ''])) $erros[] = 'Sexo inválido.';
    foreach (['data_nascimento' => 'nascimento', 'data_falecimento' => 'falecimento'] as $k => $rot) {
        $v = trim((string)($d[$k] ?? ''));
        if ($v !== '' && gen_data($v) === null) $erros[] = "Data de $rot inválida.";
    }
    if ($erros) return $erros;
    $r = gen_validar_datas_pessoa($d);
    return array_merge($erros, $r['erros']);
}

/** Datas de nascimento x falecimento: ['erros' => [], 'avisos' => []]. */
function gen_validar_datas_pessoa(array $d) {
    $out = ['erros' => [], 'avisos' => []];
    $n = gen_data_campo($d, 'data_nascimento');
    $f = gen_data_campo($d, 'data_falecimento');
    $c = gen_comparar_datas($f, $n);
    if ($c === 'antes') {
        $out['erros'][] = 'A data de falecimento (' . gen_formatar_data_campo($f) . ') é anterior ao nascimento (' . gen_formatar_data_campo($n) . ').';
    } elseif ($c === 'talvez_antes') {
        $out['avisos'][] = 'Confira as datas: o falecimento (' . gen_formatar_data_campo($f) . ') parece anterior ao nascimento (' . gen_formatar_data_campo($n) . ').';
    }
    return $out;
}

/**
 * Ao mudar o sexo de uma pessoa, impede que ela fique como pai sendo 'F'
 * ou como mãe sendo 'M' em filiações já gravadas.
 */
function gen_validar_sexo_compativel(PDO $pdo, $id, $sexo) {
    $erros = [];
    if (!$id) return $erros;
    if ($sexo === 'F') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM filiacoes WHERE pai_id = ?");
        $st->execute([(int)$id]);
        if ($st->fetchColumn() > 0) $erros[] = 'Esta pessoa está registrada como pai de alguém; não pode ter sexo Feminino. Ajuste a filiação primeiro (ou use "Desconhecido").';
    } elseif ($sexo === 'M') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM filiacoes WHERE mae_id = ?");
        $st->execute([(int)$id]);
        if ($st->fetchColumn() > 0) $erros[] = 'Esta pessoa está registrada como mãe de alguém; não pode ter sexo Masculino. Ajuste a filiação primeiro (ou use "Desconhecido").';
    }
    return $erros;
}

/**
 * Valida uma filiação (filho + pai e/ou mãe).
 * $filho_id pode ser null quando a pessoa ainda vai ser criada (pula ciclo/duplicata).
 * $ignorar_id: id da própria filiação em caso de edição.
 */
function gen_validar_filiacao(PDO $pdo, $filho_id, $pai_id, $mae_id, $tipo_pai = 'biologico', $tipo_mae = 'biologico', $ignorar_id = null) {
    $erros = [];
    $filho_id = gen_id($filho_id); $pai_id = gen_id($pai_id); $mae_id = gen_id($mae_id);
    $tipos = gen_tipos_vinculo();

    if (!$pai_id && !$mae_id) return ['Informe ao menos o pai ou a mãe.'];
    if (!isset($tipos[$tipo_pai]) || !isset($tipos[$tipo_mae])) $erros[] = 'Tipo de vínculo inválido.';
    if ($pai_id && $mae_id && $pai_id === $mae_id) $erros[] = 'O pai e a mãe não podem ser a mesma pessoa.';

    $rot = ['pai' => 'O pai', 'mae' => 'A mãe'];
    foreach (['pai' => $pai_id, 'mae' => $mae_id] as $papel => $gid) {
        if (!$gid) continue;
        if ($filho_id && $gid === $filho_id) { $erros[] = $rot[$papel] . ' não pode ser a própria pessoa.'; continue; }
        $g = gen_pessoa($pdo, $gid);
        if (!$g) { $erros[] = $rot[$papel] . ' selecionado(a) não existe.'; continue; }
        if ($papel === 'pai' && $g['sexo'] === 'F') $erros[] = $g['nome_completo'] . ' tem sexo Feminino e não pode ser registrada como pai.';
        if ($papel === 'mae' && $g['sexo'] === 'M') $erros[] = $g['nome_completo'] . ' tem sexo Masculino e não pode ser registrado como mãe.';
        if ($filho_id && gen_descende_de($pdo, $gid, $filho_id)) {
            $erros[] = $g['nome_completo'] . ' é descendente desta pessoa; o vínculo criaria um ciclo na árvore.';
        }
        if ($filho_id) {
            // Duplicata: o mesmo genitor no mesmo papel em outra filiação do mesmo filho.
            $col = $papel === 'pai' ? 'pai_id' : 'mae_id';
            $st = $pdo->prepare("SELECT COUNT(*) FROM filiacoes WHERE filho_id = ? AND $col = ? AND id <> ?");
            $st->execute([$filho_id, $gid, (int)$ignorar_id]);
            if ($st->fetchColumn() > 0) {
                $erros[] = $g['nome_completo'] . ' já está registrado(a) como ' . ($papel === 'pai' ? 'pai' : 'mãe') . ' desta pessoa em outra filiação.';
            }
        }
    }
    return $erros;
}

/**
 * Valida uma união entre A e B. $ignorar_id: id da própria união em caso de edição.
 * $aprox (opcional): data_inicio_qualificador/_precisao/_ate e data_fim_* para comparar
 * início e fim considerando datas aproximadas.
 */
function gen_validar_uniao(PDO $pdo, $a, $b, $tipo = 'casamento', $ignorar_id = null, $data_inicio = null, $data_fim = null, $aprox = null) {
    $erros = [];
    $a = gen_id($a); $b = gen_id($b);
    if (!$a || !$b) return ['Selecione o cônjuge.'];
    if ($a === $b) return ['Uma pessoa não pode ser cônjuge de si mesma.'];
    if (!isset(gen_tipos_uniao()[$tipo])) $erros[] = 'Tipo de união inválido.';
    $pa = gen_pessoa($pdo, $a); $pb = gen_pessoa($pdo, $b);
    if (!$pa || !$pb) { $erros[] = 'Cônjuge selecionado não existe.'; return $erros; }
    foreach (['início' => $data_inicio, 'fim' => $data_fim] as $rot => $d) {
        if ($d !== null && trim((string)$d) !== '' && gen_data($d) === null) $erros[] = "Data de $rot da união inválida.";
    }
    $linha = array_merge(is_array($aprox) ? $aprox : [], ['data_inicio' => gen_data($data_inicio), 'data_fim' => gen_data($data_fim)]);
    if (gen_comparar_datas(gen_data_campo($linha, 'data_fim'), gen_data_campo($linha, 'data_inicio')) === 'antes') {
        $erros[] = 'O fim da união é anterior ao início.';
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM unioes WHERE pessoa1_id = ? AND pessoa2_id = ? AND id <> ?");
    $st->execute([min($a, $b), max($a, $b), (int)$ignorar_id]);
    if ($st->fetchColumn() > 0) $erros[] = 'A união entre ' . $pa['nome_completo'] . ' e ' . $pb['nome_completo'] . ' já está registrada.';
    return $erros;
}

// ---------------------------------------------------------------------
// Gravação (validam antes de gravar)
// ---------------------------------------------------------------------

/** Insere (sem $filiacao_id) ou atualiza uma filiação. Devolve erros (vazio = ok). */
function gen_salvar_filiacao(PDO $pdo, $filho_id, $pai_id, $mae_id, $tipo_pai = 'biologico', $tipo_mae = 'biologico', $filiacao_id = null) {
    $filho_id = gen_id($filho_id); $pai_id = gen_id($pai_id); $mae_id = gen_id($mae_id); $filiacao_id = gen_id($filiacao_id);
    if (!$filho_id || !gen_pessoa($pdo, $filho_id)) return ['Pessoa (filho) inexistente.'];
    $tipo_pai = $tipo_pai ?: 'biologico'; $tipo_mae = $tipo_mae ?: 'biologico';
    $erros = gen_validar_filiacao($pdo, $filho_id, $pai_id, $mae_id, $tipo_pai, $tipo_mae, $filiacao_id);
    if ($erros) return $erros;
    if ($filiacao_id) {
        $st = $pdo->prepare("UPDATE filiacoes SET pai_id = ?, mae_id = ?, tipo_pai = ?, tipo_mae = ? WHERE id = ? AND filho_id = ?");
        $st->execute([$pai_id, $mae_id, $tipo_pai, $tipo_mae, $filiacao_id, $filho_id]);
    } else {
        $st = $pdo->prepare("INSERT INTO filiacoes (filho_id, pai_id, mae_id, tipo_pai, tipo_mae) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$filho_id, $pai_id, $mae_id, $tipo_pai, $tipo_mae]);
    }
    return [];
}

function gen_remover_filiacao(PDO $pdo, $filiacao_id, $filho_id) {
    $st = $pdo->prepare("DELETE FROM filiacoes WHERE id = ? AND filho_id = ?");
    $st->execute([(int)$filiacao_id, (int)$filho_id]);
    return $st->rowCount() > 0;
}

/**
 * Insere (sem $uniao_id) ou atualiza uma união entre A e B (par normalizado). Devolve erros.
 * $aprox (opcional): ['data_inicio_qualificador'=>, 'data_inicio_precisao'=>, 'data_inicio_ate'=>,
 * 'data_fim_qualificador'=>, ...]. Sem $aprox: na inclusão fica exata/dia; na alteração
 * os qualificadores gravados não mudam.
 */
function gen_salvar_uniao(PDO $pdo, $a, $b, $tipo = 'casamento', $data_inicio = null, $local_inicio = null, $data_fim = null, $uniao_id = null, $aprox = null) {
    $a = gen_id($a); $b = gen_id($b); $uniao_id = gen_id($uniao_id);
    $tipo = $tipo ?: 'casamento';
    $erros = gen_validar_uniao($pdo, $a, $b, $tipo, $uniao_id, $data_inicio, $data_fim, $aprox);
    if ($erros) return $erros;
    $p1 = min($a, $b); $p2 = max($a, $b);
    $local_inicio = trim((string)$local_inicio) !== '' ? trim((string)$local_inicio) : null;
    $di = gen_data($data_inicio); $df = gen_data($data_fim);
    $extra_cols = []; $extra_vals = [];
    if (is_array($aprox)) {
        foreach (['data_inicio' => $di, 'data_fim' => $df] as $campo => $valor) {
            $v = gen_data_campo(array_merge($aprox, [$campo => $valor]), $campo);
            $extra_cols[] = $campo . '_qualificador'; $extra_vals[] = $v['qualificador'];
            $extra_cols[] = $campo . '_precisao';     $extra_vals[] = $v['precisao'];
            $extra_cols[] = $campo . '_ate';          $extra_vals[] = $v['ate'];
        }
    }
    if ($uniao_id) {
        $set = '';
        foreach ($extra_cols as $c) $set .= ", $c = ?";
        $st = $pdo->prepare("UPDATE unioes SET pessoa1_id = ?, pessoa2_id = ?, tipo = ?, data_inicio = ?, local_inicio = ?, data_fim = ?$set WHERE id = ?");
        $st->execute(array_merge([$p1, $p2, $tipo, $di, $local_inicio, $df], $extra_vals, [$uniao_id]));
    } else {
        $cols = $extra_cols ? ', ' . implode(', ', $extra_cols) : '';
        $st = $pdo->prepare("INSERT INTO unioes (pessoa1_id, pessoa2_id, tipo, data_inicio, local_inicio, data_fim$cols) VALUES (" . gen_placeholders(6 + count($extra_cols)) . ")");
        $st->execute(array_merge([$p1, $p2, $tipo, $di, $local_inicio, $df], $extra_vals));
    }
    return [];
}

/** Remove uma união, desde que a pessoa informada faça parte dela. */
function gen_remover_uniao(PDO $pdo, $uniao_id, $pessoa_id) {
    $st = $pdo->prepare("DELETE FROM unioes WHERE id = ? AND (pessoa1_id = ? OR pessoa2_id = ?)");
    $st->execute([(int)$uniao_id, (int)$pessoa_id, (int)$pessoa_id]);
    return $st->rowCount() > 0;
}

/**
 * Vincula $pessoa_id como pai ou mãe ($papel = 'pai'|'mae') de $filho_id.
 * Preenche o lado vazio de uma filiação existente do filho (preferindo aquela cujo
 * outro genitor é cônjuge da pessoa); se não houver, cria uma filiação nova.
 * O papel é conferido com o sexo (homem não vira mãe, mulher não vira pai).
 * Devolve erros (vazio = ok).
 */
function gen_vincular_como_genitor(PDO $pdo, $pessoa_id, $filho_id, $papel, $tipo = 'biologico') {
    $pessoa_id = gen_id($pessoa_id); $filho_id = gen_id($filho_id);
    if (!in_array($papel, ['pai', 'mae'], true)) return ['Escolha se a pessoa é o pai ou a mãe.'];
    if (!isset(gen_tipos_vinculo()[$tipo])) return ['Tipo de vínculo inválido.'];
    if (!$pessoa_id || !$filho_id) return ['Selecione o filho.'];
    if ($pessoa_id === $filho_id) return ['Uma pessoa não pode ser pai/mãe de si mesma.'];

    $conjuges = [];
    foreach (gen_unioes($pdo, $pessoa_id) as $u) { $conjuges[(int)$u['conjuge_id']] = true; }
    $col_vazia = $papel === 'pai' ? 'pai_id' : 'mae_id';
    $col_outra = $papel === 'pai' ? 'mae_id' : 'pai_id';

    $alvo = null;
    foreach (gen_filiacoes($pdo, $filho_id) as $f) {
        if ($f[$col_vazia]) continue;
        if ($alvo === null || ($f[$col_outra] && isset($conjuges[(int)$f[$col_outra]]))) $alvo = $f;
    }
    if ($alvo) {
        $pai = $papel === 'pai' ? $pessoa_id : $alvo['pai_id'];
        $mae = $papel === 'mae' ? $pessoa_id : $alvo['mae_id'];
        $tp = $papel === 'pai' ? $tipo : $alvo['tipo_pai'];
        $tm = $papel === 'mae' ? $tipo : $alvo['tipo_mae'];
        return gen_salvar_filiacao($pdo, $filho_id, $pai, $mae, $tp, $tm, $alvo['id']);
    }
    return gen_salvar_filiacao($pdo, $filho_id,
        $papel === 'pai' ? $pessoa_id : null, $papel === 'mae' ? $pessoa_id : null,
        $papel === 'pai' ? $tipo : 'biologico', $papel === 'mae' ? $tipo : 'biologico');
}

/** Apaga filiações que ficaram sem pai e sem mãe (ex.: após excluir um genitor). */
function gen_limpar_filiacoes_vazias(PDO $pdo) {
    return $pdo->exec("DELETE FROM filiacoes WHERE pai_id IS NULL AND mae_id IS NULL");
}

/** Retrato dos vínculos de uma pessoa (para o log de auditoria). */
function gen_snapshot_vinculos(PDO $pdo, $id) {
    $fil = [];
    foreach (gen_filiacoes($pdo, $id) as $f) {
        $fil[] = ['id' => (int)$f['id'], 'pai_id' => $f['pai_id'], 'mae_id' => $f['mae_id'], 'tipo_pai' => $f['tipo_pai'], 'tipo_mae' => $f['tipo_mae']];
    }
    $uni = [];
    foreach (gen_unioes($pdo, $id) as $u) {
        $uni[] = ['id' => (int)$u['id'], 'conjuge_id' => (int)$u['conjuge_id'], 'tipo' => $u['tipo'],
                  'data_inicio' => $u['data_inicio'], 'data_inicio_texto' => gen_data_texto($u, 'data_inicio'), 'local_inicio' => $u['local_inicio'],
                  'data_fim' => $u['data_fim'], 'data_fim_texto' => gen_data_texto($u, 'data_fim')];
    }
    $ev = [];
    foreach (gen_eventos($pdo, $id) as $e) {
        $ev[] = ['id' => (int)$e['id'], 'tipo' => $e['tipo'], 'data' => $e['data'], 'data_texto' => gen_data_texto($e, 'data'),
                 'local' => $e['local'], 'descricao' => $e['descricao'], 'uniao_id' => $e['uniao_id'], 'documento_id' => $e['documento_id']];
    }
    return ['filiacoes' => $fil, 'unioes' => $uni, 'eventos' => $ev];
}

// ---------------------------------------------------------------------
// Consultas por lote (navegação centrada numa pessoa / api/)
// ---------------------------------------------------------------------
// Não carregam a tabela inteira: cada chamada busca só os ids pedidos,
// com IN (...). Usadas por api/*.php e reaproveitáveis em outras páginas.

/** Normaliza uma lista de ids: inteiros positivos, únicos, na ordem original. */
function gen_ids_unicos(array $ids) {
    $out = [];
    foreach ($ids as $v) {
        $i = gen_id($v);
        if ($i && !isset($out[$i])) $out[$i] = $i;
    }
    return array_values($out);
}

/** Placeholders "?,?,?" para um IN (...) com $n itens. */
function gen_placeholders($n) {
    return implode(',', array_fill(0, max(1, (int)$n), '?'));
}

/**
 * Dados de card (sem biografia) de várias pessoas: [id => linha].
 * Ordem do resultado = ordem dos ids pedidos; ids inexistentes são omitidos.
 */
function gen_pessoas_por_ids(PDO $pdo, array $ids) {
    $ids = gen_ids_unicos($ids);
    if (!$ids) return [];
    $st = $pdo->prepare(
        "SELECT id, nome_completo, sexo, foto, data_nascimento, local_nascimento,
                data_falecimento, local_falecimento, " . gen_cols_datas_pessoa('') . "
           FROM pessoas WHERE id IN (" . gen_placeholders(count($ids)) . ")"
    );
    $st->execute($ids);
    $por_id = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $por_id[(int)$r['id']] = $r; }
    $out = [];
    foreach ($ids as $i) { if (isset($por_id[$i])) $out[$i] = $por_id[$i]; }
    return $out;
}

/** Filiações em que algum dos ids é o filho (biológicas primeiro, depois por id). */
function gen_filiacoes_dos_filhos(PDO $pdo, array $ids) {
    $ids = gen_ids_unicos($ids);
    if (!$ids) return [];
    $st = $pdo->prepare(
        "SELECT id, filho_id, pai_id, mae_id, tipo_pai, tipo_mae FROM filiacoes
          WHERE filho_id IN (" . gen_placeholders(count($ids)) . ")
          ORDER BY (tipo_pai = 'biologico' AND tipo_mae = 'biologico') DESC, id"
    );
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Filiações em que algum dos ids é pai ou mãe (filhos em ordem de nascimento). */
function gen_filiacoes_dos_genitores(PDO $pdo, array $ids) {
    $ids = gen_ids_unicos($ids);
    if (!$ids) return [];
    $ph = gen_placeholders(count($ids));
    $st = $pdo->prepare(
        "SELECT f.id, f.filho_id, f.pai_id, f.mae_id, f.tipo_pai, f.tipo_mae
           FROM filiacoes f
           JOIN pessoas p ON p.id = f.filho_id
          WHERE f.pai_id IN ($ph) OR f.mae_id IN ($ph)
          ORDER BY p.data_nascimento IS NULL, p.data_nascimento, p.id, f.id"
    );
    $st->execute(array_merge($ids, $ids));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Uniões em que algum dos ids participa (por data de início, depois id). */
function gen_unioes_das_pessoas(PDO $pdo, array $ids) {
    $ids = gen_ids_unicos($ids);
    if (!$ids) return [];
    $ph = gen_placeholders(count($ids));
    $st = $pdo->prepare(
        "SELECT id, pessoa1_id, pessoa2_id, tipo, data_inicio, local_inicio, data_fim, " . gen_cols_datas_uniao('') . "
           FROM unioes
          WHERE pessoa1_id IN ($ph) OR pessoa2_id IN ($ph)
          ORDER BY data_inicio IS NULL, data_inicio, id"
    );
    $st->execute(array_merge($ids, $ids));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ancestrais de $id por geração (busca em largura, uma consulta por nível).
 * Devolve [ [ids da geração 1], [ids da geração 2], ... ] (no máximo $geracoes níveis,
 * sem níveis vazios). Implexo e ciclos: cada pessoa aparece uma vez só, no nível mais
 * próximo; a própria pessoa nunca reaparece como ancestral.
 * Dentro do nível, a ordem é pai, mãe de cada filho do nível anterior.
 */
function gen_ancestrais_por_nivel(PDO $pdo, $id, $geracoes) {
    $id = (int)$id;
    $visitados = [$id => true];
    $niveis = [];
    $fronteira = [$id];
    for ($g = 1; $g <= (int)$geracoes && $fronteira; $g++) {
        $prox = [];
        $pos = array_flip($fronteira);
        $fils = gen_filiacoes_dos_filhos($pdo, $fronteira);
        // ordem estável: posição do filho na fronteira, depois a ordem do banco
        foreach ($fils as $i => &$f) { $f['_ordem'] = $i; }
        unset($f);
        usort($fils, function ($a, $b) use ($pos) {
            $c = $pos[(int)$a['filho_id']] <=> $pos[(int)$b['filho_id']];
            return $c !== 0 ? $c : $a['_ordem'] <=> $b['_ordem'];
        });
        foreach ($fils as $f) {
            foreach (['pai_id', 'mae_id'] as $k) {
                $gid = $f[$k] ? (int)$f[$k] : 0;
                if ($gid && !isset($visitados[$gid])) { $visitados[$gid] = true; $prox[] = $gid; }
            }
        }
        if ($prox) $niveis[] = $prox;
        $fronteira = $prox;
    }
    return $niveis;
}

/**
 * Descendentes de $id por geração, com os cônjuges de cada descendente.
 * Devolve:
 *   'conjuges_foco' => [ids]  (cônjuges da própria pessoa e outros genitores dos filhos dela)
 *   'niveis' => [ ['geracao' => 1, 'pessoas' => [ids], 'conjuges' => [ids]], ... ]
 * 'conjuges' de um nível = cônjuges (uniões) dos descendentes desse nível e, quando há
 * nível seguinte, o outro genitor dos filhos deles mesmo sem união registrada.
 * Cada pessoa entra uma vez como descendente (implexo/ciclo); quem já foi incluído não
 * se repete como cônjuge. Um cônjuge que também descende de $id aparece nos dois papéis.
 */
function gen_descendentes_por_nivel(PDO $pdo, $id, $geracoes) {
    $id = (int)$id;
    $geracoes = (int)$geracoes;
    $desc = [$id => true];     // já visitados como descendente
    $todos = [$id => true];    // já incluídos na resposta (qualquer papel)
    $out = ['conjuges_foco' => [], 'niveis' => []];
    $fronteira = [$id];
    for ($g = 0; $g <= $geracoes && $fronteira; $g++) {
        $conj = [];
        $em_fronteira = array_flip($fronteira);
        foreach (gen_unioes_das_pessoas($pdo, $fronteira) as $u) {
            $a = (int)$u['pessoa1_id']; $b = (int)$u['pessoa2_id'];
            foreach ([[$a, $b], [$b, $a]] as $par) {
                if (isset($em_fronteira[$par[0]]) && !isset($todos[$par[1]])) {
                    $todos[$par[1]] = true; $conj[] = $par[1];
                }
            }
        }
        $prox = [];
        if ($g < $geracoes) {
            foreach (gen_filiacoes_dos_genitores($pdo, $fronteira) as $f) {
                $fid = (int)$f['filho_id'];
                if (!isset($desc[$fid])) { $desc[$fid] = true; $todos[$fid] = true; $prox[] = $fid; }
                foreach (['pai_id', 'mae_id'] as $k) {   // outro genitor sem união registrada
                    $gid = $f[$k] ? (int)$f[$k] : 0;
                    if ($gid && !isset($em_fronteira[$gid]) && !isset($todos[$gid])) {
                        $todos[$gid] = true; $conj[] = $gid;
                    }
                }
            }
        }
        if ($g === 0) $out['conjuges_foco'] = $conj;
        else $out['niveis'][$g - 1]['conjuges'] = $conj;
        if ($prox) $out['niveis'][] = ['geracao' => $g + 1, 'pessoas' => $prox, 'conjuges' => []];
        $fronteira = $prox;
    }
    return $out;
}

/** Caminho de foto seguro para devolver ao navegador (relativo à raiz do site) ou null. */
function gen_foto_publica($foto) {
    $foto = str_replace('\\', '/', trim((string)$foto));
    if ($foto === '') return null;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $foto) || $foto[0] === '/' || strpos($foto, '..') !== false) return null;
    if (preg_match('#[\s"\'<>]#', $foto)) return null;
    return $foto;
}

/** Texto vazio vira null; o resto sai sem espaços nas pontas. */
function gen_texto_ou_null($v) {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/**
 * Card público de uma pessoa (sem biografia, e-mail ou dados de usuário).
 * $flags: tem_mais_ancestrais / tem_mais_descendentes / tem_mais_conjuges.
 */
function gen_pessoa_card(array $r, array $flags = []) {
    $sexo = isset(gen_sexos()[$r['sexo'] ?? '']) ? $r['sexo'] : 'D';
    return [
        'id' => (int)$r['id'],
        'nome' => trim((string)$r['nome_completo']),
        'sexo' => $sexo,
        'foto' => gen_foto_publica($r['foto'] ?? null),
        'nascimento' => gen_data_json($r, 'data_nascimento', gen_texto_ou_null($r['local_nascimento'] ?? null)),
        'falecimento' => gen_data_json($r, 'data_falecimento', gen_texto_ou_null($r['local_falecimento'] ?? null)),
        'anos' => gen_anos_vida_linha($r),
        'tem_mais_ancestrais' => !empty($flags['tem_mais_ancestrais']),
        'tem_mais_descendentes' => !empty($flags['tem_mais_descendentes']),
        'tem_mais_conjuges' => !empty($flags['tem_mais_conjuges']),
        // absolutas (no banco todo): decidem as setas ▲ / ▼ / ◀▶ da paisagem
        'tem_pais' => !empty($flags['tem_pais']),
        'tem_filhos' => !empty($flags['tem_filhos']),
        'tem_irmaos' => !empty($flags['tem_irmaos']),
    ];
}

/** Ids (entre os pedidos) que têm ao menos um irmão ou meio-irmão: [id => true]. */
function gen_ids_com_irmaos(PDO $pdo, array $ids) {
    $ids = gen_ids_unicos($ids);
    if (!$ids) return [];
    $st = $pdo->prepare(
        "SELECT DISTINCT f1.filho_id
           FROM filiacoes f1
           JOIN filiacoes f2 ON f2.filho_id <> f1.filho_id
                            AND ((f1.pai_id IS NOT NULL AND f2.pai_id = f1.pai_id)
                              OR (f1.mae_id IS NOT NULL AND f2.mae_id = f1.mae_id))
          WHERE f1.filho_id IN (" . gen_placeholders(count($ids)) . ")"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) { $out[(int)$i] = true; }
    return $out;
}

/**
 * Subgrafo de um conjunto de pessoas, pronto para desenhar sem recalcular.
 * 4 consultas, independentemente do tamanho do conjunto. Devolve:
 *   'pessoas'   => [id => card]  (gen_pessoa_card, na ordem dos ids; com as flags
 *                  tem_mais_ancestrais / tem_mais_descendentes / tem_mais_conjuges,
 *                  verdadeiras quando existe pai/mãe, filho ou cônjuge FORA do conjunto;
 *                  e tem_pais / tem_filhos / tem_irmaos, absolutas: existe no banco)
 *   'filiacoes' => [ {id, filho_id, pai_id, mae_id, tipo_pai, tipo_mae, principal} ]
 *                  só filiações cujo filho e ao menos um genitor estão no conjunto;
 *                  genitor fora do conjunto vem null (com tipo null). principal = primeira
 *                  filiação do filho no banco (biológica de preferência).
 *   'unioes'    => [ {id, pessoa1_id, pessoa2_id, tipo, data_inicio, local_inicio, data_fim} ]
 *                  só uniões com os dois cônjuges no conjunto.
 * Todas as referências de 'filiacoes' e 'unioes' apontam para ids presentes em 'pessoas'.
 * $flags_absolutas = true: as flags passam a dizer se a pessoa tem pais/filhos/cônjuges
 * registrados, dentro ou fora do conjunto (útil para listas soltas, como a busca).
 */
function gen_subgrafo(PDO $pdo, array $ids, $flags_absolutas = false) {
    $linhas = gen_pessoas_por_ids($pdo, $ids);
    $ids = array_keys($linhas);
    $no = array_flip($ids);
    $sub = ['pessoas' => [], 'filiacoes' => [], 'unioes' => []];
    if (!$ids) return $sub;

    $fora_pais = []; $fora_filhos = []; $fora_conj = [];
    $tem_pais = []; $tem_filhos = [];

    $principal_de = [];
    foreach (gen_filiacoes_dos_filhos($pdo, $ids) as $f) {
        $filho = (int)$f['filho_id'];
        $principal = !isset($principal_de[$filho]);
        if ($principal) $principal_de[$filho] = (int)$f['id'];
        $pai = $f['pai_id'] ? (int)$f['pai_id'] : null;
        $mae = $f['mae_id'] ? (int)$f['mae_id'] : null;
        if ($pai || $mae) $tem_pais[$filho] = true;
        foreach ([$pai, $mae] as $gid) { if ($gid && ($flags_absolutas || !isset($no[$gid]))) $fora_pais[$filho] = true; }
        $pai_in = ($pai && isset($no[$pai])) ? $pai : null;
        $mae_in = ($mae && isset($no[$mae])) ? $mae : null;
        if (!$pai_in && !$mae_in) continue;
        $sub['filiacoes'][] = [
            'id' => (int)$f['id'],
            'filho_id' => $filho,
            'pai_id' => $pai_in,
            'mae_id' => $mae_in,
            'tipo_pai' => $pai_in ? $f['tipo_pai'] : null,
            'tipo_mae' => $mae_in ? $f['tipo_mae'] : null,
            'principal' => $principal,
        ];
    }

    foreach (gen_filiacoes_dos_genitores($pdo, $ids) as $f) {
        foreach (['pai_id', 'mae_id'] as $k) {
            if ($f[$k] && isset($no[(int)$f[$k]])) $tem_filhos[(int)$f[$k]] = true;
        }
        if (!$flags_absolutas && isset($no[(int)$f['filho_id']])) continue;
        foreach (['pai_id', 'mae_id'] as $k) {
            if ($f[$k] && isset($no[(int)$f[$k]])) $fora_filhos[(int)$f[$k]] = true;
        }
    }

    foreach (gen_unioes_das_pessoas($pdo, $ids) as $u) {
        $a = (int)$u['pessoa1_id']; $b = (int)$u['pessoa2_id'];
        if (isset($no[$a]) && isset($no[$b])) {
            $sub['unioes'][] = [
                'id' => (int)$u['id'],
                'pessoa1_id' => $a,
                'pessoa2_id' => $b,
                'tipo' => $u['tipo'],
                'data_inicio' => $u['data_inicio'],
                'local_inicio' => gen_texto_ou_null($u['local_inicio']),
                'data_fim' => $u['data_fim'],
                'data_inicio_texto' => gen_data_texto($u, 'data_inicio'),
                'data_fim_texto' => gen_data_texto($u, 'data_fim'),
            ];
        } else {
            $fora_conj[isset($no[$a]) ? $a : $b] = true;
        }
        if ($flags_absolutas) {
            $fora_conj[$a] = true; $fora_conj[$b] = true;
        }
    }

    $tem_irmaos = gen_ids_com_irmaos($pdo, $ids);
    foreach ($linhas as $id => $r) {
        $sub['pessoas'][$id] = gen_pessoa_card($r, [
            'tem_mais_ancestrais' => isset($fora_pais[$id]),
            'tem_mais_descendentes' => isset($fora_filhos[$id]),
            'tem_mais_conjuges' => isset($fora_conj[$id]),
            'tem_pais' => isset($tem_pais[$id]),
            'tem_filhos' => isset($tem_filhos[$id]),
            'tem_irmaos' => isset($tem_irmaos[$id]),
        ]);
    }
    return $sub;
}

/**
 * Pessoa "raiz padrão" da árvore: GEN_RAIZ_PADRAO_ID se estiver definida (define()
 * antes do require) e existir; senão, a pessoa mais antiga (por nascimento; sem data
 * por último) que tem filhos e não tem pais registrados; senão, a de menor id.
 * null se não houver ninguém cadastrado.
 */
function gen_raiz_padrao(PDO $pdo) {
    if (defined('GEN_RAIZ_PADRAO_ID') && gen_pessoa($pdo, GEN_RAIZ_PADRAO_ID)) return (int)GEN_RAIZ_PADRAO_ID;
    $id = $pdo->query(
        "SELECT p.id FROM pessoas p
          WHERE EXISTS (SELECT 1 FROM filiacoes f WHERE f.pai_id = p.id OR f.mae_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM filiacoes f WHERE f.filho_id = p.id)
          ORDER BY p.data_nascimento IS NULL, p.data_nascimento, p.id LIMIT 1"
    )->fetchColumn();
    if (!$id) $id = $pdo->query("SELECT id FROM pessoas ORDER BY id LIMIT 1")->fetchColumn();
    return $id ? (int)$id : null;
}

// ---------------------------------------------------------------------
// Eventos (Rec. 8): batismo, imigração, naturalização, residência,
// sepultamento, ocupação, outro. Nascimento/falecimento ficam em `pessoas`
// e casamento em `unioes`.
// ---------------------------------------------------------------------

function gen_tipos_evento() {
    return [
        'batismo'       => 'Batismo',
        'imigracao'     => 'Imigração',
        'naturalizacao' => 'Naturalização',
        'residencia'    => 'Residência',
        'sepultamento'  => 'Sepultamento',
        'ocupacao'      => 'Ocupação',
        'outro'         => 'Outro',
    ];
}

function gen_rotulo_evento($tipo) {
    $t = gen_tipos_evento();
    return isset($t[$tipo]) ? $t[$tipo] : $t['outro'];
}

/** Ícone Font Awesome 6 por tipo de evento. */
function gen_evento_icone($tipo) {
    $i = [
        'batismo' => 'fa-droplet', 'imigracao' => 'fa-ship', 'naturalizacao' => 'fa-flag',
        'residencia' => 'fa-house', 'sepultamento' => 'fa-monument', 'ocupacao' => 'fa-briefcase',
        'nascimento' => 'fa-baby', 'falecimento' => 'fa-cross', 'casamento' => 'fa-ring',
    ];
    return isset($i[$tipo]) ? $i[$tipo] : 'fa-calendar-day';
}

/**
 * Eventos de uma pessoa (por data; sem data por último), com o documento-fonte
 * (documento_tipo, documento_descricao, documento_caminho) e, se ligado a uma união,
 * o cônjuge (conjuge_id, conjuge_nome).
 */
function gen_eventos(PDO $pdo, $pessoa_id) {
    $st = $pdo->prepare(
        "SELECT e.id, e.pessoa_id, e.uniao_id, e.tipo, e.`data`, e.data_qualificador, e.data_precisao, e.data_ate,
                e.`local`, e.descricao, e.documento_id,
                d.tipo_documento AS documento_tipo, d.descricao AS documento_descricao, d.caminho_arquivo AS documento_caminho,
                c.id AS conjuge_id, c.nome_completo AS conjuge_nome
           FROM eventos e
           LEFT JOIN documentos d ON d.id = e.documento_id
           LEFT JOIN unioes u ON u.id = e.uniao_id
           LEFT JOIN pessoas c ON c.id = CASE WHEN u.pessoa1_id = e.pessoa_id THEN u.pessoa2_id ELSE u.pessoa1_id END
          WHERE e.pessoa_id = ?
          ORDER BY e.`data` IS NULL, e.`data`, e.id"
    );
    $st->execute([(int)$pessoa_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Evento no formato da API (público: sem caminho de arquivo). */
function gen_evento_json(array $e) {
    $doc = null;
    if (!empty($e['documento_id'])) {
        $doc = ['id' => (int)$e['documento_id'], 'rotulo' => gen_rotulo_documento([
            'id' => $e['documento_id'], 'tipo_documento' => $e['documento_tipo'] ?? '', 'descricao' => $e['documento_descricao'] ?? '',
        ])];
    }
    return [
        'id' => (int)$e['id'],
        'tipo' => $e['tipo'],
        'rotulo_tipo' => gen_rotulo_evento($e['tipo']),
        'icone' => gen_evento_icone($e['tipo']),
        'data' => gen_data_json($e, 'data', gen_texto_ou_null($e['local'] ?? null)),
        'local' => gen_texto_ou_null($e['local'] ?? null),
        'descricao' => gen_texto_ou_null($e['descricao'] ?? null),
        'uniao_id' => $e['uniao_id'] ? (int)$e['uniao_id'] : null,
        'conjuge_id' => !empty($e['conjuge_id']) && $e['uniao_id'] ? (int)$e['conjuge_id'] : null,
        'documento' => $doc,
    ];
}

/** Documentos de uma pessoa (para escolher a fonte de um evento). */
function gen_documentos_pessoa(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT id, id_pessoa, tipo_documento, caminho_arquivo, descricao, data_upload FROM documentos WHERE id_pessoa = ? ORDER BY data_upload DESC, id DESC");
    $st->execute([(int)$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** "Passaporte — 1111" / "Documento #3". */
function gen_rotulo_documento(array $d) {
    $tipo = trim((string)($d['tipo_documento'] ?? ''));
    $desc = trim((string)($d['descricao'] ?? ''));
    if ($tipo === '') $tipo = 'Documento';
    return $desc !== '' ? $tipo . ' — ' . $desc : $tipo . ' #' . (int)($d['id'] ?? 0);
}

/**
 * Normaliza um evento vindo do formulário:
 * in = [tipo, data, data_qualificador, data_precisao, data_ate, local, descricao, uniao_id, documento_id].
 * Devolve ['valor' => [...], 'erros' => [...]] (valor com a data já em AAAA-MM-DD).
 */
function gen_ler_evento(array $in, $rotulo = 'do evento') {
    $d = gen_ler_data_aprox([
        'data' => $in['data'] ?? '', 'qualificador' => $in['data_qualificador'] ?? 'exata',
        'precisao' => $in['data_precisao'] ?? 'dia', 'ate' => $in['data_ate'] ?? '',
    ], $rotulo);
    return [
        'valor' => [
            'tipo' => (string)($in['tipo'] ?? 'outro'),
            'data' => $d['valor']['data'],
            'data_qualificador' => $d['valor']['qualificador'],
            'data_precisao' => $d['valor']['precisao'],
            'data_ate' => $d['valor']['ate'],
            'local' => gen_texto_ou_null($in['local'] ?? null),
            'descricao' => gen_texto_ou_null($in['descricao'] ?? null),
            'uniao_id' => gen_id($in['uniao_id'] ?? null),
            'documento_id' => gen_id($in['documento_id'] ?? null),
        ],
        'erros' => $d['erros'],
    ];
}

/** Valida um evento (já normalizado por gen_ler_evento) de $pessoa_id. */
function gen_validar_evento(PDO $pdo, $pessoa_id, array $ev) {
    $erros = [];
    if (!isset(gen_tipos_evento()[$ev['tipo'] ?? ''])) $erros[] = 'Tipo de evento inválido.';
    if (empty($ev['data']) && empty($ev['local']) && empty($ev['descricao'])) $erros[] = 'Informe ao menos a data, o local ou a descrição do evento.';
    $conjuge = null;
    if (!empty($ev['uniao_id'])) {
        $st = $pdo->prepare("SELECT pessoa1_id, pessoa2_id FROM unioes WHERE id = ? AND (pessoa1_id = ? OR pessoa2_id = ?)");
        $st->execute([(int)$ev['uniao_id'], (int)$pessoa_id, (int)$pessoa_id]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) $erros[] = 'A união escolhida não pertence a esta pessoa.';
        else $conjuge = (int)$u['pessoa1_id'] === (int)$pessoa_id ? (int)$u['pessoa2_id'] : (int)$u['pessoa1_id'];
    }
    if (!empty($ev['documento_id'])) {
        $st = $pdo->prepare("SELECT id_pessoa FROM documentos WHERE id = ?");
        $st->execute([(int)$ev['documento_id']]);
        $dono = $st->fetchColumn();
        if ($dono === false) $erros[] = 'O documento escolhido como fonte não existe.';
        elseif ((int)$dono !== (int)$pessoa_id && (int)$dono !== (int)$conjuge) $erros[] = 'O documento escolhido como fonte não pertence a esta pessoa.';
    }
    $linha = ['data' => $ev['data'] ?? null, 'data_qualificador' => $ev['data_qualificador'] ?? 'exata', 'data_precisao' => $ev['data_precisao'] ?? 'dia', 'data_ate' => $ev['data_ate'] ?? null];
    $v = gen_data_campo($linha, 'data');
    if ($v['qualificador'] === 'entre' && (!$v['ate'] || $v['ate'] <= $v['data'])) $erros[] = 'Na data do evento, o fim da faixa deve ser posterior ao início.';
    return $erros;
}

/** Insere (sem $evento_id) ou atualiza um evento de $pessoa_id. Devolve erros. */
function gen_salvar_evento(PDO $pdo, $pessoa_id, array $ev, $evento_id = null) {
    $pessoa_id = gen_id($pessoa_id); $evento_id = gen_id($evento_id);
    if (!$pessoa_id) return ['Pessoa inexistente.'];
    $erros = gen_validar_evento($pdo, $pessoa_id, $ev);
    if ($erros) return $erros;
    $vals = [
        $ev['uniao_id'] ?: null, $ev['tipo'], $ev['data'] ?: null, $ev['data_qualificador'] ?: 'exata', $ev['data_precisao'] ?: 'dia',
        ($ev['data_qualificador'] ?? '') === 'entre' ? ($ev['data_ate'] ?: null) : null,
        $ev['local'] ?: null, $ev['descricao'] ?: null, $ev['documento_id'] ?: null,
    ];
    if ($evento_id) {
        $st = $pdo->prepare("UPDATE eventos SET uniao_id = ?, tipo = ?, `data` = ?, data_qualificador = ?, data_precisao = ?, data_ate = ?,
                                    `local` = ?, descricao = ?, documento_id = ? WHERE id = ? AND pessoa_id = ?");
        $st->execute(array_merge($vals, [$evento_id, $pessoa_id]));
    } else {
        $st = $pdo->prepare("INSERT INTO eventos (pessoa_id, uniao_id, tipo, `data`, data_qualificador, data_precisao, data_ate, `local`, descricao, documento_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute(array_merge([$pessoa_id], $vals));
    }
    return [];
}

function gen_remover_evento(PDO $pdo, $evento_id, $pessoa_id) {
    $st = $pdo->prepare("DELETE FROM eventos WHERE id = ? AND pessoa_id = ?");
    $st->execute([(int)$evento_id, (int)$pessoa_id]);
    return $st->rowCount() > 0;
}

/**
 * Filho não pode nascer antes dos pais. Confere a pessoa como filho (contra cada genitor)
 * e como pai/mãe (contra cada filho).
 * Datas exatas dos dois lados e certamente anteriores → erro; se alguma for aproximada
 * (cerca/antes/depois/entre ou só o valor gravado indica) → aviso.
 * Devolve ['erros' => [], 'avisos' => []].
 */
function gen_verificar_nascimento_pais(PDO $pdo, $id) {
    $out = ['erros' => [], 'avisos' => []];
    $id = (int)$id;
    $st = $pdo->prepare("SELECT filho_id, pai_id, mae_id FROM filiacoes WHERE filho_id = ? OR pai_id = ? OR mae_id = ?");
    $st->execute([$id, $id, $id]);
    $pares = []; $ids = [$id];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        foreach (['pai_id' => 'pai', 'mae_id' => 'mae'] as $col => $papel) {
            $g = $f[$col] ? (int)$f[$col] : 0;
            $filho = (int)$f['filho_id'];
            if (!$g || ($filho !== $id && $g !== $id)) continue;
            $pares[$filho . '-' . $g] = [$filho, $g, $papel];
            $ids[] = $filho; $ids[] = $g;
        }
    }
    if (!$pares) return $out;
    $p = gen_pessoas_por_ids($pdo, $ids);
    foreach ($pares as $par) {
        list($filho, $g, $papel) = $par;
        if (!isset($p[$filho]) || !isset($p[$g])) continue;
        $dc = gen_data_campo($p[$filho], 'data_nascimento');
        $dg = gen_data_campo($p[$g], 'data_nascimento');
        $c = gen_comparar_datas($dc, $dg);
        if ($c === null) continue;
        $msg = $p[$filho]['nome_completo'] . ' (nascimento ' . gen_formatar_data_campo($dc) . ') nasceu antes de '
             . ($papel === 'pai' ? 'seu pai, ' : 'sua mãe, ') . $p[$g]['nome_completo'] . ' (nascimento ' . gen_formatar_data_campo($dg) . ').';
        if ($c === 'antes' && $dc['qualificador'] === 'exata' && $dg['qualificador'] === 'exata') $out['erros'][] = $msg;
        else $out['avisos'][] = 'Confira as datas (aproximadas): ' . $msg;
    }
    return $out;
}
