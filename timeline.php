<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/db.php';
require_once 'includes/genealogia.php';

// Linha do tempo: nascimentos, falecimentos, uniões e eventos (imigração, batismo...),
// ordenados pela coluna DATE de cada um; o texto da data vem de gen_formatar_data().
$itens = [];
$cols_p = "id, nome_completo, sexo, " . gen_cols_datas_pessoa('');
foreach ($pdo->query("SELECT $cols_p, data_nascimento, local_nascimento, data_falecimento, local_falecimento FROM pessoas
                       WHERE data_nascimento IS NOT NULL OR data_falecimento IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $p) {
    foreach (['nascimento' => 'data_nascimento', 'falecimento' => 'data_falecimento'] as $tipo => $campo) {
        if (empty($p[$campo])) continue;
        $itens[] = [
            'tipo' => $tipo, 'rotulo' => $tipo === 'nascimento' ? 'Nascimento' : 'Falecimento',
            'data' => $p[$campo], 'texto' => gen_data_texto($p, $campo),
            'pessoa_id' => (int)$p['id'], 'nome' => $p['nome_completo'], 'sexo' => $p['sexo'],
            'local' => $tipo === 'nascimento' ? $p['local_nascimento'] : $p['local_falecimento'],
            'outro_id' => null, 'outro_nome' => null, 'descricao' => null,
        ];
    }
}
foreach ($pdo->query("SELECT u.id, u.tipo, u.data_inicio, u.local_inicio, " . gen_cols_datas_uniao('u') . ",
                             a.id AS a_id, a.nome_completo AS a_nome, a.sexo AS a_sexo, b.id AS b_id, b.nome_completo AS b_nome
                        FROM unioes u JOIN pessoas a ON a.id = u.pessoa1_id JOIN pessoas b ON b.id = u.pessoa2_id
                       WHERE u.data_inicio IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $itens[] = [
        'tipo' => 'casamento', 'rotulo' => gen_rotulo_uniao($u['tipo']),
        'data' => $u['data_inicio'], 'texto' => gen_data_texto($u, 'data_inicio'),
        'pessoa_id' => (int)$u['a_id'], 'nome' => $u['a_nome'], 'sexo' => $u['a_sexo'],
        'local' => $u['local_inicio'], 'outro_id' => (int)$u['b_id'], 'outro_nome' => $u['b_nome'], 'descricao' => null,
    ];
}
foreach ($pdo->query("SELECT e.tipo, e.`data`, e.data_qualificador, e.data_precisao, e.data_ate, e.`local`, e.descricao,
                             p.id AS pessoa_id, p.nome_completo, p.sexo
                        FROM eventos e JOIN pessoas p ON p.id = e.pessoa_id
                       WHERE e.`data` IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $itens[] = [
        'tipo' => $e['tipo'], 'rotulo' => gen_rotulo_evento($e['tipo']),
        'data' => $e['data'], 'texto' => gen_data_texto($e, 'data'),
        'pessoa_id' => (int)$e['pessoa_id'], 'nome' => $e['nome_completo'], 'sexo' => $e['sexo'],
        'local' => $e['local'], 'outro_id' => null, 'outro_nome' => null, 'descricao' => $e['descricao'],
    ];
}
$ordem_tipo = ['nascimento' => 0, 'batismo' => 1, 'casamento' => 2, 'falecimento' => 9, 'sepultamento' => 10];
usort($itens, function ($a, $b) use ($ordem_tipo) {
    return strcmp($a['data'], $b['data'])
        ?: (($ordem_tipo[$a['tipo']] ?? 5) <=> ($ordem_tipo[$b['tipo']] ?? 5))
        ?: strcmp($a['nome'], $b['nome']);
});
$cores_badge = ['nascimento' => 'bg-primary', 'falecimento' => 'bg-secondary', 'casamento' => 'bg-danger'];

include 'includes/header.php';
?>

<style>
    .timeline { position: relative; padding: 40px 0; }
    .timeline::before { content: ''; position: absolute; top: 0; left: 50%; width: 4px; height: 100%; background: #dee2e6; transform: translateX(-50%); }
    .timeline-item { position: relative; margin-bottom: 50px; width: 50%; padding: 0 40px; }
    .timeline-item:nth-child(odd) { left: 0; text-align: right; }
    .timeline-item:nth-child(even) { left: 50%; text-align: left; }
    .timeline-item::after { content: ''; position: absolute; width: 20px; height: 20px; background: #fff; border: 4px solid #6c757d; border-radius: 50%; top: 20px; }
    .timeline-item:nth-child(odd)::after { right: -10px; }
    .timeline-item:nth-child(even)::after { left: -10px; }
    @media (max-width: 767.98px) {
        .timeline::before { left: 12px; }
        .timeline-item, .timeline-item:nth-child(odd), .timeline-item:nth-child(even) { width: 100%; left: 0; text-align: left; padding: 0 0 0 40px; margin-bottom: 30px; }
        .timeline-item:nth-child(odd)::after, .timeline-item:nth-child(even)::after { left: 2px; right: auto; }
    }
</style>

<div class="container my-5 py-5">
    <div class="text-center mb-5">
        <h2 class="fw-bolder mb-1">Linha do Tempo da Família</h2>
        <div class="section-divider mx-auto"></div>
        <p class="text-muted">Uma viagem cronológica pelos eventos: nascimentos, casamentos, imigrações e falecimentos.</p>
    </div>

    <?php if (!$itens): ?>
        <p class="text-center text-muted">Nenhum evento com data registrada.</p>
    <?php endif; ?>

    <div class="timeline">
        <?php foreach ($itens as $it):
            $cor = gen_sexo_cor($it['sexo']);
            $badge = $cores_badge[$it['tipo']] ?? 'bg-success';
        ?>
            <div class="timeline-item">
                <div class="card p-3">
                    <span class="badge <?php echo $badge; ?> mb-2"><i class="fas <?php echo htmlspecialchars(gen_evento_icone($it['tipo'])); ?>"></i> <?php echo htmlspecialchars($it['rotulo']); ?>: <?php echo htmlspecialchars($it['texto']); ?></span>

                    <h5 class="fw-bold mb-1" style="color: <?php echo htmlspecialchars($cor); ?>;">
                        <a href="perfil.php?id=<?php echo (int)$it['pessoa_id']; ?>" style="text-decoration:none; color:inherit;"><?php echo htmlspecialchars($it['nome']); ?></a>
                        <?php if ($it['outro_id']): ?>
                            <span class="text-muted fw-normal">&amp;</span>
                            <a href="perfil.php?id=<?php echo (int)$it['outro_id']; ?>" style="text-decoration:none; color:inherit;"><?php echo htmlspecialchars($it['outro_nome']); ?></a>
                        <?php endif; ?>
                    </h5>

                    <?php if (!empty($it['local'])): ?>
                        <small class="text-muted d-block"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($it['local']); ?></small>
                    <?php endif; ?>
                    <?php if (!empty($it['descricao'])): ?>
                        <small class="text-muted d-block"><?php echo htmlspecialchars($it['descricao']); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
