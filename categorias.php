<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Buscar categorias
$sql = "SELECT id, nome, cor, icone, id_pai FROM categorias WHERE id_user = ? and id > 0 ORDER BY nome ASC";
$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resultado = $stmt->get_result();
$todas_categorias = $resultado->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cats_map = [];
foreach ($todas_categorias as $c) {
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

foreach ($todas_categorias as &$cat) {
    $atributos = resolveAtributosCategoria($cat['id'], $cats_map);
    $cat['icone_resolvido'] = $atributos['icone'];
    $cat['cor_resolvida'] = $atributos['cor'];
}
unset($cat);

// Construir a árvore
function buildTree(array $elements, $parentId = null) {
    $branch = array();
    foreach ($elements as $element) {
        if ($element['id_pai'] == $parentId) {
            $children = buildTree($elements, $element['id']);
            if ($children) {
                $element['children'] = $children;
            } else {
                $element['children'] = [];
            }
            $branch[] = $element;
        }
    }
    return $branch;
}

$arvore_categorias = buildTree($todas_categorias);

// Função recursiva para renderizar HTML da árvore
function renderTreeHtml($nodes, $level = 0) {
    if (count($nodes) === 0) return;
    
    $marginLeft = $level > 0 ? 'ml-6 border-l border-gray-200 dark:border-white/10 pl-2' : '';
    
    echo "<div class='space-y-1 $marginLeft'>";
    foreach ($nodes as $cat) {
        $hasChildren = count($cat['children']) > 0;
        $cor = htmlspecialchars($cat['cor_resolvida']);
        $nome = htmlspecialchars($cat['nome']);
        $icone = htmlspecialchars($cat['icone_resolvido']);
        $id = $cat['id'];
        
        echo "<div class='flex flex-col'>";
        echo "<div class='flex items-center justify-between p-3 border-b border-gray-200 dark:border-white/5 hover:bg-white/60 dark:hover:bg-white/5 transition-colors rounded-xl'>";
        
        echo "<div class='flex items-center space-x-3 flex-1 cursor-pointer' onclick='toggleChildren($id)'>";
        
        // Ícone de expandir/recolher
        if ($hasChildren) {
            echo "<svg id='icon-$id' class='w-4 h-4 text-slate-400 dark:text-white/50 transition-transform duration-200 transform -rotate-90' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'></path></svg>";
        } else {
            echo "<div class='w-4 h-4'></div>"; // Espaçador
        }
        
        if ($icone) {
            echo "<i class='ph $icone text-xl' style='color: $cor'></i>";
        } else {
            echo "<div class='w-4 h-4 rounded-full border border-gray-300 dark:border-white/20 shadow-inner' style='background-color: $cor'></div>";
        }
        echo "<span class='text-slate-800 dark:text-white font-medium'>$nome</span>";
        echo "</div>"; // fim flex interno
        
        // Botão editar
        echo "<a href='categoria.php?id=$id' class='p-2 text-cyan-600 dark:text-cyan-400 hover:text-cyan-700 dark:hover:text-cyan-300 hover:bg-slate-100 dark:hover:bg-white/10 rounded-lg transition-colors flex items-center space-x-1' title='Editar'>";
        echo "<svg class='w-5 h-5' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'></path></svg>";
        echo "<span class='text-sm hidden sm:inline'>Editar</span>";
        echo "</a>";
        
        echo "</div>"; // fim item linha
        
        if ($hasChildren) {
            echo "<div id='children-$id' class='hidden mt-1 overflow-hidden transition-all duration-300'>";
            renderTreeHtml($cat['children'], $level + 1);
            echo "</div>";
        }
        
        echo "</div>"; // fim nó
    }
    echo "</div>";
}
?>
<?php 
$page_title = "Categorias - Minhas Finanças";
$extra_head = '<script src="https://unpkg.com/@phosphor-icons/web"></script>';
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative z-10">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Categorias</h1>
            <a href="categoria.php" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
                + Nova
            </a>
        </div>

        <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.1)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] p-4">
            <?php if (count($arvore_categorias) > 0): ?>
                <?php renderTreeHtml($arvore_categorias); ?>
            <?php else: ?>
                <div class="p-8 text-center text-slate-500 dark:text-gray-400">
                    Nenhuma categoria encontrada. Clique em "+ Nova" para começar.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleChildren(id) {
            const container = document.getElementById('children-' + id);
            const icon = document.getElementById('icon-' + id);
            
            if (container) {
                if (container.classList.contains('hidden')) {
                    container.classList.remove('hidden');
                    icon.classList.remove('-rotate-90');
                } else {
                    container.classList.add('hidden');
                    icon.classList.add('-rotate-90');
                }
            }
        }
    </script>
</body>
</html>
