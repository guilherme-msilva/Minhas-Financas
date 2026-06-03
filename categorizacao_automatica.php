<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'conexao.php';
$user_id = $_SESSION['user_id'];
$erro = '';
$sucesso = '';

// Processar formulário (Adicionar/Editar/Excluir)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if ($action === 'delete' && $id > 0) {
        $stmt = $mysqliFinancas->prepare("DELETE FROM categorizacao_automatica WHERE id = ? AND iduser = ?");
        $stmt->bind_param("ii", $id, $user_id);
        if ($stmt->execute()) {
            $sucesso = "Regra excluída com sucesso!";
        } else {
            $erro = "Erro ao excluir: " . $mysqliFinancas->error;
        }
        $stmt->close();
    } elseif ($action === 'save') {
        $match = trim($_POST['match_description'] ?? '');
        $idcat = !empty($_POST['idcategoria']) ? (int)$_POST['idcategoria'] : NULL;
        $idconta = !empty($_POST['idconta']) ? (int)$_POST['idconta'] : NULL;
        
        if ($match) {
            if ($id > 0) {
                // Update
                $stmt = $mysqliFinancas->prepare("UPDATE categorizacao_automatica SET match_description = ?, idcategoria = ?, idconta = ? WHERE id = ? AND iduser = ?");
                $stmt->bind_param("siiii", $match, $idcat, $idconta, $id, $user_id);
                if ($stmt->execute()) {
                    $sucesso = "Regra atualizada com sucesso!";
                } else {
                    $erro = "Erro ao atualizar: " . $mysqliFinancas->error;
                }
                $stmt->close();
            } else {
                // Insert
                $stmt = $mysqliFinancas->prepare("INSERT INTO categorizacao_automatica (match_description, idcategoria, idconta, iduser) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("siii", $match, $idcat, $idconta, $user_id);
                if ($stmt->execute()) {
                    $sucesso = "Regra criada com sucesso!";
                } else {
                    $erro = "Erro ao inserir: " . $mysqliFinancas->error;
                }
                $stmt->close();
            }
        } else {
            $erro = "O campo Texto Identificador é obrigatório.";
        }
    }
}

// Buscar regras
$sql = "SELECT c.*, cat.nome as categoria_nome, cont.nome as conta_nome 
        FROM categorizacao_automatica c 
        LEFT JOIN categorias cat ON c.idcategoria = cat.id 
        LEFT JOIN contas cont ON c.idconta = cont.id 
        WHERE c.iduser = ? 
        ORDER BY c.count DESC, c.match_description ASC";
$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$regras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Buscar Categorias para o Select
$stmt_cat = $mysqliFinancas->prepare("SELECT id, nome FROM categorias WHERE id_user = ? ORDER BY nome ASC");
$stmt_cat->bind_param("i", $user_id);
$stmt_cat->execute();
$categorias = $stmt_cat->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_cat->close();

// Buscar Contas para o Select
$stmt_contas = $mysqliFinancas->prepare("SELECT id, nome FROM contas WHERE id_user = ? AND status = 1 ORDER BY nome ASC");
$stmt_contas->bind_param("i", $user_id);
$stmt_contas->execute();
$contas = $stmt_contas->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_contas->close();

