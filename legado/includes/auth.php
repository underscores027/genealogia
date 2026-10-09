<?php
// Páginas de cadastro/edição exigem login: quem não está logado vai para login.php
// e volta para a página pedida depois de entrar.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?voltar=" . urlencode(basename($_SERVER['PHP_SELF']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING'])));
    exit();
}
