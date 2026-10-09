<?php
/**
 * POST api/adicionar_parente.php  (exige login)
 * Adiciona um parente de uma pessoa direto pela árvore (botão "+" do card, assets/js/arvore.js).
 *   ancora_id   pessoa a partir da qual o parente é adicionado
 *   relacao     pai | mae | conjuge | filho | irmao  (o que o parente é da âncora)
 *   pessoa_id   vincula alguém já cadastrado; sem ele, cria a pessoa com:
 *               nome_completo, sexo (M|F|D), data_nascimento, data_falecimento
 *               (texto: DD/MM/AAAA, MM/AAAA ou AAAA)
 *   outro_genitor_id  (filho) o outro pai/mãe, opcional
 *   tipo_uniao        (conjuge) casamento | uniao_estavel | outro
 * Pessoa e vínculo são gravados numa transação: qualquer erro desfaz tudo.
 * Resposta: {ok, id, nome, criado, avisos} ou {erro, erros, status} (422 = validação).
 */
define('API_ESCRITA', true);
require __DIR__ . '/_comum.php';

$txt = function ($k) {
    return isset($_POST[$k]) && !is_array($_POST[$k]) ? trim((string)$_POST[$k]) : '';
};
$falhar = function (array $erros) {
    api_responder(['erro' => implode(' ', $erros), 'erros' => array_values($erros), 'status' => 422], 422);
};

$ancora = gen_pessoa($pdo, $txt('ancora_id'));
if (!$ancora) api_erro(404, 'Pessoa não encontrada.');
$ancora_id = (int)$ancora['id'];

$relacao = $txt('relacao');
if (!in_array($relacao, ['pai', 'mae', 'conjuge', 'filho', 'irmao'], true)) api_erro(400, "Parâmetro 'relacao' inválido.");

// ---- quem é o parente: alguém já cadastrado ou uma pessoa nova
$existente_id = gen_id($txt('pessoa_id'));
$erros = [];
$d = null;
if ($txt('pessoa_id') !== '') {
    $parente = $existente_id ? gen_pessoa($pdo, $existente_id) : null;
    if (!$parente) api_erro(404, 'A pessoa escolhida não existe mais.');
    if ($existente_id === $ancora_id) $falhar(['Escolha uma pessoa diferente de ' . $ancora['nome_completo'] . '.']);
    $nome = $parente['nome_completo'];
} else {
    $sexo = $txt('sexo') !== '' ? $txt('sexo') : 'D';
    if ($relacao === 'pai') $sexo = 'M';
    if ($relacao === 'mae') $sexo = 'F';
    $d = ['nome_completo' => $txt('nome_completo'), 'sexo' => $sexo];
    foreach (['data_nascimento' => 'nascimento', 'data_falecimento' => 'falecimento'] as $campo => $rot) {
        $r = gen_ler_data_aprox(['data' => $txt($campo)], $rot);
        $erros = array_merge($erros, $r['erros']);
        $d[$campo] = $r['valor']['data'];
        $d[$campo . '_qualificador'] = $r['valor']['qualificador'];
        $d[$campo . '_precisao'] = $r['valor']['precisao'];
        $d[$campo . '_ate'] = $r['valor']['ate'];
    }
    if (!$erros) $erros = gen_validar_dados_pessoa($d);
    elseif ($d['nome_completo'] === '') $erros[] = 'O nome completo é obrigatório.';
    if ($erros) $falhar($erros);
    $nome = $d['nome_completo'];
}

$avisos = $d ? gen_validar_datas_pessoa($d)['avisos'] : [];

