<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
    exit;
}

require_once 'conexao.php';
$user_id = $_SESSION['user_id'];
$q = $_GET['q'] ?? '';

if (strlen($q) < 3) {
    echo json_encode(['success' => true, 'matches' => []]);
    exit;
}

// 1. Ocorrências iguais (COUNT) - desc
// 2. Data mais recente (MAX data) - desc
// 3. Começa com a palavra (LIKE 'q%') - desc
$sql = "SELECT 
            t.descricao, 
            t.idcategoria, 
            t.idconta, 
            COUNT(t.id) as frequencia, 
            MAX(t.data) as ultima_data,
            cat.nome as categoria_nome,
            cat.cor as categoria_cor,
            cat.icone as categoria_icone,
            cont.nome as conta_nome,
            cont.img as conta_img
        FROM transacoes t
        LEFT JOIN categorias cat ON t.idcategoria = cat.id
        LEFT JOIN contas cont ON t.idconta = cont.id
        WHERE t.iduser = ? 
          AND t.data >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          AND t.descricao LIKE CONCAT('%', ?, '%')
        GROUP BY t.descricao, t.idcategoria, t.idconta
        ORDER BY 
            frequencia DESC, 
            ultima_data DESC,
            (t.descricao LIKE CONCAT(?, '%')) DESC
        LIMIT 5";

$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param("iss", $user_id, $q, $q);
$stmt->execute();
$res = $stmt->get_result();
$matches = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'matches' => $matches]);
exit;
