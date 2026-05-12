<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'conexao.php';

$user_id = $_SESSION['user_id'];

// Filtro de status (1 = Ativa, 0 = Inativa, -1 = Todas)
$filter_status = isset($_GET['status']) ? (int)$_GET['status'] : 1;

// Buscar contas do usuário
$sql = "SELECT id, nome, saldo_inicial, cor, img, status FROM contas WHERE id_user = ?";
if ($filter_status !== -1) {
    $sql .= " AND status = ?";
}
$sql .= " ORDER BY nome ASC";

$stmt = $mysqliFinancas->prepare($sql);
if ($filter_status !== -1) {
    $stmt->bind_param("ii", $user_id, $filter_status);
} else {
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$resultado = $stmt->get_result();
$contas = $resultado->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<?php 
$page_title = "Contas - Minhas Finanças";
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative z-10">
        <div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Contas</h1>
            <div class="flex items-center space-x-4">
                <form method="GET" id="form-filtro" class="flex items-center">
                    <select name="status" onchange="document.getElementById('form-filtro').submit();" class="bg-white/60 dark:bg-white/10 backdrop-blur-md border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-2 focus:outline-none focus:border-cyan-400 appearance-none shadow-lg cursor-pointer">
                        <option class="text-gray-900" value="1" <?php echo $filter_status === 1 ? 'selected' : ''; ?>>Contas Ativas</option>
                        <option class="text-gray-900" value="0" <?php echo $filter_status === 0 ? 'selected' : ''; ?>>Contas Inativas</option>
                        <option class="text-gray-900" value="-1" <?php echo $filter_status === -1 ? 'selected' : ''; ?>>Todas as Contas</option>
                    </select>
                </form>
                <a href="conta.php" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105 whitespace-nowrap">
                    + Nova Conta
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (count($contas) > 0): ?>
                <?php foreach ($contas as $conta): ?>
                    <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-3xl p-6 shadow-[0_8px_32px_0_rgba(0,0,0,0.1)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] hover:bg-white/80 dark:hover:bg-white/20 transition-all">
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex items-center space-x-3">
                                <?php if (!empty($conta['img'])): ?>
                                    <div class="w-10 h-10 rounded-xl shadow-inner overflow-hidden flex items-center justify-center bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 shrink-0">
                                        <img src="img/<?php echo htmlspecialchars($conta['img']); ?>" alt="Logo da conta" class="w-full h-full object-cover">
                                    </div>
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-xl shadow-inner flex items-center justify-center shrink-0" style="background-color: <?php echo htmlspecialchars($conta['cor'] ?: '#ccc'); ?>">
                                        <svg class="w-6 h-6 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <h2 class="text-xl font-semibold text-slate-800 dark:text-white"><?php echo htmlspecialchars($conta['nome']); ?></h2>
                                    <span class="text-xs px-2 py-1 rounded-full <?php echo $conta['status'] ? 'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-300' : 'bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-300'; ?>">
                                        <?php echo $conta['status'] ? 'Ativa' : 'Inativa'; ?>
                                    </span>
                                </div>
                            </div>
                            <a href="conta.php?id=<?php echo $conta['id']; ?>" class="p-2 text-cyan-600 dark:text-cyan-400 hover:text-cyan-700 dark:hover:text-cyan-300 hover:bg-slate-100 dark:hover:bg-white/10 rounded-lg transition-colors" title="Editar">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            </a>
                        </div>
                        <div class="mt-4">
                            <p class="text-sm text-slate-500 dark:text-gray-400">Saldo Inicial</p>
                            <p class="text-2xl font-bold text-slate-800 dark:text-white">R$ <?php echo number_format($conta['saldo_inicial'], 2, ',', '.'); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-span-full p-8 text-center text-slate-500 dark:text-gray-400 bg-white/60 dark:bg-white/5 backdrop-blur-md rounded-3xl border border-gray-200 dark:border-white/10">
                    Nenhuma conta encontrada. Clique em "Nova Conta" para começar.
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
