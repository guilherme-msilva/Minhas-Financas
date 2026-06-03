<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

require_once 'conexao.php';

$user_id = $_SESSION['user_id'];
$idcategoria = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$mes_str = isset($_GET['mes']) ? $_GET['mes'] : ''; // Formato: 2024-05 ou 'total'
$data_inicio = isset($_GET['inicio']) ? $_GET['inicio'] : '';
$data_fim = isset($_GET['fim']) ? $_GET['fim'] : '';
$conta = isset($_GET['conta']) ? (int)$_GET['conta'] : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : ''; // receitas ou despesas

if (!$idcategoria || !$mes_str) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
    exit;
}

if ($mes_str === 'total') {
    if (!$data_inicio || !$data_fim) {
        echo json_encode(['success' => false, 'message' => 'Datas inválidas para total']);
        exit;
    }
    $conditions = ["t.iduser = ?", "t.idcategoria = ?", "t.data >= ?", "t.data <= ?"];
    $params = [$user_id, $idcategoria, $data_inicio, $data_fim];
    $types = "iiss";
} else {
    if (!preg_match('/^\d{4}-\d{2}$/', $mes_str)) {
        echo json_encode(['success' => false, 'message' => 'Mês inválido']);
        exit;
    }
    list($ano, $mes) = explode('-', $mes_str);
    $ano = (int)$ano;
    $mes = (int)$mes;
    $conditions = ["t.iduser = ?", "t.idcategoria = ?", "YEAR(t.data) = ?", "MONTH(t.data) = ?"];
    $params = [$user_id, $idcategoria, $ano, $mes];
    $types = "iiii";
}

if ($conta > 0) {
    $conditions[] = "t.idconta = ?";
    $params[] = $conta;
    $types .= "i";
}

if ($tipo === 'receitas') {
    $conditions[] = "t.valor > 0";
} elseif ($tipo === 'despesas') {
    $conditions[] = "t.valor < 0";
} else {
    echo json_encode(['success' => false, 'message' => 'Tipo inválido']);
    exit;
}

// Além do idconta que vem da página, se havia um filtro de categoria "macro" na página,
// não precisamos reaplicá-lo aqui porque estamos filtrando pela idcategoria específica da linha clicada, 
// que já é um subconjunto válido.

$where_sql = implode(" AND ", $conditions);

$sql = "SELECT t.id, t.descricao, t.data, t.valor, c.nome as conta_nome, c.img as conta_img 
        FROM transacoes t 
        LEFT JOIN contas c ON t.idconta = c.id 
        WHERE $where_sql 
        ORDER BY t.data DESC";

$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$transacoes = [];
$total = 0;
while ($row = $res->fetch_assoc()) {
    $row['valor_absoluto'] = abs($row['valor']);
    $total += $row['valor_absoluto'];
    $row['data_formatada'] = date('d/m/Y', strtotime($row['data']));
    $transacoes[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true, 
    'transacoes' => $transacoes,
    'total_somado' => $total
]);
