<?php
/**
 * Árvore genealógica pública, navegável a partir de uma pessoa em foco
 * (estilo "Árvore Familiar" do FamilySearch), desenhada com layout próprio sobre d3 (assets/js/arvore.js).
 * Os dados vêm sob demanda da API em api/ (assets/js/arvore.js); esta página não consulta o banco.
 * Logado, cada card ganha o botão "+" (adicionar parente; grava por api/adicionar_parente.php).
 * ?id=N abre com a pessoa N em foco; sem parâmetro, usa api/raiz.php.
 * ?visao=paisagem|descendencia|leque escolhe a visão (assets/js/visoes.js); todas usam o mesmo foco.
 */
require_once __DIR__ . '/includes/app.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Página pública: "Editar" e o botão "+" só aparecem para usuário logado (as gravações exigem login).
$logado = isset($_SESSION['user_id']);

$id_inicial = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999999999]]);
// Visões da mesma árvore (assets/js/visoes.js): paisagem (setas ▲ ◀ ▶ ▼), descendência (lista) e leque.
$visoes = [
    'paisagem'     => ['rotulo' => 'Paisagem',     'icone' => 'fa-sitemap'],
    'descendencia' => ['rotulo' => 'Descendência', 'icone' => 'fa-list-ul'],
    'leque'        => ['rotulo' => 'Leque',        'icone' => 'fa-chart-pie'],
];
$visao = isset($_GET['visao']) && is_string($_GET['visao']) && isset($visoes[$_GET['visao']]) ? $_GET['visao'] : 'paisagem';
// Ajuda de cada visão: [desktop, celular]
$ajuda = [
    'paisagem' => [
        'Clique numa pessoa para ver o resumo. Use as setas para abrir pais (▲), irmãos (◀ ▶), filhos (▼) e cônjuges (♥); a seta cinza recolhe o que foi aberto.' . ($logado ? ' O botão + adiciona um parente.' : '') . ' Arraste para mover; role ou use os botões para o zoom.',
        'Toque numa pessoa para ver o resumo; as setas abrem pais, irmãos e filhos, e a seta cinza recolhe.' . ($logado ? ' O + adiciona um parente.' : '') . ' Um dedo arrasta, dois dedos dão zoom.',
    ],
    'descendencia' => [
        'Descendentes da pessoa em foco, com cônjuges e filhos de cada união. As setas abrem cada geração; o nome abre o resumo.',
        'Toque na seta para abrir os filhos e no nome para ver o resumo.',
    ],
    'leque' => [
        'Ancestrais da pessoa em foco, um anel por geração (pai à esquerda, mãe à direita). Clique: resumo; duplo clique: focar.',
        'Toque para ver o resumo; dois dedos dão zoom. Pai acima, mãe abaixo.',
    ],
];
function arvore_url_visao($id, $v) {
    $q = [];
    if ($id) $q['id'] = (int)$id;
    if ($v !== 'paisagem') $q['visao'] = $v;
    return 'arvore.php' . ($q ? '?' . http_build_query($q) : '');
}
function arvore_attr_ajuda($ajuda, $i) {
    $out = '';
    foreach ($ajuda as $v => $t) {
        $out .= ' data-' . $v . '="' . htmlspecialchars($t[$i], ENT_QUOTES, 'UTF-8') . '"';
    }
    return $out;
}

$config_arvore = [
    'logado' => $logado,
    'focoInicial' => $id_inicial ? (int)$id_inicial : null,
    'visaoInicial' => $visao,
    'titulo' => app_nome(),
];

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/arvore.css?v=<?php echo (int)@filemtime(__DIR__ . "/assets/css/arvore.css"); ?>">

