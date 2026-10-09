<?php
/**
 * GET api/busca.php?q=texto[&limite=N]
 * Busca por nome para o campo "ir para pessoa". Cada palavra de q (até 5) precisa
 * aparecer no nome (sem diferenciar maiúsculas/acentos, conforme a collation do banco).
 * Nomes que começam com q vêm primeiro. limite padrão 20, máximo 50.
 * As flags tem_mais_* aqui dizem se a pessoa tem pais/filhos/cônjuges registrados.
 */
require __DIR__ . '/_comum.php';

$q = $_GET['q'] ?? '';
if (is_array($q)) api_erro(400, "Parâmetro 'q' inválido.");
$q = trim(preg_replace('/\s+/u', ' ', (string)$q));
$tam = mb_strlen($q, 'UTF-8');
if ($tam < 2 || $tam > 100) api_erro(400, "Parâmetro 'q' deve ter entre 2 e 100 caracteres.");
$limite = api_param_int('limite', 20, 1, 50);

$like = function ($s) { return addcslashes($s, '\\%_'); };
$termos = array_slice(explode(' ', $q), 0, 5);
$where = [];
$params = [$like($q) . '%'];
foreach ($termos as $t) {
    $where[] = 'nome_completo LIKE ?';
    $params[] = '%' . $like($t) . '%';
}
$st = $pdo->prepare(
    "SELECT id FROM pessoas
      WHERE " . implode(' AND ', $where) . "
      ORDER BY (nome_completo LIKE ?) DESC, nome_completo, id
      LIMIT " . (int)$limite
);
// o parâmetro do ORDER BY vem depois dos do WHERE
$prefixo = array_shift($params);
$params[] = $prefixo;
$st->execute($params);
$ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

$sub = gen_subgrafo($pdo, $ids, true);
api_responder([
    'q' => $q,
    'limite' => $limite,
    'total' => count($sub['pessoas']),
    'pessoas' => array_values($sub['pessoas']),
]);