$pdo->beginTransaction();
try {
    if ($d) {
        $cols = ['nome_completo', 'sexo', 'data_nascimento', 'data_nascimento_qualificador', 'data_nascimento_precisao', 'data_nascimento_ate',
                 'data_falecimento', 'data_falecimento_qualificador', 'data_falecimento_precisao', 'data_falecimento_ate'];
        $vals = [];
        foreach ($cols as $c) $vals[] = $d[$c];
        $st = $pdo->prepare("INSERT INTO pessoas (" . implode(', ', $cols) . ") VALUES (" . gen_placeholders(count($cols)) . ")");
        $st->execute($vals);
        $novo_id = (int)$pdo->lastInsertId();
    } else {
        $novo_id = $existente_id;
    }

    // Liga $genitor como pai/mãe de $filho, a não ser que já seja.
    $vincular = function ($genitor, $filho, $papel, $tipo = 'biologico') use ($pdo) {
        foreach (gen_pais($pdo, $filho) as $g) { if ($g['id'] === (int)$genitor) return null; }
        return gen_vincular_como_genitor($pdo, $genitor, $filho, $papel, $tipo);
    };

    if ($relacao === 'pai' || $relacao === 'mae') {
        $erros = gen_vincular_como_genitor($pdo, $novo_id, $ancora_id, $relacao);
    } elseif ($relacao === 'conjuge') {
        $erros = gen_salvar_uniao($pdo, $ancora_id, $novo_id, $txt('tipo_uniao') !== '' ? $txt('tipo_uniao') : 'casamento');
    } elseif ($relacao === 'filho') {
        $outro = null;
        if ($txt('outro_genitor_id') !== '') {
            $outro = gen_pessoa($pdo, $txt('outro_genitor_id'));
            if (!$outro || (int)$outro['id'] === $ancora_id || (int)$outro['id'] === $novo_id) $erros[] = 'O outro genitor escolhido é inválido.';
        }
        if (!$erros) {
            // papel da âncora pelo sexo; sem sexo informado, fica com o lado que o outro genitor deixa livre
            $papel = $ancora['sexo'] === 'F' ? 'mae' : ($ancora['sexo'] === 'M' ? 'pai' : ($outro && $outro['sexo'] === 'M' ? 'mae' : 'pai'));
            $papel_outro = $papel === 'pai' ? 'mae' : 'pai';
            $algum = false;
            foreach ([[$ancora_id, $papel], [$outro ? (int)$outro['id'] : null, $papel_outro]] as $par) {
                if ($erros || !$par[0]) continue;
                $feito = $vincular($par[0], $novo_id, $par[1]);
                if ($feito !== null) { $algum = true; $erros = $feito; }
            }
            if (!$erros && !$algum) $erros[] = $nome . ' já é filho(a) de ' . $ancora['nome_completo'] . '.';
        }
    } else { // irmao: mesmos pais da filiação principal da âncora
        $fils = gen_filiacoes($pdo, $ancora_id);
        $f = null;
        foreach ($fils as $x) { if ($x['pai_id'] || $x['mae_id']) { $f = $x; break; } }
        if (!$f) {
            $erros[] = 'Cadastre primeiro o pai ou a mãe de ' . $ancora['nome_completo'] . ' para poder adicionar irmãos.';
        } else {
            $algum = false;
            foreach (['pai', 'mae'] as $papel) {
                if ($erros || !$f[$papel . '_id']) continue;
                $feito = $vincular((int)$f[$papel . '_id'], $novo_id, $papel, $f['tipo_' . $papel]);
                if ($feito !== null) { $algum = true; $erros = $feito; }
            }
            if (!$erros && !$algum) $erros[] = $nome . ' já é irmão(ã) de ' . $ancora['nome_completo'] . '.';
        }
    }

    if (!$erros) {
        $chk = gen_verificar_nascimento_pais($pdo, $novo_id);
        $erros = $chk['erros'];
        $avisos = array_merge($avisos, $chk['avisos']);
    }
    if ($erros) {
        $pdo->rollBack();
        $falhar($erros);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
}

$rotulos = ['pai' => 'pai', 'mae' => 'mãe', 'conjuge' => 'cônjuge', 'filho' => 'filho(a)', 'irmao' => 'irmão(ã)'];
registrarLog($pdo, $d ? 'Cadastrou novo membro pela árvore' : 'Vinculou parente pela árvore',
    "Nome: $nome (ID: $novo_id), {$rotulos[$relacao]} de {$ancora['nome_completo']} (ID: $ancora_id)");

api_responder(['ok' => true, 'id' => $novo_id, 'nome' => $nome, 'criado' => (bool)$d, 'avisos' => array_values($avisos)]);
