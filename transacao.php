<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$erro = '';
$sucesso = '';

// Variáveis default
$tipo = 'despesa';
$valor = 0.00;
$data = date('Y-m-d');
$descricao = '';
$consolidada = 1;
$id_categoria = '';
$id_conta = '';
$id_conta_destino = ''; // Apenas uso no front
$notas = '';
$recorrencias = 0;
$id_grupo_recorrencia = NULL;

// Processamento POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $id > 0) {
        // Deletar transação
        $stmt = $mysqliFinancas->prepare("DELETE FROM transacoes WHERE (id = ? OR idpai = ?) AND iduser = ?");
        $stmt->bind_param("iii", $id, $id, $user_id);
        if ($stmt->execute()) {
            header("Location: transacoes.php");
            exit;
        } else {
            $erro = "Erro ao excluir: " . $mysqliFinancas->error;
        }
    } else {
        // Salvar (Insert/Update)
        $tipo = $_POST['tipo'] ?? 'despesa';
        $valor = (float)($_POST['valor'] ?? 0);
        $data = $_POST['data'] ?? date('Y-m-d');
        $descricao = trim($_POST['descricao'] ?? '');
        $consolidada = !empty($_POST['consolidada']) ? 1 : 0;
        $notas = trim($_POST['notas'] ?? '');
        
        $id_categoria = !empty($_POST['id_categoria']) ? (int)$_POST['id_categoria'] : NULL;
        $id_conta = !empty($_POST['id_conta']) ? (int)$_POST['id_conta'] : NULL;
        $id_conta_destino = !empty($_POST['id_conta_destino']) ? (int)$_POST['id_conta_destino'] : NULL;
        
        $recorrencias = 0;
        if (isset($_POST['indefinidamente'])) {
            $recorrencias = -1;
        } elseif (!empty($_POST['recorrencias'])) {
            $recorrencias = (int)$_POST['recorrencias'];
        }
        
        $dia_vencimento = isset($_POST['dia_vencimento']) && $_POST['dia_vencimento'] !== '' ? (int)$_POST['dia_vencimento'] : (int)date('d', strtotime($data));
        $parcela_recorrencia = 1;
        
        $modo_edicao = $_POST['modo_edicao'] ?? 'todas_futuras'; // 'somente_esta' ou 'todas_futuras'
        $id_grupo_recorrencia = $_POST['id_grupo_recorrencia'] ?? NULL;
        if (empty($id_grupo_recorrencia) && $recorrencias != 0) {
            $id_grupo_recorrencia = 'REC-' . uniqid();
        }

        if ($tipo === 'despesa') {
            $valor = -abs($valor);
        } elseif ($tipo === 'receita') {
            $valor = abs($valor);
        }

        if ($descricao && $id_conta) {
            // Antes de qualquer UPDATE, verificamos se precisamos manipular a cadeia de recorrência
            $old_transacao = null;
            if ($id > 0) {
                $stmt_old = $mysqliFinancas->prepare("SELECT * FROM transacoes WHERE id=? AND iduser=?");
                $stmt_old->bind_param("ii", $id, $user_id);
                $stmt_old->execute();
                $old_transacao = $stmt_old->get_result()->fetch_assoc();
            }

            if ($old_transacao && $modo_edicao === 'somente_esta' && !empty($old_transacao['id_grupo_recorrencia']) && $old_transacao['recorrencias'] != 0) {
                // Clonar a transação antiga para a próxima data (continua a cadeia)
                $prox_data = date('Y-m-d', strtotime('+1 month', strtotime($old_transacao['data'])));
                $prox_rec = $old_transacao['recorrencias'] > 0 ? $old_transacao['recorrencias'] - 1 : -1;
                
                if ($prox_rec != 0) {
                    $stmt_spawn = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, recorrencias, id_grupo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)");
                    $stmt_spawn->bind_param("sdsiiiisis", $prox_data, $old_transacao['valor'], $old_transacao['descricao'], $old_transacao['idcategoria'], $old_transacao['idconta'], $user_id, $old_transacao['notas'], $prox_rec, $old_transacao['id_grupo_recorrencia']);
                    $stmt_spawn->execute();
                    $new_id = $mysqliFinancas->insert_id;
                    
                    // Se era transferencia, clonar a perna de entrada antiga também
                    if ($old_transacao['idcategoria'] == -1) {
                        $stmt_old_in = $mysqliFinancas->prepare("SELECT * FROM transacoes WHERE idpai=? AND iduser=?");
                        $stmt_old_in->bind_param("ii", $id, $user_id);
                        $stmt_old_in->execute();
                        if ($old_in = $stmt_old_in->get_result()->fetch_assoc()) {
                            $stmt_spawn_in = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, idpai, notas) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                            $stmt_spawn_in->bind_param("sdsiiiiis", $prox_data, $old_in['valor'], $old_in['descricao'], $old_in['idcategoria'], $old_in['idconta'], $user_id, $new_id, $old_in['notas']);
                            $stmt_spawn_in->execute();
                        }
                    }
                }
                // A transação que o usuário está editando agora vira "filha única", isolada
                $recorrencias = 0;
            }

            if ($tipo === 'transferencia') {
                if (!$id_conta_destino) {
                    $erro = "Selecione a conta de destino.";
                } else {
                    $id_categoria = -1;
                    $valor_origem = -abs($valor);
                    $valor_destino = abs($valor);

                    if ($id > 0) {
                        $mysqliFinancas->begin_transaction();
                        try {
                            $stmt1 = $mysqliFinancas->prepare("UPDATE transacoes SET data=?, valor=?, descricao=?, idcategoria=?, idconta=?, consolidada=?, notas=?, recorrencias=?, id_grupo_recorrencia=? WHERE id=? AND iduser=?");
                            $stmt1->bind_param("sdsiiisissi", $data, $valor_origem, $descricao, $id_categoria, $id_conta, $consolidada, $notas, $recorrencias, $id_grupo_recorrencia, $id, $user_id);
                            $stmt1->execute();
                            
                            $stmt2 = $mysqliFinancas->prepare("UPDATE transacoes SET data=?, valor=?, descricao=?, idcategoria=?, idconta=?, consolidada=?, notas=? WHERE idpai=? AND iduser=?");
                            $stmt2->bind_param("sdsiiisii", $data, $valor_destino, $descricao, $id_categoria, $id_conta_destino, $consolidada, $notas, $id, $user_id);
                            $stmt2->execute();
                            
                            $mysqliFinancas->commit();
                            $sucesso = "Transferência atualizada!";
                        } catch (Exception $e) {
                            $mysqliFinancas->rollback();
                            $erro = "Erro ao atualizar transferência.";
                        }
                    } else {
                        $mysqliFinancas->begin_transaction();
                        try {
                            $stmt1 = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, recorrencias, id_grupo_recorrencia, parcela_recorrencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt1->bind_param("sdsiiiisisi", $data, $valor_origem, $descricao, $id_categoria, $id_conta, $user_id, $consolidada, $notas, $recorrencias, $id_grupo_recorrencia, $parcela_recorrencia);
                            $stmt1->execute();
                            $id_pai = $mysqliFinancas->insert_id;
                            
                            $stmt2 = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, idpai, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt2->bind_param("sdsiiiiis", $data, $valor_destino, $descricao, $id_categoria, $id_conta_destino, $user_id, $consolidada, $id_pai, $notas);
                            $stmt2->execute();
                            
                            // Create 2nd occurrence immediately if recurring
                            if ($recorrencias == -1 || $parcela_recorrencia < $recorrencias) {
                                $prox_data_obj = new DateTime($data);
                                $prox_data_obj->modify('first day of next month');
                                $last_day = (int)$prox_data_obj->format('t');
                                $day_to_use = min($dia_vencimento, $last_day);
                                $prox_data_obj->setDate((int)$prox_data_obj->format('Y'), (int)$prox_data_obj->format('m'), $day_to_use);
                                $prox_data = $prox_data_obj->format('Y-m-d');
                                $prox_parcela = $parcela_recorrencia + 1;
                                
                                $stmt_spawn = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, recorrencias, id_grupo_recorrencia, parcela_recorrencia) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
                                $stmt_spawn->bind_param("sdsiiiisisi", $prox_data, $valor_origem, $descricao, $id_categoria, $id_conta, $user_id, $notas, $recorrencias, $id_grupo_recorrencia, $prox_parcela);
                                $stmt_spawn->execute();
                                $new_id = $mysqliFinancas->insert_id;
                                
                                $stmt_spawn_in = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, idpai, notas) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                                $stmt_spawn_in->bind_param("sdsiiiiis", $prox_data, $valor_destino, $descricao, $id_categoria, $id_conta_destino, $user_id, $new_id, $notas);
                                $stmt_spawn_in->execute();
                            }
                            
                            $mysqliFinancas->commit();
                            $sucesso = "Transferência registrada com sucesso!";
                            $id = $id_pai;
                        } catch (Exception $e) {
                            $mysqliFinancas->rollback();
                            $erro = "Erro ao transferir.";
                        }
                    }
                }
            } else {
                if ($id > 0) {
                    $stmt = $mysqliFinancas->prepare("UPDATE transacoes SET data=?, valor=?, descricao=?, idcategoria=?, idconta=?, consolidada=?, notas=?, recorrencias=?, id_grupo_recorrencia=? WHERE id=? AND iduser=?");
                    $stmt->bind_param("sdsiiisissi", $data, $valor, $descricao, $id_categoria, $id_conta, $consolidada, $notas, $recorrencias, $id_grupo_recorrencia, $id, $user_id);
                    if ($stmt->execute()) {
                        $sucesso = "Transação atualizada!";
                    } else {
                        $erro = "Erro ao atualizar: " . $mysqliFinancas->error;
                    }
                } else {
                    $stmt = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, recorrencias, id_grupo_recorrencia, parcela_recorrencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sdsiiiisisi", $data, $valor, $descricao, $id_categoria, $id_conta, $user_id, $consolidada, $notas, $recorrencias, $id_grupo_recorrencia, $parcela_recorrencia);
                    if ($stmt->execute()) {
                        $sucesso = "Transação inserida com sucesso!";
                        $id = $mysqliFinancas->insert_id;
                        
                        // Create 2nd occurrence immediately if recurring
                        if ($recorrencias == -1 || $parcela_recorrencia < $recorrencias) {
                            $prox_data_obj = new DateTime($data);
                            $prox_data_obj->modify('first day of next month');
                            $last_day = (int)$prox_data_obj->format('t');
                            $day_to_use = min($dia_vencimento, $last_day);
                            $prox_data_obj->setDate((int)$prox_data_obj->format('Y'), (int)$prox_data_obj->format('m'), $day_to_use);
                            $prox_data = $prox_data_obj->format('Y-m-d');
                            $prox_parcela = $parcela_recorrencia + 1;
                            
                            $stmt_spawn = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, recorrencias, id_grupo_recorrencia, parcela_recorrencia) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
                            $stmt_spawn->bind_param("sdsiiiisisi", $prox_data, $valor, $descricao, $id_categoria, $id_conta, $user_id, $notas, $recorrencias, $id_grupo_recorrencia, $prox_parcela);
                            $stmt_spawn->execute();
                        }
                    } else {
                        $erro = "Erro ao inserir: " . $mysqliFinancas->error;
                    }
                }
            }
            
            // Lógica de "todas_futuras" - atualiza as transações vinculadas que ainda não ocorreram (consolidada = 0)
            if ($id > 0 && $modo_edicao === 'todas_futuras' && !empty($id_grupo_recorrencia) && empty($erro)) {
                $stmt_futuras = $mysqliFinancas->prepare("SELECT id FROM transacoes WHERE id_grupo_recorrencia=? AND consolidada=0 AND id!=? AND iduser=?");
                $stmt_futuras->bind_param("sii", $id_grupo_recorrencia, $id, $user_id);
                $stmt_futuras->execute();
                $futuras = $stmt_futuras->get_result()->fetch_all(MYSQLI_ASSOC);
                
                foreach ($futuras as $fut) {
                    $id_fut = $fut['id'];
                    $valor_out = ($tipo === 'transferencia') ? -abs($valor) : $valor;
                    $stmt_upd = $mysqliFinancas->prepare("UPDATE transacoes SET valor=?, descricao=?, idcategoria=?, idconta=?, notas=?, recorrencias=? WHERE id=? AND iduser=?");
                    $stmt_upd->bind_param("dsiisiii", $valor_out, $descricao, $id_categoria, $id_conta, $notas, $recorrencias, $id_fut, $user_id);
                    $stmt_upd->execute();
                    
                    if ($tipo === 'transferencia') {
                        $valor_in = abs($valor);
                        $stmt_upd_in = $mysqliFinancas->prepare("UPDATE transacoes SET valor=?, descricao=?, idcategoria=?, idconta=?, notas=? WHERE idpai=? AND iduser=?");
                        $stmt_upd_in->bind_param("dsiisii", $valor_in, $descricao, $id_categoria, $id_conta_destino, $notas, $id_fut, $user_id);
                        $stmt_upd_in->execute();
                    }
                }
            }
        } else {
            $erro = "Preencha a descrição e selecione uma conta.";
        }
    }
}

