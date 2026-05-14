<?php
/**
 * Script de Sincronização Google Sheets (Nativo - Sem Composer)
 * Sincroniza transações por usuário e separa por abas anuais.
 * Otimizado para minimizar o número de requisições à API (máx. 3 por aba).
 */

require_once 'conexao.php';

// --- CONFIGURAÇÕES ---
$service_account_file = 'credentials.json';

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

/**
 * Requisição 1 (por usuário): Busca metadados da planilha e retorna mapa [título => sheetId].
 */
function getSheetMetadata($spreadsheetId, $token) {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId;
    $metadata = callSheetsAPI('GET', $url, $token);

    $sheetMap = []; // ['2024' => 123456, ...]
    if (isset($metadata['sheets'])) {
        foreach ($metadata['sheets'] as $sheet) {
            $title = $sheet['properties']['title'];
            $id    = $sheet['properties']['sheetId'];
            $sheetMap[$title] = $id;
        }
    }
    return $sheetMap;
}

/**
 * Requisição 2 (opcional, uma vez por usuário): Cria todas as abas ausentes num único batchUpdate.
 * Retorna o mapa atualizado com os novos sheetIds.
 */
function createMissingSheets($spreadsheetId, array $anosNecessarios, array $sheetMap, $token) {
    $requests = [];
    foreach ($anosNecessarios as $ano) {
        $title = (string)$ano;
        if (!isset($sheetMap[$title])) {
            $requests[] = ['addSheet' => ['properties' => ['title' => $title]]];
        }
    }

    if (empty($requests)) {
        return $sheetMap; // Nenhuma aba nova necessária
    }

    $urlBatch = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . ":batchUpdate";
    $result = callSheetsAPI('POST', $urlBatch, $token, ['requests' => $requests]);

    // Atualiza o mapa com os novos sheetIds retornados pela API
    if (isset($result['replies'])) {
        foreach ($result['replies'] as $reply) {
            if (isset($reply['addSheet'])) {
                $props = $reply['addSheet']['properties'];
                $sheetMap[$props['title']] = $props['sheetId'];
            }
        }
    }
    return $sheetMap;
}

/**
 * Requisição 3 (por aba): Limpa os dados existentes.
 */
function clearSheet($spreadsheetId, $title, $token) {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . "/values/" . urlencode($title) . "!A:Z:clear";
    return callSheetsAPI('POST', $url, $token);
}

/**
 * Requisição 4 (por aba): Grava os valores.
 */
function updateSheetValues($spreadsheetId, $title, $values, $token) {
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . "/values/" . urlencode($title) . "!A1?valueInputOption=USER_ENTERED";
    $body = ['values' => $values];
    return callSheetsAPI('PUT', $url, $token, $body);
}

/**
 * Requisição 5 (por aba): Um único batchUpdate com 4 operações combinadas:
 *  - NumberFormat CURRENCY (coluna C)
 *  - Formatação condicional: vermelho para negativos
 *  - Formatação condicional: azul para positivos
 *  - AutoResize de todas as colunas
 */
function applySheetFormatting($spreadsheetId, $sheetId, $token) {
    $colValorRange = [
        'sheetId'          => $sheetId,
        'startRowIndex'    => 1,
        'startColumnIndex' => 2,
        'endColumnIndex'   => 3
    ];

    $urlBatch = "https://sheets.googleapis.com/v4/spreadsheets/" . $spreadsheetId . ":batchUpdate";
    $body = [
        'requests' => [
            // 1. NumberFormat CURRENCY coluna C
            [
                'repeatCell' => [
                    'range'  => $colValorRange,
                    'cell'   => [
                        'userEnteredFormat' => [
                            'numberFormat' => [
                                'type'    => 'CURRENCY',
                                'pattern' => '"R$ "#,##0.00;"R$ "-#,##0.00'
                            ]
                        ]
                    ],
                    'fields' => 'userEnteredFormat.numberFormat'
                ]
            ],
            // 2. Formatação condicional: valores negativos → texto vermelho
            [
                'addConditionalFormatRule' => [
                    'rule' => [
                        'ranges'      => [$colValorRange],
                        'booleanRule' => [
                            'condition' => [
                                'type'   => 'NUMBER_LESS',
                                'values' => [['userEnteredValue' => '0']]
                            ],
                            'format' => [
                                'textFormat' => [
                                    'foregroundColor' => ['red' => 0.84, 'green' => 0.18, 'blue' => 0.18]
                                ]
                            ]
                        ]
                    ],
                    'index' => 0
                ]
            ],
            // 3. Formatação condicional: valores positivos → texto azul
            [
                'addConditionalFormatRule' => [
                    'rule' => [
                        'ranges'      => [$colValorRange],
                        'booleanRule' => [
                            'condition' => [
                                'type'   => 'NUMBER_GREATER',
                                'values' => [['userEnteredValue' => '0']]
                            ],
                            'format' => [
                                'textFormat' => [
                                    'foregroundColor' => ['red' => 0.13, 'green' => 0.47, 'blue' => 0.71]
                                ]
                            ]
                        ]
                    ],
                    'index' => 1
                ]
            ],
            // 4. AutoResize colunas A-F
            [
                'autoResizeDimensions' => [
                    'dimensions' => [
                        'sheetId'    => $sheetId,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => 0,
                        'endIndex'   => 6
                    ]
                ]
            ]
        ]
    ];

    callSheetsAPI('POST', $urlBatch, $token, $body);
}

