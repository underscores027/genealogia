<?php
include 'includes/auth.php';
include 'includes/db.php';
require_once 'includes/genealogia.php';

 $busca = $_GET['q'] ?? '';
 $resultados = [];

if (!empty($busca)) {
    $stmt = $pdo->prepare("SELECT * FROM pessoas WHERE nome_completo LIKE ? ORDER BY nome_completo");
    $stmt->execute(["%" . $busca . "%"]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include 'includes/header.php';
?>

<div class="container-fluid p-4">
    <div class="card p-5">
        <h3 class="fw-bold mb-4">
            <i class="fas fa-search me-2"></i>Resultados da Busca
        </h3>
        
        <?php if (empty($busca)): ?>
            <p class="text-muted">Digite um nome na barra de pesquisa acima.</p>
        <?php elseif (count($resultados) == 0): ?>
            <div class="alert alert-warning">Nenhuma pessoa encontrada com o nome "<strong><?php echo htmlspecialchars($busca); ?></strong>".</div>
        <?php else: ?>
            <div class="list-group">
                <?php foreach($resultados as $p): 
                    $border = gen_sexo_cor($p['sexo']);
                    $icon = gen_sexo_icone($p['sexo']);
                ?>
                    <a href="perfil.php?id=<?php echo (int)$p['id']; ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #f0f4ff; border: 2px solid <?php echo $border; ?>; display:flex; align-items:center; justify-content:center;" class="me-3">
                            <i class="fas <?php echo $icon; ?>" style="color: <?php echo $border; ?>;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?php echo htmlspecialchars($p['nome_completo']); ?></h6>
                            <small class="text-muted"><?php $dn = gen_data_texto($p, 'data_nascimento'); echo $dn !== '' ? 'Nascimento: ' . htmlspecialchars($dn) : 'Data desconhecida'; ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>