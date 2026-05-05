<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['action'])) {
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

$action = $data['action'];

if ($action === 'check') {
    $categoriesToCheck = $data['categories'] ?? [];
    $accountsToCheck = $data['accounts'] ?? [];
    
    // Buscar categorias do usuário
    $stmt = $mysqliFinancas->prepare("SELECT nome FROM categorias WHERE id_user = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $dbCategories = [];
    while($row = $res->fetch_assoc()){
        $dbCategories[] = mb_strtolower(trim($row['nome']));
    }
    $stmt->close();
    
    // Buscar contas do usuário
    $stmt = $mysqliFinancas->prepare("SELECT nome FROM contas WHERE id_user = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $dbAccounts = [];
    while($row = $res->fetch_assoc()){
        $dbAccounts[] = mb_strtolower(trim($row['nome']));
    }
    $stmt->close();
    
    $missingCategories = [];
    foreach($categoriesToCheck as $cat) {
        if (!in_array(mb_strtolower(trim($cat)), $dbCategories) && trim($cat) !== '') {
            $missingCategories[] = trim($cat);
        }
    }
    
    $missingAccounts = [];
    foreach($accountsToCheck as $acc) {
        if (!in_array(mb_strtolower(trim($acc)), $dbAccounts) && trim($acc) !== '') {
            $missingAccounts[] = trim($acc);
        }
    }
    
    echo json_encode([
        'missing_categories' => array_values(array_unique($missingCategories)),
        'missing_accounts' => array_values(array_unique($missingAccounts))
    ]);
    exit;
}

if ($action === 'import') {
    $transactions = $data['transactions'] ?? [];
    $createCategories = $data['create_categories'] ?? false;
    $createAccounts = $data['create_accounts'] ?? false;
    
    $mysqliFinancas->begin_transaction();
    
    try {
        // Criar Categorias faltantes
        if ($createCategories) {
            // Verificar se a categoria raiz "Importações" existe
            $importRootId = null;
            $stmt = $mysqliFinancas->prepare("SELECT id FROM categorias WHERE id_user = ? AND LOWER(nome) = 'importações'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $importRootId = $row['id'];
            } else {
                // Criar categoria raiz
                $nomeRaiz = "Importações";
                $corPadrao = "#8b5cf6";
                $stmt_insert = $mysqliFinancas->prepare("INSERT INTO categorias (nome, cor, id_user) VALUES (?, ?, ?)");
                $stmt_insert->bind_param("ssi", $nomeRaiz, $corPadrao, $user_id);
                $stmt_insert->execute();
                $importRootId = $stmt_insert->insert_id;
                $stmt_insert->close();
            }
            $stmt->close();
            
            // Inserir as faltantes
            $missingCats = $data['missing_categories'] ?? [];
            $corPadrao = "#cbd5e1";
            foreach($missingCats as $catName) {
                $stmt_insert = $mysqliFinancas->prepare("INSERT INTO categorias (nome, id_pai, cor, id_user) VALUES (?, ?, ?, ?)");
                $stmt_insert->bind_param("sisi", $catName, $importRootId, $corPadrao, $user_id);
                $stmt_insert->execute();
                $stmt_insert->close();
            }
        }
        
        // Criar Contas faltantes
        if ($createAccounts) {
            $missingAccs = $data['missing_accounts'] ?? [];
            $corPadrao = "#3b82f6";
            foreach($missingAccs as $accName) {
                $stmt_insert = $mysqliFinancas->prepare("INSERT INTO contas (nome, saldo_inicial, cor, status, id_user) VALUES (?, 0, ?, 1, ?)");
                $stmt_insert->bind_param("ssi", $accName, $corPadrao, $user_id);
                $stmt_insert->execute();
                $stmt_insert->close();
            }
        }
        
        // Agora mapear todas as categorias e contas para IDs
        $mapCategories = [];
        $stmt = $mysqliFinancas->prepare("SELECT id, LOWER(nome) as nome FROM categorias WHERE id_user = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) {
            $mapCategories[$row['nome']] = $row['id'];
        }
        $stmt->close();
        
        $mapAccounts = [];
        $stmt = $mysqliFinancas->prepare("SELECT id, LOWER(nome) as nome FROM contas WHERE id_user = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) {
            $mapAccounts[$row['nome']] = $row['id'];
        }
        $stmt->close();
        
        // Inserir as transações
        $insertedCount = 0;
        $stmt_trans = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada) VALUES (?, ?, ?, ?, ?, ?, 1)");
        
        foreach($transactions as $t) {
            $date = $t['date'];
            $value = (float)$t['value'];
            $desc = trim($t['description']);
            $catName = mb_strtolower(trim($t['category']));
            $accName = mb_strtolower(trim($t['account']));
            
            $idCat = isset($mapCategories[$catName]) ? $mapCategories[$catName] : NULL;
            $idAcc = isset($mapAccounts[$accName]) ? $mapAccounts[$accName] : NULL;
            
            // Só insere se tiver data e descrição válida
            if ($date && $desc !== '') {
                $stmt_trans->bind_param("sdsiii", $date, $value, $desc, $idCat, $idAcc, $user_id);
                if($stmt_trans->execute()) {
                    $insertedCount++;
                }
            }
        }
        $stmt_trans->close();
        
        $mysqliFinancas->commit();
        echo json_encode(['success' => true, 'inserted_count' => $insertedCount]);
        
    } catch (Exception $e) {
        $mysqliFinancas->rollback();
        echo json_encode(['error' => 'Erro ao processar importação: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Ação desconhecida']);
