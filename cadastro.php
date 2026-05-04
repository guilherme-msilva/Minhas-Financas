<?php
session_start();
require_once 'conexao.php';

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $senha_confirma = $_POST['senha_confirma'] ?? '';

    if ($nome && $email && $senha && $senha_confirma) {
        if ($senha === $senha_confirma) {
            // Verificar se email já existe
            $stmt = $mysqliFinancas->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $erro = "Este e-mail já está cadastrado.";
            } else {
                $stmt->close();
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $mysqliFinancas->prepare("INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $nome, $email, $senha_hash);
                if ($stmt->execute()) {
                    $sucesso = "Cadastro realizado com sucesso! Você já pode fazer login.";
                } else {
                    $erro = "Erro ao cadastrar: " . $mysqliFinancas->error;
                }
            }
            $stmt->close();
        } else {
            $erro = "As senhas não coincidem.";
        }
    } else {
        $erro = "Por favor, preencha todos os campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Minhas Finanças</title>
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
            position: absolute;
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
<body class="min-h-screen flex items-center justify-center relative py-10">
    
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <div class="w-full max-w-md p-8 bg-white/10 backdrop-blur-xl border border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] relative z-10">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold tracking-wide text-white mb-2">Criar Conta</h1>
            <p class="text-gray-300">Junte-se a nós e controle suas finanças</p>
        </div>

        <?php if ($erro): ?>
            <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-3 rounded-xl mb-6 text-sm">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="bg-emerald-500/20 border border-emerald-500/50 text-emerald-200 px-4 py-3 rounded-xl mb-6 text-sm text-center">
                <?php echo $sucesso; ?>
                <br><br>
                <a href="login.php" class="inline-block bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg transition-colors font-medium">Ir para Login</a>
            </div>
        <?php else: ?>

        <form method="POST" action="cadastro.php" class="space-y-4">
            <div>
                <label for="nome" class="block text-sm font-medium text-gray-300 mb-2">Nome Completo</label>
                <input type="text" id="nome" name="nome" required 
                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-white placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-300 mb-2">E-mail</label>
                <input type="email" id="email" name="email" required 
                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-white placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
            </div>
            
            <div>
                <label for="senha" class="block text-sm font-medium text-gray-300 mb-2">Senha</label>
                <input type="password" id="senha" name="senha" required 
                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-white placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
            </div>

            <div>
                <label for="senha_confirma" class="block text-sm font-medium text-gray-300 mb-2">Confirmar Senha</label>
                <input type="password" id="senha_confirma" name="senha_confirma" required 
                    class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-white placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
            </div>

            <button type="submit" class="w-full mt-2 py-3 px-4 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-[1.02] active:scale-95">
                Cadastrar
            </button>
        </form>
        
        <?php endif; ?>

        <p class="mt-8 text-center text-sm text-gray-300">
            Já tem uma conta? 
            <a href="login.php" class="text-cyan-400 hover:text-cyan-300 font-semibold transition-colors">Faça login</a>
        </p>
    </div>

</body>
</html>
