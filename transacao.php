<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
// Apenas o visual por enquanto, backend será implementado depois.
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Nova Transação - Minhas Finanças</title>
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
            transition: background 0.5s ease;
        }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; border-radius: 50%; }
        .blob-2 { bottom: -10%; right: -10%; width: 600px; height: 600px; border-radius: 50%; animation-delay: 2s; }
        .blob-3 { top: 40%; left: 40%; width: 400px; height: 400px; border-radius: 50%; animation-delay: 4s; }
        
        @keyframes move {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, -50px) scale(1.1); }
        }

        /* Cores dinâmicas baseadas no tipo de transação */
        .theme-despesa .blob-1 { background: #ef4444; }
        .theme-despesa .blob-2 { background: #f43f5e; }
        .theme-despesa .blob-3 { background: #be123c; }
        .theme-despesa .header-glass { background: linear-gradient(135deg, rgba(239, 68, 68, 0.4), rgba(225, 29, 72, 0.2)); border-bottom-color: rgba(239, 68, 68, 0.3); }

        .theme-receita .blob-1 { background: #10b981; }
        .theme-receita .blob-2 { background: #059669; }
        .theme-receita .blob-3 { background: #047857; }
        .theme-receita .header-glass { background: linear-gradient(135deg, rgba(16, 185, 129, 0.4), rgba(5, 150, 105, 0.2)); border-bottom-color: rgba(16, 185, 129, 0.3); }

        .theme-transferencia .blob-1 { background: #3b82f6; }
        .theme-transferencia .blob-2 { background: #4f46e5; }
        .theme-transferencia .blob-3 { background: #3730a3; }
        .theme-transferencia .header-glass { background: linear-gradient(135deg, rgba(59, 130, 246, 0.4), rgba(79, 70, 229, 0.2)); border-bottom-color: rgba(59, 130, 246, 0.3); }

        /* Estilos do switch toggle */
        .toggle-checkbox:checked { right: 0; border-color: #10b981; }
        .toggle-checkbox:checked + .toggle-label { background-color: #10b981; }

        /* Esconder barra de rolagem mas manter funcionalidade */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Animações UI */
        .slide-up { animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .slide-down { animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        @keyframes slideDown { from { transform: translateY(0); } to { transform: translateY(100%); } }
    </style>
</head>
<body class="min-h-screen relative theme-despesa" id="app-body">
    
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <?php include 'menu.php'; ?>

    <div class="max-w-md mx-auto relative h-full flex flex-col mb-20">
        <!-- Main Card simulando a tela do App -->
        <div class="bg-white/10 backdrop-blur-2xl border border-white/20 rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col mx-2 sm:mx-0 relative">
            
            <!-- Cabeçalho (Header) dinâmico -->
            <div id="header-area" class="header-glass p-6 transition-all duration-500 border-b relative">
                <div class="flex justify-between items-center mb-6">
                    <button class="text-white/80 hover:text-white font-medium">Cancelar</button>
                    
                    <!-- Seletor de Tipo (Invisível no print original, mas adicionei para facilitar a troca) -->
                    <select id="tipo-transacao" class="bg-transparent text-white font-semibold text-lg text-center appearance-none focus:outline-none cursor-pointer outline-none">
                        <option value="despesa" class="text-gray-900">Nova Despesa</option>
                        <option value="receita" class="text-gray-900">Nova Receita</option>
                        <option value="transferencia" class="text-gray-900">Transferência</option>
                    </select>

                    <button class="text-white/80 hover:text-white font-medium">Salvar</button>
                </div>

                <div class="flex items-center justify-between mb-2">
                    <button class="w-12 h-12 rounded-2xl border border-white/40 flex items-center justify-center bg-white/10 hover:bg-white/20 transition-colors">
                        <svg id="icon-seta" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>
                    </button>
                    
                    <!-- Campo de Valor Clicável -->
                    <div class="flex-1 text-right ml-4 cursor-pointer" onclick="toggleNumpad()">
                        <span class="text-4xl md:text-5xl font-bold text-white tracking-tight" id="display-valor">R$ 0,00</span>
                    </div>
                </div>
            </div>

            <!-- Formulário Lista (Estilo iOS Settings) -->
            <div class="flex-1 overflow-y-auto no-scrollbar p-2">
                <div class="bg-white/5 rounded-3xl p-2 space-y-1 my-4">
                    <!-- Data -->
                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <span class="text-gray-300 font-medium">Data da Transação</span>
                        <input type="date" class="bg-transparent text-right text-white focus:outline-none w-32" id="data" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <!-- Descrição -->
                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <span class="text-gray-300 font-medium whitespace-nowrap mr-4">Descrição</span>
                        <input type="text" class="bg-transparent text-right text-white placeholder-white/40 focus:outline-none w-full" placeholder="Ex: Marmitex" id="descricao">
                    </div>

                    <!-- Consolidada -->
                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-300 font-medium">Consolidada</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="relative inline-block w-12 mr-2 align-middle select-none transition duration-200 ease-in">
                                <input type="checkbox" name="toggle" id="consolidada" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 border-gray-400 appearance-none cursor-pointer transition-all duration-300" checked/>
                                <label for="consolidada" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-400 cursor-pointer transition-colors duration-300"></label>
                            </div>
                            <button class="text-gray-400 hover:text-white rounded-full border border-gray-400 w-5 h-5 flex items-center justify-center text-xs font-bold transition-colors">i</button>
                        </div>
                    </div>

                    <!-- Categoria -->
                    <div class="flex items-center justify-between p-3 border-b border-white/5" id="linha-categoria">
                        <span class="text-gray-300 font-medium">Categoria</span>
                        <div class="flex items-center text-white/70">
                            <select class="bg-transparent text-right focus:outline-none appearance-none pr-4 cursor-pointer" dir="rtl">
                                <option value="" class="text-gray-900">Selecione ></option>
                                <option value="1" class="text-gray-900" selected>Almoço ></option>
                            </select>
                        </div>
                    </div>

                    <!-- Conta -->
                    <div class="flex items-center justify-between p-3">
                        <span class="text-gray-300 font-medium" id="label-conta-origem">Conta</span>
                        <div class="flex items-center text-white/70">
                            <select class="bg-transparent text-right focus:outline-none appearance-none pr-4 cursor-pointer" dir="rtl">
                                <option value="" class="text-gray-900">Selecione ></option>
                                <option value="1" class="text-gray-900" selected>NuBank ></option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Conta Destino (Visível apenas para Transferência) -->
                    <div class="flex items-center justify-between p-3 border-t border-white/5 hidden" id="linha-conta-destino">
                        <span class="text-gray-300 font-medium">Conta Destino</span>
                        <div class="flex items-center text-white/70">
                            <select class="bg-transparent text-right focus:outline-none appearance-none pr-4 cursor-pointer" dir="rtl">
                                <option value="" class="text-gray-900">Selecione ></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Botão Mais Opções -->
                <div class="flex justify-center my-6">
                    <button type="button" onclick="toggleMaisOpcoes()" id="btn-mais-opcoes" class="px-6 py-2 rounded-full border border-white/30 text-white/60 hover:text-white hover:bg-white/5 hover:border-white/50 text-sm font-semibold tracking-wide transition-all uppercase">
                        Mais Opções
                    </button>
                </div>

                <!-- Sessão Mais Opções (Avançadas) -->
                <div id="mais-opcoes" class="hidden opacity-0 transition-opacity duration-500">
                    <div class="bg-white/5 rounded-3xl p-2 space-y-1 mb-6">
                        <!-- Nota -->
                        <div class="flex items-center justify-between p-3 border-b border-white/5">
                            <span class="text-gray-300 font-medium">Nota / Observação</span>
                            <input type="text" class="bg-transparent text-right text-white placeholder-white/40 focus:outline-none w-full ml-4" placeholder="Adicionar >" id="notas">
                        </div>

                        <!-- Segmented Control Recorrência -->
                        <div class="p-3">
                            <div class="flex rounded-xl bg-black/20 p-1">
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white/60 hover:text-white transition-all bg-transparent" id="tab-nenhuma">Nenhuma</button>
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white/60 hover:text-white transition-all bg-transparent" id="tab-parcelamento">Parcelamento</button>
                                <!-- Cor de ativação segue o tema da transação -->
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white shadow bg-white/20 transition-all" id="tab-avancada">Avançada</button>
                            </div>
                        </div>

                        <!-- Opções Avançadas de Recorrência -->
                        <div id="opcoes-avancadas-conteudo" class="p-2 space-y-1">
                            <div class="flex items-center justify-between p-2 border-b border-white/5">
                                <span class="text-gray-300 font-medium">Intervalo</span>
                                <select class="bg-transparent text-right text-white focus:outline-none appearance-none pr-4 cursor-pointer" dir="rtl">
                                    <option value="1" class="text-gray-900" selected>1 mês</option>
                                    <option value="2" class="text-gray-900">1 semana</option>
                                </select>
                            </div>
                            <div class="flex items-center justify-between p-2 border-b border-white/5">
                                <span class="text-gray-300 font-medium">Indefinidamente</span>
                                <div class="relative inline-block w-12 mr-2 align-middle select-none transition duration-200 ease-in">
                                    <input type="checkbox" name="indefinido" id="indefinido" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 border-gray-400 appearance-none cursor-pointer transition-all duration-300"/>
                                    <label for="indefinido" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-400 cursor-pointer transition-colors duration-300"></label>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-2 border-b border-white/5">
                                <span class="text-gray-300 font-medium">Nº de Ocorrências</span>
                                <input type="number" class="bg-transparent text-right text-white focus:outline-none w-16" value="1" min="1">
                            </div>
                            <div class="flex items-center justify-between p-2 border-b border-white/5">
                                <span class="text-gray-300 font-medium">Parcela Início</span>
                                <input type="number" class="bg-transparent text-right text-white focus:outline-none w-16" value="1" min="1">
                            </div>
                            <div class="flex items-center justify-between p-2">
                                <span class="text-gray-300 font-medium">Totalizando</span>
                                <span class="text-white/50">-</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Teclado Numérico Customizado (Oculto por Padrão) -->
        <div id="numpad" class="absolute bottom-0 left-0 right-0 bg-[#e2e8f0]/90 backdrop-blur-xl rounded-t-[2.5rem] p-6 transform translate-y-full transition-transform duration-300 z-50 shadow-[0_-10px_40px_rgba(0,0,0,0.3)] mx-2 sm:mx-0">
            <div class="flex justify-between items-center mb-4">
                <span class="text-gray-800 font-semibold pl-2">Digite o valor</span>
                <button onclick="closeNumpad()" class="text-gray-500 hover:text-gray-800 p-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="grid grid-cols-4 gap-3">
                <!-- Coluna 1-3: Números -->
                <div class="col-span-3 grid grid-cols-3 gap-3">
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('7')">7</button>
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('8')">8</button>
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('9')">9</button>
                    
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('4')">4</button>
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('5')">5</button>
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('6')">6</button>
                    
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('1')">1</button>
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('2')">2</button>
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('3')">3</button>
                    
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addComma()">,</button>
                    <button class="numpad-btn bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('0')">0</button>
                    <button class="numpad-btn bg-gray-500 rounded-full h-16 text-2xl font-medium text-white shadow-sm active:bg-gray-600 transition-colors flex items-center justify-center" onclick="backspace()">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414 6.414a2 2 0 001.414.586H19a2 2 0 002-2V7a2 2 0 00-2-2h-8.172a2 2 0 00-1.414.586L3 12z"></path></svg>
                    </button>
                </div>
                <!-- Coluna 4: Operações/OK -->
                <div class="col-span-1 grid grid-rows-4 gap-3 bg-white rounded-[2rem] p-2 shadow-sm">
                    <button class="text-2xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full">÷</button>
                    <button class="text-2xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full">×</button>
                    <button class="text-3xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full">-</button>
                    <button class="text-3xl text-gray-600 font-medium active:bg-gray-100 rounded-full h-full">+</button>
                </div>
            </div>
            <button class="w-full mt-3 bg-gradient-to-r from-orange-400 to-orange-500 rounded-full h-14 text-white text-2xl font-bold shadow-lg hover:from-orange-500 hover:to-orange-600 transition-all active:scale-95" onclick="closeNumpad()">
                OK
            </button>
        </div>

    </div>

    <!-- Scripts de Interação da UI -->
    <script>
        // Lógica de Troca de Tema (Despesa, Receita, Transferência)
        const selectTipo = document.getElementById('tipo-transacao');
        const body = document.getElementById('app-body');
        const iconSeta = document.getElementById('icon-seta');
        const linhaCategoria = document.getElementById('linha-categoria');
        const linhaContaDestino = document.getElementById('linha-conta-destino');
        const labelContaOrigem = document.getElementById('label-conta-origem');

        selectTipo.addEventListener('change', function() {
            // Remove classes antigas
            body.classList.remove('theme-despesa', 'theme-receita', 'theme-transferencia');
            // Adiciona a nova
            body.classList.add(`theme-${this.value}`);

            // Atualiza o ícone
            if (this.value === 'despesa') {
                iconSeta.setAttribute('d', 'M19 14l-7 7m0 0l-7-7m7 7V3'); // Seta pra baixo
                iconSeta.classList.replace('text-emerald-400', 'text-white');
                iconSeta.classList.replace('text-blue-400', 'text-white');
                
                linhaCategoria.classList.remove('hidden');
                linhaContaDestino.classList.add('hidden');
                labelContaOrigem.textContent = "Conta";
            } else if (this.value === 'receita') {
                iconSeta.setAttribute('d', 'M5 10l7-7m0 0l7 7m-7-7v18'); // Seta pra cima
                linhaCategoria.classList.remove('hidden');
                linhaContaDestino.classList.add('hidden');
                labelContaOrigem.textContent = "Conta";
            } else if (this.value === 'transferencia') {
                iconSeta.setAttribute('d', 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'); // Seta horizontal cruzada
                linhaCategoria.classList.add('hidden');
                linhaContaDestino.classList.remove('hidden');
                labelContaOrigem.textContent = "Conta Origem";
            }
        });

        // Lógica "Mais Opções"
        let maisOpcoesAberto = false;
        function toggleMaisOpcoes() {
            const container = document.getElementById('mais-opcoes');
            const btn = document.getElementById('btn-mais-opcoes');
            maisOpcoesAberto = !maisOpcoesAberto;

            if (maisOpcoesAberto) {
                container.classList.remove('hidden');
                setTimeout(() => container.classList.remove('opacity-0'), 10);
                btn.classList.add('bg-white/10', 'border-white/50', 'text-white');
            } else {
                container.classList.add('opacity-0');
                btn.classList.remove('bg-white/10', 'border-white/50', 'text-white');
                setTimeout(() => container.classList.add('hidden'), 500);
            }
        }

        // Segmented Control das Opções Avançadas
        const tabs = ['nenhuma', 'parcelamento', 'avancada'];
        tabs.forEach(tab => {
            document.getElementById(`tab-${tab}`).addEventListener('click', function() {
                // Reseta todas
                tabs.forEach(t => {
                    document.getElementById(`tab-${t}`).classList.remove('bg-white/20', 'text-white', 'shadow');
                    document.getElementById(`tab-${t}`).classList.add('text-white/60');
                });
                // Ativa a clicada
                this.classList.add('bg-white/20', 'text-white', 'shadow');
                this.classList.remove('text-white/60');
                
                // Exibe conteúdo condicionalmente (aqui apenas para ilustrar, deixamos sempre visível na avançada/parcelamento)
                const conteudo = document.getElementById('opcoes-avancadas-conteudo');
                if (tab === 'nenhuma') {
                    conteudo.classList.add('hidden');
                } else {
                    conteudo.classList.remove('hidden');
                }
            });
        });

        // Lógica do Numpad (Teclado Numérico)
        let valorAtual = "000"; // Armazena os digitos sem virgula
        
        function updateDisplay() {
            const display = document.getElementById('display-valor');
            // Formata o número (ex: 000 -> 0,00 | 1450 -> 14,50 | 145050 -> 1.450,50)
            let num = parseInt(valorAtual, 10);
            if (isNaN(num)) num = 0;
            
            let formatado = (num / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            display.textContent = formatado;
        }

        function addNumber(n) {
            if (valorAtual === "000") valorAtual = "";
            if (valorAtual.length < 12) {
                valorAtual += n;
                updateDisplay();
            }
        }

        function addComma() {
            // Apenas ilustrativo, o sistema base é x100
        }

        function backspace() {
            if (valorAtual.length > 1) {
                valorAtual = valorAtual.slice(0, -1);
            } else {
                valorAtual = "000";
            }
            updateDisplay();
        }

        function toggleNumpad() {
            const numpad = document.getElementById('numpad');
            // Remove a classe de translate que esconde o teclado
            numpad.classList.remove('translate-y-full');
        }

        function closeNumpad() {
            const numpad = document.getElementById('numpad');
            numpad.classList.add('translate-y-full');
        }

    </script>
</body>
</html>
