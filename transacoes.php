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

// Busca contas do usuário para popular o select de filtro
$stmt_contas_filtro = $mysqliFinancas->prepare("SELECT id, nome FROM contas WHERE id_user = ? and status = 1 ORDER BY nome");
$stmt_contas_filtro->bind_param("i", $user_id);
$stmt_contas_filtro->execute();
$contas_filtro = $stmt_contas_filtro->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_contas_filtro->close();

// Mapear categorias para resolução hierárquica de ícones e cores
$stmt_cats = $mysqliFinancas->prepare("SELECT id, id_pai, icone, cor FROM categorias WHERE id_user = ?");
$stmt_cats->bind_param("i", $user_id);
$stmt_cats->execute();
$all_cats = $stmt_cats->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_cats->close();

$cats_map = [];
foreach ($all_cats as $c) {
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

if ($mes_atual == 0) {
    $sql = "
        SELECT t.id, t.data, t.valor, t.descricao, t.consolidada, t.idcategoria, t.idpai, t.parcela_recorrencia, t.parcela_fim, t.id_grupo_recorrencia, c.nome as categoria_nome, c.cor as categoria_cor, c.icone as categoria_icone, co.nome as conta_nome
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
        SELECT t.id, t.data, t.valor, t.descricao, t.consolidada, t.idcategoria, t.idpai, t.parcela_recorrencia, t.parcela_fim, t.id_grupo_recorrencia, c.nome as categoria_nome, c.cor as categoria_cor, c.icone as categoria_icone, co.nome as conta_nome
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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transações - Minhas Finanças</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
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
            
            <form method="GET" class="flex flex-wrap items-center justify-center gap-3 w-full md:w-auto">
                <input type="hidden" id="ordem-input" name="ordem" value="<?php echo $ordem_atual; ?>">
                
                <select name="conta" onchange="this.form.submit()" class="w-full md:w-auto bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                    <option class="text-gray-900" value="0">Todas as Contas</option>
                    <?php foreach($contas_filtro as $c): ?>
                        <option class="text-gray-900" value="<?php echo $c['id']; ?>" <?php echo $conta_atual == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="mes" onchange="this.form.submit()" class="flex-1 md:flex-none bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                    <option class="text-gray-900" value="0" <?php echo $mes_atual == 0 ? 'selected' : ''; ?>>Todos os Meses</option>
                    <?php foreach($meses as $num => $nome): ?>
                        <option class="text-gray-900" value="<?php echo $num; ?>" <?php echo $mes_atual == $num ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="ano" onchange="this.form.submit()" class="flex-1 md:flex-none bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
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
                    <div class="backdrop-blur-xl border rounded-2xl p-4 flex items-center justify-between transition-all <?php echo !$t['consolidada'] ? 'bg-yellow-400/10 border-yellow-400/30 shadow-[0_0_15px_rgba(250,204,21,0.1)] hover:bg-yellow-400/20' : 'bg-white/10 border-white/10 hover:bg-white/20'; ?>">
                        <div class="flex items-center space-x-4 flex-1 min-w-0">
                            <!-- Ícone/Cor -->
                            <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-inner shrink-0" style="background-color: <?php echo $t['idcategoria'] == -1 ? '#3b82f6' : ($t['categoria_cor_resolvida']); ?>">
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
                                <h3 class="text-white font-medium text-lg leading-tight truncate">
                                    <?php 
                                        $desc_exibicao = htmlspecialchars($t['descricao']);
                                        if (!empty($t['id_grupo_recorrencia']) && isset($t['parcela_fim']) && $t['parcela_fim'] > 1) {
                                            $parcela = $t['parcela_recorrencia'] ?? 1;
                                            $desc_exibicao .= " ($parcela / {$t['parcela_fim']})";
                                        }
                                        echo $desc_exibicao;
                                    ?>
                                </h3>
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
                        <div class="flex items-center space-x-3 shrink-0">
                            <span class="font-bold text-lg whitespace-nowrap <?php echo $t['idcategoria'] == -1 ? 'text-blue-400' : ($t['valor'] < 0 ? 'text-red-400' : 'text-emerald-400'); ?>">
                                <?php 
                                    echo 'R$ ' . number_format(abs($t['valor']), 2, ',', '.');
                                ?>
                            </span>
                            
                            <div class="flex space-x-1">
                                <!-- Botão Consolidar Rapido -->
                                <?php if(!$t['consolidada']): ?>
                                    <a href="transacoes.php?action=consolidate&id=<?php echo $t['id']; ?>&mes=<?php echo $mes_atual; ?>&ano=<?php echo $ano_atual; ?>" 
                                       class="p-2 rounded-lg transition-colors text-emerald-400 bg-emerald-400/10 hover:bg-emerald-400/20 hover:text-emerald-300"
                                       title="Consolidar">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </a>
                                <?php endif; ?>

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
