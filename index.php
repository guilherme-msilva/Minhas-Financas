<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Filtros
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
$projecao = isset($_GET['projecao']) && $_GET['projecao'] == '1';
$investimentos = isset($_GET['investimentos']) && $_GET['investimentos'] == '1';

$is_current_month = ($mes == (int)date('m') && $ano == (int)date('Y'));
$data_inicio_mes = sprintf('%04d-%02d-01', $ano, $mes);

if ($is_current_month) {
    $data_limite = $projecao ? date('Y-m-t', strtotime($data_inicio_mes)) : date('Y-m-d');
} else {
    $data_limite = date('Y-m-t', strtotime($data_inicio_mes));
}

// 1. Cálculo do Saldo Total
// Somatória dos saldos iniciais das contas ativas + Somatória das transações
$sql_saldo = "
    SELECT 
      (SELECT COALESCE(SUM(saldo_inicial), 0) FROM contas WHERE id_user = ?) + 
      (SELECT COALESCE(SUM(valor), 0) FROM transacoes WHERE iduser = ? AND data <= ?) 
    AS saldo_total
";
$stmt = $mysqliFinancas->prepare($sql_saldo);
$stmt->bind_param("iis", $user_id, $user_id, $data_limite);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$saldo_total = $row['saldo_total'] ?? 0;
$stmt->close();

// 2. Resumo Mensal (Entradas, Saídas, Balanço)
// Ignorando idcategoria = -1 (Transferências)
$sql_resumo = "
    SELECT 
        COALESCE(SUM(CASE WHEN valor > 0 THEN valor ELSE 0 END), 0) as entradas,
        COALESCE(SUM(CASE WHEN valor < 0 THEN ABS(valor) ELSE 0 END), 0) as saidas
    FROM transacoes
    WHERE iduser = ? 
      AND idcategoria != -1
      AND data >= ? 
      AND data <= ?
";
$stmt = $mysqliFinancas->prepare($sql_resumo);
$stmt->bind_param("iss", $user_id, $data_inicio_mes, $data_limite);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$entradas_mes = $row['entradas'] ?? 0;
$saidas_mes = $row['saidas'] ?? 0;
$resultado_mes = $entradas_mes - $saidas_mes;
$stmt->close();

// 3. Saldo por Contas Ativas
$sql_contas = "
    SELECT 
        c.id,
        c.nome, 
        c.cor, 
        c.img,
        c.saldo_inicial + COALESCE((SELECT SUM(t.valor) FROM transacoes t WHERE t.idconta = c.id AND t.data <= ?), 0) AS saldo_atual
    FROM contas c
    WHERE c.id_user = ? AND c.status = 1
    ORDER BY nome ASC
";
$stmt_contas = $mysqliFinancas->prepare($sql_contas);
$stmt_contas->bind_param("si", $data_limite, $user_id);
$stmt_contas->execute();
$contas_ativas = $stmt_contas->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_contas->close();

// 4. Transações do mês por categoria (para o gráfico)
$sql_trans = "
    SELECT t.idcategoria, 
           SUM(CASE WHEN t.valor < 0 THEN ABS(t.valor) ELSE 0 END) as total_despesa,
           SUM(CASE WHEN t.valor > 0 THEN t.valor ELSE 0 END) as total_receita
    FROM transacoes t
    WHERE t.iduser = ? 
      AND t.idcategoria != -1
      AND t.data >= ? 
      AND t.data <= ?
    GROUP BY t.idcategoria
";
$stmt_trans = $mysqliFinancas->prepare($sql_trans);
$stmt_trans->bind_param("iss", $user_id, $data_inicio_mes, $data_limite);
$stmt_trans->execute();
$res_trans = $stmt_trans->get_result();
$despesas_agrupadas = [];
$receitas_agrupadas = [];
while ($row = $res_trans->fetch_assoc()) {
    if ($row['total_despesa'] > 0) $despesas_agrupadas[$row['idcategoria']] = $row['total_despesa'];
    if ($row['total_receita'] > 0) $receitas_agrupadas[$row['idcategoria']] = $row['total_receita'];
}
$stmt_trans->close();

