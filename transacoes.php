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
        $novo_status = $t['consolidada'] ? 0 : 1;
        
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

// Busca contas do usuário para popular o select de filtro
$stmt_contas_filtro = $mysqliFinancas->prepare("SELECT id, nome FROM contas WHERE id_user = ? and status = 1 ORDER BY nome");
$stmt_contas_filtro->bind_param("i", $user_id);
$stmt_contas_filtro->execute();
$contas_filtro = $stmt_contas_filtro->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_contas_filtro->close();

if ($mes_atual == 0) {
    $sql = "
        SELECT t.id, t.data, t.valor, t.descricao, t.consolidada, t.idcategoria, t.idpai, c.nome as categoria_nome, c.cor as categoria_cor, co.nome as conta_nome
        FROM transacoes t
        LEFT JOIN categorias c ON t.idcategoria = c.id
        LEFT JOIN contas co ON t.idconta = co.id
        WHERE t.iduser = ? AND YEAR(t.data) = ? " . ($conta_atual > 0 ? "AND t.idconta = ?" : "") . "
        ORDER BY t.data $ordem_atual, t.id $ordem_atual
    ";
    $stmt = $mysqliFinancas->prepare($sql);
    if ($conta_atual > 0) {
        $stmt->bind_param("iii", $user_id, $ano_atual, $conta_atual);
    } else {
        $stmt->bind_param("ii", $user_id, $ano_atual);
    }
} else {
    $sql = "
        SELECT t.id, t.data, t.valor, t.descricao, t.consolidada, t.idcategoria, t.idpai, c.nome as categoria_nome, c.cor as categoria_cor, co.nome as conta_nome
        FROM transacoes t
        LEFT JOIN categorias c ON t.idcategoria = c.id
        LEFT JOIN contas co ON t.idconta = co.id
        WHERE t.iduser = ? AND MONTH(t.data) = ? AND YEAR(t.data) = ? " . ($conta_atual > 0 ? "AND t.idconta = ?" : "") . "
        ORDER BY t.data $ordem_atual, t.id $ordem_atual
    ";
    $stmt = $mysqliFinancas->prepare($sql);
    if ($conta_atual > 0) {
        $stmt->bind_param("iiii", $user_id, $mes_atual, $ano_atual, $conta_atual);
    } else {
        $stmt->bind_param("iii", $user_id, $mes_atual, $ano_atual);
    }
}

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
        if ($t['idpai']) {
            // É a perna filha (Entrada), pula para não duplicar na lista
            continue;
        } else {
            // É a perna pai (Saída)
            $filha = $transferencias_filhas[$t['id']] ?? null;
            $t['conta_destino_nome'] = $filha ? $filha['conta_nome'] : 'Desconhecida';
            $transacoes_agrupadas[] = $t;
        }
    } else {
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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transações - Minhas Finanças</title>
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

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Header e Filtros -->
        <div class="flex flex-col md:flex-row justify-between items-center mb-8 bg-white/10 backdrop-blur-xl p-4 rounded-3xl border border-white/20 shadow-lg">
            <h1 class="text-2xl font-bold text-white tracking-wide mb-4 md:mb-0">Transações</h1>
            
            <form method="GET" class="flex items-center space-x-3">
                <input type="hidden" id="ordem-input" name="ordem" value="<?php echo $ordem_atual; ?>">
                
                <select name="conta" class="bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                    <option class="text-gray-900" value="0">Todas as Contas</option>
                    <?php foreach($contas_filtro as $c): ?>
                        <option class="text-gray-900" value="<?php echo $c['id']; ?>" <?php echo $conta_atual == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="mes" class="bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                    <option class="text-gray-900" value="0" <?php echo $mes_atual == 0 ? 'selected' : ''; ?>>Todos os Meses</option>
                    <?php foreach($meses as $num => $nome): ?>
                        <option class="text-gray-900" value="<?php echo $num; ?>" <?php echo $mes_atual == $num ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="ano" class="bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                    <?php foreach($anos_disponiveis as $ano_opt): ?>
                        <option class="text-gray-900" value="<?php echo $ano_opt; ?>" <?php echo $ano_atual == $ano_opt ? 'selected' : ''; ?>><?php echo $ano_opt; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <button type="button" onclick="document.getElementById('ordem-input').value = '<?php echo $ordem_atual == 'DESC' ? 'ASC' : 'DESC'; ?>'; this.form.submit();" class="p-2 bg-white/10 hover:bg-white/20 rounded-xl transition-colors border border-white/10 text-white" title="Inverter Ordem">
                    <?php if($ordem_atual == 'DESC'): ?>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-4v12m0 0l-4-4m4 4l4-4"></path></svg>
                    <?php endif; ?>
                </button>
                
                <button type="submit" class="p-2 bg-white/10 hover:bg-white/20 rounded-xl transition-colors border border-white/10 text-white" title="Filtrar">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </button>
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
                        <div class="pt-4 pb-2 border-b border-white/10">
                            <span class="text-white/60 font-medium text-sm"><?php echo $dia_semana . ', ' . $dia . ' de ' . $mes_extenso . ($mes_atual == 0 ? ' de ' . $ano_extenso : ''); ?></span>
                        </div>
            <?php   endif; ?>
                    
                    <!-- Card da Transação -->
                    <div class="bg-white/10 backdrop-blur-xl border border-white/10 rounded-2xl p-4 flex items-center justify-between hover:bg-white/20 transition-all <?php echo !$t['consolidada'] ? 'opacity-50 border-dashed' : ''; ?>">
                        <div class="flex items-center space-x-4">
                            <!-- Ícone/Cor -->
                            <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-inner" style="background-color: <?php echo $t['idcategoria'] == -1 ? '#3b82f6' : ($t['categoria_cor'] ?: '#ccc'); ?>">
                                <?php if($t['idcategoria'] == -1): ?>
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                                <?php else: ?>
                                    <svg class="w-5 h-5 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Detalhes -->
                            <div>
                                <h3 class="text-white font-medium text-lg leading-tight"><?php echo htmlspecialchars($t['descricao']); ?></h3>
                                <p class="text-white/50 text-xs mt-1">
                                    <?php echo htmlspecialchars($t['conta_nome'] ?? 'Conta Desconhecida'); ?>
                                    
                                    <?php if($t['idcategoria'] == -1 && isset($t['conta_destino_nome'])): ?>
                                        <span class="mx-1">➔</span> <?php echo htmlspecialchars($t['conta_destino_nome']); ?>
                                    <?php elseif($t['idcategoria'] != -1 && $t['categoria_nome']): ?>
                                        <span class="mx-1">•</span> <?php echo htmlspecialchars($t['categoria_nome']); ?>
                                    <?php endif; ?>
                                    
                                    <?php if(!$t['consolidada']): ?>
                                        <span class="ml-2 text-yellow-400 font-medium bg-yellow-400/10 px-2 py-0.5 rounded-full">Pendente</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Valor e Ações -->
                        <div class="flex items-center space-x-4">
                            <span class="font-bold text-lg <?php echo $t['idcategoria'] == -1 ? 'text-blue-400' : ($t['valor'] < 0 ? 'text-red-400' : 'text-emerald-400'); ?>">
                                <?php 
                                    if($t['idcategoria'] == -1) {
                                        echo 'R$ ' . number_format(abs($t['valor']), 2, ',', '.');
                                    } else {
                                        echo $t['valor'] < 0 ? '-' : '+';
                                        echo ' R$ ' . number_format(abs($t['valor']), 2, ',', '.');
                                    }
                                ?>
                            </span>
                            
                            <div class="flex space-x-1">
                                <!-- Botão Consolidar Rapido -->
                                <a href="transacoes.php?action=consolidate&id=<?php echo $t['id']; ?>&mes=<?php echo $mes_atual; ?>&ano=<?php echo $ano_atual; ?>" 
                                   class="p-2 rounded-lg transition-colors <?php echo $t['consolidada'] ? 'text-emerald-400 hover:text-emerald-300 hover:bg-emerald-400/10' : 'text-gray-400 hover:text-white hover:bg-white/10'; ?>"
                                   title="<?php echo $t['consolidada'] ? 'Marcar como pendente' : 'Consolidar'; ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <?php if($t['consolidada']): ?>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        <?php else: ?>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        <?php endif; ?>
                                    </svg>
                                </a>

                                <!-- Botão Editar -->
                                <a href="transacao.php?id=<?php echo $t['id']; ?>" class="p-2 text-cyan-400 hover:text-cyan-300 hover:bg-white/10 rounded-lg transition-colors" title="Editar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                </a>
                            </div>
                        </div>
                    </div>

            <?php 
                endforeach; 
            else: 
            ?>
                <div class="text-center p-8 bg-white/5 rounded-3xl border border-white/10">
                    <p class="text-white/50">Nenhuma transação encontrada neste mês.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
