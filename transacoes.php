<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Filtro de Mês/Ano
$mes_atual = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano_atual = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

$sql = "
    SELECT t.id, t.data, t.valor, t.descricao, t.consolidada, t.idcategoria, c.nome as categoria_nome, c.cor as categoria_cor, co.nome as conta_nome
    FROM transacoes t
    LEFT JOIN categorias c ON t.idcategoria = c.id
    LEFT JOIN contas co ON t.idconta = co.id
    WHERE t.iduser = ? AND MONTH(t.data) = ? AND YEAR(t.data) = ?
    ORDER BY t.data DESC, t.id DESC
";
$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param("iii", $user_id, $mes_atual, $ano_atual);
$stmt->execute();
$transacoes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];
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
                <select name="mes" class="bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                    <?php foreach($meses as $num => $nome): ?>
                        <option class="text-gray-900" value="<?php echo $num; ?>" <?php echo $mes_atual == $num ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="ano" class="bg-white/5 border border-white/10 text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none">
                    <?php for($i = date('Y') - 5; $i <= date('Y') + 1; $i++): ?>
                        <option class="text-gray-900" value="<?php echo $i; ?>" <?php echo $ano_atual == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="p-2 bg-white/10 hover:bg-white/20 rounded-xl transition-colors border border-white/10 text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </button>
            </form>
        </div>

        <!-- Lista de Transações -->
        <div class="space-y-4">
            <?php 
            $data_atual = '';
            if (count($transacoes) > 0): 
                foreach ($transacoes as $t): 
                    // Separador de Data
                    if ($data_atual != $t['data']): 
                        $data_atual = $t['data'];
                        $dia = date('d', strtotime($data_atual));
                        $dia_semana = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][date('w', strtotime($data_atual))];
            ?>
                        <div class="pt-4 pb-2 border-b border-white/10">
                            <span class="text-white/60 font-medium text-sm"><?php echo $dia_semana . ', ' . $dia . ' de ' . $meses[(int)date('m', strtotime($data_atual))]; ?></span>
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
                                    <?php echo htmlspecialchars($t['conta_nome'] ?? 'Transferência'); ?>
                                    <?php if($t['idcategoria'] != -1 && $t['categoria_nome']): ?>
                                        • <?php echo htmlspecialchars($t['categoria_nome']); ?>
                                    <?php endif; ?>
                                    <?php if(!$t['consolidada']): ?>
                                        <span class="ml-2 text-yellow-400 font-medium">Pendente</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Valor e Ação -->
                        <div class="flex items-center space-x-4">
                            <span class="font-bold text-lg <?php echo $t['valor'] < 0 ? 'text-red-400' : 'text-emerald-400'; ?>">
                                <?php echo $t['valor'] < 0 ? '-' : '+'; ?> R$ <?php echo number_format(abs($t['valor']), 2, ',', '.'); ?>
                            </span>
                            
                            <a href="transacao.php?id=<?php echo $t['id']; ?>" class="p-2 text-cyan-400 hover:text-cyan-300 hover:bg-white/10 rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            </a>
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
