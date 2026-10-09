<?php
/**
 * Cabeçalho comum: barra de navegação única (visitante e logado) e abertura do <main>.
 * A árvore (arvore.php) é a página inicial; quem está logado vê também os atalhos de cadastro.
 * $titulo_pagina (opcional) entra no <title>.
 */
require_once __DIR__ . '/app.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$hd_logado = isset($_SESSION['user_id']);
$hd_pagina = basename($_SERVER['PHP_SELF']);
$hd_nav = function ($arquivo, $rotulo, $icone) use ($hd_pagina) {
    $ativa = $hd_pagina === $arquivo;
    return '<li class="nav-item"><a class="nav-link' . ($ativa ? ' active' : '') . '"' . ($ativa ? ' aria-current="page"' : '') .
        ' href="' . $arquivo . '"><i class="fas ' . $icone . ' me-1" aria-hidden="true"></i>' . $rotulo . '</a></li>';
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((isset($titulo_pagina) ? $titulo_pagina . ' | ' : '') . app_nome(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; color: #333; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand fw-semibold" href="arvore.php"><i class="fas fa-sitemap me-2" aria-hidden="true"></i><?php echo htmlspecialchars(app_nome(), ENT_QUOTES, 'UTF-8'); ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navPrincipal" aria-controls="navPrincipal" aria-expanded="false" aria-label="Abrir menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navPrincipal">
            <ul class="navbar-nav me-auto">
                <?php
                echo $hd_nav('arvore.php', 'Árvore', 'fa-sitemap');
                echo $hd_nav('timeline.php', 'Linha do tempo', 'fa-clock-rotate-left');
                if ($hd_logado) {
                    echo $hd_nav('gerenciar_pessoas.php', 'Pessoas', 'fa-list');
                    echo $hd_nav('adicionar_pessoa.php', 'Adicionar pessoa', 'fa-user-plus');
                    echo $hd_nav('gerenciar_documentos.php', 'Documentos', 'fa-file-lines');
                }
                ?>
            </ul>
            <?php if ($hd_logado): ?>
            <form action="buscar.php" method="GET" class="d-flex me-lg-3 my-2 my-lg-0" role="search">
                <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar pessoa…" aria-label="Buscar pessoa" required>
            </form>
            <span class="navbar-text me-3"><i class="fas fa-user me-1" aria-hidden="true"></i><?php echo htmlspecialchars($_SESSION['username'] ?? 'Usuário', ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="btn btn-sm btn-outline-secondary" href="logout.php">Sair</a>
            <?php else: ?>
            <a class="btn btn-sm btn-outline-secondary" href="login.php">Entrar</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-3">
