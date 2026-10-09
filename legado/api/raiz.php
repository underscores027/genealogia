<?php
/**
 * GET api/raiz.php
 * Pessoa inicial padrão da árvore (para abrir arvore.php sem ?id=):
 * GEN_RAIZ_PADRAO_ID, se definida; senão a pessoa mais antiga com filhos e sem pais.
 */
require __DIR__ . '/_comum.php';

$id = gen_raiz_padrao($pdo);
if (!$id) api_erro(404, 'Nenhuma pessoa cadastrada.');
$sub = gen_subgrafo($pdo, [$id], true);
api_responder([
    'id' => $id,
    'criterio' => defined('GEN_RAIZ_PADRAO_ID') && (int)GEN_RAIZ_PADRAO_ID === $id ? 'configurada' : 'mais_antiga_com_descendentes',
    'pessoa' => $sub['pessoas'][$id],
]);
