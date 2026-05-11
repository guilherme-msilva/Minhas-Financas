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
$saldo_inicial = '0.00';
$cor = '#8b5cf6';
$status = 1;
$img = '';

// Se for edição, carregar os dados
if ($id > 0 && $_SERVER['REQUEST_METHOD'] != 'POST') {
    $stmt = $mysqliFinancas->prepare("SELECT nome, saldo_inicial, cor, img, status FROM contas WHERE id = ? AND id_user = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($conta = $res->fetch_assoc()) {
        $nome = $conta['nome'];
        $saldo_inicial = $conta['saldo_inicial'];
        $cor = $conta['cor'] ?: '#8b5cf6';
        $img = $conta['img'];
        $status = $conta['status'];
    } else {
        header("Location: contas.php");
        exit;
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $saldo_inicial = str_replace(['.', ','], ['', '.'], $_POST['saldo_inicial'] ?? '0');
    $cor = trim($_POST['cor'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;

    $img_to_save = $img;
    if (isset($_FILES['img']) && $_FILES['img']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'img/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (in_array($ext, $allowed)) {
            $new_name = uniqid('conta_') . '.' . $ext;
            if (move_uploaded_file($_FILES['img']['tmp_name'], $upload_dir . $new_name)) {
                $img_to_save = $new_name;
            }
        }
    }
    $status = isset($_POST['status']) ? 1 : 0;

    if ($nome) {
        if ($id > 0) {
            // Update
            $stmt = $mysqliFinancas->prepare("UPDATE contas SET nome = ?, saldo_inicial = ?, cor = ?, img = ?, status = ? WHERE id = ? AND id_user = ?");
            $stmt->bind_param("sdssiii", $nome, $saldo_inicial, $cor, $img_to_save, $status, $id, $user_id);
            if ($stmt->execute()) {
                header("Location: contas.php");
                exit;
            } else {
                $erro = "Erro ao atualizar: " . $mysqliFinancas->error;
            }
        } else {
            // Insert
            $stmt = $mysqliFinancas->prepare("INSERT INTO contas (nome, saldo_inicial, cor, img, status, id_user) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sdssii", $nome, $saldo_inicial, $cor, $img_to_save, $status, $user_id);
            if ($stmt->execute()) {
                header("Location: contas.php");
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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $id > 0 ? 'Editar Conta' : 'Nova Conta'; ?> - Minhas Finanças</title>
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

    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-center space-x-4 mb-8">
            <a href="contas.php" class="p-2 bg-white/5 hover:bg-white/10 rounded-full transition-colors text-gray-300 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            </a>
            <h1 class="text-3xl font-bold text-white tracking-wide">
                <?php echo $id > 0 ? 'Editar Conta' : 'Nova Conta'; ?>
            </h1>
        </div>

        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] p-8">
            <?php if ($erro): ?>
                <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-3 rounded-xl mb-6 text-sm">
                    <?php echo $erro; ?>
                </div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <div class="bg-emerald-500/20 border border-emerald-500/50 text-emerald-200 px-4 py-3 rounded-xl mb-6 text-sm">
                    <?php echo $sucesso; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="conta.php<?php echo $id > 0 ? '?id='.$id : ''; ?>" enctype="multipart/form-data" class="space-y-6">
                <div>
                    <label for="nome" class="block text-sm font-medium text-gray-300 mb-2">Nome da Conta</label>
                    <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($nome); ?>" required 
                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-white placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors"
                        placeholder="Ex: Conta Corrente Itaú, Nubank...">
                </div>

                <div>
                    <label for="saldo_inicial" class="block text-sm font-medium text-gray-300 mb-2">Saldo Inicial (R$)</label>
                    <input type="text" id="saldo_inicial" name="saldo_inicial" value="<?php echo htmlspecialchars(number_format((float)$saldo_inicial, 2, ',', '')); ?>" required 
                        class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-white placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="cor" class="block text-sm font-medium text-gray-300 mb-2">Cor de Identificação</label>
                        <div class="flex items-center space-x-4">
                            <input type="color" id="cor" name="cor" value="<?php echo htmlspecialchars($cor); ?>" 
                                class="w-14 h-14 rounded-xl border-0 bg-transparent cursor-pointer">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Imagem da Conta</label>
                        <input type="file" name="img" accept="image/*" class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-white file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-cyan-500/20 file:text-cyan-400 hover:file:bg-cyan-500/30 transition-colors">
                        <?php if ($img): ?>
                            <div class="mt-2 text-sm text-gray-400 flex items-center space-x-2">
                                <img src="img/<?php echo htmlspecialchars($img); ?>" alt="Imagem atual" class="w-8 h-8 rounded-full object-cover border border-white/20">
                                <span>Imagem atual</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Status da Conta</label>
                        <label class="flex items-center space-x-3 cursor-pointer mt-3">
                            <input type="checkbox" name="status" value="1" <?php echo $status ? 'checked' : ''; ?> 
                                class="w-5 h-5 rounded border-gray-400 text-cyan-500 focus:ring-cyan-500 bg-white/10 accent-cyan-500">
                            <span class="text-white font-medium">Conta Ativa</span>
                        </label>
                    </div>
                </div>

                <div class="pt-4 border-t border-white/10">
                    <button type="submit" class="w-full py-3 px-4 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-[1.02] active:scale-95">
                        Salvar Conta
                    </button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
