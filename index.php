<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Filtro de projeção (incluir transações futuras do mês atual)
$projecao = isset($_GET['projecao']) && $_GET['projecao'] == '1';

// Definir as datas limite
// Se projeção estiver ativa, usa o último dia do mês atual. Senão, usa a data de hoje.
$data_limite = $projecao ? date('Y-m-t') : date('Y-m-d');
$data_inicio_mes = date('Y-m-01');

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
        c.nome, 
        c.cor, 
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

// 4. Despesas do mês atual por categoria (para o gráfico)
$sql_despesas = "
    SELECT t.idcategoria, SUM(t.valor) as total
    FROM transacoes t
    WHERE t.iduser = ? 
      AND t.idcategoria != -1
      AND t.data >= ? 
      AND t.data <= ?
      AND t.valor < 0
    GROUP BY t.idcategoria
";
$stmt_despesas = $mysqliFinancas->prepare($sql_despesas);
$stmt_despesas->bind_param("iss", $user_id, $data_inicio_mes, $data_limite);
$stmt_despesas->execute();
$res_despesas = $stmt_despesas->get_result();
$despesas_agrupadas = [];
while ($row = $res_despesas->fetch_assoc()) {
    $despesas_agrupadas[$row['idcategoria']] = abs($row['total']);
}
$stmt_despesas->close();

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

function resolveCorCategoria($id_categoria, $cats_map) {
    $atual = $id_categoria;
    while ($atual && isset($cats_map[$atual])) {
        if (!empty($cats_map[$atual]['cor'])) {
            return $cats_map[$atual]['cor'];
        }
        $atual = $cats_map[$atual]['id_pai'];
    }
    return '#ccc'; // fallback
}

$dados_grafico = [
    'root' => ['labels' => [], 'data' => [], 'backgroundColor' => [], 'ids' => []],
    'drilldown' => []
];

$totais_por_raiz = [];
$mapa_raiz = [];

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

function generateHarmonicColor($index, $total) {
    if ($total <= 0) $total = 1;
    // Distribui o Hue ao longo de 360 graus
    $hue = ($index * (360 / $total)) % 360;
    // Para dar variação harmônica, alternamos um pouco saturação e luminosidade
    $saturation = 75 - (($index % 2) * 15); 
    $lightness = 55 + (($index % 3) * 5);
    return "hsl({$hue}, {$saturation}%, {$lightness}%)";
}

foreach ($despesas_agrupadas as $id_cat => $valor) {
    if (!isset($mapa_raiz[$id_cat])) continue;
    $id_raiz = $mapa_raiz[$id_cat];
    
    if (!isset($totais_por_raiz[$id_raiz])) $totais_por_raiz[$id_raiz] = 0;
    $totais_por_raiz[$id_raiz] += $valor;
    
    $nome = $cats_map_grafico[$id_cat]['nome'] . ($id_cat == $id_raiz && count($dados_grafico['drilldown'][$id_raiz]['labels']) >= 0 ? ' (Geral)' : '');
    
    $dados_grafico['drilldown'][$id_raiz]['labels'][] = $nome;
    $dados_grafico['drilldown'][$id_raiz]['data'][] = $valor;
    $dados_grafico['drilldown'][$id_raiz]['ids'][] = $id_cat;
}

// Gerar cores para drilldown
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
$json_grafico = json_encode($dados_grafico);

