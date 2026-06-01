<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';
$id_user = $_SESSION['user_id'];

// Se for requisição POST, lidamos com CRUD ou Importação
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $ticker = trim($_POST['ticker']);
        $quantidade = str_replace(',', '.', $_POST['quantidade']);
        $id_categoria = (int)$_POST['id_categoria'];
        $valor_manual = !empty($_POST['valor_manual']) ? str_replace(',', '.', $_POST['valor_manual']) : 'NULL';

        if ($action === 'add') {
            $sql = "INSERT INTO investimentos (id_user, ticker, quantidade, id_categoria, valor_manual) 
                    VALUES ($id_user, '$ticker', $quantidade, $id_categoria, $valor_manual)";
        } else {
            $sql = "UPDATE investimentos SET ticker='$ticker', quantidade=$quantidade, id_categoria=$id_categoria, valor_manual=$valor_manual 
                    WHERE id=$id AND id_user=$id_user";
        }
        $mysqliFinancas->query($sql);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $mysqliFinancas->query("DELETE FROM investimentos WHERE id=$id AND id_user=$id_user");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'import') {
        $id_categoria = (int)$_POST['id_categoria'];
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($_FILES['csv_file']['tmp_name']);
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($content)));
            
            $inserted = 0;
            $skipped = 0;
            $errors = [];
            
            $is_first_line = true;
            foreach ($lines as $index => $line) {
                if (empty(trim($line))) continue;
                
                // Tenta dividir por ponto-e-virgula ou virgula
                $row = str_getcsv($line, ';');
                if (count($row) < 2) {
                    $row = str_getcsv($line, ',');
                }
                
                if (count($row) >= 2) {
                    $ticker = trim($row[0]);
                    $quantidade_str = trim($row[1]);
                    // Se a primeira linha tiver palavras como "quantidade", "qty", etc, pula (cabeçalho)
                    if ($is_first_line && !is_numeric(str_replace(',', '.', $quantidade_str))) {
                        $is_first_line = false;
                        continue; 
                    }
                    $is_first_line = false;
                    
                    $quantidade = str_replace(',', '.', $quantidade_str);
                    
                    if ($ticker !== '' && is_numeric($quantidade)) {
                        $sql = "INSERT INTO investimentos (id_user, ticker, quantidade, id_categoria, valor_manual) 
                                VALUES ($id_user, '$ticker', $quantidade, $id_categoria, NULL)";
                        if ($mysqliFinancas->query($sql)) {
                            $inserted++;
                        } else {
                            $errors[] = "Erro no DB ao inserir linha " . ($index + 1) . ": $ticker";
                        }
                    } else {
                        $skipped++;
                        $errors[] = "Linha " . ($index + 1) . " ignorada (Ticker vazio ou Quantidade inválida): $line";
                    }
                } else {
                    $skipped++;
                    $errors[] = "Linha " . ($index + 1) . " não possui o formato esperado: $line";
                }
            }
            
            echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhum arquivo enviado ou erro no upload.']);
        }
        exit;
    }
}

// Obter categorias e seus pais
$cats_result = $mysqliFinancas->query("SELECT id, nome, id_pai FROM categorias_investimento");
$categorias = [];
$categoria_nomes = [];
$categoria_pais = [];
while ($cat = $cats_result->fetch_assoc()) {
    $categorias[$cat['id']] = $cat;
    $categoria_nomes[$cat['id']] = $cat['nome'];
    $categoria_pais[$cat['id']] = $cat['id_pai'];
}

// Obter investimentos do usuário
$invs_result = $mysqliFinancas->query("SELECT * FROM investimentos WHERE id_user = $id_user ORDER BY id_categoria ASC, ticker ASC");
$investimentos = [];
$tickers_to_fetch = ['USDBRL=X']; // Sempre buscar a cotação do dólar

while ($inv = $invs_result->fetch_assoc()) {
    $investimentos[] = $inv;
    if (empty($inv['valor_manual']) && !empty($inv['ticker'])) {
        $t = strtoupper($inv['ticker']);
        if (!in_array($t, $tickers_to_fetch)) {
            $tickers_to_fetch[] = $t;
        }
    }
}

