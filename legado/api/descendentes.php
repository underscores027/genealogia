<?php
/**
 * GET api/descendentes.php?id=X[&geracoes=N]
 * N gerações abaixo da pessoa (padrão 2, de 1 a 6), por busca em largura,
 * com os cônjuges de cada descendente (e o outro genitor dos filhos, mesmo sem união).
 */
require __DIR__ . '/_comum.php';

$p = api_pessoa_obrigatoria($pdo);
$geracoes = api_param_int('geracoes', 2, 1, 6);
$id = (int)$p['id'];

$res = gen_descendentes_por_nivel($pdo, $id, $geracoes);
$ids = array_merge([$id], $res['conjuges_foco']);
foreach ($res['niveis'] as $nivel) { $ids = array_merge($ids, $nivel['pessoas'], $nivel['conjuges']); }
$sub = gen_subgrafo($pdo, $ids);

api_responder(array_merge([
    'foco' => $id,
    'geracoes' => $geracoes,
    'conjuges_foco' => $res['conjuges_foco'],
    'niveis' => $res['niveis'],
], api_grafo($sub)));
