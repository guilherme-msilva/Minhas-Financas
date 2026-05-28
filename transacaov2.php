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
$parcela_recorrencia = 1;
$parcela_fim = 1;
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
        
        $is_recorrente = !empty($_POST['is_recorrente']) ? 1 : 0;
        
        $parcela_fim = 1;
        $parcela_recorrencia = 1;
        $dia_vencimento = (int)date('d', strtotime($data));
        
        if ($is_recorrente) {
            if (isset($_POST['indefinidamente'])) {
                $parcela_fim = -1;
            } elseif (!empty($_POST['parcela_fim'])) {
                $parcela_fim = (int)$_POST['parcela_fim'];
            }
            
            $dia_vencimento = isset($_POST['dia_vencimento']) && $_POST['dia_vencimento'] !== '' ? (int)$_POST['dia_vencimento'] : (int)date('d', strtotime($data));
            $parcela_recorrencia = !empty($_POST['parcela_recorrencia']) ? (int)$_POST['parcela_recorrencia'] : 1;
        }
        
        $modo_edicao = $_POST['modo_edicao'] ?? 'todas_futuras'; // 'somente_esta' ou 'todas_futuras'
        $id_grupo_recorrencia = $_POST['id_grupo_recorrencia'] ?? NULL;
        if (empty($id_grupo_recorrencia) && ($parcela_fim > 1 || $parcela_fim == -1)) {
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

            if ($old_transacao && $modo_edicao === 'somente_esta' && !empty($old_transacao['id_grupo_recorrencia']) && ($old_transacao['parcela_fim'] > 1 || $old_transacao['parcela_fim'] == -1)) {
                // Clonar a transação antiga para a próxima data (continua a cadeia)
                $prox_data = date('Y-m-d', strtotime('+1 month', strtotime($old_transacao['data'])));
                $prox_parcela = $old_transacao['parcela_recorrencia'] + 1;
                
                if ($old_transacao['parcela_fim'] == -1 || $prox_parcela <= $old_transacao['parcela_fim']) {
                    $stmt_spawn = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, parcela_recorrencia, parcela_fim, id_grupo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
                    $stmt_spawn->bind_param("sdsiiisiis", $prox_data, $old_transacao['valor'], $old_transacao['descricao'], $old_transacao['idcategoria'], $old_transacao['idconta'], $user_id, $old_transacao['notas'], $prox_parcela, $old_transacao['parcela_fim'], $old_transacao['id_grupo_recorrencia']);
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
                $parcela_fim = 1;
                $parcela_recorrencia = 1;
                $id_grupo_recorrencia = NULL;
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
                            $stmt1 = $mysqliFinancas->prepare("UPDATE transacoes SET data=?, valor=?, descricao=?, idcategoria=?, idconta=?, consolidada=?, notas=?, parcela_recorrencia=?, parcela_fim=?, id_grupo_recorrencia=? WHERE id=? AND iduser=?");
                            $stmt1->bind_param("sdsiiisiiisi", $data, $valor_origem, $descricao, $id_categoria, $id_conta, $consolidada, $notas, $parcela_recorrencia, $parcela_fim, $id_grupo_recorrencia, $id, $user_id);
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
                            $stmt1 = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, parcela_recorrencia, parcela_fim, id_grupo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt1->bind_param("sdsiiiisiis", $data, $valor_origem, $descricao, $id_categoria, $id_conta, $user_id, $consolidada, $notas, $parcela_recorrencia, $parcela_fim, $id_grupo_recorrencia);
                            $stmt1->execute();
                            $id_pai = $mysqliFinancas->insert_id;
                            
                            $stmt2 = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, idpai, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt2->bind_param("sdsiiiiis", $data, $valor_destino, $descricao, $id_categoria, $id_conta_destino, $user_id, $consolidada, $id_pai, $notas);
                            $stmt2->execute();
                            
                            // Create 2nd occurrence immediately if recurring
                            if ($parcela_fim == -1 || $parcela_recorrencia < $parcela_fim) {
                                $prox_data_obj = new DateTime($data);
                                $prox_data_obj->modify('first day of next month');
                                $last_day = (int)$prox_data_obj->format('t');
                                $day_to_use = min($dia_vencimento, $last_day);
                                $prox_data_obj->setDate((int)$prox_data_obj->format('Y'), (int)$prox_data_obj->format('m'), $day_to_use);
                                $prox_data = $prox_data_obj->format('Y-m-d');
                                $prox_parcela = $parcela_recorrencia + 1;
                                
                                $stmt_spawn = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, parcela_recorrencia, parcela_fim, id_grupo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
                                $stmt_spawn->bind_param("sdsiiisiis", $prox_data, $valor_origem, $descricao, $id_categoria, $id_conta, $user_id, $notas, $prox_parcela, $parcela_fim, $id_grupo_recorrencia);
                                $stmt_spawn->execute();
                                $new_id = $mysqliFinancas->insert_id;
                                
                                $stmt_spawn_in = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, idpai, notas) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                                $stmt_spawn_in->bind_param("sdsiiiis", $prox_data, $valor_destino, $descricao, $id_categoria, $id_conta_destino, $user_id, $new_id, $notas);
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
                    $stmt = $mysqliFinancas->prepare("UPDATE transacoes SET data=?, valor=?, descricao=?, idcategoria=?, idconta=?, consolidada=?, notas=?, parcela_recorrencia=?, parcela_fim=?, id_grupo_recorrencia=? WHERE id=? AND iduser=?");
                    $stmt->bind_param("sdsiiisiiisi", $data, $valor, $descricao, $id_categoria, $id_conta, $consolidada, $notas, $parcela_recorrencia, $parcela_fim, $id_grupo_recorrencia, $id, $user_id);
                    if ($stmt->execute()) {
                        $sucesso = "Transação atualizada!";
                    } else {
                        $erro = "Erro ao atualizar: " . $mysqliFinancas->error;
                    }
                } else {
                    $stmt = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, parcela_recorrencia, parcela_fim, id_grupo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sdsiiiisiis", $data, $valor, $descricao, $id_categoria, $id_conta, $user_id, $consolidada, $notas, $parcela_recorrencia, $parcela_fim, $id_grupo_recorrencia);
                    if ($stmt->execute()) {
                        $sucesso = "Transação inserida com sucesso!";
                        $id = $mysqliFinancas->insert_id;
                        
                        // Create 2nd occurrence immediately if recurring
                        if ($parcela_fim == -1 || $parcela_recorrencia < $parcela_fim) {
                            $prox_data_obj = new DateTime($data);
                            $prox_data_obj->modify('first day of next month');
                            $last_day = (int)$prox_data_obj->format('t');
                            $day_to_use = min($dia_vencimento, $last_day);
                            $prox_data_obj->setDate((int)$prox_data_obj->format('Y'), (int)$prox_data_obj->format('m'), $day_to_use);
                            $prox_data = $prox_data_obj->format('Y-m-d');
                            $prox_parcela = $parcela_recorrencia + 1;
                            
                            $stmt_spawn = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, parcela_recorrencia, parcela_fim, id_grupo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
                            $stmt_spawn->bind_param("sdsiiisiis", $prox_data, $valor, $descricao, $id_categoria, $id_conta, $user_id, $notas, $prox_parcela, $parcela_fim, $id_grupo_recorrencia);
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
                    $stmt_upd = $mysqliFinancas->prepare("UPDATE transacoes SET valor=?, descricao=?, idcategoria=?, idconta=?, notas=?, parcela_fim=? WHERE id=? AND iduser=?");
                    $stmt_upd->bind_param("dsiisiii", $valor_out, $descricao, $id_categoria, $id_conta, $notas, $parcela_fim, $id_fut, $user_id);
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
        
        if (!empty($sucesso)) {
            header("Location: transacoes.php");
            exit;
        }
    }
}

// Carregar dados se edição (ou após INSERT)
if ($id > 0) {
    $stmt = $mysqliFinancas->prepare("SELECT data, valor, descricao, idcategoria, idconta, consolidada, idpai, notas, parcela_recorrencia, parcela_fim, id_grupo_recorrencia FROM transacoes WHERE id = ? AND iduser = ?");
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
        $parcela_recorrencia = $transacao['parcela_recorrencia'] ?? 1;
        $parcela_fim = $transacao['parcela_fim'] ?? 1;
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
$stmt = $mysqliFinancas->prepare("SELECT id, nome, cor, id_pai, icone FROM categorias WHERE id_user = ? ORDER BY nome ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$categorias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$cats_map = [];
foreach ($categorias as $c) {
    $cats_map[$c['id']] = $c;
}

function resolveAtributosCategoria($id_categoria, $cats_map) {
    $atual = $id_categoria;
    $icone = '';
    $cor = '';
    
    while ($atual && isset($cats_map[$atual])) {
        if (empty($icone) && !empty($cats_map[$atual]['icone'])) {
            $icone = $cats_map[$atual]['icone'];
        }
        if (empty($cor) && !empty($cats_map[$atual]['cor'])) {
            $cor = $cats_map[$atual]['cor'];
        }
        
        if (!empty($icone) && !empty($cor)) break;
        $atual = $cats_map[$atual]['id_pai'];
    }
    
    if (empty($cor)) $cor = '#ccc';
    
    return ['icone' => $icone, 'cor' => $cor];
}

foreach ($categorias as &$cat) {
    $atributos = resolveAtributosCategoria($cat['id'], $cats_map);
    $cat['icone_resolvido'] = $atributos['icone'];
    $cat['cor_resolvida'] = $atributos['cor'];
}
unset($cat);

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
    $marginLeft = $level > 0 ? 'ml-6 border-l border-gray-200 dark:border-white/10 pl-2' : '';
    echo "<div class='space-y-1 $marginLeft'>";
    foreach ($nodes as $cat) {
        $hasChildren = count($cat['children']) > 0;
        $cor = htmlspecialchars($cat['cor_resolvida']);
        $nome = htmlspecialchars($cat['nome']);
        $nomeJs = addslashes($cat['nome']);
        $id = $cat['id'];
        $icone = htmlspecialchars($cat['icone_resolvido']);
        
        echo "<div class='flex flex-col'>";
        echo "<div class='flex items-center justify-between p-2 border-b border-gray-100 dark:border-white/5 hover:bg-white/60 dark:hover:bg-white/10 transition-colors rounded-xl'>";
        
        if ($hasChildren) {
            // Clicar no nome expande/recolhe filhos
            $onClickArea = "togglePanelChildren($id)";
        } else {
            // Clicar no nome seleciona
            $onClickArea = "selectItem('categoria', '$id', '$nomeJs')";
        }

        echo "<div class='flex items-center space-x-3 flex-1 cursor-pointer py-2' onclick=\"$onClickArea\">";
        if ($icone) {
            echo "<div class='w-7 h-7 rounded-full flex items-center justify-center shrink-0 shadow-inner border border-gray-200 dark:border-white/20' style='background-color: $cor'><i class='ph-fill $icone text-white text-sm'></i></div>";
        } else {
            echo "<div class='w-4 h-4 rounded-full border border-gray-200 dark:border-white/20 shadow-inner shrink-0' style='background-color: $cor'></div>";
        }
        echo "<span class='text-slate-800 dark:text-white font-medium'>$nome</span>";
        
        // Se tem filhos, adicionamos a setinha ao lado do nome pra indicar que expande
        if ($hasChildren) {
            echo "<svg id='panel-icon-$id' class='w-4 h-4 text-slate-400 dark:text-white/50 transform -rotate-90 transition-transform duration-200 ml-2' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'></path></svg>";
        }
        echo "</div>";
        
        // Botão da direita
        if ($hasChildren) {
            // Botão para SELECIONAR a categoria pai
            echo "<button type='button' onclick=\"selectItem('categoria', '$id', '$nomeJs')\" class='p-2 text-slate-400 dark:text-white/50 hover:text-slate-800 dark:hover:text-white hover:bg-black/5 dark:hover:bg-white/10 transition-colors rounded-lg flex items-center justify-center' title='Selecionar esta categoria'>";
            echo "<svg class='w-5 h-5' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'></path></svg>";
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
$stmt = $mysqliFinancas->prepare("SELECT id, nome, cor, img FROM contas WHERE id_user = ? AND status = 1 ORDER BY nome ASC");
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
<?php 
$page_title = "Nova Transação - Minhas Finanças";
$extra_head = '<script src="https://unpkg.com/@phosphor-icons/web"></script>
<style>
    /* Premium Themes */
    /* Despesa */
    .theme-despesa { --color-primary: #ef4444; --color-primary-light: #fee2e2; --color-primary-dark: #b91c1c; }
    .dark .theme-despesa { --color-primary-light: rgba(239, 68, 68, 0.2); }
    /* Receita */
    .theme-receita { --color-primary: #10b981; --color-primary-light: #d1fae5; --color-primary-dark: #047857; }
    .dark .theme-receita { --color-primary-light: rgba(16, 185, 129, 0.2); }
    /* Transferencia */
    .theme-transferencia { --color-primary: #3b82f6; --color-primary-light: #dbeafe; --color-primary-dark: #1d4ed8; }
    .dark .theme-transferencia { --color-primary-light: rgba(59, 130, 246, 0.2); }

    .text-primary { color: var(--color-primary); }
    .bg-primary { background-color: var(--color-primary); }
    .bg-primary-light { background-color: var(--color-primary-light); }
    .border-primary { border-color: var(--color-primary); }
    .peer-checked\:bg-primary:checked ~ .peer { background-color: var(--color-primary); }
    
    .type-bg-gradient {
        background: linear-gradient(135deg, var(--color-primary) 0%, transparent 100%);
    }

    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input[type="number"] { -moz-appearance: textfield; }

    .drag-handle { width: 40px; height: 5px; background-color: #cbd5e1; border-radius: 5px; margin: 12px auto; }
    .dark .drag-handle { background-color: #475569; }
    
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>';
$body_id = 'app-body';
$body_class = 'min-h-screen bg-slate-50 text-slate-800 dark:bg-[#0f172a] dark:text-[#f8fafc] transition-colors duration-300 theme-' . $tipo;
include 'header.php'; 
?>

    <div class="hidden md:block">
        <?php include 'menu.php'; ?>
    </div>

    <!-- Formulário Submetido via JS (submitting to transacaov2.php) -->
    <form id="transacao-form" method="POST" action="transacaov2.php<?php echo $id > 0 ? '?id='.$id : ''; ?>" class="hidden">
        <input type="hidden" name="action" id="input-action" value="save">
        <input type="hidden" name="tipo" id="input-tipo" value="<?php echo $tipo; ?>">
        <input type="hidden" name="valor" id="input-valor" value="<?php echo number_format($valor, 2, '.', ''); ?>">
        <input type="hidden" name="id_categoria" id="input-categoria" value="<?php echo $id_categoria; ?>">
        <input type="hidden" name="id_conta" id="input-conta" value="<?php echo $id_conta; ?>">
        <input type="hidden" name="id_conta_destino" id="input-conta-destino" value="<?php echo $id_conta_destino; ?>">
        <input type="hidden" name="data" id="input-data">
        <input type="hidden" name="descricao" id="input-descricao">
        <input type="hidden" name="consolidada" id="input-consolidada">
        <input type="hidden" name="notas" id="input-notas">
        <input type="hidden" name="parcela_recorrencia" id="input-parcela-recorrencia">
        <input type="hidden" name="parcela_fim" id="input-parcela-fim">
        <input type="hidden" name="indefinidamente" id="input-indefinidamente">
        <input type="hidden" name="dia_vencimento" id="input-dia-vencimento">
        <input type="hidden" name="id_grupo_recorrencia" id="input-id-grupo-recorrencia" value="<?php echo htmlspecialchars($id_grupo_recorrencia ?? ''); ?>">
        <input type="hidden" name="modo_edicao" id="input-modo-edicao" value="todas_futuras">
        <input type="hidden" name="is_recorrente" id="input-is-recorrente" value="0">
    </form>

    <div class="max-w-md mx-auto relative h-[85vh] md:h-[80vh] flex flex-col mb-10 overflow-hidden">
        
        <?php if ($erro): ?>
            <div class="bg-red-50 dark:bg-red-500/20 border border-red-200 dark:border-red-500/50 text-red-600 dark:text-red-200 px-4 py-3 rounded-2xl mb-4 mx-4 text-sm z-50 relative shadow-sm">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="bg-emerald-50 dark:bg-emerald-500/20 border border-emerald-200 dark:border-emerald-500/50 text-emerald-600 dark:text-emerald-200 px-4 py-3 rounded-2xl mb-4 mx-4 text-sm z-50 relative shadow-sm">
                <?php echo $sucesso; ?>
            </div>
        <?php endif; ?>

        <!-- Main View container -->
        <div id="main-view" class="flex flex-col h-full bg-slate-50 dark:bg-[#0f172a] w-full transition-transform duration-300">
            
            <!-- Header Area -->
            <div class="pt-6 pb-8 px-6 bg-white dark:bg-slate-900 rounded-b-[2rem] shadow-sm z-10 relative overflow-hidden transition-colors duration-500 type-header-bg">
                <div class="absolute inset-0 opacity-10 type-bg-gradient transition-all duration-500"></div>
                
                <div class="relative flex justify-between items-center mb-6">
                    <a href="transacoes.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        <i class="ph ph-x text-2xl text-slate-600 dark:text-slate-300"></i>
                    </a>
                    <span class="font-semibold text-lg text-slate-800 dark:text-white">Nova Transação</span>
                    <button type="button" onclick="submitForm()" class="p-2 -mr-2 text-primary font-bold text-lg hover:opacity-80 transition-opacity">Salvar</button>
                </div>

                <!-- Segmented Control -->
                <div class="relative flex p-1 bg-slate-100 dark:bg-slate-800 rounded-2xl mb-8 shadow-inner">
                    <div id="type-indicator" class="absolute top-1 bottom-1 w-[33.33%] bg-white dark:bg-slate-700 rounded-xl shadow-sm transition-all duration-300 ease-out left-0"></div>
                    <button onclick="setTipo('despesa')" class="relative flex-1 py-2.5 text-center text-sm font-semibold transition-colors z-10 text-slate-500" id="btn-tipo-despesa">Despesa</button>
                    <button onclick="setTipo('receita')" class="relative flex-1 py-2.5 text-center text-sm font-semibold transition-colors z-10 text-slate-500" id="btn-tipo-receita">Receita</button>
                    <button onclick="setTipo('transferencia')" class="relative flex-1 py-2.5 text-center text-sm font-semibold transition-colors z-10 text-slate-500" id="btn-tipo-transferencia">Transf.</button>
                </div>

                <!-- Value Display -->
                <div class="text-center cursor-pointer relative group" onclick="toggleNumpad()">
                    <span class="text-sm font-medium text-slate-500 dark:text-slate-400 mb-1 block">Valor</span>
                    <span class="text-5xl font-bold tracking-tight text-slate-800 dark:text-white transition-colors group-hover:text-primary" id="display-valor">
                        R$ <?php echo number_format($valor, 2, ',', '.'); ?>
                    </span>
                </div>
            </div>

            <!-- Form Fields Scrollable Area -->
            <div class="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-12">
                <!-- Main Card -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm border border-slate-100 dark:border-slate-800 mb-4 space-y-5">
                    
                    <!-- Description -->
                    <div class="flex items-center space-x-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div class="w-10 h-10 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0">
                            <i class="ph ph-text-aa text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <input type="text" id="ui-descricao" value="<?php echo htmlspecialchars($descricao); ?>" placeholder="Descrição" class="w-full bg-transparent text-slate-800 dark:text-white placeholder-slate-400 focus:outline-none text-lg font-medium">
                        </div>
                    </div>

                    <!-- Date -->
                    <div class="flex items-center space-x-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                         <div class="w-10 h-10 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0">
                            <i class="ph ph-calendar-blank text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <input type="date" id="ui-data" value="<?php echo htmlspecialchars($data); ?>" class="w-full bg-transparent text-slate-800 dark:text-white focus:outline-none text-lg font-medium">
                        </div>
                    </div>
                    
                    <!-- Category -->
                    <div class="flex items-center space-x-4 pb-4 border-b border-slate-100 dark:border-slate-800 cursor-pointer type-dependent cat-row hover:opacity-80 transition-opacity" onclick="openPanel('panel-categoria')">
                         <div class="w-10 h-10 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0" id="cat-icon-bg">
                            <i class="ph ph-tag text-xl" id="cat-icon"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-0.5">Categoria</span>
                            <span class="text-lg font-medium text-slate-800 dark:text-white line-clamp-1" id="display-categoria"><?php echo htmlspecialchars($nome_categoria); ?></span>
                        </div>
                        <i class="ph ph-caret-right text-slate-400 text-lg"></i>
                    </div>
                    
                    <!-- Account -->
                    <div class="flex items-center space-x-4 cursor-pointer hover:opacity-80 transition-opacity type-dependent" id="linha-conta-origem" onclick="openPanel('panel-conta')">
                         <div class="w-10 h-10 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0">
                            <i class="ph ph-wallet text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-0.5" id="label-conta-origem">Conta</span>
                            <span class="text-lg font-medium text-slate-800 dark:text-white line-clamp-1" id="display-conta"><?php echo htmlspecialchars($nome_conta); ?></span>
                        </div>
                        <i class="ph ph-caret-right text-slate-400 text-lg"></i>
                    </div>
                    
                    <!-- Account Dest (Transfer only) -->
                    <div class="items-center space-x-4 pt-4 border-t border-slate-100 dark:border-slate-800 cursor-pointer hidden type-dependent dest-row hover:opacity-80 transition-opacity" id="linha-conta-destino" onclick="openPanel('panel-conta-destino')">
                         <div class="w-10 h-10 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0">
                            <i class="ph ph-bank text-xl"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-0.5">Conta Destino</span>
                            <span class="text-lg font-medium text-slate-800 dark:text-white line-clamp-1" id="display-conta-destino"><?php echo htmlspecialchars($nome_conta_destino); ?></span>
                        </div>
                        <i class="ph ph-caret-right text-slate-400 text-lg"></i>
                    </div>
                </div>

                <!-- Consolidada Card -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 shadow-sm border border-slate-100 dark:border-slate-800 mb-4 flex justify-between items-center cursor-pointer" onclick="document.getElementById('ui-consolidada').click()">
                    <div class="flex items-center space-x-4">
                         <div class="w-10 h-10 rounded-full bg-primary-light text-primary flex items-center justify-center shrink-0">
                            <i class="ph-fill ph-check-circle text-2xl"></i>
                        </div>
                        <span class="text-lg font-medium text-slate-800 dark:text-white" id="label-consolidada">Pago</span>
                    </div>
                     <label class="relative inline-flex items-center cursor-pointer" onclick="event.stopPropagation()">
                        <input type="checkbox" id="ui-consolidada" class="sr-only peer" <?php echo $consolidada ? 'checked' : ''; ?>>
                        <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-primary shadow-inner"></div>
                    </label>
                </div>
                
                <!-- More Options -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-100 dark:border-slate-800 mb-4 overflow-hidden">
                     <button type="button" onclick="toggleMaisOpcoes()" class="w-full p-5 flex justify-between items-center text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                         <span class="font-medium text-lg">Opções Avançadas</span>
                         <i class="ph ph-caret-down transition-transform duration-300 text-lg" id="icon-mais-opcoes"></i>
                     </button>
                     <div id="mais-opcoes" class="hidden px-5 pb-5 space-y-5">
                        
                        <!-- Notas -->
                        <div class="flex items-center space-x-4 pb-5 border-b border-slate-100 dark:border-slate-800">
                            <div class="w-10 h-10 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0">
                                <i class="ph ph-note-pencil text-xl"></i>
                            </div>
                            <div class="flex-1">
                                <input type="text" id="ui-notas" value="<?php echo htmlspecialchars($notas); ?>" placeholder="Adicionar notas..." class="w-full bg-transparent text-slate-800 dark:text-white placeholder-slate-400 focus:outline-none font-medium">
                            </div>
                        </div>

                        <!-- Recorrente Toggle -->
                         <div class="flex items-center space-x-4 pb-5 border-b border-slate-100 dark:border-slate-800 justify-between cursor-pointer" onclick="document.getElementById('ui-is-recorrente').click()">
                             <div class="flex items-center space-x-4">
                                <div class="w-10 h-10 rounded-full bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0">
                                    <i class="ph ph-arrows-clockwise text-xl"></i>
                                </div>
                                <span class="text-lg font-medium text-slate-800 dark:text-white">Repetir</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer" onclick="event.stopPropagation()">
                                <input type="checkbox" id="ui-is-recorrente" class="sr-only peer" <?php echo ($parcela_fim > 1 || $parcela_fim == -1) ? 'checked' : ''; ?> onchange="toggleRecorrenciaUI()">
                                <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-primary shadow-inner"></div>
                            </label>
                         </div>
                         
                         <!-- Recorrencia options -->
                         <div id="opcoes-avancadas-conteudo" class="space-y-4 pt-2 <?php echo ($parcela_fim > 1 || $parcela_fim == -1) ? '' : 'hidden'; ?>">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl p-3 border border-slate-100 dark:border-slate-700">
                                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Intervalo</span>
                                    <div class="font-medium text-slate-800 dark:text-white">Mensal</div>
                                </div>
                                <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl p-3 border border-slate-100 dark:border-slate-700">
                                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Dia do Venc.</span>
                                    <input type="number" id="ui-dia-vencimento" value="<?php echo date('d', strtotime($data)); ?>" class="bg-transparent w-full font-medium text-slate-800 dark:text-white focus:outline-none" min="1" max="31">
                                </div>
                            </div>
                            
                            <div class="flex justify-between items-center p-4 bg-slate-50 dark:bg-slate-800 rounded-2xl border border-slate-100 dark:border-slate-700 cursor-pointer" onclick="document.getElementById('ui-indefinidamente').click()">
                                 <span class="font-medium text-slate-800 dark:text-white">Fixo / Indefinidamente</span>
                                  <label class="relative inline-flex items-center cursor-pointer" onclick="event.stopPropagation()">
                                    <input type="checkbox" id="ui-indefinidamente" onchange="toggleIndefinidamente()" class="sr-only peer" <?php echo ($parcela_fim == -1) ? 'checked' : ''; ?>>
                                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                                </label>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                 <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl p-3 border border-slate-100 dark:border-slate-700">
                                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Parcela Atual</span>
                                    <input type="number" id="ui-parcela-recorrencia" value="<?php echo $parcela_recorrencia; ?>" class="bg-transparent w-full font-medium text-slate-800 dark:text-white focus:outline-none" min="1">
                                </div>
                                 <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl p-3 border border-slate-100 dark:border-slate-700">
                                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Qtd Parcelas</span>
                                    <input type="number" id="ui-parcela-fim" value="<?php echo ($parcela_fim > 1) ? $parcela_fim : ''; ?>" <?php echo ($parcela_fim == -1) ? 'disabled' : ''; ?> class="bg-transparent w-full font-medium text-slate-800 dark:text-white focus:outline-none" min="2" placeholder="Ex: 12">
                                </div>
                            </div>
                         </div>
                     </div>
                </div>

                <?php if ($id > 0): ?>
                <div class="mt-8 mb-4 flex justify-center">
                     <button type="button" onclick="excluirTransacao()" class="text-red-500 hover:text-red-600 font-medium flex items-center space-x-2 p-3 bg-red-50 dark:bg-red-500/10 rounded-2xl transition-colors">
                         <i class="ph ph-trash text-xl"></i>
                         <span>Excluir Transação</span>
                     </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Overlays and Bottom Sheets -->
        <div id="backdrop" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 hidden opacity-0 transition-opacity duration-300" onclick="closeAllPanels()"></div>

        <!-- Panel Categoria -->
        <div id="panel-categoria" class="fixed inset-x-0 bottom-0 z-50 bg-white dark:bg-slate-900 rounded-t-[2rem] shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:shadow-[0_-10px_40px_rgba(0,0,0,0.5)] transform translate-y-full transition-transform duration-300 h-[80vh] flex flex-col md:max-w-md md:mx-auto md:h-[70vh]">
            <div class="drag-handle shrink-0 cursor-grab active:cursor-grabbing"></div>
            <div class="px-6 pb-4 border-b border-slate-100 dark:border-slate-800 shrink-0 flex justify-between items-center">
                <span class="text-lg font-bold text-slate-800 dark:text-white">Categoria</span>
                <button onclick="closePanel('panel-categoria')" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-full text-slate-500 hover:bg-slate-200 transition-colors">
                    <i class="ph ph-x text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <?php if (count($arvore_categorias) > 0): ?>
                    <?php renderCategoryPanelHtml($arvore_categorias); ?>
                <?php else: ?>
                    <div class="p-8 text-center text-slate-500 dark:text-slate-400">Nenhuma categoria encontrada.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel Conta Origem -->
        <div id="panel-conta" class="fixed inset-x-0 bottom-0 z-50 bg-white dark:bg-slate-900 rounded-t-[2rem] shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:shadow-[0_-10px_40px_rgba(0,0,0,0.5)] transform translate-y-full transition-transform duration-300 max-h-[80vh] flex flex-col md:max-w-md md:mx-auto">
            <div class="drag-handle shrink-0 cursor-grab active:cursor-grabbing"></div>
            <div class="px-6 pb-4 border-b border-slate-100 dark:border-slate-800 shrink-0 flex justify-between items-center">
                <span class="text-lg font-bold text-slate-800 dark:text-white" id="title-panel-conta">Conta</span>
                <button onclick="closePanel('panel-conta')" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-full text-slate-500 hover:bg-slate-200 transition-colors">
                    <i class="ph ph-x text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <div class="space-y-2">
                    <?php foreach($contas as $conta): ?>
                        <button onclick="selectItem('conta', '<?php echo $conta['id']; ?>', '<?php echo addslashes($conta['nome']); ?>')" class="w-full text-left p-4 bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-2xl transition-colors flex items-center space-x-4 border border-slate-100 dark:border-slate-800">
                            <?php if (!empty($conta['img'])): ?>
                                <img src="img/<?php echo htmlspecialchars($conta['img']); ?>" alt="Logo" class="w-8 h-8 rounded-full object-cover shrink-0 shadow-sm">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-full shrink-0 shadow-sm" style="background-color: <?php echo $conta['cor'] ?: '#ccc'; ?>"></div>
                            <?php endif; ?>
                            <span class="text-slate-800 dark:text-white font-medium text-lg"><?php echo htmlspecialchars($conta['nome']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Panel Conta Destino -->
        <div id="panel-conta-destino" class="fixed inset-x-0 bottom-0 z-50 bg-white dark:bg-slate-900 rounded-t-[2rem] shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:shadow-[0_-10px_40px_rgba(0,0,0,0.5)] transform translate-y-full transition-transform duration-300 max-h-[80vh] flex flex-col md:max-w-md md:mx-auto">
            <div class="drag-handle shrink-0 cursor-grab active:cursor-grabbing"></div>
            <div class="px-6 pb-4 border-b border-slate-100 dark:border-slate-800 shrink-0 flex justify-between items-center">
                <span class="text-lg font-bold text-slate-800 dark:text-white">Conta Destino</span>
                <button onclick="closePanel('panel-conta-destino')" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-full text-slate-500 hover:bg-slate-200 transition-colors">
                    <i class="ph ph-x text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <div class="space-y-2">
                    <?php foreach($contas as $conta): ?>
                        <button onclick="selectItem('conta-destino', '<?php echo $conta['id']; ?>', '<?php echo addslashes($conta['nome']); ?>')" class="w-full text-left p-4 bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-2xl transition-colors flex items-center space-x-4 border border-slate-100 dark:border-slate-800">
                            <?php if (!empty($conta['img'])): ?>
                                <img src="img/<?php echo htmlspecialchars($conta['img']); ?>" alt="Logo" class="w-8 h-8 rounded-full object-cover shrink-0 shadow-sm">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-full shrink-0 shadow-sm" style="background-color: <?php echo $conta['cor'] ?: '#ccc'; ?>"></div>
                            <?php endif; ?>
                            <span class="text-slate-800 dark:text-white font-medium text-lg"><?php echo htmlspecialchars($conta['nome']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Numpad -->
        <div id="numpad" class="fixed inset-x-0 bottom-0 z-50 bg-slate-100 dark:bg-slate-900 rounded-t-[2rem] p-5 shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:shadow-[0_-10px_40px_rgba(0,0,0,0.5)] transform translate-y-full transition-transform duration-300 md:max-w-md md:mx-auto">
            <div class="drag-handle shrink-0 cursor-grab active:cursor-grabbing mb-4" onclick="closeNumpad()"></div>
            <div class="flex justify-between items-center mb-6 px-2">
                <span class="text-slate-800 dark:text-gray-200 font-bold text-xl">Valor</span>
                <button onclick="closeNumpad()" class="text-primary font-bold text-lg hover:opacity-80">Concluir</button>
            </div>
            <div class="grid grid-cols-4 gap-3">
                <div class="col-span-3 grid grid-cols-3 gap-3">
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('7')">7</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('8')">8</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('9')">9</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('4')">4</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('5')">5</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('6')">6</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('1')">1</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('2')">2</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('3')">3</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addDoubleZero()">,00</button>
                    <button class="bg-white dark:bg-slate-800 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-200 dark:active:bg-slate-700 transition-colors" onclick="addNumber('0')">0</button>
                    <button class="bg-slate-200 dark:bg-slate-700 rounded-2xl h-16 text-2xl font-medium text-slate-800 dark:text-white shadow-sm active:bg-slate-300 dark:active:bg-slate-600 flex items-center justify-center transition-colors" onclick="backspace()">
                        <i class="ph ph-backspace"></i>
                    </button>
                </div>
                <div class="col-span-1 grid grid-rows-4 gap-3 bg-white dark:bg-slate-800 rounded-2xl p-2 shadow-sm border border-slate-100 dark:border-slate-700">
                    <button class="text-2xl text-slate-500 dark:text-slate-400 font-medium active:bg-slate-100 dark:active:bg-slate-700 rounded-xl h-full transition-colors" onclick="setOperation('÷')">÷</button>
                    <button class="text-2xl text-slate-500 dark:text-slate-400 font-medium active:bg-slate-100 dark:active:bg-slate-700 rounded-xl h-full transition-colors" onclick="setOperation('×')">×</button>
                    <button class="text-3xl text-slate-500 dark:text-slate-400 font-medium active:bg-slate-100 dark:active:bg-slate-700 rounded-xl h-full transition-colors" onclick="setOperation('-')">-</button>
                    <button class="text-3xl text-slate-500 dark:text-slate-400 font-medium active:bg-slate-100 dark:active:bg-slate-700 rounded-xl h-full transition-colors" onclick="setOperation('+')">+</button>
                </div>
            </div>
            <button id="btn-ok-numpad" class="w-full mt-4 bg-primary text-white rounded-2xl h-14 text-xl font-bold shadow-lg shadow-primary/30 hover:opacity-90 transition-all active:scale-[0.98]" onclick="handleOkButton()">
                OK
            </button>
        </div>

        <!-- Modal Edição Recorrência -->
        <div id="modal-edicao-recorrencia" class="fixed inset-0 bg-black/60 backdrop-blur-md z-[60] hidden opacity-0 transition-opacity duration-300 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 shadow-2xl max-w-sm w-full transform scale-95 transition-transform duration-300 border border-slate-100 dark:border-slate-800" id="modal-edicao-recorrencia-content">
                <div class="w-16 h-16 bg-blue-50 dark:bg-blue-500/10 text-blue-500 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="ph ph-arrows-clockwise text-3xl"></i>
                </div>
                <h3 class="text-slate-800 dark:text-white font-bold text-xl text-center mb-2">Editar Transação Recorrente</h3>
                <p class="text-slate-500 dark:text-slate-400 text-center mb-8">Esta é uma transação recorrente. Você deseja alterar apenas esta ocorrência ou todas as futuras também?</p>
                
                <div class="space-y-3">
                    <button type="button" onclick="confirmarEdicaoRecorrencia('somente_esta')" class="w-full py-3.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-white rounded-2xl transition-colors font-semibold text-lg">
                        Apenas esta
                    </button>
                    <button type="button" onclick="confirmarEdicaoRecorrencia('todas_futuras')" class="w-full py-3.5 bg-primary text-white rounded-2xl transition-colors font-semibold text-lg shadow-lg shadow-primary/30 hover:opacity-90">
                        Esta e as futuras
                    </button>
                    <button type="button" onclick="closeModalRecorrencia()" class="w-full py-3 text-slate-500 hover:text-slate-800 dark:hover:text-white transition-colors font-medium text-sm mt-2">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

    </div>

    <script>
        // Numpad Initialization
        let valorAtual = "<?php echo number_format($valor * 100, 0, '', ''); ?>";
        if(valorAtual === "0") valorAtual = "000";
        
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
            document.getElementById('backdrop').classList.remove('hidden');
            setTimeout(() => document.getElementById('backdrop').classList.remove('opacity-0'), 10);
            document.getElementById('numpad').classList.remove('translate-y-full');
            document.getElementById('main-view').classList.add('scale-95', 'opacity-50', '-translate-y-2');
        }
        
        function closeNumpad() {
            document.getElementById('numpad').classList.add('translate-y-full');
            document.getElementById('backdrop').classList.add('opacity-0');
            document.getElementById('main-view').classList.remove('scale-95', 'opacity-50', '-translate-y-2');
            setTimeout(() => document.getElementById('backdrop').classList.add('hidden'), 300);
        }

        // Type Selector Setup
        function initTypeSelector() {
            setTipo('<?php echo $tipo; ?>', false);
        }

        function setTipo(tipo, animate = true) {
            document.getElementById('input-tipo').value = tipo;
            const body = document.getElementById('app-body');
            body.classList.remove('theme-despesa', 'theme-receita', 'theme-transferencia');
            body.classList.add(`theme-${tipo}`);
            
            // Segmented Control Indicator
            const indicator = document.getElementById('type-indicator');
            const btns = [
                {id: 'btn-tipo-despesa', type: 'despesa', label: 'Despesa'},
                {id: 'btn-tipo-receita', type: 'receita', label: 'Receita'},
                {id: 'btn-tipo-transferencia', type: 'transferencia', label: 'Transf.'}
            ];
            
            let index = btns.findIndex(b => b.type === tipo);
            indicator.style.transform = `translateX(${index * 100}%)`;
            
            btns.forEach(btn => {
                const el = document.getElementById(btn.id);
                if (btn.type === tipo) {
                    el.classList.add('text-slate-800', 'dark:text-white');
                    el.classList.remove('text-slate-500');
                } else {
                    el.classList.remove('text-slate-800', 'dark:text-white');
                    el.classList.add('text-slate-500');
                }
            });

            // UI Adjustments based on Type
            const linhasCat = document.querySelectorAll('.cat-row');
            const linhasDest = document.querySelectorAll('.dest-row');
            const labelOrigem = document.getElementById('label-conta-origem');
            const labelConsolidada = document.getElementById('label-consolidada');
            
            if(tipo === 'despesa') {
                linhasCat.forEach(el => el.classList.remove('hidden'));
                linhasDest.forEach(el => el.classList.add('hidden'));
                if(labelOrigem) labelOrigem.textContent = "Conta";
                if(labelConsolidada) labelConsolidada.textContent = "Pago";
            } else if(tipo === 'receita') {
                linhasCat.forEach(el => el.classList.remove('hidden'));
                linhasDest.forEach(el => el.classList.add('hidden'));
                if(labelOrigem) labelOrigem.textContent = "Conta";
                if(labelConsolidada) labelConsolidada.textContent = "Recebido";
            } else {
                linhasCat.forEach(el => el.classList.add('hidden'));
                linhasDest.forEach(el => el.classList.remove('hidden'));
                if(labelOrigem) labelOrigem.textContent = "Conta Origem";
                if(labelConsolidada) labelConsolidada.textContent = "Efetivada";
            }
        }

        // Panels
        function openPanel(id) {
            document.getElementById('backdrop').classList.remove('hidden');
            setTimeout(() => document.getElementById('backdrop').classList.remove('opacity-0'), 10);
            document.getElementById(id).classList.remove('translate-y-full');
            document.getElementById('main-view').classList.add('scale-95', 'opacity-50', '-translate-y-2');
        }

        function closePanel(id) {
            document.getElementById(id).classList.add('translate-y-full');
            document.getElementById('backdrop').classList.add('opacity-0');
            document.getElementById('main-view').classList.remove('scale-95', 'opacity-50', '-translate-y-2');
            setTimeout(() => document.getElementById('backdrop').classList.add('hidden'), 300);
        }

        function closeAllPanels() {
            closePanel('panel-categoria');
            closePanel('panel-conta');
            closePanel('panel-conta-destino');
            closeNumpad();
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

        // Mais Opções
        let maisOpcoesAberto = false;
        function toggleMaisOpcoes() {
            const container = document.getElementById('mais-opcoes');
            const icon = document.getElementById('icon-mais-opcoes');
            maisOpcoesAberto = !maisOpcoesAberto;

            if (maisOpcoesAberto) {
                container.classList.remove('hidden');
                icon.classList.add('rotate-180');
            } else {
                container.classList.add('hidden');
                icon.classList.remove('rotate-180');
            }
        }

        function toggleRecorrenciaUI() {
            const check = document.getElementById('ui-is-recorrente');
            const cont = document.getElementById('opcoes-avancadas-conteudo');
            if (check.checked) {
                cont.classList.remove('hidden');
            } else {
                cont.classList.add('hidden');
            }
        }

        function toggleIndefinidamente() {
            const check = document.getElementById('ui-indefinidamente');
            const inputNum = document.getElementById('ui-parcela-fim');
            if (check.checked) {
                inputNum.disabled = true;
                inputNum.value = '';
                inputNum.classList.add('opacity-50');
            } else {
                inputNum.disabled = false;
                inputNum.classList.remove('opacity-50');
                inputNum.focus();
            }
        }

        // Submission
        function submitForm() {
            document.getElementById('input-data').value = document.getElementById('ui-data').value;
            document.getElementById('input-descricao').value = document.getElementById('ui-descricao').value;
            document.getElementById('input-consolidada').value = document.getElementById('ui-consolidada').checked ? '1' : '';
            document.getElementById('input-notas').value = document.getElementById('ui-notas').value;
            
            const parcelaFimInput = document.getElementById('ui-parcela-fim');
            const parcelaInicialInput = document.getElementById('ui-parcela-recorrencia');
            const indefinidamenteInput = document.getElementById('ui-indefinidamente');
            
            document.getElementById('input-is-recorrente').value = document.getElementById('ui-is-recorrente').checked ? '1' : '';
            document.getElementById('input-parcela-fim').value = parcelaFimInput.value;
            document.getElementById('input-parcela-recorrencia').value = parcelaInicialInput.value;
            document.getElementById('input-indefinidamente').value = indefinidamenteInput.checked ? '1' : '';
            document.getElementById('input-dia-vencimento').value = document.getElementById('ui-dia-vencimento').value;
            
            const id_grupo_recorrencia = document.getElementById('input-id-grupo-recorrencia').value;
            const isEditing = <?php echo $id > 0 ? 'true' : 'false'; ?>;
            const isConsolidated = <?php echo ($id > 0 && $consolidada == 1) ? 'true' : 'false'; ?>;
            
            if (isEditing && id_grupo_recorrencia && !isConsolidated) {
                document.getElementById('modal-edicao-recorrencia').classList.remove('hidden');
                setTimeout(() => {
                    document.getElementById('modal-edicao-recorrencia').classList.remove('opacity-0');
                    document.getElementById('modal-edicao-recorrencia-content').classList.remove('scale-95');
                }, 10);
            } else {
                document.getElementById('input-modo-edicao').value = 'somente_esta';
                document.getElementById('transacao-form').submit();
            }
        }
        
        function confirmarEdicaoRecorrencia(modo) {
            document.getElementById('input-modo-edicao').value = modo;
            document.getElementById('transacao-form').submit();
        }
        
        function closeModalRecorrencia() {
            document.getElementById('modal-edicao-recorrencia').classList.add('opacity-0');
            document.getElementById('modal-edicao-recorrencia-content').classList.add('scale-95');
            setTimeout(() => {
                document.getElementById('modal-edicao-recorrencia').classList.add('hidden');
            }, 300);
        }

        function excluirTransacao() {
            if(confirm("Deseja realmente excluir esta transação?")) {
                document.getElementById('input-action').value = 'delete';
                document.getElementById('transacao-form').submit();
            }
        }

        // Teclado Físico no Desktop
        document.addEventListener('keydown', function(e) {
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
        
        document.getElementById('ui-data').addEventListener('change', function() {
            if (this.value) {
                const parts = this.value.split('-');
                if (parts.length === 3) {
                    document.getElementById('ui-dia-vencimento').value = parseInt(parts[2], 10);
                }
            }
        });

        // Init
        document.addEventListener('DOMContentLoaded', () => {
            initTypeSelector();
        });
    </script>
</body>
</html>