<div>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h2 class="fw-bolder mb-0"><?php echo htmlspecialchars(app_nome(), ENT_QUOTES, 'UTF-8'); ?></h2>
            <small class="text-muted d-none d-md-inline" data-arv-ajuda<?php echo arvore_attr_ajuda($ajuda, 0); ?>><?php echo htmlspecialchars($ajuda[$visao][0], ENT_QUOTES, 'UTF-8'); ?></small>
            <small class="text-muted d-md-none" data-arv-ajuda<?php echo arvore_attr_ajuda($ajuda, 1); ?>><?php echo htmlspecialchars($ajuda[$visao][1], ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2" style="max-width: 100%;">
            <div class="arv-busca">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted" aria-hidden="true"></i></span>
                    <input type="search" id="arvBusca" class="form-control" placeholder="Ir para pessoa…" aria-label="Ir para pessoa">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <nav class="arv-visoes" id="arvVisoes" aria-label="Visão da árvore">
            <?php foreach ($visoes as $v => $info): ?>
            <a href="<?php echo htmlspecialchars(arvore_url_visao($id_inicial, $v), ENT_QUOTES, 'UTF-8'); ?>" data-visao="<?php echo $v; ?>"<?php echo $v === $visao ? ' class="ativa" aria-current="page"' : ''; ?>><i class="fas <?php echo $info['icone']; ?>" aria-hidden="true"></i> <span><?php echo $info['rotulo']; ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="arv-legenda d-none d-md-flex">
            <span><i class="arv-q arv-cor-M"></i> Masculino</span>
            <span><i class="arv-q arv-cor-F"></i> Feminino</span>
            <span><i class="arv-q arv-cor-D"></i> Não informado</span>
            <span data-visao-so="paisagem"<?php echo $visao === 'paisagem' ? '' : ' hidden'; ?>><i class="fas fa-chevron-up text-success" aria-hidden="true"></i> pais · <i class="fas fa-chevron-left text-success" aria-hidden="true"></i><i class="fas fa-chevron-right text-success" aria-hidden="true"></i> irmãos · <i class="fas fa-chevron-down text-success" aria-hidden="true"></i> filhos · <i class="fas fa-heart text-success" aria-hidden="true"></i> cônjuges · <i class="fas fa-chevron-down text-secondary" aria-hidden="true"></i> recolher<?php if ($logado): ?> · <i class="fas fa-plus text-success" aria-hidden="true"></i> adicionar parente<?php endif; ?></span>
            <span data-visao-so="descendencia"<?php echo $visao === 'descendencia' ? '' : ' hidden'; ?>><i class="fas fa-chevron-right text-success" aria-hidden="true"></i> abrir filhos · <i class="fas fa-crosshairs" aria-hidden="true"></i> focar</span>
            <span data-visao-so="leque"<?php echo $visao === 'leque' ? '' : ' hidden'; ?>><i class="arv-q arv-q-vazio"></i> não registrado · <i class="arv-q arv-q-mais"></i> há mais ancestrais</span>
        </div>
    </div>

    <div class="arv-palco" id="arvPalco" data-visao="<?php echo $visao; ?>">
        <div class="arv-visao" id="visPaisagem"<?php echo $visao === 'paisagem' ? '' : ' hidden'; ?>>
        <div id="arvGrafico" class="arv-grafico" aria-label="Árvore genealógica"></div>
        <div class="arv-conjuges" id="arvConjuges" aria-label="Alternar entre cônjuges"></div>
        <div class="arv-controles" id="arvControles">
            <button type="button" class="btn" data-arv-cmd="mais" title="Aproximar" aria-label="Aproximar"><i class="fas fa-plus" aria-hidden="true"></i></button>
            <button type="button" class="btn" data-arv-cmd="menos" title="Afastar" aria-label="Afastar"><i class="fas fa-minus" aria-hidden="true"></i></button>
            <button type="button" class="btn" data-arv-cmd="centralizar" title="Centralizar na pessoa em foco" aria-label="Centralizar na pessoa em foco"><i class="fas fa-crosshairs" aria-hidden="true"></i></button>
            <button type="button" class="btn" data-arv-cmd="ajustar" title="Mostrar a árvore inteira" aria-label="Mostrar a árvore inteira"><i class="fas fa-expand" aria-hidden="true"></i></button>
            <button type="button" class="btn" data-arv-cmd="girar" title="Alternar orientação (vertical/horizontal)" aria-label="Alternar orientação"><i class="fas fa-arrows-rotate" aria-hidden="true"></i></button>
        </div>
        </div>
        <div class="arv-visao" id="visDescendencia"<?php echo $visao === 'descendencia' ? '' : ' hidden'; ?>></div>
        <div class="arv-visao" id="visLeque"<?php echo $visao === 'leque' ? '' : ' hidden'; ?>></div>
        <div class="arv-status" id="arvStatus" role="status" aria-live="polite"></div>
        <aside class="arv-painel" id="arvPainel" aria-hidden="true" aria-labelledby="arvPainelTitulo" role="dialog">
            <div class="arv-painel-topo">
                <span class="arv-painel-alca" aria-hidden="true"></span>
                <span class="small text-muted d-none d-md-inline">Resumo</span>
                <button type="button" class="btn-close" data-arv-fechar aria-label="Fechar resumo"></button>
            </div>
            <div class="arv-painel-corpo"></div>
        </aside>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js" integrity="sha384-CjloA8y00+1SDAUkjs099PVfnY2KmDC2BZnws9kh8D/lX1s46w6EPhpXdqMfjK6i" crossorigin="anonymous"></script>
<script src="assets/js/arvore.js?v=<?php echo (int)@filemtime(__DIR__ . "/assets/js/arvore.js"); ?>"></script>
<script src="assets/js/visoes.js?v=<?php echo (int)@filemtime(__DIR__ . "/assets/js/visoes.js"); ?>"></script>
<script>
    (function () {
        var cfg = <?php echo json_encode($config_arvore, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function porId(id) { return document.getElementById(id); }
        window.arvoreVisoes = window.ArvoreVisoes.iniciar({
            api: 'api/',
            logado: cfg.logado,
            focoInicial: cfg.focoInicial,
            visaoInicial: cfg.visaoInicial,
            titulo: cfg.titulo,
            palco: porId('arvPalco'),
            painel: porId('arvPainel'),
            busca: porId('arvBusca'),
            status: porId('arvStatus'),
            seletor: porId('arvVisoes'),
            ajudas: document.querySelectorAll('[data-arv-ajuda]'),
            legendas: document.querySelectorAll('[data-visao-so]'),
            paisagem: { raiz: porId('visPaisagem'), grafico: porId('arvGrafico'), conjuges: porId('arvConjuges'), controles: porId('arvControles') },
            descendencia: { raiz: porId('visDescendencia') },
            leque: { raiz: porId('visLeque') }
        });
    })();
</script>

<?php include 'includes/footer.php'; ?>
