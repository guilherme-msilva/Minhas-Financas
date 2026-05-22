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
$categoria_filtro = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$conta_atual = isset($_GET['conta']) ? (int)$_GET['conta'] : 0;

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

function getCategoryPath($id, $cats_map) {
    $path = [];
    $curr = $id;
    while ($curr && isset($cats_map[$curr])) {
        array_unshift($path, $cats_map[$curr]['nome']);
        $curr = $cats_map[$curr]['id_pai'];
    }
    return implode(' > ', $path);
}

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

function buildCatTreeHtml($nodes, $selected_id = 0, $level = 0, $prefix = '') {
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
            $html .= "<span class='w-5 h-5 rounded-full flex items-center justify-center shrink-0' style='background-color:$cor'>";
            if ($icone) $html .= "<i class='ph-fill $icone text-white' style='font-size:10px'></i>";
            $html .= "</span>";
            $html .= "<span>$nome</span>";
            $html .= "<svg id='$iconId' class='w-3.5 h-3.5 ml-auto mr-2 text-slate-400 transition-transform' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'></path></svg>";
            $html .= "</button>";
            $html .= "<button type='button' onclick=\"selectCategoria($id, '$nomeJs')\" class='p-2 mr-1 rounded-lg text-slate-400 hover:text-cyan-600 hover:bg-black/5 transition-colors shrink-0' title='Selecionar esta categoria'>";
            $html .= "<svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'></path></svg>";
            $html .= "</button>";
            $html .= "</div>";
            $html .= "<div id='$childrenId' class='hidden'>";
            $html .= buildCatTreeHtml($cat['children'], $selected_id, $level + 1, $prefix);
            $html .= "</div>";
        } else {
            $html .= "<button type='button' onclick=\"selectCategoria($id, '$nomeJs')\" class='flex items-center gap-2 py-2 text-sm font-medium rounded-xl $selectedClass transition-colors w-full text-left' $pl>";
            $html .= "<span class='w-5 h-5 rounded-full flex items-center justify-center shrink-0' style='background-color:$cor'>";
            if ($icone) $html .= "<i class='ph-fill $icone text-white' style='font-size:10px'></i>";
            $html .= "</span>";
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

$tree_categorias = buildCategoryTree($all_cats);
$nome_categoria_filtro = 'Todas as Categorias';
foreach ($all_cats as $c) {
    if ($c['id'] == $categoria_filtro) {
        $nome_categoria_filtro = $c['nome'];
        break;
    }
}

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

// Colunas Dinâmicas (Meses)
$meses_colunas = [];
try {
    $start = new DateTime($data_inicio_filtro);
    $start->modify('first day of this month');
    $end = new DateTime($data_fim_filtro);
    $end->modify('last day of this month');
    
    // Adiciona +1 dia ao limite para garantir que o DatePeriod inclua o último mês
    $limit = clone $end;
    $limit->modify('+1 day');
    
    $periodo = new DatePeriod($start, new DateInterval('P1M'), $limit);
    foreach ($periodo as $dt) {
        $meses_colunas[$dt->format('Y-m')] = $dt->format('m/Y');
    }
} catch (Exception $e) {}

// Logica SQL
$conditions = ["t.iduser = ?", "t.idcategoria != -1", "t.data >= ?", "t.data <= ?"];
$params = [$user_id, $data_inicio_filtro, $data_fim_filtro];
$types = "iss";

if ($conta_atual > 0) {
    $conditions[] = "t.idconta = ?";
    $params[] = $conta_atual;
    $types .= "i";
}
if (!empty($ids_categoria_selecionados)) {
    $conditions[] = "t.idcategoria IN (" . implode(',', $ids_categoria_selecionados) . ")";
}
$where_sql = implode(" AND ", $conditions);

function buildPivotData($stmt_sql, $types, $params, $cats_map, $meses_colunas) {
    global $mysqliFinancas;
    $stmt = $mysqliFinancas->prepare($stmt_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $dados = [];
    $totais_mensais = array_fill_keys(array_keys($meses_colunas), 0);
    $total_absoluto = 0;
    
    while ($row = $res->fetch_assoc()) {
        $idcat = $row['idcategoria'];
        $chave_mes = sprintf("%04d-%02d", $row['ano'], $row['mes']);
        $valor = (float)$row['total'];
        
        if (!isset($dados[$idcat])) {
            $dados[$idcat] = [
                'path' => getCategoryPath($idcat, $cats_map),
                'total_geral' => 0,
                'mensal' => array_fill_keys(array_keys($meses_colunas), 0)
            ];
        }
        
        if (isset($dados[$idcat]['mensal'][$chave_mes])) {
            $dados[$idcat]['mensal'][$chave_mes] += $valor;
            $dados[$idcat]['total_geral'] += $valor;
            $totais_mensais[$chave_mes] += $valor;
            $total_absoluto += $valor;
        }
    }
    $stmt->close();
    
    uasort($dados, function($a, $b) {
        return strcmp($a['path'], $b['path']);
    });
    
    return [
        'linhas' => $dados,
        'totais_mensais' => $totais_mensais,
        'total_absoluto' => $total_absoluto
    ];
}

// Receitas pivot
$sql_rec = "SELECT t.idcategoria, YEAR(t.data) as ano, MONTH(t.data) as mes, SUM(t.valor) as total FROM transacoes t WHERE t.valor > 0 AND $where_sql GROUP BY t.idcategoria, YEAR(t.data), MONTH(t.data)";
$pivot_receitas = buildPivotData($sql_rec, $types, $params, $cats_map, $meses_colunas);

// Despesas pivot
$sql_desp = "SELECT t.idcategoria, YEAR(t.data) as ano, MONTH(t.data) as mes, SUM(ABS(t.valor)) as total FROM transacoes t WHERE t.valor < 0 AND $where_sql GROUP BY t.idcategoria, YEAR(t.data), MONTH(t.data)";
$pivot_despesas = buildPivotData($sql_desp, $types, $params, $cats_map, $meses_colunas);

?>
<?php 
$page_title = "Relatório - Minhas Finanças";
$extra_head = '
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<style>
@media print {
    /* Esconder elementos desnecessarios na impressao */
    nav, #form-filtros, header, button.no-print {
        display: none !important;
    }
    
    body, main, div {
        background: white !important;
        color: black !important;
    }
    
    .max-w-6xl {
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        page-break-inside: auto;
    }
    
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    
    th, td {
        border: 1px solid #ccc !important;
        color: #000 !important;
        background: transparent !important;
        padding: 6px !important;
        font-size: 10pt !important;
    }
    
    th {
        background-color: #f3f4f6 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .print-header {
        display: block !important;
        margin-bottom: 20px;
    }
    
    .shadow-lg, .shadow-sm { box-shadow: none !important; }
    .border-gray-200 { border-color: #e5e7eb !important; }
}

.print-header { display: none; }
</style>
';
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Relatório Analítico</h1>
            
            <button type="button" onclick="window.print()" class="no-print px-5 py-2.5 bg-white/80 dark:bg-white/10 hover:bg-white dark:hover:bg-white/20 text-slate-800 dark:text-white border border-gray-200 dark:border-white/20 rounded-xl font-medium shadow-sm transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                Imprimir
            </button>
        </div>

        <!-- Cabeçalho na Impressão -->
        <div class="print-header text-center">
            <h1 class="text-2xl font-bold mb-1">Relatório Mensal de Categorias</h1>
            <p class="text-sm text-gray-600">Período: <?php echo date('d/m/Y', strtotime($data_inicio_filtro)); ?> a <?php echo date('d/m/Y', strtotime($data_fim_filtro)); ?></p>
        </div>

        <!-- Painel de Filtros -->
        <div id="form-filtros" class="relative z-50 mb-8 bg-white/60 dark:bg-white/10 backdrop-blur-xl p-5 rounded-3xl border border-gray-200 dark:border-white/20 shadow-lg">
            <form method="GET" class="flex flex-col md:flex-row items-end gap-4 w-full">
                <div class="w-full md:flex-1">
                    <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Data Inicial</label>
                    <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio_filtro); ?>" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                </div>
                <div class="w-full md:flex-1">
                    <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Data Final</label>
                    <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim_filtro); ?>" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                </div>
                
                <div class="w-full md:flex-1 relative" id="cat-selector-wrapper">
                    <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Categoria</label>
                    <input type="hidden" name="categoria" id="input-categoria-filtro" value="<?php echo $categoria_filtro; ?>">
                    <button type="button" onclick="toggleCatDropdown()" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 flex items-center justify-between gap-2 text-left">
                        <span id="label-cat-filtro" class="truncate"><?php echo htmlspecialchars($nome_categoria_filtro); ?></span>
                        <svg class="w-4 h-4 shrink-0 text-slate-400 dark:text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div id="cat-dropdown" class="hidden absolute top-[calc(100%+0.25rem)] left-0 w-full min-w-[280px] max-h-80 overflow-y-auto z-[100] bg-white dark:bg-slate-800 border border-gray-200 dark:border-white/10 rounded-2xl shadow-2xl">
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

                <div class="w-full md:flex-1">
                    <label class="block text-xs font-medium text-slate-500 dark:text-white/50 mb-1">Conta</label>
                    <select name="conta" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                        <option class="text-gray-900" value="0">Todas as Contas</option>
                        <?php foreach($contas_filtro as $c): ?>
                            <option class="text-gray-900" value="<?php echo $c['id']; ?>" <?php echo $conta_atual == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="w-full md:w-auto shrink-0">
                    <button type="submit" class="w-full flex items-center justify-center gap-2 px-6 py-2 bg-cyan-500 hover:bg-cyan-400 text-white rounded-xl font-medium shadow-lg transition-colors h-[42px]">
                        Gerar Relatório
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabelas de Relatório -->
        <div class="bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-[2rem] p-6 md:p-8 shadow-lg print:border-none print:shadow-none print:bg-transparent print:p-0">
            
            <?php foreach (['Receitas' => $pivot_receitas, 'Despesas' => $pivot_despesas] as $titulo => $dados): ?>
                <?php if (count($dados['linhas']) > 0): ?>
                    <div class="mb-10 page-break-auto">
                        <h2 class="text-xl font-bold mb-4 <?php echo $titulo == 'Receitas' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'; ?> print:text-black">
                            <?php echo $titulo; ?> por Mês
                        </h2>
                        
                        <div class="overflow-x-auto rounded-2xl border border-gray-200 dark:border-white/10 shadow-sm print:border-none print:shadow-none print:rounded-none">
                            <table class="w-full text-left text-sm whitespace-nowrap">
                                <thead class="bg-gray-50 dark:bg-slate-800/50 text-slate-700 dark:text-white/80">
                                    <tr>
                                        <th class="px-5 py-4 font-semibold">Categoria</th>
                                        <th class="px-5 py-4 font-bold text-right border-r border-gray-200 dark:border-white/10">Total do Período</th>
                                        <?php foreach ($meses_colunas as $mes_display): ?>
                                            <th class="px-5 py-4 font-semibold text-right"><?php echo $mes_display; ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-white/10 text-slate-600 dark:text-white/70 bg-white dark:bg-transparent">
                                    <?php foreach ($dados['linhas'] as $linha): ?>
                                        <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors">
                                            <td class="px-5 py-3.5 font-medium text-slate-800 dark:text-white print:text-black"><?php echo htmlspecialchars($linha['path']); ?></td>
                                            <td class="px-5 py-3.5 text-right font-bold text-slate-800 dark:text-white border-r border-gray-200 dark:border-white/10">R$ <?php echo number_format($linha['total_geral'], 2, ',', '.'); ?></td>
                                            <?php foreach ($meses_colunas as $chave => $disp): ?>
                                                <td class="px-5 py-3.5 text-right font-mono text-sm">
                                                    <?php echo $linha['mensal'][$chave] > 0 ? 'R$ ' . number_format($linha['mensal'][$chave], 2, ',', '.') : '-'; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-gray-100 dark:bg-slate-800 font-bold text-slate-800 dark:text-white">
                                    <tr>
                                        <td class="px-5 py-4 uppercase tracking-wider text-xs">Total de <?php echo $titulo; ?></td>
                                        <td class="px-5 py-4 text-right border-r border-gray-200 dark:border-white/10">R$ <?php echo number_format($dados['total_absoluto'], 2, ',', '.'); ?></td>
                                        <?php foreach ($meses_colunas as $chave => $disp): ?>
                                            <td class="px-5 py-4 text-right font-mono text-sm">
                                                <?php echo $dados['totais_mensais'][$chave] > 0 ? 'R$ ' . number_format($dados['totais_mensais'][$chave], 2, ',', '.') : '-'; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="mb-8 p-6 bg-slate-50 dark:bg-white/5 rounded-2xl text-center print:hidden">
                        <p class="text-slate-500 dark:text-white/50">Nenhuma transação de <?php echo strtolower($titulo); ?> encontrada para o período.</p>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

        </div>
    </div>

    <!-- Scripts do Formulario -->
    <script>
        function toggleCatDropdown() {
            const dd = document.getElementById('cat-dropdown');
            dd.classList.toggle('hidden');
        }

        function toggleCatChildren(id) {
            const children = document.getElementById('cat-children-' + id);
            if (children) children.classList.toggle('hidden');
        }

        function selectCategoria(id, nome) {
            document.getElementById('input-categoria-filtro').value = id;
            document.getElementById('label-cat-filtro').textContent = nome;
            document.getElementById('cat-dropdown').classList.add('hidden');
        }

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
                    if (el) el.classList.remove('hidden');
                });
            }
        })();

        document.addEventListener('click', function(event) {
            const catWrapper = document.getElementById('cat-selector-wrapper');
            const catDropdown = document.getElementById('cat-dropdown');
            if (catWrapper && catDropdown && !catWrapper.contains(event.target)) {
                catDropdown.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
