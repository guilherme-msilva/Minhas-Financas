<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// ── Ações em Lote (POST) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $ids_raw = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
    $ids = array_values(array_filter(array_map('intval', $ids_raw), fn($id) => $id > 0));

    if (!empty($ids)) {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $types_ids = str_repeat('i', count($ids));

        if ($bulk_action === 'consolidar') {
            // Consolida apenas as selecionadas (não afeta recorrências relacionadas)
            $stmt_b = $mysqliFinancas->prepare("UPDATE transacoes SET consolidada = 1 WHERE id IN ($ph) AND iduser = ? AND consolidada = 0");
            $params_b = array_merge($ids, [$user_id]);
            $stmt_b->bind_param($types_ids . 'i', ...$params_b);
            $stmt_b->execute();

        } elseif ($bulk_action === 'alterar_categoria') {
            $id_cat_bulk = (int)($_POST['id_categoria'] ?? 0);
            if ($id_cat_bulk > 0) {
                // Ignora transferências (idcategoria = -1)
                $stmt_b = $mysqliFinancas->prepare("UPDATE transacoes SET idcategoria = ? WHERE id IN ($ph) AND iduser = ? AND idcategoria != -1");
                $params_b = array_merge([$id_cat_bulk], $ids, [$user_id]);
                $stmt_b->bind_param('i' . $types_ids . 'i', ...$params_b);
                $stmt_b->execute();
            }

        } elseif ($bulk_action === 'excluir') {
            // Exclui apenas os IDs selecionados (não afeta cadeia de recorrência)
            // Para transferências selecionadas: também exclui a perna filha
            $stmt_del_child = $mysqliFinancas->prepare("DELETE FROM transacoes WHERE idpai IN ($ph) AND iduser = ?");
            $params_c = array_merge($ids, [$user_id]);
            $stmt_del_child->bind_param($types_ids . 'i', ...$params_c);
            $stmt_del_child->execute();

            $stmt_del = $mysqliFinancas->prepare("DELETE FROM transacoes WHERE id IN ($ph) AND iduser = ?");
            $stmt_del->bind_param($types_ids . 'i', ...$params_c);
            $stmt_del->execute();
        }
    }

    // Redireciona preservando os filtros ativos
    $qs = http_build_query(array_filter([
        'data_inicio'    => $_POST['data_inicio_atual'] ?? '',
        'data_fim'       => $_POST['data_fim_atual'] ?? '',
        'conta'          => $_POST['conta_atual'] ?? '',
        'categoria'      => $_POST['categoria_atual'] ?? '',
        'tipo'           => $_POST['tipo_atual'] ?? '',
        'ordenacao'      => $_POST['ordenacao_atual'] ?? '',
        'incluir_subcats'=> $_POST['incluir_subcats_atual'] ?? '',
        'qualquer_data'  => $_POST['qualquer_data_atual'] ?? '',
    ], fn($v) => $v !== '' && $v !== '0' || $v === '0'));
    header('Location: transacoes.php' . ($qs ? '?' . $qs : ''));
    exit;
}