// Buscar cotações do Yahoo Finance (endpoint v8/chart não requer auth, mas só aceita 1 por vez)
$quotes = [];
if (count($tickers_to_fetch) > 0) {
    // Para otimizar, podemos usar curl_multi ou requisições sequenciais.
    // Como é para uso pessoal, sequencial com timeout curto resolve.
    $mh = curl_multi_init();
    $curl_handles = [];

    foreach ($tickers_to_fetch as $symbol) {
        $url = "https://query2.finance.yahoo.com/v8/finance/chart/" . urlencode($symbol) . "?interval=1d&range=1d";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $curl_handles[$symbol] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running);

    foreach ($curl_handles as $symbol => $ch) {
        $response = curl_multi_getcontent($ch);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['chart']['result'][0]['meta'])) {
                $meta = $data['chart']['result'][0]['meta'];
                $quotes[$symbol] = [
                    'price' => $meta['regularMarketPrice'] ?? 0,
                    'currency' => $meta['currency'] ?? 'USD',
                ];
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

$usd_brl = $quotes['USDBRL=X']['price'] ?? 5.00; // Fallback se falhar

// Processar dados para retorno e para o gráfico
$processed_investments = [];
$tree = []; // Árvore hierárquica

$total_portfolio_brl = 0;

foreach ($investimentos as $inv) {
    $id_cat = $inv['id_categoria'];
    $cat_nome = $categoria_nomes[$id_cat] ?? 'Outros';
    $id_pai = $categoria_pais[$id_cat] ?? null;
    $macro_cat_id = $id_pai ? $id_pai : $id_cat;
    $macro_cat_nome = $categoria_nomes[$macro_cat_id] ?? $cat_nome;

    $ticker = strtoupper($inv['ticker']);
    if (!$ticker) $ticker = $cat_nome;
    $qtd = (float)$inv['quantidade'];
    $valor_brl = 0;
    $valor_usd = 0;
    $preco_unidade = 0;
    $moeda = 'BRL';

    if (!empty($inv['valor_manual'])) {
        $valor_brl = (float)$inv['valor_manual'];
    } else {
        if (isset($quotes[$ticker])) {
            $preco_unidade = $quotes[$ticker]['price'];
            $moeda = $quotes[$ticker]['currency'];
            
            if ($moeda === 'USD') {
                $valor_usd = $preco_unidade * $qtd;
                $valor_brl = $valor_usd * $usd_brl;
            } else { // Assume BRL
                $valor_brl = $preco_unidade * $qtd;
            }
        }
    }

    $total_portfolio_brl += $valor_brl;

    $inv_data = [
        'id' => $inv['id'],
        'ticker' => $inv['ticker'],
        'quantidade' => $qtd,
        'categoria_id' => $id_cat,
        'categoria_nome' => $cat_nome,
        'macro_categoria_nome' => $macro_cat_nome,
        'valor_manual' => $inv['valor_manual'],
        'preco_unidade' => $preco_unidade,
        'moeda' => $moeda,
        'valor_brl' => $valor_brl,
        'valor_usd' => $valor_usd
    ];
    $processed_investments[] = $inv_data;

    // Montar Árvore
    if (!isset($tree[$macro_cat_nome])) {
        $tree[$macro_cat_nome] = ['value_brl' => 0, 'value_usd' => 0, 'subs' => [], 'assets' => []];
    }
    $tree[$macro_cat_nome]['value_brl'] += $valor_brl;
    $tree[$macro_cat_nome]['value_usd'] += $valor_usd;

    if ($id_pai) {
        if (!isset($tree[$macro_cat_nome]['subs'][$cat_nome])) {
             $tree[$macro_cat_nome]['subs'][$cat_nome] = ['value_brl' => 0, 'value_usd' => 0, 'assets' => []];
        }
        $tree[$macro_cat_nome]['subs'][$cat_nome]['value_brl'] += $valor_brl;
        $tree[$macro_cat_nome]['subs'][$cat_nome]['value_usd'] += $valor_usd;
        
        if (!isset($tree[$macro_cat_nome]['subs'][$cat_nome]['assets'][$ticker])) {
             $tree[$macro_cat_nome]['subs'][$cat_nome]['assets'][$ticker] = ['value_brl' => 0, 'value_usd' => 0];
        }
        $tree[$macro_cat_nome]['subs'][$cat_nome]['assets'][$ticker]['value_brl'] += $valor_brl;
        $tree[$macro_cat_nome]['subs'][$cat_nome]['assets'][$ticker]['value_usd'] += $valor_usd;
    } else {
        if (!isset($tree[$macro_cat_nome]['assets'][$ticker])) {
             $tree[$macro_cat_nome]['assets'][$ticker] = ['value_brl' => 0, 'value_usd' => 0];
        }
        $tree[$macro_cat_nome]['assets'][$ticker]['value_brl'] += $valor_brl;
        $tree[$macro_cat_nome]['assets'][$ticker]['value_usd'] += $valor_usd;
    }
}

date_default_timezone_set('America/Sao_Paulo');
echo json_encode([
    'total_brl' => $total_portfolio_brl,
    'cotacao_usd' => $usd_brl,
    'data_hora' => date('d/m/Y H:i:s'),
    'investimentos' => $processed_investments,
    'tree' => $tree,
    'categorias' => array_values($categorias)
]);
