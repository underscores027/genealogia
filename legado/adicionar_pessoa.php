<?php
include 'includes/auth.php';
include 'includes/db.php';
require_once 'includes/pessoa_form.php';

// Links antigos usavam adicionar_pessoa.php?id=X para editar: redireciona para a edição.
if (gen_id($_GET['id'] ?? null)) {
    header("Location: editar_pessoa.php?id=" . gen_id($_GET['id']));
    exit();
}

$erros = [];
$estado = pf_estado_vazio();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = pf_processar($pdo, null, $_POST, $_FILES);
    if ($res['ok']) {
        // avisos (ex.: datas aproximadas a conferir) aparecem na tela de edição
        if ($res['avisos']) $_SESSION['pf_avisos'] = $res['avisos'];
        header("Location: editar_pessoa.php?id=" . (int)$res['id'] . "&criado=1");
        exit();
    }
    $erros = $res['erros'];
    $estado = $res['estado'];
}

include 'includes/header.php';
?>

<div class="container my-5 py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card p-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <span class="badge bg-success px-3 py-2 rounded-pill mb-2">Novo Registro</span>
                        <h2 class="fw-bolder mb-0">Adicionar Membro</h2>
                    </div>
                    <a href="gerenciar_pessoas.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>

                <?php echo pf_render_mensagens($erros); ?>
                <?php pf_render_form($pdo, $estado, null); ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