// Buscar todas as categorias para construir a hierarquia do gráfico
$sql_cats_grafico = "SELECT id, id_pai, nome, cor FROM categorias WHERE id_user = ?";
$stmt_cats_grafico = $mysqliFinancas->prepare($sql_cats_grafico);
$stmt_cats_grafico->bind_param("i", $user_id);
$stmt_cats_grafico->execute();
$cats_grafico = $stmt_cats_grafico->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_cats_grafico->close();

$cats_map_grafico = [];
foreach ($cats_grafico as $c) {
    $cats_map_grafico[$c['id']] = $c;
}

function generateHarmonicColor($index, $total) {
    if ($total <= 0) $total = 1;
    $hue = ($index * (360 / $total)) % 360;
    $saturation = 75 - (($index % 2) * 15); 
    $lightness = 55 + (($index % 3) * 5);
    return "hsl({$hue}, {$saturation}%, {$lightness}%)";
}

function buildGraphData($agrupadas, $cats_map_grafico, $cats_grafico) {
    $dados_grafico = [
        'root' => ['labels' => [], 'data' => [], 'backgroundColor' => [], 'ids' => []],
        'drilldown' => []
    ];
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

    if ($total_roots === 1) {
        $single_root_id = null;
        foreach ($totais_por_raiz as $id_raiz => $total) {
            if ($total > 0) { $single_root_id = $id_raiz; break; }
        }
        $drill = $dados_grafico['drilldown'][$single_root_id];
        $dados_grafico['root']['labels'] = $drill['labels'];
        $dados_grafico['root']['data'] = $drill['data'];
        $dados_grafico['root']['backgroundColor'] = $drill['backgroundColor'];
        $dados_grafico['root']['ids'] = $drill['ids'];
    } else {
        foreach ($totais_por_raiz as $id_raiz => $total) {
            if ($total > 0) {
                $cor = generateHarmonicColor($color_index_root++, $total_roots);
                $dados_grafico['root']['labels'][] = $cats_map_grafico[$id_raiz]['nome'];
                $dados_grafico['root']['data'][] = $total;
                $dados_grafico['root']['backgroundColor'][] = $cor;
                $dados_grafico['root']['ids'][] = $id_raiz;
            }
        }
    }
    
    return $dados_grafico;
}

$json_grafico_despesas = json_encode(buildGraphData($despesas_agrupadas, $cats_map_grafico, $cats_grafico));
$json_grafico_receitas = json_encode(buildGraphData($receitas_agrupadas, $cats_map_grafico, $cats_grafico));

