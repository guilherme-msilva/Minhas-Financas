<?php 
$page_title = "Categorias de Investimento - Minhas Finanças";
include 'header.php'; 
?>

    <?php include 'menu.php'; ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative z-10">
        <!-- Header da Página -->
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-slate-800 dark:text-white tracking-wide">Categorias de Investimento</h1>
            <button onclick="openModal('add')" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Nova
            </button>
        </div>

        <div class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl shadow-lg p-6 mb-8 text-sm text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20">
            <strong>Aviso Global:</strong> As categorias de investimento são globais. Alterações nesta tela afetam a classificação dos ativos para todos os painéis e gráficos do sistema.
        </div>

        <!-- Loading -->
        <div id="loading" class="flex justify-center items-center py-20">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-cyan-500"></div>
        </div>

        <!-- Tabela -->
        <div id="content" class="hidden bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50/50 dark:bg-white/5 text-slate-500 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <th class="p-4 font-medium w-12 text-center">ID</th>
                            <th class="p-4 font-medium">Nome da Categoria</th>
                            <th class="p-4 font-medium text-center w-32">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="cat_tbody" class="divide-y divide-gray-200 dark:divide-white/10 text-sm">
                        <!-- Preenchido via JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Adicionar/Editar -->
    <div id="editModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-sm flex items-center justify-center opacity-0 transition-opacity duration-300">
        <div class="bg-white dark:bg-slate-800 w-full max-w-md rounded-2xl shadow-2xl p-6 m-4 transform scale-95 transition-transform duration-300 border border-gray-200 dark:border-white/10">
            <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-4" id="modalTitle">Nova Categoria</h3>
            <form id="formCat" onsubmit="saveCat(event)">
                <input type="hidden" id="cat_id" name="id">
                <input type="hidden" name="action" value="add" id="cat_action">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Nome</label>
                    <input type="text" id="cat_nome" name="nome" required placeholder="Ex: Tesouro Direto" class="w-full rounded-xl border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-700 dark:text-gray-300 mb-1">Categoria Pai (Opcional)</label>
                    <select id="cat_id_pai" name="id_pai" class="w-full rounded-xl border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                        <option value="">Nenhuma (Categoria Principal)</option>
                        <!-- Injetadas -->
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-gray-200 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-xl text-sm font-medium transition-colors shadow-lg shadow-cyan-500/30">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let allCategories = [];

        document.addEventListener('DOMContentLoaded', loadData);

        function loadData() {
            document.getElementById('loading').classList.remove('hidden');
            document.getElementById('content').classList.add('hidden');
            
            fetch('categorias_investimento_api.php')
                .then(res => res.json())
                .then(data => {
                    allCategories = data.categorias || [];
                    renderTable();
                    populateSelect();
                    
                    document.getElementById('loading').classList.add('hidden');
                    document.getElementById('content').classList.remove('hidden');
                })
                .catch(err => {
                    console.error(err);
                    alert("Erro ao carregar categorias.");
                });
        }

        function resolveCatName(id) {
            const cat = allCategories.find(c => c.id == id);
            return cat ? cat.nome : 'Desconhecida';
        }

        // Monta a hierarquia para exibição na tabela
        function getHierarchyPath(catId) {
            let path = [];
            let currentId = catId;
            let safety = 0;
            while(currentId && safety < 10) {
                const c = allCategories.find(x => x.id == currentId);
                if(c) {
                    path.unshift(c.nome);
                    currentId = c.id_pai;
                } else {
                    break;
                }
                safety++;
            }
            return path.join(' > ');
        }

        function renderTable() {
            const tbody = document.getElementById('cat_tbody');
            tbody.innerHTML = '';
            
            // Ordenar por path para agrupar categorias pai e filhas visualmente
            let sortedCats = [...allCategories].sort((a, b) => {
                let pathA = getHierarchyPath(a.id);
                let pathB = getHierarchyPath(b.id);
                if (pathA < pathB) return -1;
                if (pathA > pathB) return 1;
                return 0;
            });

            sortedCats.forEach(cat => {
                const pathText = getHierarchyPath(cat.id);
                // Calcula recuo visual básico com base no id_pai
                let isChild = cat.id_pai !== null;
                let childStyling = isChild ? `pl-8 text-slate-600 dark:text-gray-400 before:content-['└─'] before:mr-2` : `font-bold text-slate-800 dark:text-white`;

                const tr = document.createElement('tr');
                tr.className = 'hover:bg-gray-50 dark:hover:bg-white/5 transition-colors group';
                tr.innerHTML = `
                    <td class="p-4 text-center text-slate-400 text-xs">#${cat.id}</td>
                    <td class="p-4 ${childStyling}" title="${pathText}">${cat.nome}</td>
                    <td class="p-4 flex justify-center space-x-2">
                        <button onclick="openModal('edit', ${cat.id})" class="text-cyan-500 hover:text-cyan-600 p-1.5 hover:bg-cyan-50 dark:hover:bg-cyan-900/20 rounded-lg transition-colors" title="Editar">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                        </button>
                        <button onclick="deleteCat(${cat.id})" class="text-red-500 hover:text-red-600 p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors" title="Excluir">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function populateSelect() {
            const select = document.getElementById('cat_id_pai');
            // Limpa opções antigas mantendo a primeira
            select.innerHTML = '<option value="">Nenhuma (Categoria Principal)</option>';
            
            // Exibir apenas categorias raiz ou nível 1 para não ficar profundo demais
            allCategories.forEach(cat => {
                let opt = document.createElement('option');
                opt.value = cat.id;
                opt.textContent = getHierarchyPath(cat.id);
                select.appendChild(opt);
            });
        }

        function openModal(action, id = null) {
            const modal = document.getElementById('editModal');
            const form = document.getElementById('formCat');
            
            form.reset();
            document.getElementById('cat_action').value = action;
            document.getElementById('cat_id').value = id || '';
            
            if (action === 'edit' && id) {
                document.getElementById('modalTitle').innerText = 'Editar Categoria';
                const cat = allCategories.find(c => c.id == id);
                if (cat) {
                    document.getElementById('cat_nome').value = cat.nome;
                    document.getElementById('cat_id_pai').value = cat.id_pai || '';
                }
            } else {
                document.getElementById('modalTitle').innerText = 'Nova Categoria';
            }
            
            modal.classList.remove('hidden');
            // Força reflow para animação
            void modal.offsetWidth;
            modal.classList.remove('opacity-0');
            modal.querySelector('div').classList.remove('scale-95');
        }

        function closeModal() {
            const modal = document.getElementById('editModal');
            modal.classList.add('opacity-0');
            modal.querySelector('div').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        function saveCat(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            fetch('categorias_investimento_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeModal();
                    loadData();
                } else {
                    alert('Erro: ' + (data.error || 'Falha ao salvar.'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Erro na requisição.');
            });
        }

        function deleteCat(id) {
            if (!confirm('Deseja realmente excluir esta categoria? Isso afetará os painéis de todos que usam esta categoria global.')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            fetch('categorias_investimento_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadData();
                } else {
                    alert('Erro: ' + (data.error || 'Falha ao excluir.'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Erro na requisição.');
            });
        }
    </script>
</body>
</html>
