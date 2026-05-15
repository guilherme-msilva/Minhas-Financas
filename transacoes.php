<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

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
    $mes_redir = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
    $ano_redir = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
    header("Location: transacoes.php?mes=$mes_redir&ano=$ano_redir");
    exit;
}

// Filtro de Mês/Ano e Ordenação e Conta
$mes_atual = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano_atual = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
$ordem_atual = isset($_GET['ordem']) && strtoupper($_GET['ordem']) == 'ASC' ? 'ASC' : 'DESC';
$conta_atual = isset($_GET['conta']) ? (int)$_GET['conta'] : 0;

// Filtros Avançados
$categoria_filtro = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$incluir_subcats = isset($_GET['incluir_subcats']) ? (int)$_GET['incluir_subcats'] : 1;
$data_inicio_filtro = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
$data_fim_filtro = isset($_GET['data_fim']) ? trim($_GET['data_fim']) : '';
$descricao_filtro = isset($_GET['descricao']) ? trim($_GET['descricao']) : '';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todas';

$has_advanced_filter = (!empty($data_inicio_filtro) || !empty($data_fim_filtro) || !empty($descricao_filtro));

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

if (!empty($data_inicio_filtro) || !empty($data_fim_filtro) || !empty($descricao_filtro)) {
    if (!empty($data_inicio_filtro)) {
        $conditions[] = "t.data >= ?";
        $params[] = $data_inicio_filtro;
        $types .= "s";
    }
    if (!empty($data_fim_filtro)) {
        $conditions[] = "t.data <= ?";
        $params[] = $data_fim_filtro;
        $types .= "s";
    }
} else {
    if ($mes_atual > 0) {
        $conditions[] = "MONTH(t.data) = ?";
        $params[] = $mes_atual;
        $types .= "i";
    }
    if ($ano_atual > 0) {
        $conditions[] = "YEAR(t.data) = ?";
        $params[] = $ano_atual;
        $types .= "i";
    }
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

$sql = "
    SELECT t.id, t.data, t.valor, t.descricao, t.consolidada, t.idcategoria, t.idpai, t.parcela_recorrencia, t.parcela_fim, t.id_grupo_recorrencia, 
           c.nome as categoria_nome, c.cor as categoria_cor, c.icone as categoria_icone, co.nome as conta_nome, co.img as conta_img, co.cor as conta_cor,
           (SELECT co2.nome FROM transacoes t2 JOIN contas co2 ON t2.idconta = co2.id WHERE t2.idpai = t.id LIMIT 1) as conta_destino_nome_db,
           (SELECT co2.img FROM transacoes t2 JOIN contas co2 ON t2.idconta = co2.id WHERE t2.idpai = t.id LIMIT 1) as conta_destino_img_db,
           (SELECT co2.cor FROM transacoes t2 JOIN contas co2 ON t2.idconta = co2.id WHERE t2.idpai = t.id LIMIT 1) as conta_destino_cor_db,
           (SELECT co3.nome FROM transacoes t3 JOIN contas co3 ON t3.idconta = co3.id WHERE t3.id = t.idpai LIMIT 1) as conta_origem_nome_db,
           (SELECT co3.img FROM transacoes t3 JOIN contas co3 ON t3.idconta = co3.id WHERE t3.id = t.idpai LIMIT 1) as conta_origem_img_db,
           (SELECT co3.cor FROM transacoes t3 JOIN contas co3 ON t3.idconta = co3.id WHERE t3.id = t.idpai LIMIT 1) as conta_origem_cor_db
    FROM transacoes t
    LEFT JOIN categorias c ON t.idcategoria = c.id
    LEFT JOIN contas co ON t.idconta = co.id
    WHERE $where_clause
    ORDER BY t.data $ordem_atual, t.id ASC
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

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// Busca anos que possuem transações
$sql_anos = "SELECT DISTINCT YEAR(data) as ano FROM transacoes WHERE iduser = ? ORDER BY ano DESC";
$stmt_anos = $mysqliFinancas->prepare($sql_anos);
$stmt_anos->bind_param("i", $user_id);
$stmt_anos->execute();
$res_anos = $stmt_anos->get_result();
$anos_disponiveis = [];
while($row = $res_anos->fetch_assoc()) {
    if ($row['ano']) {
        $anos_disponiveis[] = (int)$row['ano'];
    }
}
$stmt_anos->close();

// Garante que o ano atual sempre esteja na lista, para permitir inserções futuras
$ano_vigente = (int)date('Y');
if (!in_array($ano_vigente, $anos_disponiveis)) {
    $anos_disponiveis[] = $ano_vigente;
    rsort($anos_disponiveis);
}

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

function buildCatTreeHtml(array $nodes, $selected_id = 0, $level = 0) {
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

        $html .= "<div class='flex flex-col'>";

        if ($hasChildren) {
            // Linha com botão de expand + botão de seleção separado
            $html .= "<div class='flex items-center rounded-xl $selectedClass transition-colors'>";
            // Área clicável p/ expandir
            $html .= "<button type='button' onclick='toggleCatChildren($id)' class='flex items-center gap-2 flex-1 py-2 text-sm font-medium text-left' $pl>";
            $html .= buildCatIconHtml($cor, $icone);
            $html .= "<span>$nome</span>";
            $html .= "<svg id='cat-icon-$id' class='w-3.5 h-3.5 ml-auto mr-2 text-slate-400 dark:text-white/40 transition-transform duration-200' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'></path></svg>";
            $html .= "</button>";
            // Botão de selecionar a categoria pai
            $html .= "<button type='button' onclick=\"selectCategoria($id, '$nomeJs')\" class='p-2 mr-1 rounded-lg text-slate-400 dark:text-white/40 hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-black/5 dark:hover:bg-white/10 transition-colors shrink-0' title='Selecionar esta categoria'>";
            $html .= "<svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'></path></svg>";
            $html .= "</button>";
            $html .= "</div>";
            // Filhos ocultos inicialmente
            $html .= "<div id='cat-children-$id' class='hidden'>";
            $html .= buildCatTreeHtml($cat['children'], $selected_id, $level + 1);
            $html .= "</div>";
        } else {
            // Categoria folha — clicar seleciona diretamente
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
$nome_categoria_filtro = 'Todas as Categorias';
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
        <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide mb-6">Transações</h1>
        
        <!-- Filtros -->
        <div class="relative z-50 mb-8 bg-white/60 dark:bg-white/10 backdrop-blur-xl p-4 rounded-3xl border border-gray-200 dark:border-white/20 shadow-lg">
            <form method="GET" class="flex flex-wrap items-center justify-start gap-3 w-full">
                <input type="hidden" id="ordem-input" name="ordem" value="<?php echo $ordem_atual; ?>">
                
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

                <!-- Seletor de Categoria Hierárquico -->
                <div class="relative w-full sm:flex-1 sm:min-w-[180px]" id="cat-selector-wrapper">
                    <input type="hidden" name="categoria" id="input-categoria-filtro" value="<?php echo $categoria_filtro; ?>">
                    <button type="button" onclick="toggleCatDropdown()" id="btn-cat-selector" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 flex items-center justify-between gap-2">
                        <span id="label-cat-filtro" class="truncate"><?php echo htmlspecialchars($nome_categoria_filtro); ?></span>
                        <svg class="w-4 h-4 shrink-0 text-slate-400 dark:text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>

                    <!-- Dropdown Hierárquico -->
                    <div id="cat-dropdown" class="hidden absolute top-full left-0 mt-2 w-72 max-h-80 overflow-y-auto z-[100] bg-white dark:bg-slate-800 border border-gray-200 dark:border-white/10 rounded-2xl shadow-2xl">
                        <div class="p-2">
                            <!-- Opção: Todas -->
                            <button type="button" onclick="selectCategoria(0, 'Todas as Categorias')" class="w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-white/10 text-slate-600 dark:text-white/70 text-sm font-medium transition-colors flex items-center gap-2">
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

                <!-- Seletor de Mês/Ano (Estilo Dashboard) -->
                <div class="relative w-full sm:w-auto z-50">
                    <button type="button" onclick="toggleDateSelect()" class="w-full sm:w-auto bg-white/50 hover:bg-white/60 dark:bg-white/5 dark:hover:bg-white/10 border border-gray-200 dark:border-white/10 px-4 py-2 rounded-xl flex items-center justify-between space-x-3 transition-colors cursor-pointer text-slate-800 dark:text-white focus:outline-none min-w-[180px]">
                        <?php 
                        $meses_nomes = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                        echo ($mes_atual > 0 ? $meses_nomes[$mes_atual] : 'Todos') . ' de ' . $ano_atual; 
                        ?>
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    
                    <div id="date-selector" class="absolute top-full right-0 mt-2 w-56 z-50 bg-white/95 dark:bg-slate-800/95 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl shadow-2xl overflow-hidden hidden opacity-0 transition-opacity duration-200">
                        <div class="p-2 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                            <button type="button" onclick="mudarAno(-1)" class="p-1 text-slate-400 hover:text-slate-800 dark:text-white/50 dark:hover:text-white transition-colors"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg></button>
                            <span class="text-slate-800 dark:text-white font-semibold text-sm" id="display-ano-dropdown"><?php echo $ano_atual; ?></span>
                            <button type="button" onclick="mudarAno(1)" class="p-1 text-slate-400 hover:text-slate-800 dark:text-white/50 dark:hover:text-white transition-colors"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
                        </div>
                        <div class="max-h-60 overflow-y-auto no-scrollbar grid grid-cols-2 gap-1 p-2">
                            <button type="button" onclick="selecionarData(0)" class="col-span-2 py-2 px-1 text-xs font-medium rounded-lg <?php echo (0 == $mes_atual) ? 'bg-cyan-500 text-white' : 'text-slate-600 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white'; ?> transition-colors text-center">
                                Todos
                            </button>
                            <?php for($i=1; $i<=12; $i++): ?>
                                <button type="button" onclick="selecionarData(<?php echo $i; ?>)" class="py-2 px-1 text-xs font-medium rounded-lg <?php echo ($i == $mes_atual) ? 'bg-cyan-500 text-white' : 'text-slate-600 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white'; ?> transition-colors text-center">
                                    <?php echo $meses_nomes[$i]; ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="mes" id="input-mes" value="<?php echo $mes_atual; ?>">
                <input type="hidden" name="ano" id="input-ano" value="<?php echo $ano_atual; ?>">
                
                <button type="button" onclick="document.getElementById('ordem-input').value = '<?php echo $ordem_atual == 'DESC' ? 'ASC' : 'DESC'; ?>'; this.form.submit();" class="p-2 bg-white/50 hover:bg-white/60 dark:bg-white/10 dark:hover:bg-white/20 rounded-xl transition-colors border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white" title="Inverter Ordem">
                    <?php if($ordem_atual == 'DESC'): ?>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-4v12m0 0l-4-4m4 4l4-4"></path></svg>
                    <?php endif; ?>
                </button>
                
                <button type="button" onclick="document.getElementById('filtros-avancados').classList.toggle('hidden')" class="p-2 bg-white/50 hover:bg-white/60 dark:bg-white/10 dark:hover:bg-white/20 rounded-xl transition-colors border border-gray-200 dark:border-white/10 text-cyan-600 dark:text-cyan-400 <?php echo $has_advanced_filter ? 'bg-white dark:bg-white/20 border-cyan-400/50 shadow-[0_0_10px_rgba(34,211,238,0.2)]' : ''; ?>" title="Filtros Avançados">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                </button>
                
                <!-- Painel de Filtros Avançados -->
                <div id="filtros-avancados" class="w-full mt-4 bg-white/60 dark:bg-white/5 p-4 rounded-2xl border border-gray-200 dark:border-white/10 <?php echo $has_advanced_filter ? '' : 'hidden'; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-1">
                            <label class="block text-xs font-medium text-slate-600 dark:text-white/70 mb-1">Buscar na descrição</label>
                            <input type="text" name="descricao" value="<?php echo htmlspecialchars($descricao_filtro); ?>" placeholder="Ex: Mercado, Uber..." class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-xs font-medium text-slate-600 dark:text-white/70 mb-1">Data Inicial</label>
                            <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio_filtro); ?>" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-xs font-medium text-slate-600 dark:text-white/70 mb-1">Data Final</label>
                            <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim_filtro); ?>" class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <!-- Checkbox: Incluir Subcategorias -->
                    <div id="row-incluir-subcats" class="mt-3 flex items-center gap-3 <?php echo $categoria_filtro > 0 ? '' : 'hidden'; ?>">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <div class="relative">
                                <input type="hidden" name="incluir_subcats" value="0">
                                <input type="checkbox" name="incluir_subcats" value="1" id="chk-incluir-subcats" class="sr-only peer" <?php echo $incluir_subcats ? 'checked' : ''; ?>>
                                <div class="w-9 h-5 bg-slate-200 dark:bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-cyan-500"></div>
                            </div>
                            <span class="text-xs font-medium text-slate-600 dark:text-white/70">Incluir subcategorias</span>
                        </label>
                    </div>

                    <div class="mt-4 flex justify-end space-x-3">
                        <?php if($has_advanced_filter): ?>
                            <a href="transacoes.php" class="px-4 py-2 text-sm text-red-500 dark:text-red-400 hover:text-red-600 dark:hover:text-red-300 hover:bg-white/50 dark:hover:bg-white/5 rounded-xl transition-colors">Limpar Filtros</a>
                        <?php endif; ?>
                        <button type="submit" class="px-6 py-2 bg-cyan-500 hover:bg-cyan-400 text-white rounded-xl font-medium shadow-lg transition-colors">
                            Aplicar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Lista de Transações -->
        <div class="space-y-4">
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
                    <div class="backdrop-blur-xl border rounded-2xl p-4 flex items-center justify-between transition-all <?php echo !$t['consolidada'] ? 'bg-yellow-50 border-yellow-200 hover:bg-yellow-100 dark:bg-yellow-400/10 dark:border-yellow-400/30 shadow-sm dark:shadow-[0_0_15px_rgba(250,204,21,0.1)] dark:hover:bg-yellow-400/20' : 'bg-white/60 border-gray-200 hover:bg-white/70 dark:bg-white/10 dark:border-white/10 dark:hover:bg-white/20 shadow-sm'; ?>">
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
                    </div>

            <?php 
                endforeach; 
            else: 
            ?>
                <div class="text-center p-8 bg-white/60 dark:bg-white/5 rounded-3xl border border-gray-200 dark:border-white/10">
                    <p class="text-slate-500 dark:text-white/50">Nenhuma transação encontrada neste mês.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
<script>
    let anoDropdown = <?php echo $ano_atual; ?>;

    // ── Date Selector ──────────────────────────────────────────────
    function toggleDateSelect() {
        const selector = document.getElementById('date-selector');
        if (selector.classList.contains('hidden')) {
            selector.classList.remove('hidden');
            setTimeout(() => selector.classList.remove('opacity-0'), 10);
        } else {
            selector.classList.add('opacity-0');
            setTimeout(() => selector.classList.add('hidden'), 200);
        }
    }

    function mudarAno(delta) {
        anoDropdown += delta;
        document.getElementById('display-ano-dropdown').innerText = anoDropdown;
    }

    function selecionarData(mes) {
        document.getElementById('input-mes').value = mes;
        document.getElementById('input-ano').value = anoDropdown;
        document.getElementById('ordem-input').closest('form').submit();
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

    function selectCategoria(id, nome) {
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
        document.getElementById('ordem-input').closest('form').submit();
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
        const formFiltros = document.getElementById('ordem-input').closest('form');

        // Date selector
        if (formFiltros && !formFiltros.contains(event.target)) {
            const selector = document.getElementById('date-selector');
            if (selector && !selector.classList.contains('hidden')) {
                selector.classList.add('opacity-0');
                setTimeout(() => selector.classList.add('hidden'), 200);
            }
        }

        // Category dropdown
        const catWrapper = document.getElementById('cat-selector-wrapper');
        const catDropdown = document.getElementById('cat-dropdown');
        if (catWrapper && catDropdown && !catWrapper.contains(event.target)) {
            catDropdown.classList.add('hidden');
        }
    });
</script>
</html>