// Buscar nome do usuário
$stmt = $mysqliFinancas->prepare("SELECT nome FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user_nome = $res->fetch_assoc()['nome'] ?? 'Usuário';
$stmt->close();
?>
<?php 
$page_title = "Dashboard - Minhas Finanças";
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-12">
        
        <!-- Cabeçalho e Toggle -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-10 gap-4">
            <div class="flex items-center space-x-4 shrink-0">
                <div>
                    <h1 class="text-3xl md:text-4xl font-bold text-slate-800 dark:text-white tracking-wide">Olá, <?php echo htmlspecialchars(explode(' ', $user_nome)[0]); ?>!</h1>
                    <p class="text-slate-500 dark:text-white/60 mt-1 text-sm md:text-base">Aqui está o seu resumo financeiro.</p>
                </div>
            </div>
            
            <form id="form-filtros" method="GET" class="flex flex-col md:flex-row flex-wrap lg:flex-nowrap items-center gap-3 w-full lg:w-auto relative z-40">
                <!-- Seletor Liquid Glass de Data -->
                <div class="relative w-full sm:w-auto">
                    <button type="button" onclick="toggleDateSelect()" class="w-full sm:w-auto bg-white/60 hover:bg-white/70 dark:bg-white/10 dark:hover:bg-white/20 backdrop-blur-md border border-gray-200 dark:border-white/10 px-4 py-3 rounded-2xl flex items-center justify-between space-x-3 shadow-lg transition-colors cursor-pointer text-slate-800 dark:text-white font-medium text-sm focus:outline-none">
                        <?php 
                        $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                        echo $meses[$mes] . ' de ' . $ano; 
                        ?>
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    
                    <div id="date-selector" class="absolute top-full left-0 mt-2 w-48 bg-white/95 dark:bg-slate-800/95 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl shadow-2xl overflow-hidden hidden opacity-0 transition-opacity duration-200 z-50">
                        <div class="p-2 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                            <button type="button" onclick="mudarAno(-1)" class="p-1 text-slate-400 hover:text-slate-800 dark:text-white/50 dark:hover:text-white transition-colors"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg></button>
                            <span class="text-slate-800 dark:text-white font-semibold text-sm" id="display-ano-dropdown"><?php echo $ano; ?></span>
                            <button type="button" onclick="mudarAno(1)" class="p-1 text-slate-400 hover:text-slate-800 dark:text-white/50 dark:hover:text-white transition-colors"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
                        </div>
                        <div class="max-h-60 overflow-y-auto no-scrollbar grid grid-cols-2 gap-1 p-2">
                            <?php for($i=1; $i<=12; $i++): ?>
                                <button type="button" onclick="selecionarData(<?php echo $i; ?>)" class="py-2 px-1 text-xs font-medium rounded-lg <?php echo ($i == $mes) ? 'bg-cyan-500 text-white' : 'text-slate-600 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white'; ?> transition-colors text-center">
                                    <?php echo substr($meses[$i], 0, 3); ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="mes" id="input-mes" value="<?php echo $mes; ?>">
                <input type="hidden" name="ano" id="input-ano" value="<?php echo $ano; ?>">
            
                <div class="bg-white/60 dark:bg-white/10 backdrop-blur-md border border-gray-200 dark:border-white/10 px-4 py-3 rounded-2xl flex items-center justify-between w-full sm:w-auto space-x-3 shadow-lg">
                    <span class="text-slate-700 dark:text-white/90 text-sm font-medium whitespace-nowrap">Projetar lançamentos</span>
                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                      <input type="checkbox" name="projecao" value="1" onchange="document.getElementById('form-filtros').submit()" class="sr-only peer" <?php echo $projecao ? 'checked' : ''; ?>>
                      <div class="w-11 h-6 bg-slate-300 dark:bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cyan-500"></div>
                    </label>
                </div>

                <div class="bg-white/60 dark:bg-white/10 backdrop-blur-md border border-gray-200 dark:border-white/10 px-4 py-3 rounded-2xl flex items-center justify-between w-full sm:w-auto space-x-3 shadow-lg">
                    <span class="text-slate-700 dark:text-white/90 text-sm font-medium whitespace-nowrap">Incluir Investimentos</span>
                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                      <input type="checkbox" name="investimentos" value="1" id="toggle-investimentos" onchange="document.getElementById('form-filtros').submit()" class="sr-only peer" <?php echo $investimentos ? 'checked' : ''; ?>>
                      <div class="w-11 h-6 bg-slate-300 dark:bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-500"></div>
                    </label>
                </div>
            </form>
        </div>

        <script>
            let anoDropdown = <?php echo $ano; ?>;

            function toggleValores() {
                fetch('toggle_valores.php')
                .then(r => r.json())
                .then(data => {
                    if(data.success) {
                        window.location.reload();
                    }
                });
            }

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
                document.getElementById('form-filtros').submit();
            }

            document.addEventListener('click', function(event) {
                const formFiltros = document.getElementById('form-filtros');
                if (formFiltros && !formFiltros.contains(event.target)) {
                    const selector = document.getElementById('date-selector');
                    if (selector && !selector.classList.contains('hidden')) {
                        selector.classList.add('opacity-0');
                        setTimeout(() => selector.classList.add('hidden'), 200);
                    }
                }
            });
        </script>

        <!-- Painel Saldo Total -->
        <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-[2rem] p-8 md:p-12 shadow-[0_8px_32px_0_rgba(0,0,0,0.1)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] relative overflow-hidden mb-6 group">
            <!-- Efeito de brilho hover -->
            <div class="absolute inset-0 bg-gradient-to-tr from-cyan-400/0 to-blue-500/0 group-hover:from-cyan-400/5 group-hover:to-blue-500/5 transition-all duration-500"></div>
            
            <button onclick="toggleValores()" class="absolute top-6 right-6 md:top-8 md:right-8 p-2 rounded-full bg-slate-100 dark:bg-white/10 text-slate-500 dark:text-white/70 hover:bg-slate-200 dark:hover:bg-white/20 transition-all shadow-sm z-20" title="Ocultar/Exibir Valores">
                <?php if($ocultar_valores): ?>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.71-1.581c4.478 0 8.268 2.943 9.543 7a9.97 9.97 0 01-1.564 3.029l-.24.3-3.29-3.29m-4.243-4.243a3 3 0 00-4.243 4.243"></path></svg>
                <?php else: ?>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                <?php endif; ?>
            </button>

            <h2 class="text-slate-500 dark:text-white/70 text-lg font-medium mb-2 uppercase tracking-widest">Saldo Total Geral</h2>
            
            <div class="flex items-end space-x-2 relative z-10">
                <span class="text-slate-400 dark:text-white/60 text-3xl font-light pb-1 md:pb-2" id="saldo_total_currency">R$</span>
                <span class="text-slate-800 dark:text-white text-5xl md:text-7xl font-bold tracking-tight" id="saldo_total_valor">
                    <?php echo $ocultar_valores ? '&bull;&bull;&bull;&bull;' : number_format($saldo_total, 2, ',', '.'); ?>
                </span>
            </div>
            
            <div id="dash_portfolio_totals" class="mt-4 hidden relative z-10">
                <!-- Preenchido via JS se investimentos estiver ativo -->
            </div>

            <?php if($projecao): ?>
                <p class="text-cyan-300/80 text-sm mt-3 flex items-center relative z-10">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    Projetado até o fim do mês
                </p>
            <?php else: ?>
                <p class="text-white/40 text-sm mt-3 flex items-center relative z-10">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Posição atual de hoje
                </p>
            <?php endif; ?>
        </div>

        <!-- Painel de Gráfico Contas VS Portfólio (Apenas quando ativo) -->
        <div id="grafico_contas_portfolio_container" class="hidden mt-6 mb-6 bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-3xl p-6 shadow-lg relative">
            <h3 class="text-slate-800 dark:text-white/80 font-medium text-xl mb-4 ml-2">Distribuição de Patrimônio</h3>
            <div class="relative h-[250px] md:h-[350px] w-full flex justify-center">
                <canvas id="graficoContasPortfolio"></canvas>
            </div>
        </div>

        <!-- Resumo Mensal Grid -->
        <h3 class="text-slate-800 dark:text-white/80 font-medium text-xl mb-4 ml-2">Resumo do Mês</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            <!-- Entradas -->
            <div class="bg-emerald-50 dark:bg-emerald-500/10 backdrop-blur-xl border border-emerald-200 dark:border-emerald-500/20 rounded-3xl p-6 shadow-lg hover:bg-emerald-100 dark:hover:bg-emerald-500/20 transition-all flex items-center space-x-4">
                <div class="w-12 h-12 rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"></path></svg>
                </div>
                <div>
                    <h4 class="text-emerald-700 dark:text-emerald-100/70 text-sm font-medium mb-1">Entradas</h4>
                    <div class="text-emerald-600 dark:text-emerald-400 text-3xl font-bold leading-none">
                        R$ <?php echo $ocultar_valores ? '&bull;&bull;&bull;&bull;' : number_format($entradas_mes, 2, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <!-- Saídas -->
            <div class="bg-red-50 dark:bg-red-500/10 backdrop-blur-xl border border-red-200 dark:border-red-500/20 rounded-3xl p-6 shadow-lg hover:bg-red-100 dark:hover:bg-red-500/20 transition-all flex items-center space-x-4">
                <div class="w-12 h-12 rounded-2xl bg-red-100 dark:bg-red-500/20 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"></path></svg>
                </div>
                <div>
                    <h4 class="text-red-700 dark:text-red-100/70 text-sm font-medium mb-1">Saídas</h4>
                    <div class="text-red-600 dark:text-red-400 text-3xl font-bold leading-none">
                        R$ <?php echo $ocultar_valores ? '&bull;&bull;&bull;&bull;' : number_format($saidas_mes, 2, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <!-- Balanço / Resultado -->
            <?php 
                $cor_bg = $resultado_mes >= 0 ? 'bg-blue-50 dark:bg-blue-500/10' : 'bg-slate-50 dark:bg-slate-500/10';
                $cor_border = $resultado_mes >= 0 ? 'border-blue-200 dark:border-blue-500/20' : 'border-slate-200 dark:border-slate-500/20';
                $cor_hover = $resultado_mes >= 0 ? 'hover:bg-blue-100 dark:hover:bg-blue-500/20' : 'hover:bg-slate-100 dark:hover:bg-slate-500/20';
                $cor_text = $resultado_mes >= 0 ? 'text-blue-700 dark:text-blue-400' : 'text-slate-700 dark:text-slate-300';
                $cor_icon_bg = $resultado_mes >= 0 ? 'bg-blue-100 dark:bg-blue-500/20' : 'bg-slate-200 dark:bg-slate-500/20';
            ?>
            <div class="<?php echo "$cor_bg $cor_border $cor_hover"; ?> backdrop-blur-xl border rounded-3xl p-6 shadow-lg transition-all flex items-center space-x-4">
                <div class="w-12 h-12 rounded-2xl <?php echo $cor_icon_bg; ?> flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 <?php echo $cor_text; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"></path></svg>
                </div>
                <div>
                    <h4 class="text-slate-600 dark:text-white/60 text-sm font-medium mb-1">Balanço do Mês</h4>
                    <div class="<?php echo $cor_text; ?> text-3xl font-bold flex flex-wrap items-baseline gap-1 leading-none">
                        R$ <?php echo $ocultar_valores ? '&bull;&bull;&bull;&bull;' : number_format(abs($resultado_mes), 2, ',', '.'); ?>
                        <?php if($resultado_mes < 0): ?>
                            <span class="text-sm font-medium text-red-500 dark:text-red-400/80 uppercase ml-1">(Negativo)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Saldos das Contas -->
        <?php if(count($contas_ativas) > 0): ?>
            <h3 class="text-slate-800 dark:text-white/80 font-medium text-xl mt-6 mb-4 ml-2">Saldos por Conta</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($contas_ativas as $conta): ?>
                    <a href="transacoes.php?conta=<?php echo $conta['id']; ?>" class="bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-3xl p-6 shadow-lg hover:bg-white/70 dark:hover:bg-white/10 transition-all flex items-center space-x-4 cursor-pointer">
                        <?php if(!empty($conta['img'])): ?>
                            <div class="w-12 h-12 rounded-2xl overflow-hidden flex items-center justify-center shadow-inner shrink-0 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
                                <img src="img/<?php echo htmlspecialchars($conta['img']); ?>" alt="Logo da conta" class="w-full h-full object-cover">
                            </div>
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center shadow-inner shrink-0" style="background-color: <?php echo $conta['cor']; ?>30;">
                                <svg class="w-6 h-6" style="color: <?php echo $conta['cor']; ?>;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h4 class="text-slate-600 dark:text-white/70 text-sm font-medium mb-1"><?php echo htmlspecialchars($conta['nome']); ?></h4>
                            <div class="text-slate-800 dark:text-white text-xl font-bold">
                                R$ <?php echo $ocultar_valores ? '&bull;&bull;&bull;&bull;' : number_format($conta['saldo_atual'], 2, ',', '.'); ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Painel de Gráfico: Despesas por Categoria -->
        <?php if(true): ?>
        <div class="mt-8 bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-3xl p-6 md:p-8 shadow-lg relative">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-slate-800 dark:text-white/80 font-medium text-xl ml-2" id="titulo-grafico">Despesas por Categoria</h3>
                    <button id="btnVoltarGrafico" class="hidden px-3 py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white/80 rounded-lg text-sm transition-all flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                        Voltar
                    </button>
                </div>
                <div class="bg-slate-200 dark:bg-black/20 p-1 rounded-xl flex items-center">
                    <button onclick="mudarTipoGrafico('despesa')" id="btn-graf-despesa" class="px-3 py-1.5 text-sm font-medium rounded-lg bg-white dark:bg-white/20 text-slate-900 dark:text-white shadow transition-all">Despesas</button>
                    <button onclick="mudarTipoGrafico('receita')" id="btn-graf-receita" class="px-3 py-1.5 text-sm font-medium rounded-lg text-slate-500 hover:text-slate-900 dark:text-white/50 dark:hover:text-white transition-all">Receitas</button>
                </div>
            </div>
            
            <div class="relative h-[300px] md:h-[400px] w-full flex justify-center">
                <canvas id="graficoDespesas"></canvas>
            </div>
            
            <script>
                const chartDadosDespesas = <?php echo $json_grafico_despesas; ?>;
                const chartDadosReceitas = <?php echo $json_grafico_receitas; ?>;
                let currentTipoGrafico = 'despesa';
                let chartDados = chartDadosDespesas;
                
                const ctx = document.getElementById('graficoDespesas').getContext('2d');
                let currentChart = null;
                let isDrilldown = false;
                
                function mudarTipoGrafico(tipo) {
                    currentTipoGrafico = tipo;
                    const btnDespesa = document.getElementById('btn-graf-despesa');
                    const btnReceita = document.getElementById('btn-graf-receita');
                    const titulo = document.getElementById('titulo-grafico');
                    
                    const isDark = document.documentElement.classList.contains('dark');
                    const activeBg = isDark ? 'bg-white/20' : 'bg-white';
                    const activeText = isDark ? 'text-white' : 'text-slate-900';
                    const inactiveText = isDark ? 'text-white/50' : 'text-slate-500';

                    if (tipo === 'despesa') {
                        chartDados = chartDadosDespesas;
                        btnDespesa.className = `px-3 py-1.5 text-sm font-medium rounded-lg ${activeBg} ${activeText} shadow transition-all`;
                        btnReceita.className = `px-3 py-1.5 text-sm font-medium rounded-lg ${inactiveText} hover:text-slate-900 dark:hover:text-white transition-all`;
                        titulo.innerText = "Despesas por Categoria";
                    } else {
                        chartDados = chartDadosReceitas;
                        btnReceita.className = `px-3 py-1.5 text-sm font-medium rounded-lg ${activeBg} ${activeText} shadow transition-all`;
                        btnDespesa.className = `px-3 py-1.5 text-sm font-medium rounded-lg ${inactiveText} hover:text-slate-900 dark:hover:text-white transition-all`;
                        titulo.innerText = "Receitas por Categoria";
                    }
                    
                    isDrilldown = false;
                    document.getElementById('btnVoltarGrafico').classList.add('hidden');
                    
                    if (chartDados.root.data.length === 0) {
                        if (currentChart) currentChart.destroy();
                        currentChart = null;
                    } else {
                        if (tipo === 'receita' && chartDados.drilldown[2] && chartDados.drilldown[2].data.length > 0) {
                            isDrilldown = true;
                            document.getElementById('btnVoltarGrafico').classList.remove('hidden');
                            renderChart(chartDados.drilldown[2], chartDados.drilldown[2].nome_raiz);
                        } else {
                            renderChart(chartDados.root, 'Geral');
                        }
                    }
                }
                
                function renderChart(dataObj, title) {
                    if (currentChart) {
                        currentChart.destroy();
                    }
                    
                    currentChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: dataObj.labels,
                            datasets: [{
                                data: dataObj.data,
                                backgroundColor: dataObj.backgroundColor,
                                borderWidth: 2,
                                borderColor: 'rgba(15, 23, 42, 0.5)',
                                hoverOffset: 10
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: window.innerWidth > 768 ? 'right' : 'bottom',
                                    labels: {
                                        color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#1e293b',
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
                                                        text: window.ocultarValores ? `${label} - R$ •••• (${pct}%)` : `${label} - R$ ${formattedValue} (${pct}%)`,
                                                        fillStyle: style.backgroundColor,
                                                        strokeStyle: style.borderColor,
                                                        lineWidth: style.borderWidth,
                                                        hidden: isNaN(data.datasets[0].data[i]) || meta.data[i].hidden,
                                                        color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#1e293b',
                                                        fontColor: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#1e293b',
                                                        index: i
                                                    };
                                                });
                                            }
                                            return [];
                                        }
                                    }
                                },
                                tooltip: {
                                    backgroundColor: document.documentElement.classList.contains('dark') ? '#1e293b' : '#ffffff',
                                    titleColor: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#1e293b',
                                    bodyColor: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#1e293b',
                                    borderColor: document.documentElement.classList.contains('dark') ? 'rgba(255,255,255,0.1)' : 'rgba(15, 23, 42, 0.1)',
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
                                            if (window.ocultarValores) {
                                                label += 'R$ ••••';
                                            } else {
                                                label += 'R$ ' + valor.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                            }
                                            label += ' (' + pct + '%)';
                                            return label;
                                        }
                                    }
                                }
                            },
                            onClick: (e, elements) => {
                                if (elements.length > 0 && !isDrilldown) {
                                    const index = elements[0].index;
                                    const id_raiz = dataObj.ids[index];
                                    
                                    if (chartDados.drilldown[id_raiz] && chartDados.drilldown[id_raiz].data.length > 0) {
                                        if(chartDados.drilldown[id_raiz].data.length === 1 && chartDados.drilldown[id_raiz].labels[0].includes('(Geral)')) {
                                            return;
                                        }
                                        
                                        isDrilldown = true;
                                        document.getElementById('btnVoltarGrafico').classList.remove('hidden');
                                        renderChart(chartDados.drilldown[id_raiz], chartDados.drilldown[id_raiz].nome_raiz);
                                    }
                                }
                            }
                        }
                    });
                }
                
                if (chartDados.root.data.length > 0) {
                    renderChart(chartDados.root, 'Geral');
                }
                
                document.getElementById('btnVoltarGrafico').addEventListener('click', () => {
                    isDrilldown = false;
                    document.getElementById('btnVoltarGrafico').classList.add('hidden');
                    renderChart(chartDados.root, 'Geral');
                });
            </script>
        </div>
        <?php endif; ?>

        <!-- Script de Integração do Portfólio no Dashboard -->
        <script>
            const formatCurrencyDash = (value, currency = 'BRL') => {
                if (window.ocultarValores) {
                    return currency === 'BRL' ? 'R$ ••••' : 'US$ ••••';
                }
                return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: currency }).format(value);
            };

            const isInvestimentosActive = <?php echo $investimentos ? 'true' : 'false'; ?>;
            const saldoTotalContas = <?php echo $saldo_total; ?>;

            if (isInvestimentosActive) {
                // Prepara a UI para carregar
                const container = document.getElementById('dash_portfolio_totals');
                container.classList.remove('hidden');
                container.innerHTML = `<div class="text-sm text-cyan-500 animate-pulse mt-2 flex items-center gap-2"><svg class="animate-spin h-4 w-4" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Consultando valores de mercado ao vivo...</div>`;
                
                // Busca os dados do Portfólio Assincronamente
                fetch('portfolio_api.php')
                .then(res => res.json())
                .then(data => {
                    const saldoPortfolio = data.total_brl || 0;
                    const novoSaldoGeral = saldoTotalContas + saldoPortfolio;
                    
                    // Atualiza valor principal animando opacidade
                    const valEl = document.getElementById('saldo_total_valor');
                    const curEl = document.getElementById('saldo_total_currency');
                    valEl.style.opacity = 0;
                    setTimeout(() => {
                        curEl.style.display = 'none';
                        valEl.innerText = formatCurrencyDash(novoSaldoGeral);
                        valEl.style.opacity = 1;
                    }, 200);

                    // Monta HTML de subtotais
                    let htmlSubtotais = `
                        <div class="mt-6 pt-4 border-t border-gray-200 dark:border-white/10 text-sm space-y-2 max-w-sm">
                            <div class="flex justify-between items-center mb-3">
                                <span class="font-medium text-slate-500 dark:text-gray-400">Total em Contas:</span>
                                <span class="font-bold text-slate-700 dark:text-white">${formatCurrencyDash(saldoTotalContas)}</span>
                            </div>
                            <div class="flex justify-between items-center mb-4">
                                <span class="font-medium text-purple-500 dark:text-purple-400">Total Portfólio:</span>
                                <span class="font-bold text-purple-600 dark:text-purple-300">${formatCurrencyDash(saldoPortfolio)}</span>
                            </div>
                            <div class="pl-4 border-l-2 border-gray-200 dark:border-white/10 space-y-2">
                    `;
                    
                    // Categorias do Portfólio
                    if (data.tree) {
                        for (let macroCat in data.tree) {
                            const node = data.tree[macroCat];
                            let usdText = node.value_usd > 0 ? ` <span class="text-xs text-gray-500">(${formatCurrencyDash(node.value_usd, 'USD')})</span>` : '';
                            htmlSubtotais += `
                                <div class="flex justify-between items-end">
                                    <span class="text-slate-600 dark:text-gray-300 text-xs">${macroCat}</span>
                                    <span class="text-slate-700 dark:text-gray-200 font-medium text-xs">${formatCurrencyDash(node.value_brl)}${usdText}</span>
                                </div>
                            `;
                        }
                    }

                    htmlSubtotais += `
                            </div>
                            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-white/5 text-[10px] text-gray-400 leading-tight">
                                Cotação Dólar: ${formatCurrencyDash(data.cotacao_usd)}<br>
                                Consulta: ${data.data_hora}
                            </div>
                        </div>
                    `;
                    container.innerHTML = htmlSubtotais;

                    // Exibir gráfico Contas x Portfólio
                    document.getElementById('grafico_contas_portfolio_container').classList.remove('hidden');
                    const ctxCP = document.getElementById('graficoContasPortfolio').getContext('2d');
                    
                    const isDark = document.documentElement.classList.contains('dark');
                    new Chart(ctxCP, {
                        type: 'pie',
                        data: {
                            labels: ['Saldos em Contas', 'Portfólio de Investimentos'],
                            datasets: [{
                                data: [saldoTotalContas, saldoPortfolio],
                                backgroundColor: ['#0ea5e9', '#a855f7'], // blue-500 e purple-500
                                borderWidth: 2,
                                borderColor: isDark ? 'rgba(15, 23, 42, 0.5)' : '#ffffff',
                                hoverOffset: 8
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: window.innerWidth > 768 ? 'right' : 'bottom',
                                    labels: {
                                        color: isDark ? '#f8fafc' : '#1e293b',
                                        font: { family: 'Outfit', size: 14 },
                                        padding: 20
                                    }
                                },
                                tooltip: {
                                    backgroundColor: isDark ? '#1e293b' : '#ffffff',
                                    titleColor: isDark ? '#f8fafc' : '#1e293b',
                                    bodyColor: isDark ? '#f8fafc' : '#1e293b',
                                    borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(15, 23, 42, 0.1)',
                                    borderWidth: 1,
                                    padding: 12,
                                    cornerRadius: 12,
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.label || '';
                                            if (label) label += ': ';
                                            const valor = context.raw;
                                            const total = saldoTotalContas + saldoPortfolio;
                                            const pct = total > 0 ? ((valor * 100) / total).toFixed(1) : 0;
                                            label += formatCurrencyDash(valor) + ' (' + pct + '%)';
                                            return label;
                                        }
                                    }
                                }
                            }
                        }
                    });

                })
                .catch(err => {
                    console.error(err);
                    container.innerHTML = `<div class="text-sm text-red-500 mt-2">Falha ao obter cotações do mercado.</div>`;
                });
            }
        </script>

    </div>

</body>
</html>