// Ação Rápida: Consolidar
if (isset($_GET['action']) && $_GET['action'] == 'consolidate' && isset($_GET['id'])) {
    $id_cons = (int)$_GET['id'];
    
    // Busca status atual e idpai
    $stmt = $mysqliFinancas->prepare("SELECT consolidada, idcategoria, idpai FROM transacoes WHERE id = ? AND iduser = ?");
    $stmt->bind_param("ii", $id_cons, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($t = $res->fetch_assoc()) {
        if ($t['consolidada']) {
            // Já está consolidada, bloqueio de desconsolidar
            header("Location: transacoes.php");
            exit;
        }
        $novo_status = 1;
        
        if ($t['idcategoria'] == -1) {
            $parent_id = $t['idpai'] ? $t['idpai'] : $id_cons;
            $stmt_up = $mysqliFinancas->prepare("UPDATE transacoes SET consolidada = ? WHERE (id = ? OR idpai = ?) AND iduser = ?");
            $stmt_up->bind_param("iiii", $novo_status, $parent_id, $parent_id, $user_id);
            $stmt_up->execute();
        } else {
            $stmt_up = $mysqliFinancas->prepare("UPDATE transacoes SET consolidada = ? WHERE id = ? AND iduser = ?");
            $stmt_up->bind_param("iii", $novo_status, $id_cons, $user_id);
            $stmt_up->execute();
        }
        
        // Se consolidou, verifica se é recorrente para "spawnar" a próxima
        if ($novo_status == 1) {
            $id_to_fetch = $t['idcategoria'] == -1 ? ($t['idpai'] ? $t['idpai'] : $id_cons) : $id_cons;
            $stmt_full = $mysqliFinancas->prepare("SELECT * FROM transacoes WHERE id = ? AND iduser = ?");
            $stmt_full->bind_param("ii", $id_to_fetch, $user_id);
            $stmt_full->execute();
            if ($t_full = $stmt_full->get_result()->fetch_assoc()) {
                if (!empty($t_full['id_grupo_recorrencia']) && ($t_full['parcela_fim'] > 1 || $t_full['parcela_fim'] == -1)) {
                    // Verifica se já existe uma futura pendente
                    $stmt_check = $mysqliFinancas->prepare("SELECT id FROM transacoes WHERE id_grupo_recorrencia = ? AND consolidada = 0 AND iduser = ?");
                    $stmt_check->bind_param("si", $t_full['id_grupo_recorrencia'], $user_id);
                    $stmt_check->execute();
                    if ($stmt_check->get_result()->num_rows == 0) {
                        // Spawna a próxima
                        $dia_vencimento = (int)date('d', strtotime($t_full['data']));
                        $prox_data_obj = new DateTime($t_full['data']);
                        $prox_data_obj->modify('first day of next month');
                        $last_day = (int)$prox_data_obj->format('t');
                        $day_to_use = min($dia_vencimento, $last_day);
                        $prox_data_obj->setDate((int)$prox_data_obj->format('Y'), (int)$prox_data_obj->format('m'), $day_to_use);
                        $prox_data = $prox_data_obj->format('Y-m-d');
                        
                        $parcela_fim = $t_full['parcela_fim'];
                        $parcela_atual = $t_full['parcela_recorrencia'] ?? 1;
                        
                        if ($parcela_fim == -1 || $parcela_atual < $parcela_fim) {
                            $prox_parcela = $parcela_atual + 1;
                            $stmt_spawn = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, notas, parcela_recorrencia, parcela_fim, id_grupo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
                            $stmt_spawn->bind_param("sdsiiisiis", $prox_data, $t_full['valor'], $t_full['descricao'], $t_full['idcategoria'], $t_full['idconta'], $user_id, $t_full['notas'], $prox_parcela, $parcela_fim, $t_full['id_grupo_recorrencia']);
                            $stmt_spawn->execute();
                            $new_id = $mysqliFinancas->insert_id;
                            
                            if ($t_full['idcategoria'] == -1) {
                                $stmt_in = $mysqliFinancas->prepare("SELECT * FROM transacoes WHERE idpai = ? AND iduser = ?");
                                $stmt_in->bind_param("ii", $t_full['id'], $user_id);
                                $stmt_in->execute();
                                if ($t_in = $stmt_in->get_result()->fetch_assoc()) {
                                    $stmt_spawn_in = $mysqliFinancas->prepare("INSERT INTO transacoes (data, valor, descricao, idcategoria, idconta, iduser, consolidada, idpai, notas) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                                    $stmt_spawn_in->bind_param("sdsiiiiis", $prox_data, $t_in['valor'], $t_in['descricao'], $t_in['idcategoria'], $t_in['idconta'], $user_id, $new_id, $t_in['notas']);
                                    $stmt_spawn_in->execute();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Redireciona para remover a querystring action=
    $di_redir = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
    $df_redir = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
    $qd_redir = isset($_GET['qualquer_data']) ? (int)$_GET['qualquer_data'] : 0;
    header("Location: transacoes.php?data_inicio=$di_redir&data_fim=$df_redir" . ($qd_redir ? "&qualquer_data=1" : ""));
    exit;
}

// Filtros e Ordenação
$ordenacao = isset($_GET['ordenacao']) ? $_GET['ordenacao'] : 'data_desc';
$valid_ordenacoes = ['data_desc', 'data_asc', 'valor_desc', 'valor_asc'];
if (!in_array($ordenacao, $valid_ordenacoes)) $ordenacao = 'data_desc';
$conta_atual = isset($_GET['conta']) ? (int)$_GET['conta'] : 0;

// Filtros
$categoria_filtro = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$incluir_subcats = isset($_GET['incluir_subcats']) ? (int)$_GET['incluir_subcats'] : 1;
$data_inicio_filtro = isset($_GET['data_inicio']) && trim($_GET['data_inicio']) !== '' ? trim($_GET['data_inicio']) : date('Y-m-01');
$data_fim_filtro = isset($_GET['data_fim']) && trim($_GET['data_fim']) !== '' ? trim($_GET['data_fim']) : date('Y-m-d');
$descricao_filtro = isset($_GET['descricao']) ? trim($_GET['descricao']) : '';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todas';
$qualquer_data = isset($_GET['qualquer_data']) ? (int)$_GET['qualquer_data'] : 0;

// Verifica se há filtros avançados ativos
$has_advanced_filters = ($conta_atual > 0 || $categoria_filtro > 0 || $tipo_filtro !== 'todas' || !empty($descricao_filtro) || $ordenacao !== 'data_desc' || $qualquer_data == 1);

// Busca contas do usuário para popular o select de filtro
$stmt_contas_filtro = $mysqliFinancas->prepare("SELECT id, nome FROM contas WHERE id_user = ? and status = 1 ORDER BY nome");
$stmt_contas_filtro->bind_param("i", $user_id);
$stmt_contas_filtro->execute();
$contas_filtro = $stmt_contas_filtro->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_contas_filtro->close();

// Mapear categorias para resolução hierárquica de ícones e cores
$stmt_cats = $mysqliFinancas->prepare("SELECT id, nome, id_pai, icone, cor FROM categorias WHERE id_user = ? ORDER BY nome");
$stmt_cats->bind_param("i", $user_id);
$stmt_cats->execute();
$all_cats = $stmt_cats->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_cats->close();

$cats_map = [];
foreach ($all_cats as $c) {
    $cats_map[$c['id']] = $c;
}

// Pré-resolver ícone e cor hierárquicos para todas as categorias
foreach ($all_cats as &$c) {
    $atributos = resolveAtributosCategoria($c['id'], $cats_map);
    $c['icone_resolvido'] = $atributos['icone'];
    $c['cor_resolvida']   = $atributos['cor'];
    $cats_map[$c['id']] = $c; // atualiza o map com os campos resolvidos
}
unset($c);

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

// Construção Dinâmica da Query
$conditions = ["t.iduser = ?"];
$params = [$user_id];
$types = "i";

if (!$qualquer_data) {
    $conditions[] = "t.data >= ?";
    $params[] = $data_inicio_filtro;
    $types .= "s";

    $conditions[] = "t.data <= ?";
    $params[] = $data_fim_filtro;
    $types .= "s";
}

if ($conta_atual > 0) {
    $conditions[] = "t.idconta = ?";
    $params[] = $conta_atual;
    $types .= "i";
}

if ($categoria_filtro > 0) {
    if ($incluir_subcats) {
        // Coleta recursivamente todos os IDs filhos da categoria selecionada
        $ids_categoria = [$categoria_filtro];
        $fila = [$categoria_filtro];
        while (!empty($fila)) {
            $id_atual = array_shift($fila);
            foreach ($all_cats as $c) {
                if ($c['id_pai'] == $id_atual) {
                    $ids_categoria[] = $c['id'];
                    $fila[] = $c['id'];
                }
            }
        }
        $placeholders = implode(',', array_fill(0, count($ids_categoria), '?'));
        $conditions[] = "t.idcategoria IN ($placeholders)";
        foreach ($ids_categoria as $cid) {
            $params[] = $cid;
            $types .= "i";
        }
    } else {
        $conditions[] = "t.idcategoria = ?";
        $params[] = $categoria_filtro;
        $types .= "i";
    }
}

if (!empty($descricao_filtro)) {
    $conditions[] = "t.descricao LIKE ?";
    $params[] = "%" . $descricao_filtro . "%";
    $types .= "s";
}

if ($tipo_filtro == 'receitas') {
    $conditions[] = "t.valor > 0 AND t.idcategoria != -1";
} elseif ($tipo_filtro == 'despesas') {
    $conditions[] = "t.valor < 0 AND t.idcategoria != -1";
} elseif ($tipo_filtro == 'transferencias') {
    $conditions[] = "t.idcategoria = -1";
}

$where_clause = implode(" AND ", $conditions);

switch ($ordenacao) {
    case 'data_asc':    $order_by_clause = 't.data ASC, t.id ASC'; break;
    case 'valor_desc':  $order_by_clause = 'ABS(t.valor) DESC, t.data DESC'; break;
    case 'valor_asc':   $order_by_clause = 'ABS(t.valor) ASC, t.data DESC'; break;
    default:            $order_by_clause = 't.data DESC, t.id DESC'; break;
}

$sql = "
    SELECT t.id, t.data, t.valor, t.descricao, t.consolidada, t.idcategoria, t.idpai, t.parcela_recorrencia, t.parcela_fim, t.id_grupo_recorrencia,
           c.nome as categoria_nome, c.cor as categoria_cor, c.icone as categoria_icone, co.nome as conta_nome, co.img as conta_img, co.cor as conta_cor,
           co_dest.nome as conta_destino_nome_db, co_dest.img as conta_destino_img_db, co_dest.cor as conta_destino_cor_db,
           co_orig.nome as conta_origem_nome_db, co_orig.img as conta_origem_img_db, co_orig.cor as conta_origem_cor_db
    FROM transacoes t
    LEFT JOIN categorias c ON t.idcategoria = c.id
    LEFT JOIN contas co ON t.idconta = co.id
    LEFT JOIN transacoes t_dest ON t_dest.idpai = t.id
    LEFT JOIN contas co_dest ON t_dest.idconta = co_dest.id
    LEFT JOIN transacoes t_orig ON t_orig.id = t.idpai
    LEFT JOIN contas co_orig ON t_orig.idconta = co_orig.id
    WHERE $where_clause
    ORDER BY $order_by_clause
";

$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transacoes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Agrupamento das Transferências (Para não mostrar 2 linhas separadas)
$transacoes_agrupadas = [];
$transferencias_filhas = [];

// Primeira passagem: mapear filhas
foreach ($transacoes as $t) {
    if ($t['idcategoria'] == -1 && $t['idpai']) {
        $transferencias_filhas[$t['idpai']] = $t;
    }
}

// Segunda passagem: agrupar
foreach ($transacoes as $t) {
    if ($t['idcategoria'] == -1) {
        if ($conta_atual > 0) {
            // Se está filtrado por conta, mostramos a perna que retornou sem pular a entrada
            if ($t['idpai']) {
                $t['is_transferencia_entrada'] = true;
                $t['conta_oposta_nome'] = $t['conta_origem_nome_db'] ?? 'Desconhecida';
                $t['conta_oposta_img'] = $t['conta_origem_img_db'] ?? null;
                $t['conta_oposta_cor'] = $t['conta_origem_cor_db'] ?? null;
            } else {
                $t['is_transferencia_saida'] = true;
                $t['conta_oposta_nome'] = $t['conta_destino_nome_db'] ?? 'Desconhecida';
                $t['conta_oposta_img'] = $t['conta_destino_img_db'] ?? null;
                $t['conta_oposta_cor'] = $t['conta_destino_cor_db'] ?? null;
            }
            $transacoes_agrupadas[] = $t;
        } else {
            if ($t['idpai']) {
                // É a perna filha (Entrada), pula para não duplicar na lista
                continue;
            } else {
                // É a perna pai (Saída)
                $filha = $transferencias_filhas[$t['id']] ?? null;
                $t['conta_destino_nome'] = $filha ? $filha['conta_nome'] : ($t['conta_destino_nome_db'] ?? 'Desconhecida');
                $t['conta_destino_img'] = $filha ? $filha['conta_img'] : ($t['conta_destino_img_db'] ?? null);
                $t['conta_destino_cor'] = $filha ? $filha['conta_cor'] : ($t['conta_destino_cor_db'] ?? null);
                $transacoes_agrupadas[] = $t;
            }
        }
    } else {
        $atributos = resolveAtributosCategoria($t['idcategoria'], $cats_map);
        $t['categoria_icone_resolvido'] = $atributos['icone'];
        $t['categoria_cor_resolvida'] = $atributos['cor'];
        $transacoes_agrupadas[] = $t;
    }
}

// Totais para o painel de resumo
$total_receitas = 0;
$total_despesas = 0;
foreach ($transacoes_agrupadas as $t) {
    if ($t['idcategoria'] == -1) continue; // Ignora transferências nos totais
    if ($t['valor'] > 0) {
        $total_receitas += $t['valor'];
    } else {
        $total_despesas += $t['valor'];
    }
}
$saldo_periodo = $total_receitas + $total_despesas;

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];



// Funções para hierarquia de categorias
function buildCategoryTree(array $elements, $parentId = null) {
    $branch = array();
    foreach ($elements as $element) {
        if ($element['id_pai'] == $parentId) {
            $children = buildCategoryTree($elements, $element['id']);
            $element['children'] = $children ?: [];
            $branch[] = $element;
        }
    }
    return $branch;
}

function buildCatTreeHtml(array $nodes, $selected_id = 0, $level = 0, $prefix = '') {
    $html = '';
    foreach ($nodes as $cat) {
        $hasChildren = !empty($cat['children']);
        $id = $cat['id'];
        $nome = htmlspecialchars($cat['nome']);
        $nomeJs = addslashes($cat['nome']);
        $cor = htmlspecialchars($cat['cor_resolvida'] ?? '#ccc');
        $icone = htmlspecialchars($cat['icone_resolvido'] ?? '');
        $isSelected = ($id == $selected_id);
        $pl = $level > 0 ? 'style="padding-left:' . ($level * 12 + 12) . 'px"' : 'style="padding-left:12px"';
        $selectedClass = $isSelected ? 'bg-cyan-50 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-300' : 'text-slate-700 dark:text-white/80 hover:bg-slate-100 dark:hover:bg-white/10';
        // Função JS de toggle depende do prefixo
        $toggleFn = $prefix ? "toggleBulkCatChildren($id)" : "toggleCatChildren($id)";
        $childrenId = "{$prefix}cat-children-$id";
        $iconId     = "{$prefix}cat-icon-$id";

        $html .= "<div class='flex flex-col'>";

        if ($hasChildren) {
            $html .= "<div class='flex items-center rounded-xl $selectedClass transition-colors'>";
            $html .= "<button type='button' onclick='$toggleFn' class='flex items-center gap-2 flex-1 py-2 text-sm font-medium text-left' $pl>";
            $html .= buildCatIconHtml($cor, $icone);
            $html .= "<span>$nome</span>";
            $html .= "<svg id='$iconId' class='w-3.5 h-3.5 ml-auto mr-2 text-slate-400 dark:text-white/40 transition-transform duration-200' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'></path></svg>";
            $html .= "</button>";
            $html .= "<button type='button' onclick=\"selectCategoria($id, '$nomeJs')\" class='p-2 mr-1 rounded-lg text-slate-400 dark:text-white/40 hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-black/5 dark:hover:bg-white/10 transition-colors shrink-0' title='Selecionar esta categoria'>";
            $html .= "<svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'></path></svg>";
            $html .= "</button>";
            $html .= "</div>";
            $html .= "<div id='$childrenId' class='hidden'>";
            $html .= buildCatTreeHtml($cat['children'], $selected_id, $level + 1, $prefix);
            $html .= "</div>";
        } else {
            $html .= "<button type='button' onclick=\"selectCategoria($id, '$nomeJs')\" class='flex items-center gap-2 py-2 text-sm font-medium rounded-xl $selectedClass transition-colors w-full text-left' $pl>";
            $html .= buildCatIconHtml($cor, $icone);
            $html .= "<span>$nome</span>";
            if ($isSelected) {
                $html .= "<svg class='w-4 h-4 ml-auto mr-2 text-cyan-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'></path></svg>";
            }
            $html .= "</button>";
        }

        $html .= "</div>";
    }
    return $html;
}

function buildCatIconHtml($cor, $icone) {
    $html = "<span class='w-5 h-5 rounded-full flex items-center justify-center shrink-0' style='background-color:$cor'>";
    if ($icone) {
        $html .= "<i class='ph-fill $icone text-white' style='font-size:10px'></i>";
    }
    $html .= "</span>";
    return $html;
}

$tree_categorias = buildCategoryTree($all_cats);

// Nome da categoria selecionada para exibir no botão
$nome_categoria_filtro = 'Categorias';
foreach ($all_cats as $c) {
    if ($c['id'] == $categoria_filtro) {
        $nome_categoria_filtro = $c['nome'];
        break;
    }
}
?>
<?php 
$page_title = "Transações - Minhas Finanças";
$extra_head = '<script src="https://unpkg.com/@phosphor-icons/web"></script>';
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Transações</h1>
            <div class="flex items-center gap-2">
                <button id="btn-modo-selecao" onclick="toggleModoSelecao()" title="Selecionar" class="flex items-center gap-2 px-4 py-2 bg-white/60 dark:bg-white/10 hover:bg-white/80 dark:hover:bg-white/20 backdrop-blur-md border border-gray-200 dark:border-white/20 text-slate-700 dark:text-white rounded-xl text-sm font-medium transition-all shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 11l3 3L22 4M16 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
                    <span class="hidden sm:inline">Selecionar</span>
                </button>
                <button onclick="exportCSV()" title="Exportar CSV" class="flex items-center gap-2 px-4 py-2 bg-[#217346]/70 hover:bg-[#217346]/90 backdrop-blur-md border border-[#2ecc71]/40 hover:border-[#2ecc71]/70 text-white rounded-xl text-sm font-medium transition-all shadow-sm hover:shadow-[0_0_16px_rgba(33,115,70,0.45)]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    <span class="hidden sm:inline">Exportar CSV</span>
                </button>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="relative z-50 mb-8 bg-white/60 dark:bg-white/10 backdrop-blur-xl px-5 py-4 sm:p-6 rounded-3xl border border-gray-200 dark:border-white/20 shadow-lg">
            <form method="GET" id="form-filtros" class="flex flex-col gap-3 w-full">

                <!-- Linha 1: Data Inicial / Data Final / Filtrar + Mais Filtros -->
                <div class="flex flex-col sm:flex-row sm:items-end gap-3 w-full min-w-0">
                    <div class="w-full min-w-0 sm:flex-1 sm:min-w-[140px] <?php echo $qualquer_data ? 'opacity-40 pointer-events-none' : ''; ?> transition-all">
                        <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Data Inicial</label>
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio_filtro); ?>" class="w-full max-w-full min-w-0 bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-3 sm:px-4 py-2 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div class="w-full min-w-0 sm:flex-1 sm:min-w-[140px] <?php echo $qualquer_data ? 'opacity-40 pointer-events-none' : ''; ?> transition-all">
                        <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Data Final</label>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim_filtro); ?>" class="w-full max-w-full min-w-0 bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-3 sm:px-4 py-2 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div class="w-full sm:w-auto sm:shrink-0 flex items-center gap-2">
                        <button type="submit" class="flex-1 sm:flex-none flex items-center justify-center gap-2 px-5 py-2 bg-cyan-500 hover:bg-cyan-400 text-white rounded-xl font-medium shadow-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            Filtrar
                        </button>
                        <button type="button" onclick="toggleFiltrosAvancados()" id="btn-toggle-filtros" class="flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-2 bg-white/60 dark:bg-white/10 hover:bg-white/80 dark:hover:bg-white/20 border border-gray-200 dark:border-white/20 text-slate-700 dark:text-white rounded-xl text-sm font-medium transition-all shadow-sm">
                            <svg id="icon-toggle-filtros" class="w-4 h-4 transition-transform duration-300 <?php echo $has_advanced_filters ? 'rotate-180' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            <span id="text-toggle-filtros"><?php echo $has_advanced_filters ? 'Menos Filtros' : 'Mais Filtros'; ?></span>
                        </button>
                    </div>
                </div>

                <!-- Painel Filtros Avançados: Colapsável (Linhas 2 e 3) -->
                <div id="filtros-avancados" class="flex flex-col gap-3 <?php echo $has_advanced_filters ? '' : 'hidden'; ?>">
                    <!-- Linha 2: Categorias / Contas / Transações / Ordenação -->
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Categoria -->
                        <div class="relative flex-1 min-w-[160px]" id="cat-selector-wrapper">
                            <input type="hidden" name="categoria" id="input-categoria-filtro" value="<?php echo $categoria_filtro; ?>">
                            <button type="button" onclick="toggleCatDropdown()" id="btn-cat-selector" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 flex items-center justify-between gap-2">
                                <span id="label-cat-filtro" class="truncate"><?php echo htmlspecialchars($nome_categoria_filtro); ?></span>
                                <svg class="w-4 h-4 shrink-0 text-slate-400 dark:text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div id="cat-dropdown" class="hidden absolute top-full left-0 mt-2 w-72 max-h-80 overflow-y-auto z-[100] bg-white dark:bg-slate-800 border border-gray-200 dark:border-white/10 rounded-2xl shadow-2xl">
                                <div class="p-2">
                                    <button type="button" onclick="selectCategoria(0, 'Categorias')" class="w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-white/10 text-slate-600 dark:text-white/70 text-sm font-medium transition-colors flex items-center gap-2">
                                        <span class="w-5 h-5 rounded-full bg-slate-200 dark:bg-white/20 flex items-center justify-center shrink-0">
                                            <svg class="w-3 h-3 text-slate-500 dark:text-white/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                                        </span>
                                        Categorias
                                    </button>
                                    <div id="cat-tree-root" class="mt-1 space-y-0.5">
                                        <?php echo buildCatTreeHtml($tree_categorias, $categoria_filtro); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <select name="conta" onchange="this.form.submit()" class="flex-1 min-w-[140px] bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                            <option class="text-gray-900" value="0">Contas</option>
                            <?php foreach($contas_filtro as $c): ?>
                                <option class="text-gray-900" value="<?php echo $c['id']; ?>" <?php echo $conta_atual == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="tipo" onchange="this.form.submit()" class="flex-1 min-w-[140px] bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                            <option class="text-gray-900" value="todas" <?php echo $tipo_filtro == 'todas' ? 'selected' : ''; ?>>Transações</option>
                            <option class="text-gray-900" value="receitas" <?php echo $tipo_filtro == 'receitas' ? 'selected' : ''; ?>>Receitas</option>
                            <option class="text-gray-900" value="despesas" <?php echo $tipo_filtro == 'despesas' ? 'selected' : ''; ?>>Despesas</option>
                            <option class="text-gray-900" value="transferencias" <?php echo $tipo_filtro == 'transferencias' ? 'selected' : ''; ?>>Transferências</option>
                        </select>

                        <select name="ordenacao" onchange="this.form.submit()" class="flex-1 min-w-[160px] bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                            <option class="text-gray-900" value="data_desc"  <?php echo $ordenacao == 'data_desc'  ? 'selected' : ''; ?>>Data (Decrescente)</option>
                            <option class="text-gray-900" value="data_asc"   <?php echo $ordenacao == 'data_asc'   ? 'selected' : ''; ?>>Data (Crescente)</option>
                            <option class="text-gray-900" value="valor_desc" <?php echo $ordenacao == 'valor_desc' ? 'selected' : ''; ?>>Valor (Decrescente)</option>
                            <option class="text-gray-900" value="valor_asc"  <?php echo $ordenacao == 'valor_asc'  ? 'selected' : ''; ?>>Valor (Crescente)</option>
                        </select>
                    </div>

                    <!-- Linha 3: Busca por descrição + Qualquer Data + Incluir subcategorias + Limpar -->
                    <div class="flex flex-wrap items-center gap-3 pt-1 border-t border-gray-200 dark:border-white/10">
                        <div class="flex-1 min-w-[200px]">
                            <input type="text" name="descricao" value="<?php echo htmlspecialchars($descricao_filtro); ?>" placeholder="Buscar na descrição (ex: Mercado, Uber...)" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                        </div>
                        <div class="flex items-center">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <div class="relative">
                                    <input type="hidden" name="qualquer_data" value="0">
                                    <input type="checkbox" name="qualquer_data" value="1" id="chk-qualquer-data" onchange="this.form.submit()" class="sr-only peer" <?php echo $qualquer_data ? 'checked' : ''; ?>>
                                    <div class="w-9 h-5 bg-slate-200 dark:bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-cyan-500"></div>
                                </div>
                                <span class="text-xs font-medium text-slate-600 dark:text-white/70 whitespace-nowrap">Qualquer Data</span>
                            </label>
                        </div>
                        <div id="row-incluir-subcats" class="flex items-center <?php echo $categoria_filtro > 0 ? '' : 'hidden'; ?>">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <div class="relative">
                                    <input type="hidden" name="incluir_subcats" value="0">
                                    <input type="checkbox" name="incluir_subcats" value="1" id="chk-incluir-subcats" class="sr-only peer" <?php echo $incluir_subcats ? 'checked' : ''; ?>>
                                    <div class="w-9 h-5 bg-slate-200 dark:bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-cyan-500"></div>
                                </div>
                                <span class="text-xs font-medium text-slate-600 dark:text-white/70 whitespace-nowrap">Incluir subcategorias</span>
                            </label>
                        </div>
                        <a href="transacoes.php" class="text-sm text-red-500 dark:text-red-400 hover:text-red-600 dark:hover:text-red-300 hover:bg-white/50 dark:hover:bg-white/5 px-3 py-2 rounded-xl transition-colors whitespace-nowrap">Limpar Filtros</a>
                    </div>
                </div>

            </form>
        </div>

        <!-- Painel de Resumo do Per�odo -->
        <div class="mb-6 grid grid-cols-3 gap-3">
            <!-- Receitas -->
            <div class="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 rounded-2xl p-4 flex flex-col items-start">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wide mb-1">Receitas</span>
                <span class="text-lg sm:text-xl font-bold text-emerald-700 dark:text-emerald-300 leading-tight">
                    R$ <?php echo number_format($total_receitas, 2, ',', '.'); ?>
                </span>
            </div>
            <!-- Despesas -->
            <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-2xl p-4 flex flex-col items-start">
                <span class="text-xs font-semibold text-red-600 dark:text-red-400 uppercase tracking-wide mb-1">Despesas</span>
                <span class="text-lg sm:text-xl font-bold text-red-700 dark:text-red-300 leading-tight">
                    R$ <?php echo number_format(abs($total_despesas), 2, ',', '.'); ?>
                </span>
            </div>
            <!-- Saldo -->
            <div class="<?php echo $saldo_periodo >= 0 ? 'bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20' : 'bg-orange-50 dark:bg-orange-500/10 border-orange-200 dark:border-orange-500/20'; ?> border rounded-2xl p-4 flex flex-col items-start">
                <span class="text-xs font-semibold <?php echo $saldo_periodo >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-orange-600 dark:text-orange-400'; ?> uppercase tracking-wide mb-1">Saldo</span>
                <span class="text-lg sm:text-xl font-bold <?php echo $saldo_periodo >= 0 ? 'text-blue-700 dark:text-blue-300' : 'text-orange-700 dark:text-orange-300'; ?> leading-tight">
                    <?php echo ($saldo_periodo >= 0 ? '' : '-') . 'R$ ' . number_format(abs($saldo_periodo), 2, ',', '.'); ?>
                </span>
            </div>
        </div>

        <!-- Formulário oculto para ações em lote -->
        <form id="bulk-form" method="POST" action="transacoes.php" class="hidden">
            <input type="hidden" name="bulk_action" id="bulk-action-input">
            <input type="hidden" name="id_categoria" id="bulk-categoria-input" value="">
            <div id="bulk-ids-container"></div>
            <input type="hidden" name="data_inicio_atual" value="<?php echo htmlspecialchars($data_inicio_filtro); ?>">
            <input type="hidden" name="data_fim_atual" value="<?php echo htmlspecialchars($data_fim_filtro); ?>">
            <input type="hidden" name="conta_atual" value="<?php echo $conta_atual; ?>">
            <input type="hidden" name="categoria_atual" value="<?php echo $categoria_filtro; ?>">
            <input type="hidden" name="tipo_atual" value="<?php echo $tipo_filtro; ?>">
            <input type="hidden" name="ordenacao_atual" value="<?php echo $ordenacao; ?>">
            <input type="hidden" name="incluir_subcats_atual" value="<?php echo $incluir_subcats; ?>">
            <input type="hidden" name="qualquer_data_atual" value="<?php echo $qualquer_data; ?>">
        </form>

        <!-- Lista de Transações -->
        <div class="space-y-4" id="lista-transacoes">
            <!-- Linha Selecionar Todos (visível apenas no modo seleção) -->
            <div id="row-select-all" class="hidden flex items-center justify-between px-1 pb-1">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" id="chk-select-all" onchange="toggleSelectAll(this.checked)" class="w-4 h-4 rounded accent-cyan-500">
                    <span class="text-sm text-slate-500 dark:text-white/60 font-medium">Selecionar todos</span>
                </label>
            </div>
            <?php 
            $data_atual = '';
            if (count($transacoes_agrupadas) > 0): 
                foreach ($transacoes_agrupadas as $t): 
                    // Separador de Data
                    if ($data_atual != $t['data']): 
                        $data_atual = $t['data'];
                        $dia = date('d', strtotime($data_atual));
                        $dia_semana = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][date('w', strtotime($data_atual))];
                        $mes_extenso = $meses[(int)date('m', strtotime($data_atual))];
                        $ano_extenso = date('Y', strtotime($data_atual));
            ?>
                        <div class="pt-4 pb-2 border-b border-gray-200 dark:border-white/10">
                            <span class="text-slate-500 dark:text-white/60 font-medium text-sm"><?php echo $dia_semana . ', ' . $dia . ' de ' . $mes_extenso . ($ano_extenso != date('Y') ? ' de ' . $ano_extenso : ''); ?></span>
                        </div>
            <?php   endif; ?>
                    
                    <!-- Card da Transação -->
                    <div class="bulk-card backdrop-blur-xl border rounded-2xl p-4 flex items-center transition-all <?php echo !$t['consolidada'] ? 'bg-yellow-50 border-yellow-200 hover:bg-yellow-100 dark:bg-yellow-400/10 dark:border-yellow-400/30 shadow-sm dark:shadow-[0_0_15px_rgba(250,204,21,0.1)] dark:hover:bg-yellow-400/20' : 'bg-white/60 border-gray-200 hover:bg-white/70 dark:bg-white/10 dark:border-white/10 dark:hover:bg-white/20 shadow-sm'; ?>"
                         data-id="<?php echo $t['id']; ?>"
                         data-consolidada="<?php echo $t['consolidada']; ?>"
                         data-is-transferencia="<?php echo ($t['idcategoria'] == -1) ? '1' : '0'; ?>"
                         onclick="handleCardClick(event, <?php echo $t['id']; ?>)">

                        <!-- Checkbox de Seleção (oculto fora do modo) -->
                        <div class="bulk-checkbox hidden shrink-0 mr-3" onclick="event.stopPropagation()">
                            <input type="checkbox" class="card-chk w-5 h-5 rounded accent-cyan-500 cursor-pointer"
                                   data-id="<?php echo $t['id']; ?>"
                                   onchange="onCardCheckChange(<?php echo $t['id']; ?>, this.checked)">
                        </div>

                        <div class="flex items-center justify-between flex-1 min-w-0">
                        <div class="flex items-center space-x-4 flex-1 min-w-0">
                            <!-- Ícone/Cor -->
                            <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-inner shrink-0" style="background-color: <?php echo ($t['idcategoria'] == -1 && $conta_atual == 0) ? '#3b82f6' : ($t['idcategoria'] == -1 ? ($t['valor'] < 0 ? '#ef4444' : '#10b981') : $t['categoria_cor_resolvida']); ?>">
                                <?php if($t['idcategoria'] == -1): ?>
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                                <?php else: ?>
                                    <?php if(!empty($t['categoria_icone_resolvido'])): ?>
                                        <i class="ph-fill <?php echo htmlspecialchars($t['categoria_icone_resolvido']); ?> text-white text-xl"></i>
                                    <?php else: ?>
                                        <?php if($t['valor'] > 0): ?>
                                            <svg class="w-5 h-5 text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>
                                        <?php else: ?>
                                            <svg class="w-5 h-5 text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Detalhes -->
                            <div class="flex-1 min-w-0 pr-2">
                                <h3 class="text-slate-800 dark:text-white font-medium text-lg leading-tight break-words whitespace-normal">
                                    <?php 
                                        $desc_exibicao = htmlspecialchars($t['descricao']);
                                        if (!empty($t['id_grupo_recorrencia']) && isset($t['parcela_fim']) && $t['parcela_fim'] > 1) {
                                            $parcela = $t['parcela_recorrencia'] ?? 1;
                                            $desc_exibicao .= " ($parcela / {$t['parcela_fim']})";
                                        }
                                        echo $desc_exibicao;
                                    ?>
                                </h3>
                                <p class="text-slate-500 dark:text-white/50 text-xs mt-1 flex items-center flex-wrap">
                                    <?php if(!empty($t['conta_img'])): ?>
                                        <img src="img/<?php echo htmlspecialchars($t['conta_img']); ?>" class="w-3.5 h-3.5 rounded-full object-cover mr-1.5 shrink-0 border border-gray-200 dark:border-white/10">
                                    <?php else: ?>
                                        <span class="w-3.5 h-3.5 rounded-full mr-1.5 shrink-0" style="background-color: <?php echo $t['conta_cor'] ?? '#ccc'; ?>"></span>
                                    <?php endif; ?>
                                    <span class="truncate"><?php echo htmlspecialchars($t['conta_nome'] ?? 'Conta Desconhecida'); ?></span>
                                    
                                    <?php if($t['idcategoria'] == -1): ?>
                                        <?php if(isset($t['is_transferencia_entrada'])): ?>
                                            <span class="mx-1">⬅</span> 
                                            <?php if(!empty($t['conta_oposta_img'])): ?>
                                                <img src="img/<?php echo htmlspecialchars($t['conta_oposta_img']); ?>" class="w-3.5 h-3.5 rounded-full object-cover mr-1.5 inline-block shrink-0 border border-gray-200 dark:border-white/10">
                                            <?php else: ?>
                                                <span class="w-3.5 h-3.5 rounded-full mr-1.5 inline-block shrink-0" style="background-color: <?php echo $t['conta_oposta_cor'] ?? '#ccc'; ?>"></span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($t['conta_oposta_nome']); ?>
                                        <?php elseif(isset($t['is_transferencia_saida'])): ?>
                                            <span class="mx-1">➔</span> 
                                            <?php if(!empty($t['conta_oposta_img'])): ?>
                                                <img src="img/<?php echo htmlspecialchars($t['conta_oposta_img']); ?>" class="w-3.5 h-3.5 rounded-full object-cover mr-1.5 inline-block shrink-0 border border-gray-200 dark:border-white/10">
                                            <?php else: ?>
                                                <span class="w-3.5 h-3.5 rounded-full mr-1.5 inline-block shrink-0" style="background-color: <?php echo $t['conta_oposta_cor'] ?? '#ccc'; ?>"></span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($t['conta_oposta_nome']); ?>
                                        <?php elseif(isset($t['conta_destino_nome'])): ?>
                                            <span class="mx-1">➔</span> 
                                            <?php if(!empty($t['conta_destino_img'])): ?>
                                                <img src="img/<?php echo htmlspecialchars($t['conta_destino_img']); ?>" class="w-3.5 h-3.5 rounded-full object-cover mr-1.5 inline-block shrink-0 border border-gray-200 dark:border-white/10">
                                            <?php else: ?>
                                                <span class="w-3.5 h-3.5 rounded-full mr-1.5 inline-block shrink-0" style="background-color: <?php echo $t['conta_destino_cor'] ?? '#ccc'; ?>"></span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($t['conta_destino_nome']); ?>
                                        <?php endif; ?>
                                    <?php elseif($t['idcategoria'] != -1 && $t['categoria_nome']): ?>
                                        <span class="mx-1">•</span> <?php echo htmlspecialchars($t['categoria_nome']); ?>
                                    <?php endif; ?>
                                    
                                    <?php if(!$t['consolidada']): ?>
                                        <span class="ml-2 text-yellow-600 dark:text-yellow-400 font-medium bg-yellow-100 dark:bg-yellow-400/10 px-2 py-0.5 rounded-full">Pendente</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Valor e Ações -->
                        <div class="flex items-center space-x-3 shrink-0">
                            <span class="font-bold text-lg whitespace-nowrap <?php echo ($t['idcategoria'] == -1 && $conta_atual == 0) ? 'text-blue-600 dark:text-blue-400' : ($t['valor'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'); ?>">
                                <?php 
                                    echo 'R$ ' . number_format(abs($t['valor']), 2, ',', '.');
                                ?>
                            </span>
                            
                            <div class="flex space-x-1">
                                <!-- Botão Consolidar Rapido -->
                                <?php if(!$t['consolidada']): ?>
                                    <a href="transacoes.php?action=consolidate&id=<?php echo $t['id']; ?>&mes=<?php echo $mes_atual; ?>&ano=<?php echo $ano_atual; ?>" 
                                       class="p-2 rounded-lg transition-colors text-emerald-600 bg-emerald-50 hover:bg-emerald-100 hover:text-emerald-700 dark:text-emerald-400 dark:bg-emerald-400/10 dark:hover:bg-emerald-400/20 dark:hover:text-emerald-300"
                                       title="Consolidar">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </a>
                                <?php endif; ?>

                                <!-- Botão Editar -->
                                <a href="transacao.php?id=<?php echo $t['id']; ?>" class="p-2 text-cyan-600 hover:text-cyan-700 hover:bg-cyan-50 dark:text-cyan-400 dark:hover:text-cyan-300 dark:hover:bg-white/10 rounded-lg transition-colors" title="Editar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                </a>
                            </div>
                        </div>
                        </div><!-- /flex items-center justify-between -->
                    </div><!-- /bulk-card -->

            <?php 
                endforeach; 
            else: 
            ?>
                <div class="text-center p-8 bg-white/60 dark:bg-white/5 rounded-3xl border border-gray-200 dark:border-white/10">
                    <p class="text-slate-500 dark:text-white/50">Nenhuma transação encontrada neste mês.</p>
                </div>
            <?php endif; ?>
        </div><!-- /lista-transacoes -->
    </div><!-- /max-w-4xl -->

    <!-- ── Toolbar Flutuante de Ações em Lote ── -->
    <div id="bulk-toolbar" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 z-[200] flex items-center gap-2 px-4 py-3 bg-slate-900/90 dark:bg-slate-800/95 backdrop-blur-xl border border-white/20 rounded-2xl shadow-2xl text-white">
        <span id="bulk-count-label" class="text-sm font-semibold text-white/80 mr-2 whitespace-nowrap">0 selecionadas</span>

        <button onclick="bulkConsolidar()" class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-500/80 hover:bg-emerald-500 rounded-xl text-sm font-medium transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="hidden sm:inline">Consolidar</span>
        </button>

        <button onclick="openBulkCategoria()" class="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-500/80 hover:bg-cyan-500 rounded-xl text-sm font-medium transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
            <span class="hidden sm:inline">Categoria</span>
        </button>

        <button onclick="bulkExcluir()" class="flex items-center gap-1.5 px-3 py-1.5 bg-red-500/80 hover:bg-red-500 rounded-xl text-sm font-medium transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
            <span class="hidden sm:inline">Excluir</span>
        </button>

        <button onclick="toggleModoSelecao()" class="p-1.5 bg-white/10 hover:bg-white/20 rounded-xl transition-colors ml-1" title="Cancelar seleção">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <!-- ── Modal: Alterar Categoria em Lote ── -->
    <div id="modal-bulk-categoria" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[300] flex items-end sm:items-center justify-center p-4" onclick="closeBulkCategoria()">
        <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-white/10 rounded-3xl shadow-2xl w-full max-w-sm max-h-[80vh] flex flex-col" onclick="event.stopPropagation()">
            <div class="p-5 border-b border-gray-100 dark:border-white/10 flex items-center justify-between shrink-0">
                <h3 class="text-slate-800 dark:text-white font-semibold text-base">Alterar Categoria</h3>
                <button onclick="closeBulkCategoria()" class="text-slate-400 hover:text-slate-700 dark:text-white/50 dark:hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <p id="bulk-cat-aviso" class="hidden mx-5 mt-4 text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-400/10 border border-amber-200 dark:border-amber-400/20 rounded-xl px-3 py-2">
                As transferências selecionadas serão ignoradas nesta operação.
            </p>
            <div class="flex-1 overflow-y-auto p-3">
                <div class="space-y-0.5">
                    <?php echo buildCatTreeHtml($tree_categorias, 0, 0, 'bulk-'); ?>
                </div>
            </div>
        </div>
    </div>

</body>
<script>

    // ── Toggle Filtros Avançados ───────────────────────────────────
    let filtersExpanded = <?php echo $has_advanced_filters ? 'true' : 'false'; ?>;
    function toggleFiltrosAvancados() {
        const adv = document.getElementById('filtros-avancados');
        const icon = document.getElementById('icon-toggle-filtros');
        const text = document.getElementById('text-toggle-filtros');
        filtersExpanded = !filtersExpanded;
        if (filtersExpanded) {
            adv.classList.remove('hidden');
            icon.classList.add('rotate-180');
            text.textContent = 'Menos Filtros';
        } else {
            adv.classList.add('hidden');
            icon.classList.remove('rotate-180');
            text.textContent = 'Mais Filtros';
        }
    }

    // ── Category Hierarchical Dropdown ─────────────────────────────
    function toggleCatDropdown() {
        const dd = document.getElementById('cat-dropdown');
        dd.classList.toggle('hidden');
    }

    function toggleCatChildren(id) {
        const children = document.getElementById('cat-children-' + id);
        const icon = document.getElementById('cat-icon-' + id);
        if (children) {
            children.classList.toggle('hidden');
            if (icon) icon.classList.toggle('rotate-180');
        }
    }

    function toggleBulkCatChildren(id) {
        const children = document.getElementById('bulk-cat-children-' + id);
        const icon = document.getElementById('bulk-cat-icon-' + id);
        if (children) {
            children.classList.toggle('hidden');
            if (icon) icon.classList.toggle('rotate-180');
        }
    }

    function selectCategoria(id, nome) {
        // Se o modal de bulk estiver aberto, opera em modo bulk
        if (bulkCatModalOpen) {
            closeBulkCategoria();
            if (id > 0) submitBulkForm('alterar_categoria', id);
            return;
        }
        // Caso contrário, aplica o filtro de categoria
        document.getElementById('input-categoria-filtro').value = id;
        document.getElementById('label-cat-filtro').textContent = nome;
        // Mostrar/ocultar checkbox de subcategorias
        const rowSubcats = document.getElementById('row-incluir-subcats');
        if (rowSubcats) {
            if (id > 0) {
                rowSubcats.classList.remove('hidden');
            } else {
                rowSubcats.classList.add('hidden');
            }
        }
        // Fechar dropdown e submeter o form imediatamente
        document.getElementById('cat-dropdown').classList.add('hidden');
        document.getElementById('form-filtros').submit();
    }

    // Auto-expandir ancestrais se já há uma categoria selecionada
    (function autoExpandSelectedCat() {
        <?php if ($categoria_filtro > 0): ?>
        // Mapa id -> id_pai vindo do PHP
        const catParentMap = <?php
            $map = [];
            foreach ($all_cats as $c) {
                if ($c['id_pai']) $map[$c['id']] = (int)$c['id_pai'];
            }
            echo json_encode($map);
        ?>;
        let cur = <?php echo $categoria_filtro; ?>;
        const toExpand = [];
        while (catParentMap[cur]) {
            cur = catParentMap[cur];
            toExpand.push(cur);
        }
        toExpand.forEach(function(pid) {
            const el = document.getElementById('cat-children-' + pid);
            const icon = document.getElementById('cat-icon-' + pid);
            if (el) el.classList.remove('hidden');
            if (icon) icon.classList.add('rotate-180');
        });
        <?php endif; ?>
    })();

    // Fechar dropdowns ao clicar fora
    document.addEventListener('click', function(event) {
        const formFiltros = document.getElementById('form-filtros');

        // Category dropdown
        const catWrapper = document.getElementById('cat-selector-wrapper');
        const catDropdown = document.getElementById('cat-dropdown');
        if (catWrapper && catDropdown && !catWrapper.contains(event.target)) {
            catDropdown.classList.add('hidden');
        }
    });


    // ── Exportar CSV ───────────────────────────────────────────────
    const csvData = <?php
        $csv_rows = [];
        foreach ($transacoes_agrupadas as $t) {
            $data_fmt  = date('d/m/Y', strtotime($t['data']));
            $descricao = $t['descricao'];
            $valor     = number_format($t['valor'], 2, ',', '.');
            if ($t['idcategoria'] == -1) {
                $categoria = 'Transferência';
            } else {
                $categoria = $t['categoria_nome'] ?? '';
            }
            $conta = $t['conta_nome'] ?? '';
            $csv_rows[] = [
                'data'      => $data_fmt,
                'descricao' => $descricao,
                'valor'     => $valor,
                'categoria' => $categoria,
                'conta'     => $conta,
            ];
        }
        echo json_encode($csv_rows, JSON_UNESCAPED_UNICODE);
    ?>;

    function exportCSV() {
        const header = 'Data Ocorrência;Descrição;Valor;Categoria;Conta';
        const lines = csvData.map(r =>
            [r.data, r.descricao, r.valor, r.categoria, r.conta]
                .map(v => '"' + String(v).replace(/"/g, '""') + '"')
                .join(';')
        );
        const content = '\uFEFF' + header + '\n' + lines.join('\n'); // BOM para Excel UTF-8
        const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'transacoes_<?php echo str_pad($mes_atual,2,'0',STR_PAD_LEFT) . '_' . $ano_atual; ?>.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
    // ── Bulk Selection ──────────────────────────────────────────────
    let bulkMode   = false;
    let selectedIds = new Set();

    function toggleModoSelecao() {
        bulkMode = !bulkMode;
        const btn = document.getElementById('btn-modo-selecao');
        const rowAll = document.getElementById('row-select-all');
        const checkboxes = document.querySelectorAll('.bulk-checkbox');
        const cards = document.querySelectorAll('.bulk-card');

        if (bulkMode) {
            btn.classList.add('bg-cyan-500/20', 'border-cyan-400/50', 'text-cyan-700', 'dark:text-cyan-300');
            rowAll.classList.remove('hidden');
            checkboxes.forEach(c => c.classList.remove('hidden'));
            cards.forEach(c => c.style.cursor = 'pointer');
        } else {
            // Reset
            btn.classList.remove('bg-cyan-500/20', 'border-cyan-400/50', 'text-cyan-700', 'dark:text-cyan-300');
            rowAll.classList.add('hidden');
            checkboxes.forEach(c => c.classList.add('hidden'));
            cards.forEach(c => {
                c.style.cursor = '';
                c.classList.remove('ring-2', 'ring-cyan-400', 'ring-offset-1');
            });
            document.querySelectorAll('.card-chk').forEach(chk => chk.checked = false);
            document.getElementById('chk-select-all').checked = false;
            selectedIds.clear();
            updateToolbar();
        }
    }

    function handleCardClick(event, id) {
        if (!bulkMode) return; // fora do modo, clique normal (links funcionam)
        // Evitar disparar quando clicar nos botões de ação internos
        if (event.target.closest('a') || event.target.closest('button')) return;
        const chk = document.querySelector(`.card-chk[data-id="${id}"]`);
        if (chk) {
            chk.checked = !chk.checked;
            onCardCheckChange(id, chk.checked);
        }
    }

    function onCardCheckChange(id, checked) {
        const card = document.querySelector(`.bulk-card[data-id="${id}"]`);
        if (checked) {
            selectedIds.add(id);
            card && card.classList.add('ring-2', 'ring-cyan-400', 'ring-offset-1', 'dark:ring-offset-slate-900');
        } else {
            selectedIds.delete(id);
            card && card.classList.remove('ring-2', 'ring-cyan-400', 'ring-offset-1', 'dark:ring-offset-slate-900');
        }
        // Sync select-all checkbox
        const allChks = document.querySelectorAll('.card-chk');
        document.getElementById('chk-select-all').checked =
            allChks.length > 0 && [...allChks].every(c => c.checked);
        updateToolbar();
    }

    function toggleSelectAll(checked) {
        document.querySelectorAll('.card-chk').forEach(chk => {
            const id = parseInt(chk.dataset.id);
            chk.checked = checked;
            onCardCheckChange(id, checked);
        });
    }

    function updateToolbar() {
        const toolbar = document.getElementById('bulk-toolbar');
        const label   = document.getElementById('bulk-count-label');
        const n = selectedIds.size;
        if (n > 0 && bulkMode) {
            toolbar.classList.remove('hidden');
            label.textContent = n + (n === 1 ? ' selecionada' : ' selecionadas');
        } else {
            toolbar.classList.add('hidden');
        }
    }

    function submitBulkForm(action, idCategoria = null) {
        const form = document.getElementById('bulk-form');
        document.getElementById('bulk-action-input').value = action;
        document.getElementById('bulk-categoria-input').value = idCategoria ?? '';
        // Popula os IDs
        const container = document.getElementById('bulk-ids-container');
        container.innerHTML = '';
        selectedIds.forEach(id => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'ids[]';
            inp.value = id;
            container.appendChild(inp);
        });
        form.submit();
    }

    function bulkConsolidar() {
        if (selectedIds.size === 0) return;
        submitBulkForm('consolidar');
    }

    // ── Modal Categoria Bulk ────────────────────────────────────────
    let bulkCatModalOpen = false;

    function openBulkCategoria() {
        if (selectedIds.size === 0) return;
        // Verificar se há transferências entre os selecionados
        let temTransferencia = false;
        selectedIds.forEach(id => {
            const card = document.querySelector(`.bulk-card[data-id="${id}"]`);
            if (card && card.dataset.isTransferencia === '1') temTransferencia = true;
        });
        const aviso = document.getElementById('bulk-cat-aviso');
        aviso.classList.toggle('hidden', !temTransferencia);
        bulkCatModalOpen = true;
        document.getElementById('modal-bulk-categoria').classList.remove('hidden');
    }

    function closeBulkCategoria() {
        bulkCatModalOpen = false;
        document.getElementById('modal-bulk-categoria').classList.add('hidden');
    }

    // selectCategoria já lida com o modo bulk internamente (ver definição acima)

    function bulkExcluir() {
        if (selectedIds.size === 0) return;
        const n = selectedIds.size;
        if (!confirm(`Excluir ${n} transaç${n === 1 ? 'ão' : 'ões'} selecionada${n === 1 ? '' : 's'}? Esta ação não pode ser desfeita.`)) return;
        submitBulkForm('excluir');
    }
</script>
</html>
