<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Obtém os dados atuais do usuário
$stmt = $mysqliFinancas->prepare("SELECT nome, email, tema, google_sheets_id FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user_data = $res->fetch_assoc();
$stmt->close();

$current_google_sheets_id = $user_data['google_sheets_id'] ?? '';

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
                $mensagem = 'Tema salvo com sucesso!';
                $tipo_mensagem = 'sucesso';
            } else {
                $mensagem = 'Erro ao atualizar a preferência de tema.';
                $tipo_mensagem = 'erro';
            }
            $stmt->close();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_sheets') {
        $nova_url = trim($_POST['google_sheets_id'] ?? '');
        $stmt = $mysqliFinancas->prepare("UPDATE usuarios SET google_sheets_id = ? WHERE id = ?");
        $stmt->bind_param("si", $nova_url, $user_id);
        if ($stmt->execute()) {
            $current_google_sheets_id = $nova_url;
            $mensagem = 'URL da planilha salva com sucesso!';
            $tipo_mensagem = 'sucesso';
        } else {
            $mensagem = 'Erro ao salvar a URL da planilha.';
            $tipo_mensagem = 'erro';
        }
        $stmt->close();
    }
}
?>
<?php 
$page_title = "Preferências - Minhas Finanças";
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide mb-2">Preferências da Conta</h1>
        <p class="text-slate-500 dark:text-white/60 mb-8 text-sm md:text-base">Gerencie sua segurança e como a aplicação será exibida para você.</p>

        <?php if ($mensagem): ?>
            <div class="mb-6 p-4 rounded-xl backdrop-blur-md border <?php echo $tipo_mensagem == 'sucesso' ? 'bg-green-500/10 border-green-500/30 text-green-400' : 'bg-red-500/10 border-red-500/30 text-red-400'; ?>">
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Card Senha -->
            <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl p-6 rounded-3xl border border-gray-200 dark:border-white/20 shadow-lg flex flex-col">
                <h2 class="text-xl font-medium text-slate-800 dark:text-white mb-6 flex items-center">
                    <svg class="w-6 h-6 mr-2 text-cyan-600 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    Segurança e Acesso
                </h2>
                <form method="POST" class="flex-1 flex flex-col">
                    <input type="hidden" name="action" value="update_password">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-slate-600 dark:text-white/70 mb-2">Nova Senha</label>
                        <input type="password" name="nova_senha" required class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-3 focus:outline-none focus:border-cyan-400 transition-colors placeholder-slate-400 dark:placeholder-white/20" placeholder="••••••••">
                    </div>
                    
                    <div class="mb-8">
                        <label class="block text-sm font-medium text-slate-600 dark:text-white/70 mb-2">Confirmar Nova Senha</label>
                        <input type="password" name="confirmar_senha" required class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-3 focus:outline-none focus:border-cyan-400 transition-colors placeholder-slate-400 dark:placeholder-white/20" placeholder="••••••••">
                    </div>
                    
                    <div class="mt-auto pt-4">
                        <button type="submit" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white py-3 rounded-xl font-bold shadow-lg transition-all transform hover:scale-[1.02]">
                            Alterar Senha
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card Tema -->
            <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl p-6 rounded-3xl border border-gray-200 dark:border-white/20 shadow-lg flex flex-col">
                <h2 class="text-xl font-medium text-slate-800 dark:text-white mb-6 flex items-center">
                    <svg class="w-6 h-6 mr-2 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                    Aparência Visual
                </h2>
                <form method="POST" class="flex-1 flex flex-col">
                    <input type="hidden" name="action" value="update_theme">
                    
                    <div class="mb-8">
                        <label class="block text-sm font-medium text-slate-600 dark:text-white/70 mb-4">Escolha como o painel será exibido para você</label>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Option ESCURO -->
                            <label class="relative cursor-pointer group">
                                <input type="radio" name="tema" value="ESCURO" class="peer sr-only" <?php echo $current_tema == 'ESCURO' ? 'checked' : ''; ?>>
                                <div class="p-4 rounded-2xl border-2 border-slate-600 bg-slate-800 text-white text-center transition-all peer-checked:border-cyan-400 peer-checked:shadow-[0_0_15px_rgba(34,211,238,0.3)] group-hover:bg-slate-700">
                                    <svg class="w-8 h-8 mx-auto mb-2 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                                    <span class="font-medium text-sm">Escuro</span>
                                </div>
                            </label>

                            <!-- Option CLARO -->
                            <label class="relative cursor-pointer group">
                                <input type="radio" name="tema" value="CLARO" class="peer sr-only" <?php echo $current_tema == 'CLARO' ? 'checked' : ''; ?>>
                                <div class="p-4 rounded-2xl border-2 border-gray-300 bg-gray-50 text-gray-800 text-center transition-all peer-checked:border-cyan-400 peer-checked:shadow-[0_0_15px_rgba(34,211,238,0.3)] group-hover:bg-gray-100">
                                    <svg class="w-8 h-8 mx-auto mb-2 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                                    <span class="font-medium text-sm">Claro</span>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mt-auto pt-4">
                        <button type="submit" class="w-full bg-slate-200 hover:bg-slate-300 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white py-3 rounded-xl font-bold border border-gray-300 dark:border-white/20 shadow-lg transition-all transform hover:scale-[1.02]">
                            Salvar Preferência
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card Google Sheets -->
        <div class="mt-8 bg-white/60 dark:bg-white/10 backdrop-blur-xl p-6 rounded-3xl border border-gray-200 dark:border-white/20 shadow-lg">
            <h2 class="text-xl font-medium text-slate-800 dark:text-white mb-2 flex items-center">
                <svg class="w-6 h-6 mr-2 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Integração Google Sheets
            </h2>
            <p class="text-slate-500 dark:text-white/50 text-sm mb-6">Conecte sua conta a uma planilha do Google Sheets para sincronizar automaticamente suas transações.</p>

            <form method="POST">
                <input type="hidden" name="action" value="update_sheets">
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-600 dark:text-white/70 mb-2">URL da Planilha Google Sheets</label>
                        <input
                            type="url"
                            name="google_sheets_id"
                            id="google_sheets_id"
                            value="<?php echo htmlspecialchars($current_google_sheets_id); ?>"
                            class="w-full bg-white/50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-slate-800 dark:text-white rounded-xl px-4 py-3 focus:outline-none focus:border-emerald-400 transition-colors placeholder-slate-400 dark:placeholder-white/20"
                            placeholder="https://docs.google.com/spreadsheets/d/..."
                        >
                        <p class="text-xs text-slate-600 dark:text-white/40 mt-2">Cole aqui a URL completa da sua planilha.</p>
                        <p class="text-xs text-slate-600 dark:text-white/40 mt-2">E-mail da conta de serviço: <span class="font-mono text-slate-800 dark:text-white">minhas-financas@red-abstraction-488018-i7.iam.gserviceaccount.com</span>. Lembre-se de compartilhá-la com este e-mail.</p>
                    </div>
                    <?php if (!empty($current_google_sheets_id)): ?>
                        <div class="flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            <span>Planilha vinculada. As sincronizações serão enviadas para esta URL.</span>
                        </div>
                    <?php endif; ?>
                    <div>
                        <button type="submit" class="bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white px-6 py-3 rounded-xl font-bold shadow-lg transition-all transform hover:scale-[1.02]">
                            Salvar Planilha
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
