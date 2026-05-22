<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Filtros Básicos
$data_inicio_filtro = isset($_GET['data_inicio']) && trim($_GET['data_inicio']) !== '' ? trim($_GET['data_inicio']) : date('Y-m-01');
$data_fim_filtro = isset($_GET['data_fim']) && trim($_GET['data_fim']) !== '' ? trim($_GET['data_fim']) : date('Y-m-d');
$tipo_grafico = isset($_GET['tipo_grafico']) ? $_GET['tipo_grafico'] : 'pizza';
$categoria_filtro = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$conta_atual = isset($_GET['conta']) ? (int)$_GET['conta'] : 0;
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'despesas';

$valid_tipos_grafico = ['pizza', 'barra', 'linha', 'saldo'];
if (!in_array($tipo_grafico, $valid_tipos_grafico)) $tipo_grafico = 'pizza';

// Busca contas
$stmt_contas = $mysqliFinancas->prepare("SELECT id, nome FROM contas WHERE id_user = ? and status = 1 ORDER BY nome");
$stmt_contas->bind_param("i", $user_id);
$stmt_contas->execute();
$contas_filtro = $stmt_contas->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_contas->close();

// Busca categorias
$stmt_cats = $mysqliFinancas->prepare("SELECT id, nome, id_pai, icone, cor FROM categorias WHERE id_user = ? ORDER BY nome");
$stmt_cats->bind_param("i", $user_id);
$stmt_cats->execute();
$all_cats = $stmt_cats->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_cats->close();

$cats_map = [];
foreach ($all_cats as $c) {
    $cats_map[$c['id']] = $c;
}

// Resolução de Categorias (ícones, cor)
function resolveAtributosCategoria($id_categoria, $cats_map) {
    $atual = $id_categoria;
    $icone = '';
    $cor = '';
    while ($atual && isset($cats_map[$atual])) {
        if (empty($icone) && !empty($cats_map[$atual]['icone'])) $icone = $cats_map[$atual]['icone'];
        if (empty($cor) && !empty($cats_map[$atual]['cor'])) $cor = $cats_map[$atual]['cor'];
        if (!empty($icone) && !empty($cor)) break;
        $atual = $cats_map[$atual]['id_pai'];
    }
    if (empty($cor)) $cor = '#ccc';
    return ['icone' => $icone, 'cor' => $cor];
}

foreach ($all_cats as &$c) {
    $atributos = resolveAtributosCategoria($c['id'], $cats_map);
    $c['icone_resolvido'] = $atributos['icone'];
    $c['cor_resolvida']   = $atributos['cor'];
    $cats_map[$c['id']] = $c;
}
unset($c);

// Árvore de Categoria UI
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
    if ($icone) $html .= "<i class='ph-fill $icone text-white' style='font-size:10px'></i>";
    $html .= "</span>";
    return $html;
}

$tree_categorias = buildCategoryTree($all_cats);
$nome_categoria_filtro = 'Todas as Categorias';
foreach ($all_cats as $c) {
    if ($c['id'] == $categoria_filtro) {
        $nome_categoria_filtro = $c['nome'];
        break;
    }
}

// Extrair IDs de Subcategorias
$ids_categoria_selecionados = [];
if ($categoria_filtro > 0) {
    $ids_categoria_selecionados = [$categoria_filtro];
    $fila = [$categoria_filtro];
    while (!empty($fila)) {
        $id_atual = array_shift($fila);
        foreach ($all_cats as $c) {
            if ($c['id_pai'] == $id_atual) {
                $ids_categoria_selecionados[] = $c['id'];
                $fila[] = $c['id'];
            }
        }
    }
}

// Funções para cor do gráfico de pizza
function generateHarmonicColor($index, $total) {
    if ($total <= 0) $total = 1;
    $hue = ($index * (360 / $total)) % 360;
    $saturation = 75 - (($index % 2) * 15); 
    $lightness = 55 + (($index % 3) * 5);
    return "hsl({$hue}, {$saturation}%, {$lightness}%)";
}

