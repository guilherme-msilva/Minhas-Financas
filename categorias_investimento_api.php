<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $nome = trim($_POST['nome']);
        // Evita SQL Injection básica escapando string
        $nome = $mysqliFinancas->real_escape_string($nome);
        $id_pai = !empty($_POST['id_pai']) ? (int)$_POST['id_pai'] : 'NULL';

        if (empty($nome)) {
            echo json_encode(['success' => false, 'error' => 'Nome é obrigatório']);
            exit;
        }

        if ($action === 'add') {
            $sql = "INSERT INTO categorias_investimento (nome, id_pai) VALUES ('$nome', $id_pai)";
        } else {
            // Evitar loop infinito: pai não pode ser o próprio id
            if ($id_pai === $id) {
                echo json_encode(['success' => false, 'error' => 'Uma categoria não pode ser pai dela mesma.']);
                exit;
            }
            $sql = "UPDATE categorias_investimento SET nome='$nome', id_pai=$id_pai WHERE id=$id";
        }
        
        if ($mysqliFinancas->query($sql)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar no banco.']);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        // Verifica se tem filhos
        $res = $mysqliFinancas->query("SELECT id FROM categorias_investimento WHERE id_pai = $id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'Não é possível excluir: existem subcategorias vinculadas a esta.']);
            exit;
        }

        // Verifica se tem investimentos vinculados
        $res = $mysqliFinancas->query("SELECT id FROM investimentos WHERE id_categoria = $id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'Não é possível excluir: existem investimentos de usuários usando esta categoria.']);
            exit;
        }

        if ($mysqliFinancas->query("DELETE FROM categorias_investimento WHERE id=$id")) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao deletar do banco.']);
        }
        exit;
    }
}

// GET - Obter categorias
$cats_result = $mysqliFinancas->query("SELECT id, nome, id_pai FROM categorias_investimento");
$categorias = [];
if ($cats_result) {
    while ($cat = $cats_result->fetch_assoc()) {
        $categorias[] = $cat;
    }
}

echo json_encode([
    'categorias' => $categorias
]);
