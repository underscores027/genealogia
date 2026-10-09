<?php
include 'includes/auth.php';
include 'includes/db.php';
require_once 'includes/pessoa_form.php';

$id_pessoa = gen_id($_GET['id'] ?? null);
if (!$id_pessoa) { header("Location: gerenciar_pessoas.php"); exit(); }

$erros = [];
$avisos = [];
$sucesso = '';
$estado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = pf_processar($pdo, $id_pessoa, $_POST, $_FILES);
    if ($res['ok']) {
        $sucesso = 'Dados atualizados com sucesso!';
        $avisos = $res['avisos'];
    } else {
        $erros = $res['erros'];
        $estado = $res['estado']; // reexibe o que o usuário digitou
    }
} elseif (isset($_GET['criado'])) {
    $sucesso = 'Pessoa cadastrada com sucesso!';
    if (!empty($_SESSION['pf_avisos']) && is_array($_SESSION['pf_avisos'])) $avisos = $_SESSION['pf_avisos'];
}
unset($_SESSION['pf_avisos']);

$p = gen_pessoa($pdo, $id_pessoa);
if (!$p) { header("Location: gerenciar_pessoas.php"); exit(); }
if ($estado === null) { $estado = pf_estado_do_banco($pdo, $id_pessoa); }

include 'includes/header.php';
?>

<div class="container my-5 py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card p-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill mb-2">Editando Registro</span>
                        <h2 class="fw-bolder mb-0"><?php echo htmlspecialchars($p['nome_completo']); ?></h2>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="perfil.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary"><i class="fas fa-eye"></i> Perfil</a>
                        <a href="arvore.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Voltar</a>
                    </div>
                </div>

                <?php echo pf_render_mensagens($erros, $avisos, $sucesso); ?>
                <?php pf_render_form($pdo, $estado, $id_pessoa); ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