function buildGraphDataLocal($agrupadas, $cats_map_grafico, $cats_grafico) {
    $dados_grafico = ['root' => ['labels' => [], 'data' => [], 'backgroundColor' => [], 'ids' => []], 'drilldown' => []];
    $mapa_raiz = [];
    $totais_por_raiz = [];
    
    foreach ($cats_grafico as $c) {
        $id = $c['id'];
        $atual = $id;
        while (!empty($cats_map_grafico[$atual]['id_pai'])) {
            $atual = $cats_map_grafico[$atual]['id_pai'];
        }
        $mapa_raiz[$id] = $atual;
        if (!isset($dados_grafico['drilldown'][$atual])) {
            $dados_grafico['drilldown'][$atual] = ['labels' => [], 'data' => [], 'backgroundColor' => [], 'ids' => [], 'nome_raiz' => $cats_map_grafico[$atual]['nome']];
        }
    }

    foreach ($agrupadas as $id_cat => $valor) {
        if (!isset($mapa_raiz[$id_cat])) continue;
        $id_raiz = $mapa_raiz[$id_cat];
        
        if (!isset($totais_por_raiz[$id_raiz])) $totais_por_raiz[$id_raiz] = 0;
        $totais_por_raiz[$id_raiz] += $valor;
        
        $nome = $cats_map_grafico[$id_cat]['nome'] . ($id_cat == $id_raiz ? ' (Geral)' : '');
        $dados_grafico['drilldown'][$id_raiz]['labels'][] = $nome;
        $dados_grafico['drilldown'][$id_raiz]['data'][] = $valor;
        $dados_grafico['drilldown'][$id_raiz]['ids'][] = $id_cat;
    }

    foreach ($dados_grafico['drilldown'] as $id_raiz => &$drill) {
        $total_drills = count($drill['ids']);
        foreach ($drill['ids'] as $idx => $id_cat) {
            $drill['backgroundColor'][] = generateHarmonicColor($idx, $total_drills);
        }
    }
    unset($drill);

    $color_index_root = 0;
    $total_roots = count(array_filter($totais_por_raiz, fn($val) => $val > 0));
    foreach ($totais_por_raiz as $id_raiz => $total) {
        if ($total > 0) {
            $cor = generateHarmonicColor($color_index_root++, $total_roots);
            $dados_grafico['root']['labels'][] = $cats_map_grafico[$id_raiz]['nome'];
            $dados_grafico['root']['data'][] = $total;
            $dados_grafico['root']['backgroundColor'][] = $cor;
            $dados_grafico['root']['ids'][] = $id_raiz;
        }
    }
    return $dados_grafico;
}

// ── OBTENÇÃO DOS DADOS DO GRÁFICO ──────────────────────────────────────────
$json_chart_data = "null";
$has_data = false;

