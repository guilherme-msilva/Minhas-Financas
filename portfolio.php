<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$titulo_pagina = "Portfólio de Investimentos";
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
require_once 'header.php';
require_once 'menu.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 animate-fade-in-up">
    
    <!-- Cabeçalho e Ações -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-tight">Portfólio de Investimentos</h1>
            <p class="text-slate-500 dark:text-gray-400 mt-1">Acompanhe e gerencie seu patrimônio</p>
        </div>
        <div class="flex gap-3">
            <button onclick="openImportModal()" class="bg-white/60 dark:bg-slate-800 border border-gray-200 dark:border-white/10 hover:bg-gray-50 dark:hover:bg-slate-700 text-slate-700 dark:text-gray-200 px-4 py-2 rounded-xl text-sm font-semibold shadow-sm transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Importar CSV
            </button>
            <button onclick="openEditModal()" class="bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white px-4 py-2 rounded-xl text-sm font-bold shadow-lg transition-all transform hover:scale-105 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Novo Ativo
            </button>
        </div>
    </div>

    <!-- Loading State -->
    <div id="loading" class="flex justify-center items-center py-20">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-cyan-500"></div>
    </div>

    <div id="content" class="hidden">
        <!-- Resumo Patrimonial -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl p-6 shadow-lg relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 opacity-10">
                    <svg class="w-16 h-16 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <h3 class="text-sm font-medium text-slate-500 dark:text-gray-400">Patrimônio Total</h3>
                <p class="text-3xl font-bold text-slate-800 dark:text-white mt-2" id="total_portfolio">R$ 0,00</p>
                <p class="text-xs text-slate-400 dark:text-gray-500 mt-2">Valor atualizado via cotações em tempo real</p>
            </div>
            
            <div class="md:col-span-2 bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl p-6 shadow-lg flex items-center justify-center">
                <div class="w-full max-w-sm">
                    <canvas id="portfolioChart"></canvas>
                </div>
                <div class="ml-4 flex flex-col justify-center">
                    <button id="btn_drillup" onclick="resetChart()" class="hidden bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-3 py-1 rounded-full transition-colors">← Voltar</button>
                    <p class="text-xs text-gray-400 mt-2 text-center" id="chart_hint">Clique em uma fatia para ver os ativos</p>
                </div>
            </div>
        </div>

        <!-- Tabela de Ativos -->
        <div class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-white/10 flex justify-between items-center">
                <h2 class="text-lg font-bold text-slate-800 dark:text-white">Meus Ativos</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50/50 dark:bg-white/5 text-slate-500 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <th class="p-4 font-medium">Categoria</th>
                            <th class="p-4 font-medium">Ticker / Nome</th>
                            <th class="p-4 font-medium text-right">Qtd.</th>
                            <th class="p-4 font-medium text-right">Cotação Atual</th>
                            <th class="p-4 font-medium text-right">Total (BRL)</th>
                            <th class="p-4 font-medium text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="ativos_tbody" class="divide-y divide-gray-200 dark:divide-white/10 text-sm">
                        <!-- Preenchido via JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal Adicionar/Editar -->
<div id="editModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-sm flex items-center justify-center">
    <div class="bg-white dark:bg-slate-800 w-full max-w-md rounded-2xl shadow-2xl p-6 m-4 transform transition-all border border-gray-200 dark:border-white/10">
        <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-4" id="modalTitle">Novo Ativo</h3>
        <form id="formAtivo" onsubmit="saveAtivo(event)">
            <input type="hidden" id="ativo_id" name="id">
            <input type="hidden" name="action" value="add" id="ativo_action">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Categoria</label>
                <select id="ativo_categoria" name="id_categoria" required class="w-full rounded-xl border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                    <!-- Opções injetadas -->
                </select>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Ticker / Nome</label>
                <input type="text" id="ativo_ticker" name="ticker" required placeholder="Ex: PETR4.SA, AAPL, Tesouro Selic" class="w-full rounded-xl border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Quantidade</label>
                <input type="text" id="ativo_quantidade" name="quantidade" required placeholder="Ex: 100" class="w-full rounded-xl border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Valor Atual em Reais (Opcional - Apenas Renda Fixa/Manual)</label>
                <input type="text" id="ativo_valor_manual" name="valor_manual" placeholder="Ex: 5000,00" class="w-full rounded-xl border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <p class="text-xs text-gray-500 mt-1">Preencha apenas se não for usar a busca automática de cotação por Ticker.</p>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 rounded-xl text-sm font-medium text-slate-600 dark:text-gray-300 bg-gray-100 dark:bg-white/10 hover:bg-gray-200 dark:hover:bg-white/20 transition-colors">Cancelar</button>
                <button type="submit" class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 transition-all shadow-md">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Importar CSV -->
