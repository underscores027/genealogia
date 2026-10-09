<?php
/**
 * GET api/ancestrais.php?id=X[&geracoes=N]
 * N gerações acima da pessoa (padrão 2, de 1 a 6), por busca em largura.
 * Implexo/ciclo: cada ancestral aparece uma vez, no nível mais próximo.
 */
require __DIR__ . '/_comum.php';

$p = api_pessoa_obrigatoria($pdo);
$geracoes = api_param_int('geracoes', 2, 1, 6);
$id = (int)$p['id'];

$niveis = gen_ancestrais_por_nivel($pdo, $id, $geracoes);
$ids = [$id];
foreach ($niveis as $nivel) { $ids = array_merge($ids, $nivel); }
$sub = gen_subgrafo($pdo, $ids);

$saida = [];
foreach ($niveis as $i => $nivel) {
    $saida[] = ['geracao' => $i + 1, 'pessoas' => $nivel];
}

api_responder(array_merge([
    'foco' => $id,
    'geracoes' => $geracoes,
    'niveis' => $saida,
], api_grafo($sub)));
