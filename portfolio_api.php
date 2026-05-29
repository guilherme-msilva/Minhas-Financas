<?php
session_start();
if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once 'conexao.php';
$id_user = $_SESSION['id'];

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
        $conn->query($sql);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM investimentos WHERE id=$id AND id_user=$id_user");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'import') {
        $id_categoria = (int)$_POST['id_categoria'];
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
            while (($row = fgetcsv($file, 1000, ";")) !== FALSE) {
                if (count($row) >= 2) {
                    $ticker = trim($row[0]);
                    $quantidade = str_replace(',', '.', trim($row[1]));
                    if ($ticker && is_numeric($quantidade)) {
                        $conn->query("INSERT INTO investimentos (id_user, ticker, quantidade, id_categoria, valor_manual) 
                                      VALUES ($id_user, '$ticker', $quantidade, $id_categoria, NULL)");
                    }
                }
            }
            fclose($file);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Falha no upload do arquivo']);
        }
        exit;
    }
}

// Obter categorias e seus pais
$cats_result = $conn->query("SELECT id, nome, id_pai FROM categorias_investimento");
$categorias = [];
$categoria_nomes = [];
$categoria_pais = [];
while ($cat = $cats_result->fetch_assoc()) {
    $categorias[$cat['id']] = $cat;
    $categoria_nomes[$cat['id']] = $cat['nome'];
    $categoria_pais[$cat['id']] = $cat['id_pai'];
}

// Obter investimentos do usuário
$invs_result = $conn->query("SELECT * FROM investimentos WHERE id_user = $id_user");
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

// Buscar cotações do Yahoo Finance
$quotes = [];
if (count($tickers_to_fetch) > 0) {
    $symbols = implode(',', $tickers_to_fetch);
    $url = "https://query1.finance.yahoo.com/v7/finance/quote?symbols=" . urlencode($symbols);
    
    // Inicializar cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // User-Agent é obrigatório em algumas APIs
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['quoteResponse']['result'])) {
            foreach ($data['quoteResponse']['result'] as $q) {
                $quotes[$q['symbol']] = [
                    'price' => $q['regularMarketPrice'] ?? 0,
                    'currency' => $q['currency'] ?? 'USD',
                ];
            }
        }
    }
}

$usd_brl = $quotes['USDBRL=X']['price'] ?? 5.00; // Fallback se falhar

// Processar dados para retorno e para o gráfico
$processed_investments = [];
$chart_data_macro = []; // Categorias Pai
$chart_data_drilldown = []; // Subcategorias e Ativos

$total_portfolio_brl = 0;

foreach ($investimentos as $inv) {
    $id_cat = $inv['id_categoria'];
    $cat_nome = $categoria_nomes[$id_cat] ?? 'Outros';
    $id_pai = $categoria_pais[$id_cat] ?? null;
    $macro_cat_id = $id_pai ? $id_pai : $id_cat;
    $macro_cat_nome = $categoria_nomes[$macro_cat_id] ?? $cat_nome;

    $ticker = strtoupper($inv['ticker']);
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

    // Agrupar para gráfico Macro
    if (!isset($chart_data_macro[$macro_cat_nome])) {
        $chart_data_macro[$macro_cat_nome] = 0;
    }
    $chart_data_macro[$macro_cat_nome] += $valor_brl;

    // Agrupar para Drilldown
    if (!isset($chart_data_drilldown[$macro_cat_nome])) {
        $chart_data_drilldown[$macro_cat_nome] = [];
    }
    
    // Se a macro tiver filhos, agrupamos pelo filho primeiro, senao pelo ticker
    $drill_label = $id_pai ? $cat_nome : $inv['ticker'];
    if (!$drill_label) $drill_label = $cat_nome; // fallback renda fixa sem ticker

    if (!isset($chart_data_drilldown[$macro_cat_nome][$drill_label])) {
        $chart_data_drilldown[$macro_cat_nome][$drill_label] = 0;
    }
    $chart_data_drilldown[$macro_cat_nome][$drill_label] += $valor_brl;
}

echo json_encode([
    'total_brl' => $total_portfolio_brl,
    'cotacao_usd' => $usd_brl,
    'investimentos' => $processed_investments,
    'chart_macro' => $chart_data_macro,
    'chart_drilldown' => $chart_data_drilldown,
    'categorias' => array_values($categorias) // Enviar categorias estruturadas pro frontend montar selects
]);
