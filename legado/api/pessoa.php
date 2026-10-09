<?php
/**
 * GET api/pessoa.php?id=X
 * Pessoa em foco + vizinhança imediata: pais (por filiação), famílias (uniões com os
 * filhos de cada uma; filhos com outro genitor sem união; filhos sem o outro genitor)
 * e irmãos (completos ou meio-irmãos), eventos (imigração etc.). Inclui as listas normalizadas
 * pessoas/unioes/filiacoes. Toda data vem também como texto formatado (gen_formatar_data).
 */
require __DIR__ . '/_comum.php';

$p = api_pessoa_obrigatoria($pdo);
$id = (int)$p['id'];

$filiacoes = gen_filiacoes($pdo, $id);
$familias = gen_familias($pdo, $id);
$irmaos = gen_irmaos($pdo, $id);

$ids = [$id];
foreach ($filiacoes as $f) { $ids[] = $f['pai_id']; $ids[] = $f['mae_id']; }
foreach ($familias as $fam) {
    $ids[] = $fam['conjuge_id'];
    foreach ($fam['filhos'] as $c) { $ids[] = $c['id']; }
}
foreach ($irmaos as $i) { $ids[] = $i['id']; }
$sub = gen_subgrafo($pdo, $ids);

// Pessoa em foco: card + biografia (limitada; o texto completo fica em perfil.php)
$limite_bio = 600;
$bio = gen_texto_ou_null($p['biografia'] ?? null);
$bio_truncada = $bio !== null && mb_strlen($bio, 'UTF-8') > $limite_bio;
if ($bio_truncada) $bio = rtrim(mb_substr($bio, 0, $limite_bio, 'UTF-8')) . '…';
$pessoa = $sub['pessoas'][$id];
$pessoa['biografia'] = $bio;
$pessoa['biografia_truncada'] = $bio_truncada;

$pais = [];
foreach ($filiacoes as $i => $f) {
    $pai = $f['pai_id'] ? (int)$f['pai_id'] : null;
    $mae = $f['mae_id'] ? (int)$f['mae_id'] : null;
    $pais[] = [
        'filiacao_id' => (int)$f['id'],
        'principal' => $i === 0,
        'pai_id' => $pai,
        'mae_id' => $mae,
        'tipo_pai' => $pai ? $f['tipo_pai'] : null,
        'tipo_mae' => $mae ? $f['tipo_mae'] : null,
        'rotulo_pai' => $pai ? gen_rotulo_vinculo($f['tipo_pai'], 'pai') : null,
        'rotulo_mae' => $mae ? gen_rotulo_vinculo($f['tipo_mae'], 'mae') : null,
    ];
}

$fams = [];
foreach ($familias as $fam) {
    $u = $fam['uniao'];
    $filhos = [];
    foreach ($fam['filhos'] as $c) {
        $filhos[] = [
            'id' => (int)$c['id'],
            'filiacao_id' => (int)$c['filiacao_id'],
            'tipo' => $c['tipo'],
            'rotulo_tipo' => gen_rotulo_vinculo($c['tipo'], $c['papel']),
        ];
    }
    $fams[] = [
        'uniao_id' => $u ? (int)$u['id'] : null,
        'conjuge_id' => $fam['conjuge_id'] !== null ? (int)$fam['conjuge_id'] : null,
        'tipo' => $u ? $u['tipo'] : null,
        'rotulo_tipo' => $u ? gen_rotulo_uniao($u['tipo']) : null,
        'data_inicio' => $u ? $u['data_inicio'] : null,
        'data_inicio_texto' => $u ? (gen_data_texto($u, 'data_inicio') ?: null) : null,
        'local_inicio' => $u ? gen_texto_ou_null($u['local_inicio']) : null,
        'data_fim' => $u ? $u['data_fim'] : null,
        'data_fim_texto' => $u ? (gen_data_texto($u, 'data_fim') ?: null) : null,
        'filhos' => $filhos,
    ];
}

$irm = [];
foreach ($irmaos as $i) {
    $irm[] = ['id' => (int)$i['id'], 'tipo' => $i['tipo']];
}

// Eventos (batismo, imigração...), por data; datas já formatadas em data.texto
$eventos = [];
foreach (gen_eventos($pdo, $id) as $e) { $eventos[] = gen_evento_json($e); }

api_responder(array_merge([
    'foco' => $id,
    'pessoa' => $pessoa,
    'pais' => $pais,
    'familias' => $fams,
    'irmaos' => $irm,
    'eventos' => $eventos,
], api_grafo($sub)));
