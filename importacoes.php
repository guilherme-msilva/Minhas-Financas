<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
?>
<?php 
$page_title = "Importações - Minhas Finanças";
$extra_head = '
    <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.4.1/papaparse.min.js"></script>
    <style>
        .step-inactive { display: none; }
        .step-active { display: block; animation: fadeIn 0.5s ease-out; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .drop-zone { border: 2px dashed rgba(156, 163, 175, 0.5); transition: all 0.3s ease; }
        .dark .drop-zone { border: 2px dashed rgba(255,255,255,0.2); }
        .drop-zone.dragover { border-color: #06b6d4; background: rgba(6, 182, 212, 0.1); }
    </style>
';
include 'header.php'; 
?>

    <div class="hidden md:block">
        <?php include 'menu.php'; ?>
    </div>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-12 relative z-10">
        
        <div class="flex items-center space-x-4 mb-8">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Importar Transações</h1>
        </div>

        <div class="bg-white/60 dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-3xl shadow-[0_8px_32px_0_rgba(0,0,0,0.1)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.37)] p-6 md:p-10 relative overflow-hidden">
            
            <!-- Barra de Progresso Visível apenas se > passo 1 -->
            <div id="progress-container" class="hidden mb-8">
                <div class="w-full bg-slate-200 dark:bg-white/10 rounded-full h-2.5">
                    <div id="progress-bar" class="bg-gradient-to-r from-cyan-400 to-blue-500 h-2.5 rounded-full transition-all duration-500" style="width: 25%"></div>
                </div>
            </div>

            <!-- PASSO 1: Upload do Arquivo -->
            <div id="step-1" class="step-active text-center">
                <div class="mb-6">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-blue-500/30">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    </div>
                    <h2 class="text-2xl font-semibold text-slate-800 dark:text-white mb-2">Envie sua planilha CSV</h2>
                    <p class="text-slate-500 dark:text-white/60 text-sm max-w-md mx-auto">
                        O arquivo deve conter as colunas: <br/>
                        <span class="font-mono text-cyan-600 dark:text-cyan-300">Data Ocorrência, Descrição, Valor, Categoria, Conta</span>
                    </p>
                </div>

                <div id="drop-zone" class="drop-zone rounded-3xl p-10 cursor-pointer flex flex-col items-center justify-center relative overflow-hidden group">
                    <input type="file" id="file-input" accept=".csv" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                    <svg class="w-12 h-12 text-slate-300 dark:text-white/30 group-hover:text-cyan-500 dark:group-hover:text-cyan-400 transition-colors mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="text-slate-600 dark:text-white font-medium text-lg" id="drop-text">Clique ou arraste o arquivo aqui</span>
                </div>
            </div>

            <!-- PASSO 2: Validar Categorias -->
            <div id="step-2" class="step-inactive text-center">
                <div class="mb-6">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-orange-400 to-red-500 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-orange-500/30">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                    <h2 class="text-2xl font-semibold text-slate-800 dark:text-white mb-2">Categorias Não Encontradas</h2>
                    <p class="text-slate-600 dark:text-white/70">O arquivo contém categorias que ainda não existem no sistema:</p>
                </div>

                <div class="bg-white/50 dark:bg-white/5 rounded-2xl p-4 mb-6 max-h-40 overflow-y-auto border border-gray-200 dark:border-white/10 text-left">
                    <ul id="list-missing-categories" class="list-disc list-inside text-slate-700 dark:text-white/80 space-y-1"></ul>
                </div>

                <p class="text-sm text-cyan-700 dark:text-cyan-300 mb-8 bg-cyan-100 dark:bg-cyan-400/10 p-3 rounded-xl border border-cyan-200 dark:border-cyan-400/20">
                    Deseja importá-las automaticamente? Elas serão criadas dentro de uma nova categoria pai chamada <strong>"Importações"</strong>.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center space-y-3 sm:space-y-0 sm:space-x-4">
                    <button onclick="abortImport()" class="w-full sm:w-auto px-6 py-3 rounded-xl border border-gray-300 dark:border-white/20 text-slate-600 dark:text-white/70 hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-800 dark:hover:text-white transition-colors font-medium">
                        Abortar
                    </button>
                    <button onclick="approveCategories()" class="w-full sm:w-auto px-8 py-3 bg-gradient-to-r from-orange-400 to-red-500 hover:from-orange-500 hover:to-red-600 text-white rounded-xl font-bold shadow-lg transition-all transform hover:scale-105">
                        Sim, criar categorias
                    </button>
                </div>
            </div>

            <!-- PASSO 3: Validar Contas -->
            <div id="step-3" class="step-inactive text-center">
                <div class="mb-6">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-yellow-500/30">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                    </div>
                    <h2 class="text-2xl font-semibold text-slate-800 dark:text-white mb-2">Contas Não Encontradas</h2>
                    <p class="text-slate-600 dark:text-white/70">As seguintes contas do arquivo não existem no sistema:</p>
                </div>

                <div class="bg-white/50 dark:bg-white/5 rounded-2xl p-4 mb-6 max-h-40 overflow-y-auto border border-gray-200 dark:border-white/10 text-left">
                    <ul id="list-missing-accounts" class="list-disc list-inside text-slate-700 dark:text-white/80 space-y-1"></ul>
                </div>

                <p class="text-sm text-yellow-700 dark:text-yellow-300 mb-8 bg-yellow-100 dark:bg-yellow-400/10 p-3 rounded-xl border border-yellow-200 dark:border-yellow-400/20">
                    Deseja importá-las automaticamente? Elas serão criadas com saldo inicial de R$ 0,00.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center space-y-3 sm:space-y-0 sm:space-x-4">
                    <button onclick="abortImport()" class="w-full sm:w-auto px-6 py-3 rounded-xl border border-gray-300 dark:border-white/20 text-slate-600 dark:text-white/70 hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-800 dark:hover:text-white transition-colors font-medium">
                        Abortar
                    </button>
                    <button onclick="approveAccounts()" class="w-full sm:w-auto px-8 py-3 bg-gradient-to-r from-yellow-400 to-orange-500 hover:from-yellow-500 hover:to-orange-600 text-white rounded-xl font-bold shadow-lg transition-all transform hover:scale-105">
                        Sim, criar contas
                    </button>
                </div>
            </div>

            <!-- PASSO 4: Confirmação -->
            <div id="step-4" class="step-inactive text-center">
                <div class="mb-6">
                    <div class="w-20 h-20 rounded-full bg-gradient-to-br from-emerald-400 to-green-500 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-emerald-500/40 transform scale-110">
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                    <h2 class="text-3xl font-bold text-slate-800 dark:text-white mb-2">Tudo Pronto!</h2>
                    <p class="text-slate-600 dark:text-white/70 text-lg">O arquivo foi analisado com sucesso.</p>
                </div>

                <div class="bg-white/50 dark:bg-white/10 rounded-3xl p-6 mb-8 border border-gray-200 dark:border-white/20 inline-block text-left shadow-inner">
                    <p class="text-xl font-medium text-slate-800 dark:text-white mb-1">
                        <span id="final-count" class="font-bold text-emerald-600 dark:text-emerald-400 text-3xl mr-2">0</span> 
                        Transações prontas para importar
                    </p>
                    <p class="text-slate-500 dark:text-white/50 text-sm mt-2">Os dados serão consolidados imediatamente no seu painel.</p>
                </div>

                <div class="flex flex-col sm:flex-row items-center justify-center space-y-3 sm:space-y-0 sm:space-x-4">
                    <button onclick="abortImport()" class="w-full sm:w-auto px-6 py-3 rounded-xl border border-gray-300 dark:border-white/20 text-slate-600 dark:text-white/70 hover:bg-slate-100 dark:hover:bg-white/10 hover:text-slate-800 dark:hover:text-white transition-colors font-medium">
                        Cancelar
                    </button>
                    <button id="btn-final-import" onclick="executeImport()" class="w-full sm:w-auto px-10 py-4 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-bold shadow-[0_0_20px_rgba(6,182,212,0.4)] transition-all transform hover:scale-105">
                        Confirmar e Importar
                    </button>
                </div>
            </div>
            
            <!-- PASSO 5: Sucesso -->
            <div id="step-5" class="step-inactive text-center py-10">
                <h2 class="text-3xl font-bold text-emerald-600 dark:text-emerald-400 mb-4">Importação Concluída!</h2>
                <p class="text-slate-700 dark:text-white/80 mb-8 text-lg">As transações já estão disponíveis no sistema.</p>
                <a href="transacoes.php" class="px-8 py-3 bg-white/50 dark:bg-white/10 hover:bg-slate-100 dark:hover:bg-white/20 text-slate-800 dark:text-white rounded-xl font-semibold transition-colors border border-gray-300 dark:border-white/20">
                    Ver Transações
                </a>
            </div>

            <!-- Loader (Overlay) -->
            <div id="loader" class="absolute inset-0 bg-slate-900/80 backdrop-blur-sm z-50 hidden flex-col items-center justify-center">
                <div class="w-12 h-12 border-4 border-cyan-400 border-t-transparent rounded-full animate-spin mb-4"></div>
                <p class="text-white font-medium" id="loader-text">Processando...</p>
            </div>

        </div>
    </div>

    <!-- Menu Mobile Toggle fix -->
    <div class="fixed bottom-0 left-0 right-0 p-4 z-40 md:hidden bg-gradient-to-t from-white dark:from-slate-900 to-transparent">
        <a href="transacoes.php" class="w-full block text-center py-3 bg-slate-800 dark:bg-white/10 border border-transparent dark:border-white/20 text-white rounded-xl backdrop-blur-md shadow-lg">
            Voltar
        </a>
    </div>

    <script>
        let parsedTransactions = [];
        let missingCategories = [];
        let missingAccounts = [];
        let approvedMissingCategories = [];
        let approvedMissingAccounts = [];
        let willCreateCategories = false;
        let willCreateAccounts = false;

        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const dropText = document.getElementById('drop-text');

        // Eventos de Drag & Drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
        });
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
        });

        dropZone.addEventListener('drop', handleDrop, false);
        fileInput.addEventListener('change', function() {
            if(this.files.length) handleFiles(this.files[0]);
        });

        function handleDrop(e) {
            let dt = e.dataTransfer;
            let files = dt.files;
            if (files.length) handleFiles(files[0]);
        }

        function showLoader(text = "Processando...") {
            document.getElementById('loader-text').innerText = text;
            document.getElementById('loader').classList.remove('hidden');
            document.getElementById('loader').classList.add('flex');
        }

        function hideLoader() {
            document.getElementById('loader').classList.add('hidden');
            document.getElementById('loader').classList.remove('flex');
        }

        function setStep(step) {
            for(let i=1; i<=5; i++) {
                let el = document.getElementById('step-'+i);
                if(el) {
                    el.classList.remove('step-active');
                    el.classList.add('step-inactive');
                }
            }
            document.getElementById('step-'+step).classList.remove('step-inactive');
            document.getElementById('step-'+step).classList.add('step-active');
            
            // Progress bar
            const prog = document.getElementById('progress-container');
            const bar = document.getElementById('progress-bar');
            if (step === 1 || step === 5) {
                prog.classList.add('hidden');
            } else {
                prog.classList.remove('hidden');
                let pct = (step / 4) * 100;
                bar.style.width = pct + '%';
            }
        }

        function abortImport() {
            parsedTransactions = [];
            missingCategories = [];
            missingAccounts = [];
            approvedMissingCategories = [];
            approvedMissingAccounts = [];
            willCreateCategories = false;
            willCreateAccounts = false;
            fileInput.value = '';
            setStep(1);
        }

        function parseDate(str) {
            if(!str) return null;
            let parts = str.split('/');
            if(parts.length === 3) {
                let y = parts[2].substring(0,4);
                let m = parts[1].padStart(2, '0');
                let d = parts[0].padStart(2, '0');
                return `${y}-${m}-${d}`;
            }
            return null;
        }

        function parseValue(str) {
            if(!str) return 0;
            // Robust parsing for Brazilian format: "1.500,50" or "1500,50" or "-150,00"
            let sign = str.includes('-') ? -1 : 1;
            let clean = str.replace(/[^\d.,]/g, '');
            if (clean.includes('.') && clean.includes(',')) {
                let firstDot = clean.indexOf('.');
                let firstComma = clean.indexOf(',');
                if (firstDot < firstComma) {
                    clean = clean.replace(/\./g, '').replace(',', '.'); // 1.500,50 -> 1500.50
                } else {
                    clean = clean.replace(/,/g, ''); // 1,500.50 -> 1500.50
                }
            } else if (clean.includes(',')) {
                clean = clean.replace(',', '.'); // 1500,50 -> 1500.50
            }
            return (parseFloat(clean) || 0) * sign;
        }

        function handleFiles(file) {
            if (!file.name.toLowerCase().endsWith('.csv')) {
                alert("Por favor, envie um arquivo CSV.");
                return;
            }
            showLoader("Lendo arquivo...");
            
            Papa.parse(file, {
                header: true,
                skipEmptyLines: true,
                complete: function(results) {
                    processCSVData(results.data);
                },
                error: function() {
                    hideLoader();
                    alert("Erro ao ler o arquivo CSV.");
                }
            });
        }

        function processCSVData(data) {
            let transactions = [];
            let uniqueCategories = new Set();
            let uniqueAccounts = new Set();
            
            // Expected headers: Data Ocorrência, Descrição, Valor, Categoria, Conta
            // We'll normalize keys just in case
            data.forEach(row => {
                let dateStr = row['Data Ocorrência'] || row['Data Ocorrencia'] || row['Data'] || row['data'];
                let desc = row['Descrição'] || row['Descricao'] || row['descricao'];
                let valStr = row['Valor'] || row['valor'];
                let cat = row['Categoria'] || row['categoria'];
                let acc = row['Conta'] || row['conta'];
                
                if (dateStr && desc && valStr) {
                    let date = parseDate(dateStr);
                    let value = parseValue(valStr);
                    if (date) {
                        transactions.push({
                            date: date,
                            description: desc,
                            value: value,
                            category: cat || '',
                            account: acc || ''
                        });
                        if (cat) uniqueCategories.add(cat);
                        if (acc) uniqueAccounts.add(acc);
                    }
                }
            });
            
            if (transactions.length === 0) {
                hideLoader();
                alert("Nenhuma transação válida encontrada. Verifique os cabeçalhos das colunas do CSV.");
                abortImport();
                return;
            }
            
            parsedTransactions = transactions;
            checkDataInServer(Array.from(uniqueCategories), Array.from(uniqueAccounts));
        }

        function checkDataInServer(categories, accounts) {
            showLoader("Validando dados...");
            fetch('api_importacao.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'check',
                    categories: categories,
                    accounts: accounts
                })
            })
            .then(res => res.json())
            .then(data => {
                hideLoader();
                if (data.error) {
                    alert(data.error);
                    abortImport();
                    return;
                }
                
                missingCategories = data.missing_categories || [];
                missingAccounts = data.missing_accounts || [];
                
                nextWizardStep();
            })
            .catch(err => {
                hideLoader();
                alert("Erro ao comunicar com o servidor.");
                abortImport();
            });
        }

        function nextWizardStep() {
            // Se existem categorias faltando, vai pro passo 2
            if (missingCategories.length > 0) {
                document.getElementById('list-missing-categories').innerHTML = missingCategories.map(c => `<li>${c}</li>`).join('');
                setStep(2);
                return;
            }
            
            // Se não, e existem contas faltando, vai pro passo 3
            if (missingAccounts.length > 0) {
                document.getElementById('list-missing-accounts').innerHTML = missingAccounts.map(a => `<li>${a}</li>`).join('');
                setStep(3);
                return;
            }
            
            // Senão, vai direto pro passo 4
            document.getElementById('final-count').innerText = parsedTransactions.length;
            setStep(4);
        }

        function approveCategories() {
            willCreateCategories = true;
            approvedMissingCategories = [...missingCategories];
            missingCategories = []; // Cleared so nextWizardStep skips step 2
            nextWizardStep();
        }

        function approveAccounts() {
            willCreateAccounts = true;
            approvedMissingAccounts = [...missingAccounts];
            missingAccounts = []; // Cleared so nextWizardStep skips step 3
            nextWizardStep();
        }

        function executeImport() {
            showLoader("Importando dados para o banco...");
            document.getElementById('btn-final-import').disabled = true;
            
            fetch('api_importacao.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'import',
                    transactions: parsedTransactions,
                    create_categories: willCreateCategories,
                    create_accounts: willCreateAccounts,
                    missing_categories: willCreateCategories ? approvedMissingCategories : [],
                    missing_accounts: willCreateAccounts ? approvedMissingAccounts : []
                })
            })
            .then(res => res.json())
            .then(data => {
                hideLoader();
                if (data.error) {
                    alert(data.error);
                    document.getElementById('btn-final-import').disabled = false;
                    return;
                }
                if (data.success) {
                    setStep(5);
                }
            })
            .catch(err => {
                hideLoader();
                alert("Erro ao importar dados.");
                document.getElementById('btn-final-import').disabled = false;
            });
        }
    </script>
</body>
</html>