// Carregar dados se edição (ou após INSERT)
if ($id > 0) {
    $stmt = $mysqliFinancas->prepare("SELECT data, valor, descricao, idcategoria, idconta, consolidada, idpai, notas, recorrencias, id_grupo_recorrencia FROM transacoes WHERE id = ? AND iduser = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($transacao = $res->fetch_assoc()) {
        $data = $transacao['data'];
        $valor = $transacao['valor'];
        $descricao = $transacao['descricao'];
        $id_categoria = $transacao['idcategoria'];
        $id_conta = $transacao['idconta'];
        $consolidada = $transacao['consolidada'];
        $id_pai = $transacao['idpai'];
        $notas = $transacao['notas'];
        $recorrencias = $transacao['recorrencias'] ?? 0;
        $id_grupo_recorrencia = $transacao['id_grupo_recorrencia'];
        
        if ($id_categoria == -1) {
            $tipo = 'transferencia';
            
            // Lógica para carregar as DUAS pernas da transferência e exibir corretamente na interface
            $parent_id = $id_pai ? $id_pai : $id;
            
            $stmt2 = $mysqliFinancas->prepare("SELECT idconta, valor FROM transacoes WHERE (id = ? OR idpai = ?) AND iduser = ?");
            $stmt2->bind_param("iii", $parent_id, $parent_id, $user_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($leg = $res2->fetch_assoc()) {
                if ($leg['valor'] < 0) {
                    $id_conta = $leg['idconta']; // Conta Origem (Despesa)
                } else {
                    $id_conta_destino = $leg['idconta']; // Conta Destino (Receita)
                }
            }
            $stmt2->close();
            
            // Forçamos o ID atual a ser o ID PAI. Assim, ao salvar a edição (POST), atualizamos o pai e o filho.
            $id = $parent_id; 
            
        } elseif ($valor < 0) {
            $tipo = 'despesa';
        } else {
            $tipo = 'receita';
        }
        $valor = abs($valor); // Remove o sinal para exibir no numpad
    } else {
        header("Location: transacoes.php");
        exit;
    }
    $stmt->close();
}

// Buscar Categorias
$stmt = $mysqliFinancas->prepare("SELECT id, nome, cor, id_pai FROM categorias WHERE id_user = ? ORDER BY nome ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$categorias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function buildCategoryTree(array $elements, $parentId = null) {
    $branch = array();
    foreach ($elements as $element) {
        if ($element['id_pai'] == $parentId) {
            $children = buildCategoryTree($elements, $element['id']);
            if ($children) {
                $element['children'] = $children;
            } else {
                $element['children'] = [];
            }
            $branch[] = $element;
        }
    }
    return $branch;
}

$arvore_categorias = buildCategoryTree($categorias);

function renderCategoryPanelHtml($nodes, $level = 0) {
    if (count($nodes) === 0) return;
    $marginLeft = $level > 0 ? 'ml-6 border-l border-white/10 pl-2' : '';
    echo "<div class='space-y-1 $marginLeft'>";
    foreach ($nodes as $cat) {
        $hasChildren = count($cat['children']) > 0;
        $cor = htmlspecialchars($cat['cor'] ?: '#ccc');
        $nome = htmlspecialchars($cat['nome']);
        $nomeJs = addslashes($cat['nome']);
        $id = $cat['id'];
        
        echo "<div class='flex flex-col'>";
        echo "<div class='flex items-center justify-between p-2 border-b border-white/5 hover:bg-white/10 transition-colors rounded-xl'>";
        
        // Área clicável para SELECIONAR a categoria (Opção B)
        echo "<div class='flex items-center space-x-3 flex-1 cursor-pointer py-2' onclick=\"selectItem('categoria', '$id', '$nomeJs')\">";
        echo "<div class='w-4 h-4 rounded-full border border-white/20 shadow-inner' style='background-color: $cor'></div>";
        echo "<span class='text-white font-medium'>$nome</span>";
        echo "</div>";
        
        // Botão para EXPANDIR filhos (apenas se tiver filhos)
        if ($hasChildren) {
            echo "<button type='button' onclick='togglePanelChildren($id)' class='p-2 text-white/50 hover:text-white transition-colors bg-white/5 rounded-lg'>";
            echo "<svg id='panel-icon-$id' class='w-5 h-5 transform -rotate-90 transition-transform duration-200' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'></path></svg>";
            echo "</button>";
        } else {
            echo "<div class='w-9 h-9'></div>"; // Espaçador
        }
        
        echo "</div>";
        
        if ($hasChildren) {
            echo "<div id='panel-children-$id' class='hidden mt-1'>";
            renderCategoryPanelHtml($cat['children'], $level + 1);
            echo "</div>";
        }
        
        echo "</div>";
    }
    echo "</div>";
}

// Buscar Contas
$stmt = $mysqliFinancas->prepare("SELECT id, nome, cor FROM contas WHERE id_user = ? AND status = 1 ORDER BY nome ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$contas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Auxiliar para obter nome na UI
$nome_categoria = 'Selecionar';
foreach ($categorias as $cat) {
    if ($cat['id'] == $id_categoria) $nome_categoria = $cat['nome'];
}
$nome_conta = 'Selecionar';
foreach ($contas as $conta) {
    if ($conta['id'] == $id_conta) $nome_conta = $conta['nome'];
}
$nome_conta_destino = 'Selecionar';
foreach ($contas as $conta) {
    if ($conta['id'] == $id_conta_destino) $nome_conta_destino = $conta['nome'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Nova Transação - Minhas Finanças</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            overflow-x: hidden;
        }
        .blob {
            position: fixed;
            filter: blur(80px);
            z-index: -1;
            opacity: 0.6;
            animation: move 10s infinite alternate ease-in-out;
            transition: background 0.5s ease;
        }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; border-radius: 50%; }
        .blob-2 { bottom: -10%; right: -10%; width: 600px; height: 600px; border-radius: 50%; animation-delay: 2s; }
        .blob-3 { top: 40%; left: 40%; width: 400px; height: 400px; border-radius: 50%; animation-delay: 4s; }
        
        @keyframes move {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, -50px) scale(1.1); }
        }

        .theme-despesa .blob-1 { background: #ef4444; }
        .theme-despesa .blob-2 { background: #f43f5e; }
        .theme-despesa .blob-3 { background: #be123c; }
        .theme-despesa .header-glass { background: linear-gradient(135deg, rgba(239, 68, 68, 0.4), rgba(225, 29, 72, 0.2)); border-bottom-color: rgba(239, 68, 68, 0.3); }

        .theme-receita .blob-1 { background: #10b981; }
        .theme-receita .blob-2 { background: #059669; }
        .theme-receita .blob-3 { background: #047857; }
        .theme-receita .header-glass { background: linear-gradient(135deg, rgba(16, 185, 129, 0.4), rgba(5, 150, 105, 0.2)); border-bottom-color: rgba(16, 185, 129, 0.3); }

        .theme-transferencia .blob-1 { background: #3b82f6; }
        .theme-transferencia .blob-2 { background: #4f46e5; }
        .theme-transferencia .blob-3 { background: #3730a3; }
        .theme-transferencia .header-glass { background: linear-gradient(135deg, rgba(59, 130, 246, 0.4), rgba(79, 70, 229, 0.2)); border-bottom-color: rgba(59, 130, 246, 0.3); }

        .toggle-checkbox:checked { right: 0; border-color: #10b981; }
        .toggle-checkbox:checked + .toggle-label { background-color: #10b981; }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-screen relative theme-<?php echo $tipo; ?>" id="app-body">
    
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <div class="hidden md:block">
        <?php include 'menu.php'; ?>
    </div>

    <!-- Formulário Submetido via JS -->
    <form id="transacao-form" method="POST" action="transacao.php<?php echo $id > 0 ? '?id='.$id : ''; ?>" class="hidden">
        <input type="hidden" name="action" id="input-action" value="save">
        <input type="hidden" name="tipo" id="input-tipo" value="<?php echo $tipo; ?>">
        <input type="hidden" name="valor" id="input-valor" value="<?php echo number_format($valor, 2, '.', ''); ?>">
        <input type="hidden" name="id_categoria" id="input-categoria" value="<?php echo $id_categoria; ?>">
        <input type="hidden" name="id_conta" id="input-conta" value="<?php echo $id_conta; ?>">
        <input type="hidden" name="id_conta_destino" id="input-conta-destino" value="<?php echo $id_conta_destino; ?>">
        <!-- Valores abaixo serão populados via JS antes do submit -->
        <input type="hidden" name="data" id="input-data">
        <input type="hidden" name="descricao" id="input-descricao">
        <input type="hidden" name="consolidada" id="input-consolidada">
        <input type="hidden" name="notas" id="input-notas">
        <input type="hidden" name="recorrencias" id="input-recorrencias">
        <input type="hidden" name="indefinidamente" id="input-indefinidamente">
        <input type="hidden" name="dia_vencimento" id="input-dia-vencimento">
        <input type="hidden" name="id_grupo_recorrencia" id="input-id-grupo-recorrencia" value="<?php echo htmlspecialchars($id_grupo_recorrencia ?? ''); ?>">
        <input type="hidden" name="modo_edicao" id="input-modo-edicao" value="todas_futuras">
    </form>

    <div class="max-w-md mx-auto relative h-[85vh] md:h-[80vh] flex flex-col mb-10 overflow-hidden">
        
        <?php if ($erro): ?>
            <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-2 rounded-xl mb-4 mx-2 sm:mx-0 text-sm z-50 relative">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="bg-emerald-500/20 border border-emerald-500/50 text-emerald-200 px-4 py-2 rounded-xl mb-4 mx-2 sm:mx-0 text-sm z-50 relative">
                <?php echo $sucesso; ?>
            </div>
        <?php endif; ?>

        <!-- Formulário Principal -->
        <div id="main-view" class="bg-white/10 backdrop-blur-2xl border border-white/20 rounded-[2.5rem] shadow-2xl h-full flex flex-col relative mx-2 sm:mx-0 z-10 transition-transform duration-300">
            
            <!-- Cabeçalho dinâmico -->
            <div id="header-area" class="header-glass p-6 transition-all duration-500 border-b relative shrink-0 rounded-t-[2.5rem] z-50">
                <div class="flex justify-between items-center mb-6">
                    <a href="transacoes.php" class="text-white/80 hover:text-white font-medium">Cancelar</a>
                    <span id="header-title" class="text-white font-semibold text-lg tracking-wide">
                        <?php 
                        if($tipo == 'despesa') echo 'Despesa'; 
                        elseif($tipo == 'receita') echo 'Receita'; 
                        else echo 'Transferência'; 
                        ?>
                    </span>
                    <button type="button" onclick="submitForm()" class="text-white font-bold tracking-wide">Salvar</button>
                </div>

                <div class="flex items-center justify-between mb-2">
                    <button type="button" onclick="toggleTypeSelect()" class="w-12 h-12 rounded-2xl border border-white/40 flex items-center justify-center bg-white/10 hover:bg-white/20 transition-colors cursor-pointer z-20">
                        <svg id="icon-seta" class="w-6 h-6 text-white transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <?php if($tipo == 'despesa'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                            <?php elseif($tipo == 'receita'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
                            <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                            <?php endif; ?>
                        </svg>
                    </button>
                    
                    <div class="flex-1 text-right ml-4 cursor-pointer relative z-10" onclick="toggleNumpad()">
                        <span class="text-4xl md:text-5xl font-bold text-white tracking-tight" id="display-valor">
                            R$ <?php echo number_format($valor, 2, ',', '.'); ?>
                        </span>
                    </div>
                </div>

                <!-- Action Sheet de Seleção de Tipo -->
                <div id="type-selector" class="absolute top-[85px] left-6 bg-white/95 backdrop-blur-3xl rounded-2xl shadow-[0_10px_40px_rgba(0,0,0,0.5)] border border-white/20 w-48 overflow-hidden hidden opacity-0 transition-opacity duration-200 z-50">
                    <button onclick="setTipo('despesa')" class="w-full text-left px-4 py-3 border-b border-gray-100 flex items-center space-x-3 hover:bg-gray-50 transition-colors">
                        <span class="w-6 h-6 rounded-full bg-red-100 text-red-500 flex items-center justify-center"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg></span>
                        <span class="text-gray-800 font-medium">Despesa</span>
                    </button>
                    <button onclick="setTipo('receita')" class="w-full text-left px-4 py-3 border-b border-gray-100 flex items-center space-x-3 hover:bg-gray-50 transition-colors">
                        <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-500 flex items-center justify-center"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg></span>
                        <span class="text-gray-800 font-medium">Receita</span>
                    </button>
                    <button onclick="setTipo('transferencia')" class="w-full text-left px-4 py-3 flex items-center space-x-3 hover:bg-gray-50 transition-colors">
                        <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-500 flex items-center justify-center"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg></span>
                        <span class="text-gray-800 font-medium">Transferência</span>
                    </button>
                </div>
                <div id="type-selector-overlay" onclick="toggleTypeSelect()" class="fixed inset-0 z-40 hidden"></div>
            </div>

            <div class="flex-1 overflow-y-auto no-scrollbar p-2 relative">
                <div class="bg-white/5 rounded-3xl p-2 space-y-1 my-4">
                    
                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <span class="text-gray-300 font-medium">Data</span>
                        <input type="date" class="bg-transparent text-right text-white focus:outline-none w-32" id="ui-data" value="<?php echo htmlspecialchars($data); ?>">
                    </div>
                    
                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <span class="text-gray-300 font-medium whitespace-nowrap mr-4">Descrição</span>
                        <input type="text" class="bg-transparent text-right text-white placeholder-white/40 focus:outline-none w-full" placeholder="Ex: Mercado" id="ui-descricao" value="<?php echo htmlspecialchars($descricao); ?>">
                    </div>

                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-300 font-medium">Consolidada</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="relative inline-block w-12 mr-2 align-middle select-none transition duration-200 ease-in">
                                <input type="checkbox" id="ui-consolidada" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 border-gray-400 appearance-none cursor-pointer transition-all duration-300" <?php echo $consolidada ? 'checked' : ''; ?>/>
                                <label for="ui-consolidada" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-400 cursor-pointer transition-colors duration-300"></label>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-3 border-b border-white/5 cursor-pointer hover:bg-white/5 rounded-xl transition-colors <?php echo $tipo == 'transferencia' ? 'hidden' : ''; ?>" id="linha-categoria" onclick="openPanel('panel-categoria')">
                        <span class="text-gray-300 font-medium">Categoria</span>
                        <div class="flex items-center text-white/70 space-x-2">
                            <span id="display-categoria" class="text-white"><?php echo htmlspecialchars($nome_categoria); ?></span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-3 border-b border-white/5 cursor-pointer hover:bg-white/5 rounded-xl transition-colors" onclick="openPanel('panel-conta')">
                        <span class="text-gray-300 font-medium" id="label-conta-origem"><?php echo $tipo == 'transferencia' ? 'Conta Origem' : 'Conta'; ?></span>
                        <div class="flex items-center text-white/70 space-x-2">
                            <span id="display-conta" class="text-white"><?php echo htmlspecialchars($nome_conta); ?></span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between p-3 border-white/5 cursor-pointer hover:bg-white/5 rounded-xl transition-colors <?php echo $tipo == 'transferencia' ? '' : 'hidden'; ?>" id="linha-conta-destino" onclick="openPanel('panel-conta-destino')">
                        <span class="text-gray-300 font-medium">Conta Destino</span>
                        <div class="flex items-center text-white/70 space-x-2">
                            <span id="display-conta-destino" class="text-white"><?php echo htmlspecialchars($nome_conta_destino); ?></span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </div>

                <div class="flex justify-center my-6" id="container-btn-mais-opcoes">
                    <button type="button" onclick="toggleMaisOpcoes()" id="btn-mais-opcoes" class="px-6 py-2 rounded-full border border-white/30 text-white/60 hover:text-white hover:bg-white/5 hover:border-white/50 text-sm font-semibold tracking-wide transition-all uppercase">
                        Mais Opções
                    </button>
                </div>

                <div id="mais-opcoes" class="hidden opacity-0 transition-opacity duration-500 pb-6">
                    <div class="bg-white/5 rounded-3xl p-2 space-y-1">
                        <div class="flex items-center justify-between p-3 border-b border-white/5">
                            <span class="text-gray-300 font-medium">Nota</span>
                            <input type="text" class="bg-transparent text-right text-white placeholder-white/40 focus:outline-none w-full ml-4" placeholder="Adicionar Notas..." id="ui-notas" value="<?php echo htmlspecialchars($notas); ?>">
                        </div>

                        <div class="p-3">
                            <div class="flex rounded-xl bg-black/20 p-1">
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white shadow bg-white/20 transition-all" id="tab-nenhuma">Nenhuma</button>
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white/60 hover:text-white transition-all bg-transparent" id="tab-avancada">Avançada</button>
                            </div>
                        </div>

                        <div id="opcoes-avancadas-conteudo" class="p-2 space-y-3 hidden">
                            <div class="flex items-center justify-between p-3 border border-white/5 rounded-xl bg-white/5">
                                <span class="text-gray-300 font-medium text-sm">Intervalo</span>
                                <span class="text-white font-medium text-sm bg-black/20 px-3 py-1 rounded-lg">Mensal (1 Mês)</span>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 border border-white/5 rounded-xl bg-white/5">
                                <span class="text-gray-300 font-medium text-sm">Dia do Vencimento</span>
                                <input type="number" id="ui-dia-vencimento" min="1" max="31" value="<?php echo date('d', strtotime($data)); ?>" class="bg-black/20 text-right text-white placeholder-white/40 focus:outline-none focus:ring-1 focus:ring-cyan-500 rounded-lg px-3 py-1 w-24">
                            </div>
                            
                            <div class="flex items-center justify-between p-3 border border-white/5 rounded-xl bg-white/5">
                                <span class="text-gray-300 font-medium text-sm">Indefinidamente</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                  <input type="checkbox" id="ui-indefinidamente" onchange="toggleIndefinidamente()" class="sr-only peer" <?php echo ($recorrencias == -1) ? 'checked' : ''; ?>>
                                  <div class="w-11 h-6 bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cyan-500"></div>
                                </label>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 border border-white/5 rounded-xl bg-white/5">
                                <span class="text-gray-300 font-medium text-sm">Parcela Final</span>
                                <input type="number" id="ui-ocorrencias" min="1" value="<?php echo ($recorrencias > 0) ? $recorrencias : ''; ?>" class="bg-black/20 text-right text-white placeholder-white/40 focus:outline-none focus:ring-1 focus:ring-cyan-500 rounded-lg px-3 py-1 w-24 disabled:opacity-50" <?php echo ($recorrencias == -1) ? 'disabled' : ''; ?> placeholder="Ex: 12">
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($id > 0): ?>
                <!-- Botão Excluir -->
                <div class="pb-8 pt-4 px-4" id="btn-excluir-container">
                    <button type="button" onclick="excluirTransacao()" class="w-full py-3 bg-red-500/20 text-red-400 hover:bg-red-500 hover:text-white rounded-xl border border-red-500/30 transition-colors font-medium">
                        Excluir Transação
                    </button>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Panels Categoria e Contas -->
        <div id="panel-categoria" class="absolute inset-0 bg-slate-900/95 backdrop-blur-3xl rounded-[2.5rem] z-30 translate-x-full transition-transform duration-300 flex flex-col shadow-2xl">
            <div class="p-6 border-b border-white/10 flex items-center justify-between shrink-0">
                <button onclick="closePanel('panel-categoria')" class="text-cyan-400 hover:text-cyan-300 flex items-center space-x-1 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    <span>Voltar</span>
                </button>
                <span class="text-white font-semibold">Selecionar Categoria</span>
                <div class="w-16"></div>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <div class="bg-white/5 rounded-3xl p-2 border border-white/10">
                    <?php if (count($arvore_categorias) > 0): ?>
                        <?php renderCategoryPanelHtml($arvore_categorias); ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-white/50">Nenhuma categoria encontrada.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="panel-conta" class="absolute inset-0 bg-slate-900/95 backdrop-blur-3xl rounded-[2.5rem] z-30 translate-x-full transition-transform duration-300 flex flex-col shadow-2xl">
            <div class="p-6 border-b border-white/10 flex items-center justify-between shrink-0">
                <button onclick="closePanel('panel-conta')" class="text-cyan-400 hover:text-cyan-300 flex items-center space-x-1 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    <span>Voltar</span>
                </button>
                <span class="text-white font-semibold" id="title-panel-conta">Selecionar Conta</span>
                <div class="w-16"></div>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <div class="bg-white/5 rounded-3xl overflow-hidden border border-white/10">
                    <?php foreach($contas as $conta): ?>
                        <button onclick="selectItem('conta', '<?php echo $conta['id']; ?>', '<?php echo addslashes($conta['nome']); ?>')" class="w-full text-left p-4 border-b border-white/5 hover:bg-white/10 transition-colors flex items-center space-x-3 last:border-b-0">
                            <div class="w-4 h-4 rounded-full" style="background-color: <?php echo $conta['cor'] ?: '#ccc'; ?>"></div>
                            <span class="text-white font-medium"><?php echo htmlspecialchars($conta['nome']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="panel-conta-destino" class="absolute inset-0 bg-slate-900/95 backdrop-blur-3xl rounded-[2.5rem] z-30 translate-x-full transition-transform duration-300 flex flex-col shadow-2xl">
            <div class="p-6 border-b border-white/10 flex items-center justify-between shrink-0">
                <button onclick="closePanel('panel-conta-destino')" class="text-cyan-400 hover:text-cyan-300 flex items-center space-x-1 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    <span>Voltar</span>
                </button>
                <span class="text-white font-semibold">Selecionar Destino</span>
                <div class="w-16"></div>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <div class="bg-white/5 rounded-3xl overflow-hidden border border-white/10">
                    <?php foreach($contas as $conta): ?>
                        <button onclick="selectItem('conta-destino', '<?php echo $conta['id']; ?>', '<?php echo addslashes($conta['nome']); ?>')" class="w-full text-left p-4 border-b border-white/5 hover:bg-white/10 transition-colors flex items-center space-x-3 last:border-b-0">
                            <div class="w-4 h-4 rounded-full" style="background-color: <?php echo $conta['cor'] ?: '#ccc'; ?>"></div>
                            <span class="text-white font-medium"><?php echo htmlspecialchars($conta['nome']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
    </div>

    <!-- Overlay Numpad -->
    <div id="numpad-overlay" onclick="closeNumpad()" class="fixed inset-0 bg-transparent z-40 hidden md:hidden"></div>

    <div id="numpad" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto bg-[#e2e8f0]/95 backdrop-blur-2xl rounded-t-[2.5rem] p-6 transform translate-y-full transition-transform duration-300 z-50 shadow-[0_-10px_40px_rgba(0,0,0,0.3)] md:hidden">
        <div class="flex justify-between items-center mb-4">
            <span class="text-gray-800 font-semibold pl-2">Digite o valor</span>
            <button onclick="closeNumpad()" class="text-gray-500 hover:text-gray-800 p-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="grid grid-cols-4 gap-3">
            <div class="col-span-3 grid grid-cols-3 gap-3">
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('7')">7</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('8')">8</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('9')">9</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('4')">4</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('5')">5</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('6')">6</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('1')">1</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('2')">2</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('3')">3</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addDoubleZero()">,00</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200" onclick="addNumber('0')">0</button>
                <button class="bg-gray-500 rounded-full h-16 text-2xl font-medium text-white shadow-sm active:bg-gray-600 flex items-center justify-center" onclick="backspace()">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414 6.414a2 2 0 001.414.586H19a2 2 0 002-2V7a2 2 0 00-2-2h-8.172a2 2 0 00-1.414.586L3 12z"></path></svg>
                </button>
            </div>
            <div class="col-span-1 grid grid-rows-4 gap-3 bg-white rounded-[2rem] p-2 shadow-sm">
                <button class="text-2xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full" onclick="setOperation('÷')">÷</button>
                <button class="text-2xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full" onclick="setOperation('×')">×</button>
                <button class="text-3xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full" onclick="setOperation('-')">-</button>
                <button class="text-3xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full" onclick="setOperation('+')">+</button>
            </div>
        </div>
        <button id="btn-ok-numpad" class="w-full mt-3 bg-gradient-to-r from-orange-400 to-orange-500 rounded-full h-14 text-white text-2xl font-bold shadow-lg hover:from-orange-500 hover:to-orange-600 transition-all active:scale-95" onclick="handleOkButton()">
            OK
        </button>
    </div>

        <!-- Modal Edição Recorrência -->
        <div id="modal-edicao-recorrencia" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-slate-800 border border-white/10 rounded-3xl p-6 shadow-2xl max-w-sm w-full">
                <h3 class="text-white font-medium text-lg mb-2">Editar Transação Recorrente</h3>
                <p class="text-white/60 text-sm mb-6">Esta é uma transação recorrente. Você deseja alterar apenas esta ocorrência ou todas as futuras também?</p>
                
                <div class="space-y-3">
                    <button type="button" onclick="confirmarEdicaoRecorrencia('somente_esta')" class="w-full py-3 bg-white/5 hover:bg-white/10 text-white rounded-xl transition-colors font-medium border border-white/5">
                        Apenas esta transação
                    </button>
                    <button type="button" onclick="confirmarEdicaoRecorrencia('todas_futuras')" class="w-full py-3 bg-cyan-500 hover:bg-cyan-600 text-white rounded-xl transition-colors font-medium shadow-lg shadow-cyan-500/20">
                        Esta e as futuras
                    </button>
                    <button type="button" onclick="document.getElementById('modal-edicao-recorrencia').classList.add('hidden')" class="w-full py-3 text-white/50 hover:text-white transition-colors font-medium text-sm mt-2">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

    <script>
        // Inicializar Numpad com valor atual
        let valorAtual = "<?php echo number_format($valor * 100, 0, '', ''); ?>";
        if(valorAtual === "0") valorAtual = "000";
        
        // Variaveis da Calculadora
        let storedValue = 0;
        let pendingOp = null;
        let isNewInput = false;

        function updateDisplay() {
            const display = document.getElementById('display-valor');
            let num = parseInt(valorAtual, 10);
            if (isNaN(num)) num = 0;
            document.getElementById('input-valor').value = (num / 100).toFixed(2);
            let formatado = (num / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            display.textContent = formatado;
        }

        function addNumber(n) {
            if (isNewInput) {
                valorAtual = "000";
                isNewInput = false;
            }
            if (valorAtual === "000") valorAtual = "";
            if (valorAtual.length < 12) {
                valorAtual += n;
                updateDisplay();
            }
        }
        function addDoubleZero() {
            if (isNewInput) {
                valorAtual = "000";
                isNewInput = false;
            }
            if (valorAtual === "000") valorAtual = "";
            if (valorAtual.length < 11) {
                valorAtual += "00";
                updateDisplay();
            }
        }
        function backspace() {
            if (valorAtual.length > 1) {
                valorAtual = valorAtual.slice(0, -1);
            } else {
                valorAtual = "000";
            }
            updateDisplay();
        }
        
        function setOperation(op) {
            if (pendingOp && !isNewInput) calculateResult();
            storedValue = parseInt(valorAtual, 10) / 100;
            pendingOp = op;
            isNewInput = true;
            document.getElementById('btn-ok-numpad').innerText = "=";
        }

        function calculateResult() {
            if (!pendingOp) return;
            let currentVal = parseInt(valorAtual, 10) / 100;
            let res = 0;
            if (pendingOp === '+') res = storedValue + currentVal;
            else if (pendingOp === '-') res = storedValue - currentVal;
            else if (pendingOp === '×') res = storedValue * currentVal;
            else if (pendingOp === '÷') res = currentVal !== 0 ? storedValue / currentVal : 0;
            
            res = Math.abs(res);
            valorAtual = Math.round(res * 100).toString();
            if(valorAtual === "0") valorAtual = "000";
            updateDisplay();
            
            pendingOp = null;
            isNewInput = true;
            document.getElementById('btn-ok-numpad').innerText = "OK";
        }
        
        function handleOkButton() {
            if (pendingOp) {
                calculateResult();
            } else {
                closeNumpad();
            }
        }
        function toggleNumpad() {
            document.getElementById('numpad').classList.remove('translate-y-full');
            document.getElementById('numpad-overlay').classList.remove('hidden');
        }
        function closeNumpad() {
            document.getElementById('numpad').classList.add('translate-y-full');
            document.getElementById('numpad-overlay').classList.add('hidden');
        }

        // Seletor de Tipo
        function toggleTypeSelect() {
            const sel = document.getElementById('type-selector');
            const overlay = document.getElementById('type-selector-overlay');
            if (sel.classList.contains('hidden')) {
                sel.classList.remove('hidden');
                overlay.classList.remove('hidden');
                setTimeout(() => sel.classList.remove('opacity-0'), 10);
            } else {
                sel.classList.add('opacity-0');
                overlay.classList.add('hidden');
                setTimeout(() => sel.classList.add('hidden'), 200);
            }
        }

        function setTipo(tipo) {
            document.getElementById('input-tipo').value = tipo;
            const body = document.getElementById('app-body');
            body.classList.remove('theme-despesa', 'theme-receita', 'theme-transferencia');
            body.classList.add(`theme-${tipo}`);
            
            const title = document.getElementById('header-title');
            const seta = document.getElementById('icon-seta');
            const linhaCat = document.getElementById('linha-categoria');
            const linhaDest = document.getElementById('linha-conta-destino');
            
            if(tipo === 'despesa') {
                title.textContent = "Despesa";
                seta.setAttribute('d', 'M19 14l-7 7m0 0l-7-7m7 7V3');
                linhaCat.classList.remove('hidden');
                linhaDest.classList.add('hidden');
                document.getElementById('label-conta-origem').textContent = "Conta";
            } else if(tipo === 'receita') {
                title.textContent = "Receita";
                seta.setAttribute('d', 'M5 10l7-7m0 0l7 7m-7-7v18');
                linhaCat.classList.remove('hidden');
                linhaDest.classList.add('hidden');
                document.getElementById('label-conta-origem').textContent = "Conta";
            } else {
                title.textContent = "Transferência";
                seta.setAttribute('d', 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4');
                linhaCat.classList.add('hidden');
                linhaDest.classList.remove('hidden');
                document.getElementById('label-conta-origem').textContent = "Conta Origem";
            }
            toggleTypeSelect();
        }

        // Mais Opções
        let maisOpcoesAberto = false;
        function toggleMaisOpcoes() {
            const container = document.getElementById('mais-opcoes');
            const btn = document.getElementById('btn-mais-opcoes');
            const excluir = document.getElementById('btn-excluir-container');
            maisOpcoesAberto = !maisOpcoesAberto;

            if (maisOpcoesAberto) {
                container.classList.remove('hidden');
                setTimeout(() => container.classList.remove('opacity-0'), 10);
                btn.classList.add('bg-white/10', 'border-white/50', 'text-white');
                if (excluir) excluir.classList.add('hidden');
            } else {
                container.classList.add('opacity-0');
                btn.classList.remove('bg-white/10', 'border-white/50', 'text-white');
                setTimeout(() => container.classList.add('hidden'), 500);
                if (excluir) setTimeout(() => excluir.classList.remove('hidden'), 500);
            }
        }

        const tabs = ['nenhuma', 'avancada'];
        tabs.forEach(tab => {
            document.getElementById(`tab-${tab}`).addEventListener('click', function() {
                tabs.forEach(t => {
                    document.getElementById(`tab-${t}`).classList.remove('bg-white/20', 'text-white', 'shadow');
                    document.getElementById(`tab-${t}`).classList.add('text-white/60', 'bg-transparent');
                });
                this.classList.add('bg-white/20', 'text-white', 'shadow');
                this.classList.remove('text-white/60', 'bg-transparent');
                
                const cont = document.getElementById('opcoes-avancadas-conteudo');
                if (tab === 'nenhuma') cont.classList.add('hidden');
                else cont.classList.remove('hidden');
            });
        });

        function openPanel(id) {
            document.getElementById(id).classList.remove('translate-x-full');
            document.getElementById('main-view').classList.add('-translate-x-8', 'opacity-50', 'scale-95');
        }
        function closePanel(id) {
            document.getElementById(id).classList.add('translate-x-full');
            document.getElementById('main-view').classList.remove('-translate-x-8', 'opacity-50', 'scale-95');
        }
        function selectItem(tipo, id, nome) {
            document.getElementById(`display-${tipo}`).textContent = nome;
            document.getElementById(`input-${tipo}`).value = id;
            closePanel(`panel-${tipo}`);
        }

        function togglePanelChildren(id) {
            const container = document.getElementById('panel-children-' + id);
            const icon = document.getElementById('panel-icon-' + id);
            
            if (container) {
                if (container.classList.contains('hidden')) {
                    container.classList.remove('hidden');
                    icon.classList.remove('-rotate-90');
                } else {
                    container.classList.add('hidden');
                    icon.classList.add('-rotate-90');
                }
            }
        }

        function toggleIndefinidamente() {
            const check = document.getElementById('ui-indefinidamente');
            const inputNum = document.getElementById('ui-ocorrencias');
            if (check.checked) {
                inputNum.disabled = true;
                inputNum.value = '';
            } else {
                inputNum.disabled = false;
                inputNum.focus();
            }
        }

        function submitForm() {
            // Sincronizar UI com Form oculto
            document.getElementById('input-data').value = document.getElementById('ui-data').value;
            document.getElementById('input-descricao').value = document.getElementById('ui-descricao').value;
            document.getElementById('input-consolidada').value = document.getElementById('ui-consolidada').checked ? '1' : '';
            document.getElementById('input-notas').value = document.getElementById('ui-notas').value;
            
            const recorrenciasInput = document.getElementById('ui-ocorrencias');
            const indefinidamenteInput = document.getElementById('ui-indefinidamente');
            
            document.getElementById('input-recorrencias').value = recorrenciasInput.value;
            document.getElementById('input-indefinidamente').value = indefinidamenteInput.checked ? '1' : '';
            document.getElementById('input-dia-vencimento').value = document.getElementById('ui-dia-vencimento').value;
            
            const id_grupo_recorrencia = document.getElementById('input-id-grupo-recorrencia').value;
            const isEditing = <?php echo $id > 0 ? 'true' : 'false'; ?>;
            const isConsolidated = <?php echo ($id > 0 && $consolidada == 1) ? 'true' : 'false'; ?>;
            
            if (isEditing && id_grupo_recorrencia && !isConsolidated) {
                document.getElementById('modal-edicao-recorrencia').classList.remove('hidden');
            } else {
                document.getElementById('input-modo-edicao').value = 'somente_esta';
                document.getElementById('transacao-form').submit();
            }
        }
        
        function confirmarEdicaoRecorrencia(modo) {
            document.getElementById('input-modo-edicao').value = modo;
            document.getElementById('modal-edicao-recorrencia').classList.add('hidden');
            document.getElementById('transacao-form').submit();
        }

        function excluirTransacao() {
            if(confirm("Deseja realmente excluir esta transação?")) {
                document.getElementById('input-action').value = 'delete';
                document.getElementById('transacao-form').submit();
            }
        }

        // Teclado Físico no Desktop
        document.addEventListener('keydown', function(e) {
            // Ignorar se o usuário estiver digitando em um campo de texto real
            if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;

            if (e.key >= '0' && e.key <= '9') {
                addNumber(e.key);
            } else if (e.key === 'Backspace') {
                backspace();
            } else if (e.key === 'Enter') {
                handleOkButton();
            } else if (['+', '-', '*', '/'].includes(e.key)) {
                let op = e.key;
                if(op === '*') op = '×';
                if(op === '/') op = '÷';
                setOperation(op);
            } else if (e.key === ',' || e.key === '.') {
                addDoubleZero();
            }
        });
    </script>
</body>
</html>