// --- SCRIPT PRINCIPAL ---

echo "Iniciando sincronização...\n";

$access_token = getGoogleAccessToken($service_account_file);
if (!$access_token) {
    die("Erro: Não foi possível obter o token de acesso do Google.\n");
}

// 1. Buscar usuários com ID de planilha configurado
$sql_users = "SELECT id, google_sheets_id FROM usuarios WHERE google_sheets_id IS NOT NULL AND google_sheets_id != ''";
$res_users = $mysqliFinancas->query($sql_users);

if ($res_users->num_rows === 0) {
    echo "Nenhum usuário com planilha configurada.\n";
    exit;
}

while ($user = $res_users->fetch_assoc()) {
    $userId        = $user['id'];
    $spreadsheetId = extractSpreadsheetId($user['google_sheets_id']);

    echo "Processando usuário ID $userId (ID Planilha: $spreadsheetId)...\n";

    // 2. Buscar transações do usuário agrupadas por ano
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
        LEFT JOIN categorias c  ON t.idcategoria = c.id
        LEFT JOIN contas     co ON t.idconta     = co.id
        WHERE t.iduser = ?
        ORDER BY t.data ASC
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
                ['ID', 'Data', 'Valor', 'Descrição', 'Categoria', 'Conta']
            ];
        }
        $trans_por_ano[$ano][] = [
            $row['id'],
            date('d/m/Y', strtotime($row['data'])),
            (float)$row['valor'],
            $row['descricao'],
            $row['categoria'],
            $row['conta']
        ];
    }
    $stmt->close();

    if (empty($trans_por_ano)) {
        echo "  Nenhuma transação encontrada.\n";
        continue;
    }

    // Ordena os anos do mais recente para o mais antigo
    krsort($trans_por_ano);
    $anosNecessarios = array_keys($trans_por_ano);

    // REQUISIÇÃO 1: Busca os metadados uma única vez
    echo "  -> Buscando metadados da planilha...\n";
    $sheetMap = getSheetMetadata($spreadsheetId, $access_token);

    // REQUISIÇÃO 2 (opcional): Cria todas as abas ausentes em um único batchUpdate
    $sheetMap = createMissingSheets($spreadsheetId, $anosNecessarios, $sheetMap, $access_token);

    // 3. Para cada ano: limpa, grava dados e aplica toda a formatação (3 requisições)
    foreach ($trans_por_ano as $ano => $values) {
        $sheetTitle = (string)$ano;
        $sheetId    = $sheetMap[$sheetTitle] ?? null;

        if ($sheetId === null) {
            echo "  [AVISO] sheetId não encontrado para aba '$sheetTitle'. Pulando.\n";
            continue;
        }

        echo "  -> Sincronizando $sheetTitle (" . (count($values) - 1) . " transações)...\n";

        clearSheet($spreadsheetId, $sheetTitle, $access_token);              // Req. 3
        updateSheetValues($spreadsheetId, $sheetTitle, $values, $access_token); // Req. 4
        applySheetFormatting($spreadsheetId, $sheetId, $access_token);       // Req. 5 (tudo junto)
    }
}

echo "Sincronização concluída com sucesso!\n";
?>
