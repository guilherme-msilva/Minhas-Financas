<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'conexao.php';
$user_id = $_SESSION['user_id'];

// Buscar Categorias
$stmt = $mysqliFinancas->prepare("SELECT id, nome, cor FROM categorias WHERE id_user = ? ORDER BY nome ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$categorias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Buscar Contas
$stmt = $mysqliFinancas->prepare("SELECT id, nome, cor FROM contas WHERE id_user = ? AND status = 1 ORDER BY nome ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$contas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
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

        /* Esconder barra de rolagem */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-screen relative theme-despesa" id="app-body">
    
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <?php include 'menu.php'; ?>

    <div class="max-w-md mx-auto relative h-[85vh] md:h-[80vh] flex flex-col mb-10 overflow-hidden">
        
        <!-- Formulário Principal -->
        <div id="main-view" class="bg-white/10 backdrop-blur-2xl border border-white/20 rounded-[2.5rem] shadow-2xl h-full flex flex-col relative mx-2 sm:mx-0 z-10 transition-transform duration-300">
            
            <!-- Cabeçalho (Header) dinâmico -->
            <div id="header-area" class="header-glass p-6 transition-all duration-500 border-b relative shrink-0">
                <div class="flex justify-between items-center mb-6">
                    <button class="text-white/80 hover:text-white font-medium">Cancelar</button>
                    <span id="header-title" class="text-white font-semibold text-lg tracking-wide">Nova Despesa</span>
                    <button class="text-white font-bold tracking-wide">Salvar</button>
                </div>

                <div class="flex items-center justify-between mb-2">
                    <button type="button" onclick="toggleTypeSelect()" class="w-12 h-12 rounded-2xl border border-white/40 flex items-center justify-center bg-white/10 hover:bg-white/20 transition-colors cursor-pointer z-20">
                        <svg id="icon-seta" class="w-6 h-6 text-white transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>
                    </button>
                    
                    <!-- Campo de Valor Clicável -->
                    <div class="flex-1 text-right ml-4 cursor-pointer relative z-10" onclick="toggleNumpad()">
                        <span class="text-4xl md:text-5xl font-bold text-white tracking-tight" id="display-valor">R$ 0,00</span>
                    </div>
                </div>

                <!-- Action Sheet de Seleção de Tipo -->
                <div id="type-selector" class="absolute top-[85px] left-6 bg-white/95 backdrop-blur-3xl rounded-2xl shadow-[0_10px_40px_rgba(0,0,0,0.5)] border border-white/20 w-48 overflow-hidden hidden opacity-0 transition-opacity duration-200 z-50">
                    <button onclick="setTipo('despesa')" class="w-full text-left px-4 py-3 border-b border-gray-100 flex items-center space-x-3 hover:bg-gray-50 transition-colors">
                        <span class="w-6 h-6 rounded-full bg-red-100 text-red-500 flex items-center justify-center"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg></span>
                        <span class="text-gray-800 font-medium">Despesa</span>
                    </button>
                    <button onclick="setTipo('receita')" class="w-full text-left px-4 py-3 border-b border-gray-100 flex items-center space-x-3 hover:bg-gray-50 transition-colors">
                        <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-500 flex items-center justify-center"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg></span>
                        <span class="text-gray-800 font-medium">Receita</span>
                    </button>
                    <button onclick="setTipo('transferencia')" class="w-full text-left px-4 py-3 flex items-center space-x-3 hover:bg-gray-50 transition-colors">
                        <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-500 flex items-center justify-center"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg></span>
                        <span class="text-gray-800 font-medium">Transferência</span>
                    </button>
                </div>
                <!-- Overlay transparente para fechar seletor de tipo -->
                <div id="type-selector-overlay" onclick="toggleTypeSelect()" class="fixed inset-0 z-40 hidden"></div>
            </div>

            <!-- Formulário Lista -->
            <div class="flex-1 overflow-y-auto no-scrollbar p-2">
                <div class="bg-white/5 rounded-3xl p-2 space-y-1 my-4">
                    
                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <span class="text-gray-300 font-medium">Data da Transação</span>
                        <input type="date" class="bg-transparent text-right text-white focus:outline-none w-32" id="data" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <span class="text-gray-300 font-medium whitespace-nowrap mr-4">Descrição</span>
                        <input type="text" class="bg-transparent text-right text-white placeholder-white/40 focus:outline-none w-full" placeholder="Ex: Mercado" id="descricao">
                    </div>

                    <div class="flex items-center justify-between p-3 border-b border-white/5">
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-300 font-medium">Consolidada</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="relative inline-block w-12 mr-2 align-middle select-none transition duration-200 ease-in">
                                <input type="checkbox" name="consolidada" id="consolidada" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 border-gray-400 appearance-none cursor-pointer transition-all duration-300" checked/>
                                <label for="consolidada" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-400 cursor-pointer transition-colors duration-300"></label>
                            </div>
                            <button class="text-gray-400 hover:text-white rounded-full border border-gray-400 w-5 h-5 flex items-center justify-center text-xs font-bold transition-colors">i</button>
                        </div>
                    </div>

                    <!-- Botão que abre a seleção de Categoria -->
                    <div class="flex items-center justify-between p-3 border-b border-white/5 cursor-pointer hover:bg-white/5 rounded-xl transition-colors" id="linha-categoria" onclick="openPanel('panel-categoria')">
                        <span class="text-gray-300 font-medium">Categoria</span>
                        <div class="flex items-center text-white/70 space-x-2">
                            <span id="display-categoria" class="text-white">Selecionar</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>

                    <!-- Botão que abre a seleção de Conta -->
                    <div class="flex items-center justify-between p-3 border-b border-white/5 cursor-pointer hover:bg-white/5 rounded-xl transition-colors" onclick="openPanel('panel-conta')">
                        <span class="text-gray-300 font-medium" id="label-conta-origem">Conta</span>
                        <div class="flex items-center text-white/70 space-x-2">
                            <span id="display-conta" class="text-white">Selecionar</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                    
                    <!-- Botão que abre a seleção de Conta Destino -->
                    <div class="flex items-center justify-between p-3 border-white/5 cursor-pointer hover:bg-white/5 rounded-xl transition-colors hidden" id="linha-conta-destino" onclick="openPanel('panel-conta-destino')">
                        <span class="text-gray-300 font-medium">Conta Destino</span>
                        <div class="flex items-center text-white/70 space-x-2">
                            <span id="display-conta-destino" class="text-white">Selecionar</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </div>

                <!-- Botão Mais Opções -->
                <div class="flex justify-center my-6">
                    <button type="button" onclick="toggleMaisOpcoes()" id="btn-mais-opcoes" class="px-6 py-2 rounded-full border border-white/30 text-white/60 hover:text-white hover:bg-white/5 hover:border-white/50 text-sm font-semibold tracking-wide transition-all uppercase">
                        Mais Opções
                    </button>
                </div>

                <!-- Sessão Mais Opções -->
                <div id="mais-opcoes" class="hidden opacity-0 transition-opacity duration-500 pb-6">
                    <div class="bg-white/5 rounded-3xl p-2 space-y-1">
                        <!-- Nota -->
                        <div class="flex items-center justify-between p-3 border-b border-white/5">
                            <span class="text-gray-300 font-medium">Nota</span>
                            <input type="text" class="bg-transparent text-right text-white placeholder-white/40 focus:outline-none w-full ml-4" placeholder="Adicionar >" id="notas">
                        </div>

                        <!-- Recorrência Tabs -->
                        <div class="p-3">
                            <div class="flex rounded-xl bg-black/20 p-1">
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white shadow bg-white/20 transition-all" id="tab-nenhuma">Nenhuma</button>
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white/60 hover:text-white transition-all bg-transparent" id="tab-parcelamento">Parcelamento</button>
                                <button class="flex-1 py-2 text-sm font-medium rounded-lg text-white/60 hover:text-white transition-all bg-transparent" id="tab-avancada">Avançada</button>
                            </div>
                        </div>

                        <!-- Opções Avançadas -->
                        <div id="opcoes-avancadas-conteudo" class="p-2 space-y-1 hidden">
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
                                    <input type="checkbox" id="indefinido" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 border-gray-400 appearance-none cursor-pointer transition-all duration-300"/>
                                    <label for="indefinido" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-400 cursor-pointer transition-colors duration-300"></label>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-2 border-b border-white/5">
                                <span class="text-gray-300 font-medium">Ocorrências</span>
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
        </div> <!-- End Main View -->

        <!-- Side Panels para Categoria e Contas -->
        
        <!-- Panel Categoria -->
        <div id="panel-categoria" class="absolute inset-0 bg-slate-900/95 backdrop-blur-3xl rounded-[2.5rem] z-30 translate-x-full transition-transform duration-300 flex flex-col mx-2 sm:mx-0 shadow-2xl">
            <div class="p-6 border-b border-white/10 flex items-center justify-between shrink-0">
                <button onclick="closePanel('panel-categoria')" class="text-cyan-400 hover:text-cyan-300 flex items-center space-x-1 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    <span>Voltar</span>
                </button>
                <span class="text-white font-semibold">Selecionar Categoria</span>
                <div class="w-16"></div> <!-- Espaçador -->
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <?php if(count($categorias) > 0): ?>
                    <div class="bg-white/5 rounded-3xl overflow-hidden border border-white/10">
                        <?php foreach($categorias as $cat): ?>
                            <button onclick="selectItem('categoria', '<?php echo $cat['id']; ?>', '<?php echo addslashes($cat['nome']); ?>')" class="w-full text-left p-4 border-b border-white/5 hover:bg-white/10 transition-colors flex items-center space-x-3 last:border-b-0">
                                <div class="w-4 h-4 rounded-full" style="background-color: <?php echo $cat['cor'] ?: '#ccc'; ?>"></div>
                                <span class="text-white font-medium"><?php echo htmlspecialchars($cat['nome']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-white/50 mt-10">Nenhuma categoria encontrada.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel Conta Origem -->
        <div id="panel-conta" class="absolute inset-0 bg-slate-900/95 backdrop-blur-3xl rounded-[2.5rem] z-30 translate-x-full transition-transform duration-300 flex flex-col mx-2 sm:mx-0 shadow-2xl">
            <div class="p-6 border-b border-white/10 flex items-center justify-between shrink-0">
                <button onclick="closePanel('panel-conta')" class="text-cyan-400 hover:text-cyan-300 flex items-center space-x-1 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    <span>Voltar</span>
                </button>
                <span class="text-white font-semibold" id="title-panel-conta">Selecionar Conta</span>
                <div class="w-16"></div>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <?php if(count($contas) > 0): ?>
                    <div class="bg-white/5 rounded-3xl overflow-hidden border border-white/10">
                        <?php foreach($contas as $conta): ?>
                            <button onclick="selectItem('conta', '<?php echo $conta['id']; ?>', '<?php echo addslashes($conta['nome']); ?>')" class="w-full text-left p-4 border-b border-white/5 hover:bg-white/10 transition-colors flex items-center space-x-3 last:border-b-0">
                                <div class="w-4 h-4 rounded-full" style="background-color: <?php echo $conta['cor'] ?: '#ccc'; ?>"></div>
                                <span class="text-white font-medium"><?php echo htmlspecialchars($conta['nome']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-white/50 mt-10">Nenhuma conta encontrada.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel Conta Destino -->
        <div id="panel-conta-destino" class="absolute inset-0 bg-slate-900/95 backdrop-blur-3xl rounded-[2.5rem] z-30 translate-x-full transition-transform duration-300 flex flex-col mx-2 sm:mx-0 shadow-2xl">
            <div class="p-6 border-b border-white/10 flex items-center justify-between shrink-0">
                <button onclick="closePanel('panel-conta-destino')" class="text-cyan-400 hover:text-cyan-300 flex items-center space-x-1 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    <span>Voltar</span>
                </button>
                <span class="text-white font-semibold">Selecionar Conta Destino</span>
                <div class="w-16"></div>
            </div>
            <div class="flex-1 overflow-y-auto no-scrollbar p-4">
                <?php if(count($contas) > 0): ?>
                    <div class="bg-white/5 rounded-3xl overflow-hidden border border-white/10">
                        <?php foreach($contas as $conta): ?>
                            <button onclick="selectItem('conta-destino', '<?php echo $conta['id']; ?>', '<?php echo addslashes($conta['nome']); ?>')" class="w-full text-left p-4 border-b border-white/5 hover:bg-white/10 transition-colors flex items-center space-x-3 last:border-b-0">
                                <div class="w-4 h-4 rounded-full" style="background-color: <?php echo $conta['cor'] ?: '#ccc'; ?>"></div>
                                <span class="text-white font-medium"><?php echo htmlspecialchars($conta['nome']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-white/50 mt-10">Nenhuma conta encontrada.</div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>

    <!-- Overlay do Numpad (para fechar ao clicar fora) -->
    <div id="numpad-overlay" onclick="closeNumpad()" class="fixed inset-0 bg-black/20 backdrop-blur-sm z-40 hidden opacity-0 transition-opacity duration-300"></div>

    <!-- Teclado Numérico Customizado -->
    <div id="numpad" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto bg-[#e2e8f0]/95 backdrop-blur-2xl rounded-t-[2.5rem] p-6 transform translate-y-full transition-transform duration-300 z-50 shadow-[0_-10px_40px_rgba(0,0,0,0.3)]">
        <div class="flex justify-between items-center mb-4">
            <span class="text-gray-800 font-semibold pl-2">Digite o valor</span>
            <button onclick="closeNumpad()" class="text-gray-500 hover:text-gray-800 p-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="grid grid-cols-4 gap-3">
            <div class="col-span-3 grid grid-cols-3 gap-3">
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('7')">7</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('8')">8</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('9')">9</button>
                
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('4')">4</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('5')">5</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('6')">6</button>
                
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('1')">1</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('2')">2</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('3')">3</button>
                
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addComma()">,</button>
                <button class="bg-white rounded-full h-16 text-2xl font-medium text-gray-800 shadow-sm active:bg-gray-200 transition-colors" onclick="addNumber('0')">0</button>
                <button class="bg-gray-500 rounded-full h-16 text-2xl font-medium text-white shadow-sm active:bg-gray-600 transition-colors flex items-center justify-center" onclick="backspace()">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414 6.414a2 2 0 001.414.586H19a2 2 0 002-2V7a2 2 0 00-2-2h-8.172a2 2 0 00-1.414.586L3 12z"></path></svg>
                </button>
            </div>
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

    <!-- Hidden form values para enviar ao backend depois -->
    <form id="transacao-form" method="POST" action="salvar_transacao.php" class="hidden">
        <input type="hidden" name="tipo" id="input-tipo" value="despesa">
        <input type="hidden" name="valor" id="input-valor" value="0.00">
        <input type="hidden" name="id_categoria" id="input-categoria" value="">
        <input type="hidden" name="id_conta" id="input-conta" value="">
        <input type="hidden" name="id_conta_destino" id="input-conta-destino" value="">
    </form>

    <script>
        // Lógica de Seletor de Tipo (Despesa, Receita, Transferencia)
        const typeSelector = document.getElementById('type-selector');
        const typeSelectorOverlay = document.getElementById('type-selector-overlay');
        const headerTitle = document.getElementById('header-title');
        const body = document.getElementById('app-body');
        const iconSeta = document.getElementById('icon-seta');
        
        function toggleTypeSelect() {
            if (typeSelector.classList.contains('hidden')) {
                typeSelector.classList.remove('hidden');
                typeSelectorOverlay.classList.remove('hidden');
                setTimeout(() => typeSelector.classList.remove('opacity-0'), 10);
            } else {
                typeSelector.classList.add('opacity-0');
                typeSelectorOverlay.classList.add('hidden');
                setTimeout(() => typeSelector.classList.add('hidden'), 200);
            }
        }

        function setTipo(tipo) {
            document.getElementById('input-tipo').value = tipo;
            body.classList.remove('theme-despesa', 'theme-receita', 'theme-transferencia');
            body.classList.add(`theme-${tipo}`);
            
            const linhaCat = document.getElementById('linha-categoria');
            const linhaContaDest = document.getElementById('linha-conta-destino');
            const labelContaOri = document.getElementById('label-conta-origem');
            const titleContaOri = document.getElementById('title-panel-conta');

            if(tipo === 'despesa') {
                headerTitle.textContent = "Nova Despesa";
                iconSeta.setAttribute('d', 'M19 14l-7 7m0 0l-7-7m7 7V3'); // Seta baixo
                linhaCat.classList.remove('hidden');
                linhaContaDest.classList.add('hidden');
                labelContaOri.textContent = "Conta";
                titleContaOri.textContent = "Selecionar Conta";
            } else if(tipo === 'receita') {
                headerTitle.textContent = "Nova Receita";
                iconSeta.setAttribute('d', 'M5 10l7-7m0 0l7 7m-7-7v18'); // Seta cima
                linhaCat.classList.remove('hidden');
                linhaContaDest.classList.add('hidden');
                labelContaOri.textContent = "Conta";
                titleContaOri.textContent = "Selecionar Conta";
            } else if(tipo === 'transferencia') {
                headerTitle.textContent = "Transferência";
                iconSeta.setAttribute('d', 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'); // Duas setas
                linhaCat.classList.add('hidden');
                linhaContaDest.classList.remove('hidden');
                labelContaOri.textContent = "Conta Origem";
                titleContaOri.textContent = "Selecionar Conta Origem";
            }
            toggleTypeSelect(); // fecha o menu
        }

        // Lógica de "Mais Opções"
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

        // Tabs Recorrência
        const tabs = ['nenhuma', 'parcelamento', 'avancada'];
        tabs.forEach(tab => {
            document.getElementById(`tab-${tab}`).addEventListener('click', function() {
                tabs.forEach(t => {
                    document.getElementById(`tab-${t}`).classList.remove('bg-white/20', 'text-white', 'shadow');
                    document.getElementById(`tab-${t}`).classList.add('text-white/60', 'bg-transparent');
                });
                this.classList.add('bg-white/20', 'text-white', 'shadow');
                this.classList.remove('text-white/60', 'bg-transparent');
                
                const conteudo = document.getElementById('opcoes-avancadas-conteudo');
                if (tab === 'nenhuma') {
                    conteudo.classList.add('hidden');
                } else {
                    conteudo.classList.remove('hidden');
                }
            });
        });

        // Numpad Lógica
        let valorAtual = "000"; 
        
        function updateDisplay() {
            const display = document.getElementById('display-valor');
            let num = parseInt(valorAtual, 10);
            if (isNaN(num)) num = 0;
            
            // Para o input hidden do backend
            document.getElementById('input-valor').value = (num / 100).toFixed(2);
            
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

        function addComma() {}

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
            const overlay = document.getElementById('numpad-overlay');
            numpad.classList.remove('translate-y-full');
            overlay.classList.remove('hidden');
            setTimeout(() => overlay.classList.remove('opacity-0'), 10);
        }

        function closeNumpad() {
            const numpad = document.getElementById('numpad');
            const overlay = document.getElementById('numpad-overlay');
            numpad.classList.add('translate-y-full');
            overlay.classList.add('opacity-0');
            setTimeout(() => overlay.classList.add('hidden'), 300);
        }

        // Lógica dos Painéis Deslizantes (Side Panels)
        function openPanel(panelId) {
            document.getElementById(panelId).classList.remove('translate-x-full');
            document.getElementById('main-view').classList.add('-translate-x-8', 'opacity-50', 'scale-95');
        }

        function closePanel(panelId) {
            document.getElementById(panelId).classList.add('translate-x-full');
            document.getElementById('main-view').classList.remove('-translate-x-8', 'opacity-50', 'scale-95');
        }

        function selectItem(tipo, id, nome) {
            document.getElementById(`display-${tipo}`).textContent = nome;
            document.getElementById(`input-${tipo}`).value = id;
            closePanel(`panel-${tipo}`);
        }
    </script>
</body>
</html>