if ($tipo_grafico === 'pizza') {
    $conditions = ["t.iduser = ?", "t.idcategoria != -1", "t.data >= ?", "t.data <= ?"];
    $params = [$user_id, $data_inicio_filtro, $data_fim_filtro];
    $types = "iss";
    
    if ($conta_atual > 0) {
        $conditions[] = "t.idconta = ?";
        $params[] = $conta_atual;
        $types .= "i";
    }
    if ($tipo_filtro === 'despesas') {
        $conditions[] = "t.valor < 0";
    } else {
        $conditions[] = "t.valor > 0";
    }
    if (!empty($ids_categoria_selecionados)) {
        $conditions[] = "t.idcategoria IN (" . implode(',', $ids_categoria_selecionados) . ")";
    }
    
    $where_sql = implode(" AND ", $conditions);
    $sql_pizza = "SELECT t.idcategoria, SUM(ABS(t.valor)) as total FROM transacoes t WHERE $where_sql GROUP BY t.idcategoria";
    
    $stmt = $mysqliFinancas->prepare($sql_pizza);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $agrupadas = [];
    while ($row = $res->fetch_assoc()) {
        $agrupadas[$row['idcategoria']] = $row['total'];
    }
    $stmt->close();
    
    $chart_obj = buildGraphDataLocal($agrupadas, $cats_map, $all_cats);
    $json_chart_data = json_encode($chart_obj);
    $has_data = count($agrupadas) > 0;

} elseif ($tipo_grafico === 'barra' || $tipo_grafico === 'linha') {
    $conditions = ["t.iduser = ?", "t.idcategoria != -1", "t.data >= ?", "t.data <= ?"];
    $params = [$user_id, $data_inicio_filtro, $data_fim_filtro];
    $types = "iss";
    
    if ($conta_atual > 0) {
        $conditions[] = "t.idconta = ?";
        $params[] = $conta_atual;
        $types .= "i";
    }
    if ($tipo_filtro === 'despesas') {
        $conditions[] = "t.valor < 0";
    } else {
        $conditions[] = "t.valor > 0";
    }
    if (!empty($ids_categoria_selecionados)) {
        $conditions[] = "t.idcategoria IN (" . implode(',', $ids_categoria_selecionados) . ")";
    }
    
    $where_sql = implode(" AND ", $conditions);
    $sql_linha = "SELECT t.data, SUM(ABS(t.valor)) as total FROM transacoes t WHERE $where_sql GROUP BY t.data ORDER BY t.data ASC";
    
    $stmt = $mysqliFinancas->prepare($sql_linha);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $db_data = [];
    while ($row = $res->fetch_assoc()) {
        $db_data[$row['data']] = $row['total'];
    }
    $stmt->close();
    
    $labels = [];
    $data_points = [];
    
    $start = new DateTime($data_inicio_filtro);
    $end = new DateTime($data_fim_filtro);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $daterange = new DatePeriod($start, $interval, $end);
    
    foreach ($daterange as $date) {
        $dtStr = $date->format('Y-m-d');
        $labels[] = $date->format('d/m/Y');
        $data_points[] = isset($db_data[$dtStr]) ? $db_data[$dtStr] : 0;
    }
    
    $json_chart_data = json_encode([
        'labels' => $labels,
        'data' => $data_points,
        'cor' => $tipo_filtro === 'despesas' ? '#ef4444' : '#10b981',
        'fillCor' => $tipo_filtro === 'despesas' ? 'rgba(239,68,68,0.2)' : 'rgba(16,185,129,0.2)'
    ]);
    $has_data = count($db_data) > 0;

} elseif ($tipo_grafico === 'saldo') {
    // 1. Saldo Base das Contas
    $sql_baseline = "SELECT COALESCE(SUM(saldo_inicial), 0) FROM contas WHERE id_user = ?";
    if ($conta_atual > 0) $sql_baseline .= " AND id = ?"; else $sql_baseline .= " AND status = 1";
    
    $stmt = $mysqliFinancas->prepare($sql_baseline);
    if ($conta_atual > 0) $stmt->bind_param("ii", $user_id, $conta_atual); else $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $saldo_base = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    
    // 2. Transações anteriores ao filtro
    $sql_before = "SELECT COALESCE(SUM(valor), 0) FROM transacoes WHERE iduser = ? AND data < ?";
    if ($conta_atual > 0) $sql_before .= " AND idconta = ?";
    $stmt = $mysqliFinancas->prepare($sql_before);
    if ($conta_atual > 0) $stmt->bind_param("isi", $user_id, $data_inicio_filtro, $conta_atual); else $stmt->bind_param("is", $user_id, $data_inicio_filtro);
    $stmt->execute();
    $saldo_before_trans = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    
    $saldo_atual_loop = $saldo_base + $saldo_before_trans;
    
    // 3. Transações do período diárias
    $sql_period = "SELECT data, SUM(valor) as variacao FROM transacoes WHERE iduser = ? AND data >= ? AND data <= ?";
    if ($conta_atual > 0) $sql_period .= " AND idconta = ?";
    $sql_period .= " GROUP BY data ORDER BY data ASC";
    
    $stmt = $mysqliFinancas->prepare($sql_period);
    if ($conta_atual > 0) $stmt->bind_param("issi", $user_id, $data_inicio_filtro, $data_fim_filtro, $conta_atual); else $stmt->bind_param("iss", $user_id, $data_inicio_filtro, $data_fim_filtro);
    $stmt->execute();
    $res = $stmt->get_result();
    $db_data = [];
    while ($row = $res->fetch_assoc()) {
        $db_data[$row['data']] = $row['variacao'];
    }
    $stmt->close();
    
    // 4. Preencher todos os dias do range
    $labels = [];
    $data_points = [];
    
    $start = new DateTime($data_inicio_filtro);
    $end = new DateTime($data_fim_filtro);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $daterange = new DatePeriod($start, $interval, $end);
    
    foreach ($daterange as $date) {
        $dtStr = $date->format('Y-m-d');
        $labels[] = $date->format('d/m/Y');
        $variacao = isset($db_data[$dtStr]) ? $db_data[$dtStr] : 0;
        $saldo_atual_loop += $variacao;
        $data_points[] = $saldo_atual_loop;
    }
    
    // Cor dinâmica: se terminar negativo, vermelho, senão azul
    $is_negative = end($data_points) < 0;
    
    $json_chart_data = json_encode([
        'labels' => $labels,
        'data' => $data_points,
        'cor' => $is_negative ? '#f97316' : '#3b82f6',
        'fillCor' => $is_negative ? 'rgba(249,115,22,0.2)' : 'rgba(59,130,246,0.2)'
    ]);
    $has_data = true;
}
?>
<?php 
$page_title = "Gráficos - Minhas Finanças";
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script src="https://unpkg.com/@phosphor-icons/web"></script>';
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Análise Visual</h1>
        </div>

        <!-- Painel de Filtros -->
        <div class="relative z-50 mb-8 bg-white/60 dark:bg-white/10 backdrop-blur-xl p-5 rounded-3xl border border-gray-200 dark:border-white/20 shadow-lg">
            <form method="GET" id="form-filtros" class="flex flex-col gap-4 w-full">
                <!-- Linha 1: Data / Tipo de Gráfico / Filtrar -->
                <div class="flex flex-col sm:flex-row items-end gap-3 w-full">
                    <div class="w-full sm:flex-1">
                        <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Data Inicial</label>
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio_filtro); ?>" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div class="w-full sm:flex-1">
                        <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Data Final</label>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim_filtro); ?>" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                    </div>
                    <div class="w-full sm:flex-[1.5]">
                        <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Tipo de Gráfico</label>
                        <select name="tipo_grafico" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                            <option class="text-gray-900" value="pizza" <?php echo $tipo_grafico == 'pizza' ? 'selected' : ''; ?>>Despesas/Receitas (Pizza)</option>
                            <option class="text-gray-900" value="barra" <?php echo $tipo_grafico == 'barra' ? 'selected' : ''; ?>>Despesas/Receitas (Barra)</option>
                            <option class="text-gray-900" value="linha" <?php echo $tipo_grafico == 'linha' ? 'selected' : ''; ?>>Despesas/Receitas (Linha)</option>
                            <option class="text-gray-900" value="saldo" <?php echo $tipo_grafico == 'saldo' ? 'selected' : ''; ?>>Saldo em Contas (Linha)</option>
                        </select>
                    </div>
                    <div class="w-full sm:w-auto shrink-0">
                        <button type="submit" class="w-full flex items-center justify-center gap-2 px-6 py-2 bg-cyan-500 hover:bg-cyan-400 text-white rounded-xl font-medium shadow-lg transition-colors h-[42px]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            Atualizar
                        </button>
                    </div>
                </div>

                <!-- Linha 2: Categoria / Contas / Receitas ou Despesas -->
                <div class="flex flex-col sm:flex-row items-center gap-3 w-full border-t border-gray-200 dark:border-white/10 pt-4 mt-1">
                    <!-- Árvore de Categorias -->
                    <div class="relative w-full sm:flex-1 min-w-[200px]" id="cat-selector-wrapper">
                        <input type="hidden" name="categoria" id="input-categoria-filtro" value="<?php echo $categoria_filtro; ?>">
                        <button type="button" onclick="toggleCatDropdown()" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 flex items-center justify-between gap-2">
                            <span id="label-cat-filtro" class="truncate"><?php echo htmlspecialchars($nome_categoria_filtro); ?></span>
                            <svg class="w-4 h-4 shrink-0 text-slate-400 dark:text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div id="cat-dropdown" class="hidden absolute top-full left-0 mt-2 w-full sm:w-80 max-h-80 overflow-y-auto z-[100] bg-white dark:bg-slate-800 border border-gray-200 dark:border-white/10 rounded-2xl shadow-2xl">
                            <div class="p-2">
                                <button type="button" onclick="selectCategoria(0, 'Todas as Categorias')" class="w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-white/10 text-slate-600 dark:text-white/70 text-sm font-medium transition-colors flex items-center gap-2">
                                    <span class="w-5 h-5 rounded-full bg-slate-200 dark:bg-white/20 flex items-center justify-center shrink-0">
                                        <svg class="w-3 h-3 text-slate-500 dark:text-white/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                                    </span>
                                    Todas as Categorias
                                </button>
                                <div id="cat-tree-root" class="mt-1 space-y-0.5">
                                    <?php echo buildCatTreeHtml($tree_categorias, $categoria_filtro); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <select name="conta" class="w-full sm:flex-1 bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                        <option class="text-gray-900" value="0">Todas as Contas</option>
                        <?php foreach($contas_filtro as $c): ?>
                            <option class="text-gray-900" value="<?php echo $c['id']; ?>" <?php echo $conta_atual == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="tipo" class="w-full sm:flex-1 bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                        <option class="text-gray-900" value="despesas" <?php echo $tipo_filtro == 'despesas' ? 'selected' : ''; ?>>Filtrar Despesas</option>
                        <option class="text-gray-900" value="receitas" <?php echo $tipo_filtro == 'receitas' ? 'selected' : ''; ?>>Filtrar Receitas</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- Área do Gráfico -->
        <div class="bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-[2rem] p-6 md:p-10 shadow-lg relative min-h-[450px]">
            <?php if($has_data): ?>
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-slate-800 dark:text-white/80 font-semibold text-xl ml-2">
                        <?php 
                            if ($tipo_grafico == 'pizza') echo 'Distribuição por Categoria';
                            else if ($tipo_grafico == 'barra') echo 'Série Diária (Barras)';
                            else if ($tipo_grafico == 'linha') echo 'Evolução Diária (Linha)';
                            else if ($tipo_grafico == 'saldo') echo 'Evolução de Saldo Diário';
                        ?>
                    </h3>
                    <button id="btnVoltarGrafico" class="hidden px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white/80 rounded-xl text-sm font-medium transition-all flex items-center shadow-sm">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                        Voltar (Geral)
                    </button>
                </div>

                <div class="relative h-[350px] md:h-[500px] w-full flex justify-center">
                    <canvas id="mainChart"></canvas>
                </div>
            <?php else: ?>
                <div class="flex flex-col items-center justify-center h-[300px] text-center">
                    <div class="w-16 h-16 bg-slate-100 dark:bg-white/5 rounded-full flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-slate-400 dark:text-white/30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    </div>
                    <p class="text-slate-500 dark:text-white/50 font-medium">Não há dados suficientes para gerar o gráfico neste período.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts da Interface -->
    <script>
        // Funções da Árvore de Categorias
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

        function selectCategoria(id, nome) {
            document.getElementById('input-categoria-filtro').value = id;
            document.getElementById('label-cat-filtro').textContent = nome;
            document.getElementById('cat-dropdown').classList.add('hidden');
        }

        // Auto-expandir árvore na seleção atual
        (function autoExpandSelectedCat() {
            const catFiltro = <?php echo $categoria_filtro; ?>;
            if (catFiltro > 0) {
                const catParentMap = <?php
                    $map = [];
                    foreach ($all_cats as $c) {
                        if ($c['id_pai']) $map[$c['id']] = (int)$c['id_pai'];
                    }
                    echo json_encode($map);
                ?>;
                let cur = catFiltro;
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
            }
        })();

        // Fechar dropdown ao clicar fora
        document.addEventListener('click', function(event) {
            const catWrapper = document.getElementById('cat-selector-wrapper');
            const catDropdown = document.getElementById('cat-dropdown');
            if (catWrapper && catDropdown && !catWrapper.contains(event.target)) {
                catDropdown.classList.add('hidden');
            }
        });

        // ==========================================
        // Inicialização do Chart.js
        // ==========================================
        const hasData = <?php echo $has_data ? 'true' : 'false'; ?>;
        
        if (hasData) {
            const tipoGrafico = "<?php echo $tipo_grafico; ?>";
            const chartData = <?php echo $json_chart_data; ?>;
            const isDark = document.documentElement.classList.contains('dark');
            const textColor = isDark ? '#f8fafc' : '#1e293b';
            const gridColor = isDark ? 'rgba(255,255,255,0.05)' : 'rgba(15,23,42,0.05)';
            const tooltipBg = isDark ? '#1e293b' : '#ffffff';
            
            const ctx = document.getElementById('mainChart').getContext('2d');
            let currentChart = null;
            let isDrilldown = false;

            if (tipoGrafico === 'pizza') {
                // Logica do gráfico de Pizza (com drilldown)
                function renderPieChart(dataObj) {
                    if (currentChart) currentChart.destroy();
                    currentChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: dataObj.labels,
                            datasets: [{
                                data: dataObj.data,
                                backgroundColor: dataObj.backgroundColor,
                                borderWidth: 2,
                                borderColor: isDark ? 'rgba(30, 41, 59, 0.8)' : '#ffffff',
                                hoverOffset: 12
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: window.innerWidth > 768 ? 'right' : 'bottom',
                                    labels: {
                                        color: textColor,
                                        font: { family: 'Outfit', size: 14 },
                                        padding: 20,
                                        generateLabels: function(chart) {
                                            const data = chart.data;
                                            if (data.labels.length && data.datasets.length) {
                                                const total = data.datasets[0].data.reduce((a, b) => parseFloat(a) + parseFloat(b), 0);
                                                return data.labels.map(function(label, i) {
                                                    const meta = chart.getDatasetMeta(0);
                                                    const style = meta.controller.getStyle(i);
                                                    const value = parseFloat(data.datasets[0].data[i]);
                                                    const pct = total > 0 ? ((value * 100) / total).toFixed(1) : 0;
                                                    const formattedValue = value.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                                    
                                                    return {
                                                        text: `${label} - R$ ${formattedValue} (${pct}%)`,
                                                        fillStyle: style.backgroundColor,
                                                        strokeStyle: style.borderColor,
                                                        lineWidth: style.borderWidth,
                                                        hidden: isNaN(data.datasets[0].data[i]) || meta.data[i].hidden,
                                                        color: textColor,
                                                        fontColor: textColor,
                                                        index: i
                                                    };
                                                });
                                            }
                                            return [];
                                        }
                                    }
                                },
                                tooltip: {
                                    backgroundColor: tooltipBg,
                                    titleColor: textColor,
                                    bodyColor: textColor,
                                    borderColor: gridColor,
                                    borderWidth: 1,
                                    padding: 12,
                                    cornerRadius: 12,
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.label || '';
                                            if (label) label += ': ';
                                            const valor = parseFloat(context.parsed || context.raw);
                                            const total = context.dataset.data.reduce((acc, val) => acc + parseFloat(val), 0);
                                            const pct = total > 0 ? ((valor * 100) / total).toFixed(1) : 0;
                                            label += 'R$ ' + valor.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + ' (' + pct + '%)';
                                            return label;
                                        }
                                    }
                                }
                            },
                            onClick: (e, elements) => {
                                if (elements.length > 0 && !isDrilldown) {
                                    const index = elements[0].index;
                                    const id_raiz = dataObj.ids[index];
                                    if (chartData.drilldown[id_raiz] && chartData.drilldown[id_raiz].data.length > 0) {
                                        if(chartData.drilldown[id_raiz].data.length === 1 && chartData.drilldown[id_raiz].labels[0].includes('(Geral)')) {
                                            return;
                                        }
                                        isDrilldown = true;
                                        document.getElementById('btnVoltarGrafico').classList.remove('hidden');
                                        renderPieChart(chartData.drilldown[id_raiz]);
                                    }
                                }
                            }
                        }
                    });
                }
                
                renderPieChart(chartData.root);
                
                document.getElementById('btnVoltarGrafico').addEventListener('click', () => {
                    isDrilldown = false;
                    document.getElementById('btnVoltarGrafico').classList.add('hidden');
                    renderPieChart(chartData.root);
                });

            } else if (tipoGrafico === 'barra') {
                // Gráfico de Barras Diárias
                currentChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: 'Total R$',
                            data: chartData.data,
                            backgroundColor: chartData.cor,
                            borderRadius: 6,
                            barPercentage: 0.7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: tooltipBg,
                                titleColor: textColor,
                                bodyColor: textColor,
                                borderColor: gridColor,
                                borderWidth: 1,
                                padding: 12,
                                cornerRadius: 12,
                                callbacks: {
                                    label: function(context) {
                                        return 'R$ ' + context.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: isDark ? '#94a3b8' : '#64748b', font: { family: 'Outfit' } } },
                            y: { grid: { color: gridColor }, ticks: { color: isDark ? '#94a3b8' : '#64748b', font: { family: 'Outfit' }, callback: function(value) { return 'R$ ' + value; } }, beginAtZero: true }
                        }
                    }
                });

            } else if (tipoGrafico === 'linha' || tipoGrafico === 'saldo') {
                // Gráfico de Linha ou Saldo
                currentChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: 'Valor R$',
                            data: chartData.data,
                            borderColor: chartData.cor,
                            backgroundColor: chartData.fillCor,
                            borderWidth: 3,
                            pointBackgroundColor: tooltipBg,
                            pointBorderColor: chartData.cor,
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            fill: true,
                            tension: 0.4 // Linha curva suave
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: tooltipBg,
                                titleColor: textColor,
                                bodyColor: textColor,
                                borderColor: gridColor,
                                borderWidth: 1,
                                padding: 12,
                                cornerRadius: 12,
                                callbacks: {
                                    label: function(context) {
                                        return 'R$ ' + context.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: isDark ? '#94a3b8' : '#64748b', font: { family: 'Outfit' } } },
                            y: { grid: { color: gridColor }, ticks: { color: isDark ? '#94a3b8' : '#64748b', font: { family: 'Outfit' }, callback: function(value) { return 'R$ ' + value; } } }
                        }
                    }
                });
            }
        }
    </script>
</body>
</html>