// Buscar nome do usuário
$stmt = $mysqliFinancas->prepare("SELECT nome FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user_nome = $res->fetch_assoc()['nome'] ?? 'Usuário';
$stmt->close();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Minhas Finanças</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; background: #3b82f6; border-radius: 50%; }
        .blob-2 { bottom: -10%; right: -10%; width: 600px; height: 600px; background: #8b5cf6; border-radius: 50%; animation-delay: 2s; }
        .blob-3 { top: 40%; left: 40%; width: 400px; height: 400px; background: #06b6d4; border-radius: 50%; animation-delay: 4s; }
        
        @keyframes move {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, -50px) scale(1.1); }
        }
    </style>
</head>
<body class="min-h-screen relative pb-20">
    
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <?php include 'menu.php'; ?>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-12">
        
        <!-- Cabeçalho e Toggle -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
            <div>
                <h1 class="text-3xl md:text-4xl font-bold text-white tracking-wide">Olá, <?php echo htmlspecialchars(explode(' ', $user_nome)[0]); ?>!</h1>
                <p class="text-white/60 mt-1 text-sm md:text-base">Aqui está o seu resumo financeiro de <?php echo strtolower(date('F')); ?>.</p>
            </div>
            
            <form id="form-projecao" method="GET" class="bg-white/10 backdrop-blur-md border border-white/10 px-4 py-3 rounded-2xl flex items-center space-x-3 shadow-lg">
                <span class="text-white/90 text-sm font-medium">Projetar lançamentos futuros</span>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" name="projecao" value="1" onchange="document.getElementById('form-projecao').submit()" class="sr-only peer" <?php echo $projecao ? 'checked' : ''; ?>>
                  <div class="w-11 h-6 bg-white/20 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cyan-500"></div>
                </label>
            </form>
        </div>

        <!-- Painel Saldo Total -->
        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-[2rem] p-8 md:p-12 shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] relative overflow-hidden mb-8 group">
            <!-- Efeito de brilho hover -->
            <div class="absolute inset-0 bg-gradient-to-tr from-cyan-400/0 to-blue-500/0 group-hover:from-cyan-400/5 group-hover:to-blue-500/5 transition-all duration-500"></div>
            
            <h2 class="text-white/70 text-lg font-medium mb-2 uppercase tracking-widest">Saldo Total Geral</h2>
            <div class="flex items-end space-x-2 relative z-10">
                <span class="text-white/60 text-3xl font-light pb-1 md:pb-2">R$</span>
                <span class="text-white text-5xl md:text-7xl font-bold tracking-tight">
                    <?php echo number_format($saldo_total, 2, ',', '.'); ?>
                </span>
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

        <!-- Resumo Mensal Grid -->
        <h3 class="text-white/80 font-medium text-xl mb-4 ml-2">Resumo do Mês</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            <!-- Entradas -->
            <div class="bg-emerald-500/10 backdrop-blur-xl border border-emerald-500/20 rounded-3xl p-6 shadow-lg hover:bg-emerald-500/20 transition-all flex items-center space-x-4">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"></path></svg>
                </div>
                <div>
                    <h4 class="text-emerald-100/70 text-sm font-medium mb-1">Entradas</h4>
                    <div class="text-emerald-400 text-3xl font-bold leading-none">
                        R$ <?php echo number_format($entradas_mes, 2, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <!-- Saídas -->
            <div class="bg-red-500/10 backdrop-blur-xl border border-red-500/20 rounded-3xl p-6 shadow-lg hover:bg-red-500/20 transition-all flex items-center space-x-4">
                <div class="w-12 h-12 rounded-2xl bg-red-500/20 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"></path></svg>
                </div>
                <div>
                    <h4 class="text-red-100/70 text-sm font-medium mb-1">Saídas</h4>
                    <div class="text-red-400 text-3xl font-bold leading-none">
                        R$ <?php echo number_format($saidas_mes, 2, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <!-- Balanço / Resultado -->
            <?php 
                $cor_bg = $resultado_mes >= 0 ? 'bg-blue-500/10' : 'bg-slate-500/10';
                $cor_border = $resultado_mes >= 0 ? 'border-blue-500/20' : 'border-slate-500/20';
                $cor_hover = $resultado_mes >= 0 ? 'hover:bg-blue-500/20' : 'hover:bg-slate-500/20';
                $cor_text = $resultado_mes >= 0 ? 'text-blue-400' : 'text-slate-300';
                $cor_icon_bg = $resultado_mes >= 0 ? 'bg-blue-500/20' : 'bg-slate-500/20';
            ?>
            <div class="<?php echo "$cor_bg $cor_border $cor_hover"; ?> backdrop-blur-xl border rounded-3xl p-6 shadow-lg transition-all flex items-center space-x-4">
                <div class="w-12 h-12 rounded-2xl <?php echo $cor_icon_bg; ?> flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 <?php echo $cor_text; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"></path></svg>
                </div>
                <div>
                    <h4 class="text-white/60 text-sm font-medium mb-1">Balanço do Mês</h4>
                    <div class="<?php echo $cor_text; ?> text-3xl font-bold flex flex-wrap items-baseline gap-1 leading-none">
                        R$ <?php echo number_format(abs($resultado_mes), 2, ',', '.'); ?>
                        <?php if($resultado_mes < 0): ?>
                            <span class="text-sm font-medium text-red-400/80 uppercase ml-1">(Negativo)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Painel de Gráfico: Despesas por Categoria -->
        <?php if(!empty($dados_grafico['root']['data'])): ?>
        <div class="mt-8 bg-white/5 backdrop-blur-xl border border-white/10 rounded-3xl p-6 md:p-8 shadow-lg relative">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-white/80 font-medium text-xl ml-2">Despesas por Categoria</h3>
                <button id="btnVoltarGrafico" class="hidden px-4 py-2 bg-white/10 hover:bg-white/20 text-white/80 rounded-xl text-sm transition-all flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Voltar para Geral
                </button>
            </div>
            
            <div class="relative h-[300px] md:h-[400px] w-full flex justify-center">
                <canvas id="graficoDespesas"></canvas>
            </div>
            
            <script>
                const chartDados = <?php echo $json_grafico; ?>;
                const ctx = document.getElementById('graficoDespesas').getContext('2d');
                let currentChart = null;
                let isDrilldown = false;
                
                function renderChart(dataObj, title) {
                    if (currentChart) {
                        currentChart.destroy();
                    }
                    
                    currentChart = new Chart(ctx, {
                        type: 'doughnut',
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
                            cutout: '65%',
                            plugins: {
                                legend: {
                                    position: window.innerWidth > 768 ? 'right' : 'bottom',
                                    labels: {
                                        color: 'rgba(255, 255, 255, 0.7)',
                                        font: { family: 'Outfit', size: 14 },
                                        padding: 20
                                    }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                    titleColor: 'rgba(255,255,255,0.9)',
                                    bodyColor: 'rgba(255,255,255,0.9)',
                                    borderColor: 'rgba(255,255,255,0.1)',
                                    borderWidth: 1,
                                    padding: 12,
                                    cornerRadius: 12,
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.label || '';
                                            if (label) label += ': ';
                                            label += 'R$ ' + context.parsed.toLocaleString('pt-BR', {minimumFractionDigits: 2});
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
                
                renderChart(chartDados.root, 'Geral');
                
                document.getElementById('btnVoltarGrafico').addEventListener('click', () => {
                    isDrilldown = false;
                    document.getElementById('btnVoltarGrafico').classList.add('hidden');
                    renderChart(chartDados.root, 'Geral');
                });
            </script>
        </div>
        <?php endif; ?>

        <!-- Saldos das Contas -->
        <?php if(count($contas_ativas) > 0): ?>
            <h3 class="text-white/80 font-medium text-xl mt-12 mb-4 ml-2">Saldos por Conta</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($contas_ativas as $conta): ?>
                    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-3xl p-6 shadow-lg hover:bg-white/10 transition-all flex items-center space-x-4">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center shadow-inner" style="background-color: <?php echo $conta['cor']; ?>30;">
                            <svg class="w-6 h-6" style="color: <?php echo $conta['cor']; ?>;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                        </div>
                        <div>
                            <h4 class="text-white/70 text-sm font-medium mb-1"><?php echo htmlspecialchars($conta['nome']); ?></h4>
                            <div class="text-white text-xl font-bold">
                                R$ <?php echo number_format($conta['saldo_atual'], 2, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>
