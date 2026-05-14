<?php
/**
 * Script de Sincronização Google Sheets (Nativo - Sem Composer)
 * Sincroniza transações por usuário e separa por abas anuais.
 */


require_once 'conexao.php';

// --- CONFIGURAÇÕES ---
$service_account_file = 'credentials.json'; // Caminho para o seu arquivo JSON da conta de serviço

// --- FUNÇÕES DE AUTENTICAÇÃO GOOGLE (JWT NATIVO) ---

function base64UrlEncode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function extractSpreadsheetId($input) {
    if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $input, $matches)) {
        return $matches[1];
    }
    return $input;
}

function getGoogleAccessToken($service_account_file) {
    if (!file_exists($service_account_file)) {
        die("Erro: Arquivo de credenciais não encontrado: $service_account_file\n");
    }

    $json = json_decode(file_get_contents($service_account_file), true);
    $private_key = $json['private_key'];
    $client_email = $json['client_email'];

    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $payload = json_encode([
        'iss' => $client_email,
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]);

    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode($payload);

    $signature = '';
    openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $private_key, "SHA256");
    $base64UrlSignature = base64UrlEncode($signature);

    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// --- FUNÇÕES DE API GOOGLE SHEETS ---

function callSheetsAPI($method, $url, $token, $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        echo "  [ERRO API] Código $httpCode: " . ($decoded['error']['message'] ?? $response) . "\n";
    }
    return $decoded;
}


function ensureSheetExists($spreadsheetId, $title, $token) {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId;
    $metadata = callSheetsAPI('GET', $url, $token);
    
    if (isset($metadata['sheets'])) {
        foreach ($metadata['sheets'] as $sheet) {
            if ($sheet['properties']['title'] == $title) {
                return true;
            }
        }
    }

    // Criar aba se não existir
    $urlBatch = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . ":batchUpdate";
    $body = [
        'requests' => [
            ['addSheet' => ['properties' => ['title' => $title]]]
        ]
    ];
    callSheetsAPI('POST', $urlBatch, $token, $body);
    return true;
}

function clearSheet($spreadsheetId, $title, $token) {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . "/values/" . urlencode($title) . "!A:Z:clear";
    return callSheetsAPI('POST', $url, $token);
}

function updateSheetValues($spreadsheetId, $title, $values, $token) {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . "/values/" . urlencode($title) . "!A1?valueInputOption=USER_ENTERED";
    $body = ['values' => $values];
    return callSheetsAPI('PUT', $url, $token, $body);
}

function autoResizeSheet($spreadsheetId, $title, $token) {
    // Buscar o sheetId a partir do título
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId;
    $metadata = callSheetsAPI('GET', $url, $token);
    $sheetId = null;
    if (isset($metadata['sheets'])) {
        foreach ($metadata['sheets'] as $sheet) {
            if ($sheet['properties']['title'] == $title) {
                $sheetId = $sheet['properties']['sheetId'];
                break;
            }
        }
    }
    if ($sheetId === null) return;

    $urlBatch = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . ":batchUpdate";
    $body = [
        'requests' => [[
            'autoResizeDimensions' => [
                'dimensions' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'COLUMNS',
                    'startIndex' => 0,
                    'endIndex' => 6
                ]
            ]
        ]]
    ];
    callSheetsAPI('POST', $urlBatch, $token, $body);
}

// --- SCRIPT PRINCIPAL ---

echo "Iniciando sincronização...\n";

$access_token = getGoogleAccessToken($service_account_file);
if (!$access_token) {
    die("Erro: Não foi possível obter o token de acesso do Google.\n");
}

// 1. Buscar usuários com ID de planilha
$sql_users = "SELECT id, google_sheets_id FROM usuarios WHERE google_sheets_id IS NOT NULL AND google_sheets_id != ''";
$res_users = $mysqliFinancas->query($sql_users);

if ($res_users->num_rows === 0) {
    echo "Nenhum usuário com planilha configurada.\n";
    exit;
}

while ($user = $res_users->fetch_assoc()) {
    $userId = $user['id'];
    $spreadsheetId = extractSpreadsheetId($user['google_sheets_id']);
    
    echo "Processando usuário ID $userId (ID Planilha: $spreadsheetId)...\n";

    // 2. Buscar transações do usuário (com nomes de categoria e conta)
    $sql_trans = "
        SELECT 
            t.id, 
            t.data, 
            t.valor, 
            t.descricao, 
            COALESCE(c.nome, 'Sem Categoria') as categoria, 
            COALESCE(co.nome, 'Sem Conta') as conta,
            YEAR(t.data) as ano
        FROM transacoes t
        LEFT JOIN categorias c ON t.idcategoria = c.id
        LEFT JOIN contas co ON t.idconta = co.id
        WHERE t.iduser = ?
        ORDER BY t.data ASC, t.id ASC
    ";
    
    $stmt = $mysqliFinancas->prepare($sql_trans);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res_trans = $stmt->get_result();
    
    $trans_por_ano = [];
    while ($row = $res_trans->fetch_assoc()) {
        $ano = $row['ano'];
        if (!isset($trans_por_ano[$ano])) {
            $trans_por_ano[$ano] = [
                ['ID', 'Data', 'Valor', 'Descrição', 'Categoria', 'Conta'] // Cabeçalho
            ];
        }
        $trans_por_ano[$ano][] = [
            $row['id'],
            date('d/m/Y', strtotime($row['data'])),
            'R$ ' . number_format((float)$row['valor'], 2, ',', '.'),
            $row['descricao'],
            $row['categoria'],
            $row['conta']
        ];
    }
    $stmt->close();

    // 3. Sincronizar cada ano na sua respectiva aba (do mais recente para o mais antigo)
    krsort($trans_por_ano);
    foreach ($trans_por_ano as $ano => $values) {
        $sheetTitle = (string)$ano;
        echo "  -> Sincronizando ano $sheetTitle...\n";
        
        ensureSheetExists($spreadsheetId, $sheetTitle, $access_token);
        clearSheet($spreadsheetId, $sheetTitle, $access_token);
        updateSheetValues($spreadsheetId, $sheetTitle, $values, $access_token);
        autoResizeSheet($spreadsheetId, $sheetTitle, $access_token);
    }
}

echo "Sincronização concluída com sucesso!\n";
?>