<div id="importModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-sm flex items-center justify-center">
    <div class="bg-white dark:bg-slate-800 w-full max-w-md rounded-2xl shadow-2xl p-6 m-4 transform transition-all border border-gray-200 dark:border-white/10">
        <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-4">Importar Ativos CSV</h3>
        <form id="formImport" onsubmit="importCSV(event)" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">O arquivo CSV deve conter duas colunas separadas por ponto-e-vírgula: <code class="bg-gray-100 dark:bg-slate-700 px-1 rounded">ticker;quantidade</code></p>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Arquivo CSV</label>
                <input type="file" name="csv_file" accept=".csv" required class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 dark:file:bg-cyan-900/30 dark:file:text-cyan-400">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Categoria (Aplicada a todos os itens)</label>
                <select id="import_categoria" name="id_categoria" required class="w-full rounded-xl border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                    <!-- Opções injetadas -->
                </select>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeImportModal()" class="px-4 py-2 rounded-xl text-sm font-medium text-slate-600 dark:text-gray-300 bg-gray-100 dark:bg-white/10 hover:bg-gray-200 dark:hover:bg-white/20 transition-colors">Cancelar</button>
                <button type="submit" id="btn_import" class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 transition-all shadow-md">Importar</button>
            </div>
        </form>
    </div>
</div>

<script>
let globalData = null;
let currentChart = null;
let expandedCategories = [];

const formatCurrency = (value, currency = 'BRL') => {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: currency }).format(value);
};

const formatNumber = (value) => {
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 6 }).format(value);
};

const cores = [
    '#06b6d4', // cyan-500
    '#3b82f6', // blue-500
    '#8b5cf6', // violet-500
    '#ec4899', // pink-500
    '#f59e0b', // amber-500
    '#10b981', // emerald-500
    '#6366f1', // indigo-500
    '#f43f5e'  // rose-500
];

function loadData() {
    document.getElementById('loading').classList.remove('hidden');
    document.getElementById('content').classList.add('hidden');
    
    fetch('portfolio_api.php')
        .then(res => res.json())
        .then(data => {
            globalData = data;
            document.getElementById('total_portfolio').innerText = formatCurrency(data.total_brl);
            
            renderTable(data.investimentos);
            populateCategorySelects(data.categorias);
            renderDynamicChart();
            
            document.getElementById('loading').classList.add('hidden');
            document.getElementById('content').classList.remove('hidden');
        })
        .catch(err => {
            console.error(err);
            alert("Erro ao carregar dados do portfólio.");
        });
}

