<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Buscar categorias
$sql = "SELECT id, nome, cor, icone, id_pai FROM categorias WHERE id_user = ? ORDER BY nome ASC";
$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resultado = $stmt->get_result();
$todas_categorias = $resultado->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
    
    $marginLeft = $level > 0 ? 'ml-6 border-l border-white/10 pl-2' : '';
    
    echo "<div class='space-y-1 $marginLeft'>";
    foreach ($nodes as $cat) {
        $hasChildren = count($cat['children']) > 0;
        $cor = htmlspecialchars($cat['cor'] ?: '#ccc');
        $nome = htmlspecialchars($cat['nome']);
        $icone = htmlspecialchars($cat['icone'] ?? '');
        $id = $cat['id'];
        
        echo "<div class='flex flex-col'>";
        echo "<div class='flex items-center justify-between p-3 border-b border-white/5 hover:bg-white/5 transition-colors rounded-xl'>";
        
        echo "<div class='flex items-center space-x-3 flex-1 cursor-pointer' onclick='toggleChildren($id)'>";
        
        // Ícone de expandir/recolher
        if ($hasChildren) {
            echo "<svg id='icon-$id' class='w-4 h-4 text-white/50 transition-transform duration-200 transform -rotate-90' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'></path></svg>";
        } else {
            echo "<div class='w-4 h-4'></div>"; // Espaçador
        }
        
        if ($icone) {
            echo "<i class='ph $icone text-xl' style='color: $cor'></i>";
        } else {
            echo "<div class='w-4 h-4 rounded-full border border-white/20 shadow-inner' style='background-color: $cor'></div>";
        }
        echo "<span class='text-white font-medium'>$nome</span>";
        echo "</div>"; // fim flex interno
        
        // Botão editar
        echo "<a href='categoria.php?id=$id' class='p-2 text-cyan-400 hover:text-cyan-300 hover:bg-white/10 rounded-lg transition-colors flex items-center space-x-1' title='Editar'>";
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
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias - Minhas Finanças</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
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
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white tracking-wide">Categorias</h1>
            <a href="categoria.php" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
                + Nova
            </a>
        </div>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] p-4">
            <?php if (count($arvore_categorias) > 0): ?>
                <?php renderTreeHtml($arvore_categorias); ?>
            <?php else: ?>
                <div class="p-8 text-center text-gray-400">
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
