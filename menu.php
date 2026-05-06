<nav class="relative md:sticky top-0 z-50 mb-8 rounded-b-2xl md:rounded-2xl mt-0 md:mt-4 mx-0 md:mx-4 bg-white/10 backdrop-blur-md border border-white/20 shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center space-x-4">
                <a href="index.php" class="text-white text-xl font-bold tracking-wider flex items-center space-x-2">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Finanças</span>
                </a>
            </div>
            <div class="hidden md:block">
                <div class="ml-10 flex items-baseline space-x-4">
                    <a href="transacao.php" class="text-white bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 px-4 py-2 rounded-xl text-sm font-bold shadow-lg transition-all transform hover:scale-105">Nova Transação</a>
                    <a href="categorias.php" class="text-gray-300 hover:text-white hover:bg-white/10 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Categorias</a>
                    <a href="contas.php" class="text-gray-300 hover:text-white hover:bg-white/10 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Contas</a>
                    <a href="transacoes.php" class="text-gray-300 hover:text-white hover:bg-white/10 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Transações</a>
                    <a href="importacoes.php" class="text-gray-300 hover:text-white hover:bg-white/10 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Importar</a>
                </div>
            </div>
            <div>
                <a href="logout.php" class="text-gray-300 hover:text-red-400 hover:bg-white/10 px-3 py-2 rounded-xl text-sm font-medium transition-all duration-300">Sair</a>
            </div>
        </div>
    </div>
    <!-- Mobile Menu - Simplificado para este exemplo -->
    <div class="md:hidden flex flex-wrap justify-center pb-3 space-x-2 px-2 items-center">
        <a href="transacao.php" class="text-white bg-gradient-to-r from-cyan-500 to-blue-600 px-3 py-1.5 mb-2 rounded-lg text-xs font-bold shadow-md">Nova Transação</a>
        <a href="categorias.php" class="text-gray-300 hover:text-white px-2 py-1 mb-2 text-sm font-medium">Categorias</a>
        <a href="contas.php" class="text-gray-300 hover:text-white px-2 py-1 mb-2 text-sm font-medium">Contas</a>
        <a href="transacoes.php" class="text-gray-300 hover:text-white px-2 py-1 mb-2 text-sm font-medium">Transações</a>
        <a href="importacoes.php" class="text-gray-300 hover:text-white px-2 py-1 mb-2 text-sm font-medium">Importar</a>
    </div>
</nav>
