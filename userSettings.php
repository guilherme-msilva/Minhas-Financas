<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Obtém os dados atuais do usuário
$stmt = $mysqliFinancas->prepare("SELECT nome, email, tema FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user_data = $res->fetch_assoc();
$stmt->close();

$current_tema = !empty($user_data['tema']) ? $user_data['tema'] : 'ESCURO';
$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_password') {
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';
        
        if (empty($nova_senha) || empty($confirmar_senha)) {
            $mensagem = 'Preencha todos os campos da senha.';
            $tipo_mensagem = 'erro';
        } elseif ($nova_senha !== $confirmar_senha) {
            $mensagem = 'A nova senha e a confirmação não coincidem.';
            $tipo_mensagem = 'erro';
        } else {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $stmt = $mysqliFinancas->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            $stmt->bind_param("si", $hash, $user_id);
            if ($stmt->execute()) {
                $mensagem = 'Senha atualizada com sucesso!';
                $tipo_mensagem = 'sucesso';
            } else {
                $mensagem = 'Erro ao atualizar a senha.';
                $tipo_mensagem = 'erro';
            }
            $stmt->close();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_theme') {
        $novo_tema = $_POST['tema'] ?? 'ESCURO';
        if (in_array($novo_tema, ['CLARO', 'ESCURO'])) {
            $stmt = $mysqliFinancas->prepare("UPDATE usuarios SET tema = ? WHERE id = ?");
            $stmt->bind_param("si", $novo_tema, $user_id);
            if ($stmt->execute()) {
                $_SESSION['tema'] = $novo_tema;
                $current_tema = $novo_tema;
                $mensagem = 'Tema salvo com sucesso! (A aplicação suportará o Tema Claro em breve).';
                $tipo_mensagem = 'sucesso';
            } else {
                $mensagem = 'Erro ao atualizar a preferência de tema.';
                $tipo_mensagem = 'erro';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preferências - Minhas Finanças</title>
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
        <h1 class="text-3xl font-bold text-white tracking-wide mb-2">Preferências da Conta</h1>
        <p class="text-white/60 mb-8 text-sm md:text-base">Gerencie sua segurança e como a aplicação será exibida para você.</p>

        <?php if ($mensagem): ?>
            <div class="mb-6 p-4 rounded-xl backdrop-blur-md border <?php echo $tipo_mensagem == 'sucesso' ? 'bg-green-500/10 border-green-500/30 text-green-400' : 'bg-red-500/10 border-red-500/30 text-red-400'; ?>">
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Card Senha -->
            <div class="bg-white/10 backdrop-blur-xl p-6 rounded-3xl border border-white/20 shadow-lg flex flex-col">
                <h2 class="text-xl font-medium text-white mb-6 flex items-center">
                    <svg class="w-6 h-6 mr-2 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    Segurança e Acesso
                </h2>
                <form method="POST" class="flex-1 flex flex-col">
                    <input type="hidden" name="action" value="update_password">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-white/70 mb-2">Nova Senha</label>
                        <input type="password" name="nova_senha" required class="w-full bg-white/5 border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-cyan-400 transition-colors placeholder-white/20" placeholder="••••••••">
                    </div>
                    
                    <div class="mb-8">
                        <label class="block text-sm font-medium text-white/70 mb-2">Confirmar Nova Senha</label>
                        <input type="password" name="confirmar_senha" required class="w-full bg-white/5 border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-cyan-400 transition-colors placeholder-white/20" placeholder="••••••••">
                    </div>
                    
                    <div class="mt-auto pt-4">
                        <button type="submit" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white py-3 rounded-xl font-bold shadow-lg transition-all transform hover:scale-[1.02]">
                            Alterar Senha
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card Tema -->
            <div class="bg-white/10 backdrop-blur-xl p-6 rounded-3xl border border-white/20 shadow-lg flex flex-col">
                <h2 class="text-xl font-medium text-white mb-6 flex items-center">
                    <svg class="w-6 h-6 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                    Aparência Visual
                </h2>
                <form method="POST" class="flex-1 flex flex-col">
                    <input type="hidden" name="action" value="update_theme">
                    
                    <div class="mb-8">
                        <label class="block text-sm font-medium text-white/70 mb-4">Escolha como o painel será exibido para você</label>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Option ESCURO -->
                            <label class="relative cursor-pointer group">
                                <input type="radio" name="tema" value="ESCURO" class="peer sr-only" <?php echo $current_tema == 'ESCURO' ? 'checked' : ''; ?>>
                                <div class="p-4 rounded-2xl border-2 border-white/10 bg-slate-800 text-white text-center transition-all peer-checked:border-cyan-400 peer-checked:shadow-[0_0_15px_rgba(34,211,238,0.3)] group-hover:bg-slate-700">
                                    <svg class="w-8 h-8 mx-auto mb-2 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                                    <span class="font-medium text-sm">Escuro</span>
                                </div>
                            </label>

                            <!-- Option CLARO -->
                            <label class="relative cursor-pointer group">
                                <input type="radio" name="tema" value="CLARO" class="peer sr-only" <?php echo $current_tema == 'CLARO' ? 'checked' : ''; ?>>
                                <div class="p-4 rounded-2xl border-2 border-white/10 bg-gray-100 text-gray-800 text-center transition-all peer-checked:border-cyan-400 peer-checked:shadow-[0_0_15px_rgba(34,211,238,0.3)] group-hover:bg-gray-200">
                                    <svg class="w-8 h-8 mx-auto mb-2 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                                    <span class="font-medium text-sm">Claro</span>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mt-auto pt-4">
                        <button type="submit" class="w-full bg-white/10 hover:bg-white/20 text-white py-3 rounded-xl font-bold border border-white/20 shadow-lg transition-all transform hover:scale-[1.02]">
                            Salvar Preferência
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
