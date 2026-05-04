<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'conexao.php';

$user_id = $_SESSION['user_id'];

// Buscar categorias do usuário, incluindo o nome da categoria pai se houver
$sql = "
    SELECT c.id, c.nome, c.cor, p.nome as nome_pai 
    FROM categorias c
    LEFT JOIN categorias p ON c.id_pai = p.id
    WHERE c.id_user = ?
    ORDER BY c.nome ASC
";
$stmt = $mysqliFinancas->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resultado = $stmt->get_result();
$categorias = $resultado->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias - Minhas Finanças</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
<body class="min-h-screen relative">
    
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <?php include 'menu.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white tracking-wide">Categorias</h1>
            <a href="categoria.php" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
                + Nova Categoria
            </a>
        </div>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-white/5 border-b border-white/10">
                            <th class="p-4 text-sm font-semibold text-gray-300">Cor</th>
                            <th class="p-4 text-sm font-semibold text-gray-300">Nome</th>
                            <th class="p-4 text-sm font-semibold text-gray-300">Categoria Pai</th>
                            <th class="p-4 text-sm font-semibold text-gray-300 text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($categorias) > 0): ?>
                            <?php foreach ($categorias as $cat): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5 transition-colors">
                                    <td class="p-4">
                                        <div class="w-6 h-6 rounded-full border border-white/20 shadow-inner" style="background-color: <?php echo htmlspecialchars($cat['cor'] ?: '#ccc'); ?>"></div>
                                    </td>
                                    <td class="p-4 text-white font-medium">
                                        <?php echo htmlspecialchars($cat['nome']); ?>
                                    </td>
                                    <td class="p-4 text-gray-400">
                                        <?php echo $cat['nome_pai'] ? htmlspecialchars($cat['nome_pai']) : '-'; ?>
                                    </td>
                                    <td class="p-4 text-center">
                                        <a href="categoria.php?id=<?php echo $cat['id']; ?>" class="inline-block p-2 text-cyan-400 hover:text-cyan-300 hover:bg-white/10 rounded-lg transition-colors" title="Editar">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="p-8 text-center text-gray-400">
                                    Nenhuma categoria encontrada. Clique em "Nova Categoria" para começar.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>
