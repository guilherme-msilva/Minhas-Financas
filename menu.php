<?php
$current_page = basename($_SERVER['PHP_SELF']);
$active_class = 'text-cyan-800 dark:text-white bg-white/60 dark:bg-white/20 shadow-inner';
$inactive_class = 'text-slate-600 dark:text-gray-300 hover:text-cyan-800 dark:hover:text-white hover:bg-white/40 dark:hover:bg-white/10';

$c_cat      = in_array($current_page, ['categorias.php', 'categoria.php']) ? 'bg-slate-100 dark:bg-white/10 font-semibold text-slate-900 dark:text-white' : 'text-slate-700 dark:text-gray-300';
$c_conta    = in_array($current_page, ['contas.php', 'conta.php']) ? 'bg-slate-100 dark:bg-white/10 font-semibold text-slate-900 dark:text-white' : 'text-slate-700 dark:text-gray-300';
$c_cat_inv  = in_array($current_page, ['categorias_investimento.php']) ? 'bg-slate-100 dark:bg-white/10 font-semibold text-slate-900 dark:text-white' : 'text-slate-700 dark:text-gray-300';

$c_trans    = in_array($current_page, ['transacoes.php', 'transacao.php']) ? $active_class : $inactive_class;
$c_orc      = in_array($current_page, ['orcamento.php']) ? $active_class : $inactive_class;
$c_analise  = in_array($current_page, ['relatorio.php', 'graficos.php']) ? $active_class : $inactive_class;
$c_cadastros = in_array($current_page, ['categorias.php', 'categoria.php', 'contas.php', 'conta.php', 'categorias_investimento.php']) ? $active_class : $inactive_class;
$c_imp      = in_array($current_page, ['importacoes.php']) ? 'bg-slate-100 dark:bg-white/10 font-semibold text-slate-900 dark:text-white' : 'text-slate-700 dark:text-gray-300';
$c_user     = in_array($current_page, ['userSettings.php']) ? $active_class : $inactive_class;
$c_port     = in_array($current_page, ['portfolio.php']) ? $active_class : $inactive_class;
?>
<nav class="relative md:sticky top-0 z-50 mb-8 rounded-b-2xl md:rounded-2xl mt-0 md:mt-4 mx-0 md:mx-4 bg-white/60 dark:bg-white/10 backdrop-blur-md border border-gray-200 dark:border-white/20 shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center space-x-4">
                <a href="index.php" class="text-slate-800 dark:text-white text-xl font-bold tracking-wider flex items-center space-x-2">
                    <svg class="w-6 h-6 text-cyan-500 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Free Finance</span>
                </a>
            </div>
            <div class="hidden md:block">
                <div class="ml-10 flex items-baseline space-x-4">
                    <a href="transacao.php" class="text-white bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 px-4 py-2 rounded-xl text-sm font-bold shadow-lg transition-all transform hover:scale-105">Nova Transação</a>
                    <a href="transacoes.php" class="<?php echo $c_trans; ?> px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Transações</a>
                    <a href="portfolio.php" class="<?php echo $c_port; ?> px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Investimentos</a>
                    
                    <!-- Menu Análise com submenu hover -->
                    <div class="relative group">
                        <button class="<?php echo $c_analise; ?> px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300 flex items-center gap-1">
                            Análise
                            <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div class="absolute left-0 mt-0 w-40 bg-white/95 dark:bg-slate-800/95 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 overflow-hidden z-50 transform origin-top-left group-hover:scale-100 scale-95 pt-1">
                            <a href="relatorio.php" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white transition-colors <?php echo $current_page == 'relatorio.php' ? 'bg-slate-100 dark:bg-white/10 font-semibold text-slate-900 dark:text-white' : 'text-slate-700 dark:text-gray-300'; ?>">Relatórios</a>
                            <a href="graficos.php" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white transition-colors <?php echo $current_page == 'graficos.php' ? 'bg-slate-100 dark:bg-white/10 font-semibold text-slate-900 dark:text-white' : 'text-slate-700 dark:text-gray-300'; ?>">Gráficos</a>
                        </div>
                    </div>

                    <a href="orcamento.php" class="<?php echo $c_orc; ?> px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Orçamento</a>
                    
                    <!-- Menu Cadastros com submenu hover -->
                    <div class="relative group">
                        <button class="<?php echo $c_cadastros; ?> px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300 flex items-center gap-1">
                            Cadastros
                            <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div class="absolute left-0 mt-0 w-48 bg-white/95 dark:bg-slate-800/95 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 overflow-hidden z-50 transform origin-top-left group-hover:scale-100 scale-95 pt-1">
                            <a href="categorias.php" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white transition-colors <?php echo $c_cat; ?>">Categorias Base</a>
                            <a href="contas.php" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white transition-colors <?php echo $c_conta; ?>">Contas Báncarias</a>
                            <a href="categorias_investimento.php" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white transition-colors <?php echo $c_cat_inv; ?>">Categ. Investimentos</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Menu do Usuário -->
            <div class="relative group">
                <button class="<?php echo str_replace(' px-3 py-2 ', ' ', $c_user); ?> p-2 rounded-xl transition-all duration-300 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                </button>
                <!-- Dropdown -->
                <div class="absolute right-0 mt-0 w-40 bg-white/95 dark:bg-slate-800/95 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 overflow-hidden z-50 transform origin-top-right group-hover:scale-100 scale-95 pt-1">
                    <a href="userSettings.php" class="block px-4 py-2 text-sm text-slate-700 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white transition-colors">Preferências</a>
                    <a href="importacoes.php" class="block px-4 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-900 dark:hover:text-white transition-colors <?php echo $c_imp; ?>">Importar</a>
                    <a href="logout.php" class="block px-4 py-2 text-sm text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-400/10 hover:text-red-600 dark:hover:text-red-300 transition-colors border-t border-gray-100 dark:border-white/5">Sair</a>
                </div>
            </div>
        </div>
    </div>
    <!-- Mobile Menu -->
    <div class="md:hidden flex flex-wrap justify-center pb-3 space-x-2 px-2 items-center text-center">
        <a href="transacao.php" class="text-white bg-gradient-to-r from-cyan-500 to-blue-600 px-3 py-1.5 mb-2 rounded-lg text-xs font-bold shadow-md w-[45%]">Nova Transação</a>
        <a href="transacoes.php" class="<?php echo str_replace('bg-white/60', 'bg-white/40', $c_trans); ?> px-2 py-1.5 mb-2 text-sm font-medium rounded-lg w-[45%]">Transações</a>
        
        <a href="portfolio.php" class="<?php echo str_replace('bg-white/60', 'bg-white/40', $c_port); ?> px-2 py-1.5 mb-2 text-sm font-medium rounded-lg w-[45%]">Investimentos</a>
        <a href="orcamento.php" class="<?php echo str_replace('bg-white/60', 'bg-white/40', $c_orc); ?> px-2 py-1.5 mb-2 text-sm font-medium rounded-lg w-[45%]">Orçamento</a>

        <!-- Análise Dropdown Mobile -->
        <div class="w-[45%] mb-2 relative">
            <button onclick="toggleMobileMenu('mob_analise')" class="<?php echo str_replace('bg-white/60', 'bg-white/40', $c_analise); ?> w-full px-2 py-1.5 text-sm font-medium rounded-lg flex items-center justify-center gap-1">
                Análise
                <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </button>
            <div id="mob_analise" class="hidden absolute bottom-full left-0 w-full mb-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-white/10 rounded-lg shadow-xl overflow-hidden z-50 pb-1 pt-1">
                <a href="relatorio.php" class="block px-3 py-2 text-sm text-slate-700 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10">Relatórios</a>
                <a href="graficos.php" class="block px-3 py-2 text-sm text-slate-700 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10">Gráficos</a>
            </div>
        </div>

        <!-- Cadastros Dropdown Mobile -->
        <div class="w-[45%] mb-2 relative">
            <button onclick="toggleMobileMenu('mob_cadastros')" class="<?php echo str_replace('bg-white/60', 'bg-white/40', $c_cadastros); ?> w-full px-2 py-1.5 text-sm font-medium rounded-lg flex items-center justify-center gap-1">
                Cadastros
                <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </button>
            <div id="mob_cadastros" class="hidden absolute bottom-full right-0 w-48 mb-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-white/10 rounded-lg shadow-xl overflow-hidden z-50 pb-1 pt-1 text-left">
                <a href="categorias.php" class="block px-3 py-2 text-sm text-slate-700 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10">Categorias Base</a>
                <a href="contas.php" class="block px-3 py-2 text-sm text-slate-700 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10">Contas Báncarias</a>
                <a href="categorias_investimento.php" class="block px-3 py-2 text-sm text-slate-700 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-white/10">Categ. Investimentos</a>
            </div>
        </div>
    </div>
</nav>

<script>
    function toggleMobileMenu(id) {
        document.querySelectorAll('[id^="mob_"]').forEach(el => {
            if (el.id !== id) el.classList.add('hidden');
        });
        document.getElementById(id).classList.toggle('hidden');
    }
</script>