function renderTable(investimentos) {
    const tbody = document.getElementById('ativos_tbody');
    tbody.innerHTML = '';
    
    investimentos.forEach(inv => {
        let catText = inv.macro_categoria_nome !== inv.categoria_nome ? `${inv.macro_categoria_nome} > ${inv.categoria_nome}` : inv.categoria_nome;
        let valorTotalStr = formatCurrency(inv.valor_brl);
        if (inv.valor_usd > 0) {
            valorTotalStr += ` <span class="text-xs text-gray-500 block">(${formatCurrency(inv.valor_usd, 'USD')})</span>`;
        }

        let cotacaoStr = inv.valor_manual ? '<span class="text-gray-400 italic">Manual</span>' : formatCurrency(inv.preco_unidade, inv.moeda);
        
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-gray-50 dark:hover:bg-white/5 transition-colors';
        tr.innerHTML = `
            <td class="p-4 text-slate-700 dark:text-gray-300 font-medium">${catText}</td>
            <td class="p-4 text-slate-800 dark:text-white font-bold">${inv.ticker}</td>
            <td class="p-4 text-slate-700 dark:text-gray-300 text-right">${formatNumber(inv.quantidade)}</td>
            <td class="p-4 text-slate-700 dark:text-gray-300 text-right">${cotacaoStr}</td>
            <td class="p-4 text-slate-800 dark:text-white font-bold text-right">${valorTotalStr}</td>
            <td class="p-4 text-center">
                <button onclick='editAtivo(${JSON.stringify(inv)})' class="text-blue-500 hover:text-blue-700 p-1"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></button>
                <button onclick="deleteAtivo(${inv.id})" class="text-red-500 hover:text-red-700 p-1"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function renderChart(labels, dataArr, title, onClickCallback) {
    const ctx = document.getElementById('portfolioChart').getContext('2d');
    if (currentChart) {
        currentChart.destroy();
    }
    
    currentChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: dataArr,
                backgroundColor: cores,
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: document.documentElement.classList.contains('dark') ? '#cbd5e1' : '#475569' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            if (label) label += ': ';
                            label += formatCurrency(context.raw);
                            return label;
                        }
                    }
                }
            },
            onClick: onClickCallback
        }
    });
}

function resetChart() {
    expandedCategories = [];
    renderDynamicChart();
}

function renderDynamicChart() {
    if (!globalData || Object.keys(globalData.chart_macro).length === 0) return;
    
    let labels = [];
    let dataArr = [];
    let sliceMetadata = [];

    for (let macroCat in globalData.chart_macro) {
        if (expandedCategories.includes(macroCat)) {
            // Expandir subcategorias/ativos
            const subData = globalData.chart_drilldown[macroCat];
            for (let subCat in subData) {
                labels.push(`${subCat} (${macroCat})`);
                dataArr.push(subData[subCat]);
                sliceMetadata.push({ isExpandedAsset: true, parentCat: macroCat });
            }
        } else {
            // Mostrar a categoria macro agrupada
            labels.push(macroCat);
            dataArr.push(globalData.chart_macro[macroCat]);
            sliceMetadata.push({ isExpandedAsset: false, parentCat: macroCat });
        }
    }
    
    if (expandedCategories.length > 0) {
        document.getElementById('btn_drillup').classList.remove('hidden');
        document.getElementById('chart_hint').classList.add('hidden');
    } else {
        document.getElementById('btn_drillup').classList.add('hidden');
        document.getElementById('chart_hint').classList.remove('hidden');
    }

    renderChart(labels, dataArr, 'Portfólio', (evt, activeElements) => {
        if (activeElements.length > 0) {
            const idx = activeElements[0].index;
            const meta = sliceMetadata[idx];
            
            if (meta.isExpandedAsset) {
                // Clique em um ativo expandido: recolhe a categoria pai
                expandedCategories = expandedCategories.filter(c => c !== meta.parentCat);
            } else {
                // Clique em uma categoria macro: expande a categoria
                if (!expandedCategories.includes(meta.parentCat)) {
                    expandedCategories.push(meta.parentCat);
                }
            }
            renderDynamicChart();
        }
    });
}

function populateCategorySelects(categorias) {
    let options = '';
    
    // Agrupar por parent
    let groups = { macro: [], subs: {} };
    categorias.forEach(c => {
        if (c.id_pai === null) groups.macro.push(c);
        else {
            if (!groups.subs[c.id_pai]) groups.subs[c.id_pai] = [];
            groups.subs[c.id_pai].push(c);
        }
    });

    groups.macro.forEach(m => {
        if (groups.subs[m.id]) {
            options += `<optgroup label="${m.nome}">`;
            groups.subs[m.id].forEach(s => {
                options += `<option value="${s.id}">${s.nome}</option>`;
            });
            options += `</optgroup>`;
        } else {
            options += `<option value="${m.id}">${m.nome}</option>`;
        }
    });
    
    document.getElementById('ativo_categoria').innerHTML = options;
    document.getElementById('import_categoria').innerHTML = options;
}

// Modals
function openEditModal() {
    document.getElementById('formAtivo').reset();
    document.getElementById('ativo_id').value = '';
    document.getElementById('ativo_action').value = 'add';
    document.getElementById('modalTitle').innerText = 'Novo Ativo';
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function editAtivo(inv) {
    document.getElementById('ativo_id').value = inv.id;
    document.getElementById('ativo_action').value = 'edit';
    document.getElementById('modalTitle').innerText = 'Editar Ativo';
    document.getElementById('ativo_categoria').value = inv.categoria_id;
    document.getElementById('ativo_ticker').value = inv.ticker;
    document.getElementById('ativo_quantidade').value = inv.quantidade.toString().replace('.', ',');
    document.getElementById('ativo_valor_manual').value = inv.valor_manual ? inv.valor_manual.toString().replace('.', ',') : '';
    document.getElementById('editModal').classList.remove('hidden');
}

function openImportModal() {
    document.getElementById('formImport').reset();
    document.getElementById('importModal').classList.remove('hidden');
}

function closeImportModal() {
    document.getElementById('importModal').classList.add('hidden');
}

// Actions
function saveAtivo(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fetch('portfolio_api.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                closeEditModal();
                loadData();
            } else alert('Erro ao salvar');
        });
}

function deleteAtivo(id) {
    if (confirm("Deseja realmente remover este ativo?")) {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch('portfolio_api.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(res => {
                if (res.success) loadData();
            });
    }
}

function importCSV(e) {
    e.preventDefault();
    document.getElementById('btn_import').innerText = 'Importando...';
    document.getElementById('btn_import').disabled = true;
    const fd = new FormData(e.target);
    fetch('portfolio_api.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            document.getElementById('btn_import').innerText = 'Importar';
            document.getElementById('btn_import').disabled = false;
            if (res.success) {
                closeImportModal();
                let msg = `Importação concluída!\nLinhas inseridas: ${res.inserted}\nLinhas ignoradas: ${res.skipped}`;
                if (res.errors && res.errors.length > 0) {
                    msg += `\n\nErros ocorridos:\n` + res.errors.slice(0, 10).join('\n');
                    if (res.errors.length > 10) msg += `\n...e mais ${res.errors.length - 10} erros.`;
                }
                alert(msg);
                loadData();
            } else alert(res.error || 'Erro na importação');
        })
        .catch(err => {
            document.getElementById('btn_import').innerText = 'Importar';
            document.getElementById('btn_import').disabled = false;
            alert('Erro de conexão ao tentar importar o arquivo.');
        });
}

// Init
document.addEventListener('DOMContentLoaded', loadData);
</script>

</body>
</html>
