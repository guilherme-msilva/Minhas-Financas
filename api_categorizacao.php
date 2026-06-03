<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
    exit;
}

require_once 'conexao.php';
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'search') {
    $descricao = $_GET['description'] ?? '';
    if (empty($descricao)) {
        echo json_encode(['success' => true, 'matches' => []]);
        exit;
    }

    // Buscar todas as regras do usuário
    // Como a checagem é: a descrição (da transação) contém o match_description da regra?
    // Ex: Descrição: "UBER TRIP SAO PAULO". Match: "UBER".
    // Isso significa que "UBER TRIP SAO PAULO" LIKE '%UBER%' -> TRUE.
    // Em SQL: ? LIKE CONCAT('%', match_description, '%')
    $sql = "SELECT c.*, cat.nome as categoria_nome, cat.icone as categoria_icone, cat.cor as categoria_cor, cont.nome as conta_nome, cont.img as conta_img 
            FROM categorizacao_automatica c 
            LEFT JOIN categorias cat ON c.idcategoria = cat.id 
            LEFT JOIN contas cont ON c.idconta = cont.id 
            WHERE c.iduser = ? 
              AND ? LIKE CONCAT('%', c.match_description, '%')
            ORDER BY c.count DESC";
            
    $stmt = $mysqliFinancas->prepare($sql);
    $stmt->bind_param("is", $user_id, $descricao);
    $stmt->execute();
    $res = $stmt->get_result();
    $matches = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode(['success' => true, 'matches' => $matches]);
    exit;
}

if ($action === 'increment') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        $stmt = $mysqliFinancas->prepare("UPDATE categorizacao_automatica SET count = count + 1 WHERE id = ? AND iduser = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        echo json_encode(['success' => $affected > 0]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'ID inválido.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);
exit;