$page_title = "Categorização Automática - Minhas Finanças";
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative z-10">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Categorização Automática</h1>
                <p class="text-slate-500 dark:text-white/60 mt-1 text-sm">Regras para preenchimento automático de transações.</p>
            </div>
            <button onclick="openModal()" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
                + Nova Regra
            </button>
        </div>

        <?php if ($erro): ?>
            <div class="bg-red-50 dark:bg-red-500/20 border border-red-200 dark:border-red-500/50 text-red-600 dark:text-red-200 px-4 py-3 rounded-xl mb-6 text-sm">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="bg-emerald-50 dark:bg-emerald-500/20 border border-emerald-200 dark:border-emerald-500/50 text-emerald-600 dark:text-emerald-200 px-4 py-3 rounded-xl mb-6 text-sm">
                <?php echo $sucesso; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.1)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] p-4 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="p-4 text-slate-500 dark:text-gray-400 font-medium text-sm">Texto Identificador (Match)</th>
                            <th class="p-4 text-slate-500 dark:text-gray-400 font-medium text-sm">Categoria a Aplicar</th>
                            <th class="p-4 text-slate-500 dark:text-gray-400 font-medium text-sm">Conta a Aplicar</th>
                            <th class="p-4 text-slate-500 dark:text-gray-400 font-medium text-sm text-center">Usos</th>
                            <th class="p-4 text-slate-500 dark:text-gray-400 font-medium text-sm text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($regras) > 0): ?>
                            <?php foreach ($regras as $r): ?>
                                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-white/60 dark:hover:bg-white/5 transition-colors">
                                    <td class="p-4 text-slate-800 dark:text-white font-medium"><?php echo htmlspecialchars($r['match_description']); ?></td>
                                    <td class="p-4 text-slate-600 dark:text-gray-300"><?php echo htmlspecialchars($r['categoria_nome'] ?? '-'); ?></td>
                                    <td class="p-4 text-slate-600 dark:text-gray-300"><?php echo htmlspecialchars($r['conta_nome'] ?? '-'); ?></td>
                                    <td class="p-4 text-slate-600 dark:text-gray-300 text-center font-mono">
                                        <span class="bg-cyan-100 dark:bg-cyan-500/20 text-cyan-600 dark:text-cyan-400 px-2 py-1 rounded-lg text-xs font-bold"><?php echo $r['count']; ?></span>
                                    </td>
                                    <td class="p-4 text-center">
                                        <div class="flex justify-center space-x-2">
                                            <button type="button" onclick='editModal(<?php echo json_encode($r); ?>)' class="p-2 text-cyan-600 dark:text-cyan-400 hover:bg-slate-100 dark:hover:bg-white/10 rounded-lg transition-colors" title="Editar">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                            </button>
                                            <form method="POST" class="inline-block" onsubmit="return confirm('Deseja realmente excluir esta regra?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="p-2 text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors" title="Excluir">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="p-8 text-center text-slate-500 dark:text-gray-400">Nenhuma regra cadastrada. Clique em "+ Nova Regra" para criar.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Adicionar/Editar Regra -->
    <div id="modal-regra" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
            <div class="relative bg-white dark:bg-slate-800 rounded-3xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:max-w-lg w-full border border-gray-200 dark:border-white/10">
                <form method="POST" action="categorizacao_automatica.php">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="modal-id" value="0">
                    
                    <div class="px-6 py-6 border-b border-gray-100 dark:border-white/10 flex justify-between items-center">
                        <h3 class="text-xl font-bold text-slate-800 dark:text-white" id="modal-title">Nova Regra</h3>
                        <button type="button" onclick="closeModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                    
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-1">Texto Identificador (Match)</label>
                            <input type="text" id="modal-match" name="match_description" required placeholder="Ex: UBER, IFOOD, POSTO..."
                                class="w-full px-4 py-2.5 rounded-xl bg-slate-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white placeholder-slate-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
                            <p class="text-xs text-slate-500 mt-1">Se este texto for encontrado na descrição da transação, a regra será aplicada.</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-1">Categoria (Opcional)</label>
                            <select id="modal-idcategoria" name="idcategoria" class="w-full px-4 py-2.5 rounded-xl bg-slate-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
                                <option value="">Não alterar categoria</option>
                                <?php foreach ($categorias as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-1">Conta (Opcional)</label>
                            <select id="modal-idconta" name="idconta" class="w-full px-4 py-2.5 rounded-xl bg-slate-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
                                <option value="">Não alterar conta</option>
                                <?php foreach ($contas as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 bg-slate-50 dark:bg-white/5 flex justify-end gap-3 border-t border-gray-100 dark:border-white/10">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-xl text-sm font-medium text-slate-600 dark:text-gray-300 bg-gray-200 dark:bg-white/10 hover:bg-gray-300 dark:hover:bg-white/20 transition-colors">
                            Cancelar
                        </button>
                        <button type="submit" class="px-4 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl text-sm font-bold shadow-md transition-all">
                            Salvar Regra
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('modal-id').value = '0';
            document.getElementById('modal-match').value = '';
            document.getElementById('modal-idcategoria').value = '';
            document.getElementById('modal-idconta').value = '';
            document.getElementById('modal-title').innerText = 'Nova Regra';
            document.getElementById('modal-regra').classList.remove('hidden');
        }

        function editModal(r) {
            document.getElementById('modal-id').value = r.id;
            document.getElementById('modal-match').value = r.match_description;
            document.getElementById('modal-idcategoria').value = r.idcategoria || '';
            document.getElementById('modal-idconta').value = r.idconta || '';
            document.getElementById('modal-title').innerText = 'Editar Regra';
            document.getElementById('modal-regra').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('modal-regra').classList.add('hidden');
        }
    </script>
</body>
</html>
