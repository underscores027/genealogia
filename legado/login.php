<?php
/**
 * Login simples: usuário e senha da tabela `usuarios` (contas criadas com criar_usuario.php).
 * ?voltar=pagina.php?x=1 leva de volta à página que exigiu o login (só páginas deste projeto).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// destino após o login: apenas "arquivo.php[?query]" local, nunca outro site ou pasta
$voltar = isset($_REQUEST['voltar']) && is_string($_REQUEST['voltar']) ? $_REQUEST['voltar'] : '';
if (!preg_match('/^[a-z_]+\.php(\?[^\r\n]*)?$/', $voltar)) $voltar = 'arvore.php';

if (isset($_SESSION['user_id'])) {
    header("Location: " . $voltar);
    exit();
}

include 'includes/db.php';

$erro = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $senha = isset($_POST['senha']) && is_string($_POST['senha']) ? $_POST['senha'] : '';
    if ($username === '' || $senha === '') {
        $erro = 'Preencha usuário e senha.';
    } else {
        $st = $pdo->prepare("SELECT id, username, senha FROM usuarios WHERE username = ?");
        $st->execute([$username]);
        $user = $st->fetch();
        if ($user && password_verify($senha, $user['senha'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            registrarLog($pdo, "Entrou no sistema (Login).");
            header("Location: " . $voltar);
            exit();
        }
        $erro = 'Usuário ou senha inválidos.';
    }
}

$titulo_pagina = 'Entrar';
include 'includes/header.php';
?>

<div class="row justify-content-center my-5">
    <div class="col-sm-8 col-md-5 col-lg-4">
        <div class="card p-4">
            <h1 class="h4 mb-3">Entrar</h1>
            <?php if ($erro): ?>
            <div class="alert alert-danger py-2" role="alert"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="POST" action="login.php">
                <input type="hidden" name="voltar" value="<?php echo htmlspecialchars($voltar, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                    <label for="loginUsuario" class="form-label">Usuário</label>
                    <input type="text" class="form-control" id="loginUsuario" name="username" value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="username" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="loginSenha" class="form-label">Senha</label>
                    <input type="password" class="form-control" id="loginSenha" name="senha" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Entrar</button>
            </form>
            <p class="text-muted small mt-3 mb-0">A árvore pode ser consultada sem login; entrar libera o cadastro e a edição de pessoas.</p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
