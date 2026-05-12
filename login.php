<?php
session_start();
require_once 'conexao.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';

    if ($email && $senha) {
        $stmt = $mysqliFinancas->prepare("SELECT id, nome, senha FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($usuario = $resultado->fetch_assoc()) {
            if (password_verify($senha, $usuario['senha'])) {
                $_SESSION['user_id'] = $usuario['id'];
                $_SESSION['user_nome'] = $usuario['nome'];
                header("Location: index.php");
                exit;
            } else {
                $erro = "E-mail ou senha incorretos.";
            }
        } else {
            $erro = "E-mail ou senha incorretos.";
        }
        $stmt->close();
    } else {
        $erro = "Por favor, preencha todos os campos.";
    }
}
?>
<?php 
$page_title = "Login - Minhas Finanças";
$body_class = "min-h-screen flex items-center justify-center relative bg-slate-50 text-slate-800 dark:bg-[#0f172a] dark:text-[#f8fafc] transition-colors duration-300";
include 'header.php'; 
?>

    <div class="w-full max-w-md p-8 bg-white/60 dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.1)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] relative z-10 mx-4">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold tracking-wide text-slate-800 dark:text-white mb-2">Bem-vindo</h1>
            <p class="text-slate-600 dark:text-gray-300">Acesse sua conta para continuar</p>
        </div>

        <?php if ($erro): ?>
            <div class="bg-red-50 dark:bg-red-500/20 border border-red-200 dark:border-red-500/50 text-red-600 dark:text-red-200 px-4 py-3 rounded-xl mb-6 text-sm">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-6">
            <div>
                <label for="email" class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-2">E-mail</label>
                <input type="email" id="email" name="email" required 
                    class="w-full px-4 py-3 rounded-xl bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
            </div>
            
            <div>
                <label for="senha" class="block text-sm font-medium text-slate-600 dark:text-gray-300 mb-2">Senha</label>
                <input type="password" id="senha" name="senha" required 
                    class="w-full px-4 py-3 rounded-xl bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-gray-400 focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 transition-colors">
            </div>

            <button type="submit" class="w-full py-3 px-4 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-[1.02] active:scale-95">
                Entrar
            </button>
        </form>

        <p class="mt-8 text-center text-sm text-slate-600 dark:text-gray-300">
            Ainda não tem uma conta? 
            <a href="cadastro.php" class="text-cyan-600 dark:text-cyan-400 hover:text-cyan-700 dark:hover:text-cyan-300 font-semibold transition-colors">Cadastre-se</a>
        </p>
    </div>

</body>
</html>
