<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'conexao.php';

$user_id = $_SESSION['user_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$erro = '';
$sucesso = '';

$nome = '';
$id_pai = '';
$cor = '#3b82f6';
$icone = '';

// Se for edição, carregar os dados
if ($id > 0 && $_SERVER['REQUEST_METHOD'] != 'POST') {
    $stmt = $mysqliFinancas->prepare("SELECT nome, id_pai, cor, icone FROM categorias WHERE id = ? AND id_user = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($cat = $res->fetch_assoc()) {
        $nome = $cat['nome'];
        $id_pai = $cat['id_pai'];
        $cor = $cat['cor'];
        $icone = $cat['icone'] ?? '';
    } else {
        header("Location: categorias.php");
        exit;
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $id > 0) {
        $stmt_check = $mysqliFinancas->prepare("SELECT COUNT(*) as qtd FROM transacoes WHERE idcategoria = ? AND iduser = ?");
        $stmt_check->bind_param("ii", $id, $user_id);
        $stmt_check->execute();
        $qtd = $stmt_check->get_result()->fetch_assoc()['qtd'];
        $stmt_check->close();

        if ($qtd > 0) {
            $erro = "Não é possível excluir. Existem transações vinculadas a esta categoria.";
        } else {
            $stmt = $mysqliFinancas->prepare("DELETE FROM categorias WHERE id = ? AND id_user = ?");
            $stmt->bind_param("ii", $id, $user_id);
            if ($stmt->execute()) {
                header("Location: categorias.php");
                exit;
            } else {
                $erro = "Erro ao excluir: " . $mysqliFinancas->error;
            }
            $stmt->close();
        }
    } else {
        $nome = trim($_POST['nome'] ?? '');
        $id_pai = !empty($_POST['id_pai']) ? (int)$_POST['id_pai'] : NULL;
        $cor = isset($_POST['usar_cor']) ? trim($_POST['cor'] ?? '') : '';
        $icone = trim($_POST['icone'] ?? '');

    if ($nome) {
        if ($id > 0) {
            // Update
            $stmt = $mysqliFinancas->prepare("UPDATE categorias SET nome = ?, id_pai = ?, cor = ?, icone = ? WHERE id = ? AND id_user = ?");
            $stmt->bind_param("sissii", $nome, $id_pai, $cor, $icone, $id, $user_id);
            if ($stmt->execute()) {
                header("Location: categorias.php");
                exit;
            } else {
                $erro = "Erro ao atualizar: " . $mysqliFinancas->error;
            }
        } else {
            // Insert
            $stmt = $mysqliFinancas->prepare("INSERT INTO categorias (nome, id_pai, cor, icone, id_user) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sissi", $nome, $id_pai, $cor, $icone, $user_id);
            if ($stmt->execute()) {
                header("Location: categorias.php");
                exit;
            } else {
                $erro = "Erro ao inserir: " . $mysqliFinancas->error;
            }
        }
        if (isset($stmt)) $stmt->close();
    } else {
        $erro = "O campo nome é obrigatório.";
    }
    }
}

// Buscar categorias pai disponíveis (excluir a própria categoria e suas subcategorias, para simplificar apenas excluímos ela mesma)
$sql_pais = "SELECT id, nome FROM categorias WHERE id_user = ? AND id != ? ORDER BY nome ASC";
$stmt_pais = $mysqliFinancas->prepare($sql_pais);
$stmt_pais->bind_param("ii", $user_id, $id);
$stmt_pais->execute();
$categorias_pai = $stmt_pais->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_pais->close();

require_once 'lista_icones.php';
?>
<?php 
$page_title = ($id > 0 ? 'Editar Categoria' : 'Nova Categoria') . ' - Minhas Finanças';
$extra_head = '<script src="https://unpkg.com/@phosphor-icons/web"></script>';
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative z-10">
        <div class="flex items-center space-x-4 mb-8">
            <a href="categorias.php" class="p-2 bg-white/60 dark:bg-white/5 hover:bg-white/80 dark:hover:bg-white/10 rounded-full transition-colors text-slate-600 dark:text-gray-300 hover:text-slate-800 dark:hover:text-white border border-gray-200 dark:border-transparent">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            </a>
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">
                <?php echo $id > 0 ? 'Editar Categoria' : 'Nova Categoria'; ?>
            </h1>
        </div>

        <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.1)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] p-8">
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

            <form method="POST" action="categoria.php<?php echo $id > 0 ? '?id='.$id : ''; ?>" class="space-y-6">
                <div>
                    <label for="nome" class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-2">Nome da Categoria</label>
                    <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($nome); ?>" required 
                        class="w-full px-4 py-3 rounded-xl bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
                </div>

                <div>
                    <label for="id_pai" class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-2">Categoria Pai (Opcional)</label>
                    <select id="id_pai" name="id_pai" 
                        class="w-full px-4 py-3 rounded-xl bg-white/50 dark:bg-[#1e293b] border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors appearance-none">
                        <option class="text-gray-900 dark:text-white" value="">Nenhuma</option>
                        <?php foreach ($categorias_pai as $cp): ?>
                            <option class="text-gray-900 dark:text-white" value="<?php echo $cp['id']; ?>" <?php echo $id_pai == $cp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cp['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-2">Cor de Identificação</label>
                    <div class="flex flex-col space-y-3">
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" name="usar_cor" id="usar_cor" value="1" <?php echo $cor ? 'checked' : ''; ?> 
                                class="w-5 h-5 rounded border-gray-400 dark:border-white/20 bg-white dark:bg-white/5 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-white dark:focus:ring-offset-slate-900"
                                onchange="document.getElementById('cor_container').classList.toggle('opacity-50', !this.checked); document.getElementById('cor').disabled = !this.checked;">
                            <span class="text-slate-800 dark:text-white">Definir cor específica (se desmarcado, herdará do pai)</span>
                        </label>
                        
                        <div id="cor_container" class="flex items-center space-x-4 <?php echo $cor ? '' : 'opacity-50'; ?>">
                            <input type="color" id="cor" name="cor" value="<?php echo htmlspecialchars($cor ?: '#3b82f6'); ?>" <?php echo $cor ? '' : 'disabled'; ?>
                                class="w-14 h-14 rounded-xl border-0 bg-transparent cursor-pointer">
                            <span class="text-slate-500 dark:text-gray-400 text-sm">Escolha uma cor.</span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-2">Ícone da Categoria</label>
                    <input type="hidden" id="icone" name="icone" value="<?php echo htmlspecialchars($icone); ?>">
                    
                    <div class="bg-white/50 dark:bg-[#1e293b] border border-gray-200 dark:border-white/10 rounded-xl p-4 h-48 overflow-y-auto">
                        <div class="grid grid-cols-5 sm:grid-cols-6 md:grid-cols-8 gap-2">
                            <?php foreach($icones_catalogo as $ic): ?>
                                <button type="button" 
                                        onclick="selectIcon('<?php echo $ic; ?>')" 
                                        id="btn-<?php echo $ic; ?>"
                                        class="icon-btn p-2 rounded-lg flex items-center justify-center transition-all <?php echo $icone == $ic ? 'bg-cyan-100 dark:bg-cyan-500/30 border border-cyan-400 text-cyan-600 dark:text-cyan-400' : 'bg-transparent border border-transparent text-slate-400 dark:text-gray-400 hover:text-slate-800 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10'; ?>">
                                    <i class="ph <?php echo $ic; ?> text-3xl"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <span class="text-slate-500 dark:text-gray-400 text-sm mt-2 block">Escolha um ícone para representar esta categoria.</span>
                </div>

                <div class="pt-4 border-t border-gray-200 dark:border-white/10">
                    <button type="submit" class="w-full py-3 px-4 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-[1.02] active:scale-95">
                        Salvar Categoria
                    </button>
                </div>
            </form>

            <?php if ($id > 0): ?>
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-white/10">
                <form method="POST" action="categoria.php?id=<?php echo $id; ?>" onsubmit="return confirm('Deseja realmente excluir esta categoria?');">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="w-full py-3 bg-red-50 dark:bg-red-500/20 text-red-600 dark:text-red-400 hover:bg-red-500 hover:text-white rounded-xl border border-red-200 dark:border-red-500/30 transition-colors font-medium">
                        Excluir Categoria
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function selectIcon(iconClass) {
            document.getElementById('icone').value = iconClass;
            
            // Remove highlight from all
            document.querySelectorAll('.icon-btn').forEach(btn => {
                btn.classList.remove('bg-cyan-100', 'dark:bg-cyan-500/30', 'border-cyan-400', 'text-cyan-600', 'dark:text-cyan-400');
                btn.classList.add('bg-transparent', 'border-transparent', 'text-slate-400', 'dark:text-gray-400');
                btn.classList.remove('hover:text-slate-800', 'dark:hover:text-white', 'hover:bg-slate-100', 'dark:hover:bg-white/10');
                if (btn.id !== 'btn-' + iconClass) {
                    btn.classList.add('hover:text-slate-800', 'dark:hover:text-white', 'hover:bg-slate-100', 'dark:hover:bg-white/10');
                }
            });
            
            // Add highlight to selected
            const selectedBtn = document.getElementById('btn-' + iconClass);
            if (selectedBtn) {
                selectedBtn.classList.remove('bg-transparent', 'border-transparent', 'text-slate-400', 'dark:text-gray-400', 'hover:text-slate-800', 'dark:hover:text-white', 'hover:bg-slate-100', 'dark:hover:bg-white/10');
                selectedBtn.classList.add('bg-cyan-100', 'dark:bg-cyan-500/30', 'border-cyan-400', 'text-cyan-600', 'dark:text-cyan-400');
            }
        }
    </script>
</body>
</html>
