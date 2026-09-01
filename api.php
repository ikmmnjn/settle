<?php
declare(strict_types=1);
session_start();

/**
 * 대시보드의 저장 요청을 받는 엔드포인트.
 *
 *   POST api.php?action=save        {"items":[{"key":"eap-...","value":"<base64(JSON 문자열)>"}]} (구버전 호환용, 더 이상 화면에서 쓰지 않음)
 *   POST api.php?action=savechunk   {"key":"eap-...","chunkIndex":0,"chunkTotal":3,"chunkData":"<base64 조각>"}
 *   GET  api.php?action=load        저장된 전체 상태를 돌려준다(디버깅·백업용)
 *
 * 로그인(index.php)을 통과한 세션만 처리한다.
 *
 * 값은 base64로 감싸서 받는다 — 일부 호스팅의 웹방화벽(ModSecurity)이
 * 한글/JSON 원문이 섞인 POST 본문을 의심 요청으로 오탐해 404로 막는 경우가 있어서,
 * 전송 구간만 base64로 우회한다. DB에는 디코딩한 원문 그대로 저장된다.
 *
 * 값이 큰 경우(업로드한 엑셀 등)는 웹방화벽의 요청 본문 크기 제한에 걸릴 수 있어
 * savechunk로 잘게 나눠 보낸다. 조각들은 세션에 모아두다가 마지막 조각이 오면
 * 합쳐서 한 번에 DB에 쓴다.
 */

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!eap_is_authed()) {
    fail(401, '로그인이 필요합니다.');
}

$config = eap_load_config();
if (!$config) {
    fail(500, 'db.config.php 를 찾지 못했습니다. index.php 와 같은 폴더(또는 상위 폴더)에 올려 주세요.');
}

try {
    $pdo = eap_connect($config);
    eap_ensure_table($pdo);
} catch (PDOException $e) {
    fail(500, 'DB 연결에 실패했습니다. db.config.php 의 접속 정보를 확인해 주세요.');
}

$action = $_GET['action'] ?? '';

if ($action === 'load') {
    echo json_encode(['ok' => true, 'state' => eap_load_state($pdo)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'savechunk') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail(405, 'POST 로만 저장할 수 있습니다.');
    }
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        fail(413, '보낸 데이터가 서버 허용 크기를 넘었습니다. php.ini 의 post_max_size 를 늘려 주세요 (현재 ' . ini_get('post_max_size') . ').');
    }
    $data = json_decode($raw, true);
    $key = is_array($data) ? (string) ($data['key'] ?? '') : '';
    $chunkIndex = is_array($data) && isset($data['chunkIndex']) ? (int) $data['chunkIndex'] : -1;
    $chunkTotal = is_array($data) && isset($data['chunkTotal']) ? (int) $data['chunkTotal'] : 0;
    $chunkData = is_array($data) ? (string) ($data['chunkData'] ?? '') : '';
    if (!eap_is_allowed_key($key) || $chunkTotal < 1 || $chunkIndex < 0 || $chunkIndex >= $chunkTotal) {
        fail(400, '잘못된 조각 저장 요청입니다.');
    }

    if (!isset($_SESSION['eap_chunk_total'][$key]) || $_SESSION['eap_chunk_total'][$key] !== $chunkTotal) {
        $_SESSION['eap_chunks'][$key] = [];
        $_SESSION['eap_chunk_total'][$key] = $chunkTotal;
    }
    $_SESSION['eap_chunks'][$key][$chunkIndex] = $chunkData;

    if (count($_SESSION['eap_chunks'][$key]) < $chunkTotal) {
        echo json_encode(['ok' => true, 'partial' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ksort($_SESSION['eap_chunks'][$key]);
    $encoded = implode('', $_SESSION['eap_chunks'][$key]);
    unset($_SESSION['eap_chunks'][$key], $_SESSION['eap_chunk_total'][$key]);

    $value = $encoded === '' ? '' : base64_decode($encoded, true);
    if ($value === false) {
        fail(400, '값 디코딩에 실패했습니다: ' . $key);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO `' . EAP_STATE_TABLE . '` (state_key, payload) VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE payload = VALUES(payload)');
        $stmt->execute([':k' => $key, ':v' => $value]);
    } catch (PDOException $e) {
        fail(500, '저장에 실패했습니다. 데이터가 너무 크면 MariaDB 의 max_allowed_packet 을 늘려야 할 수 있습니다.');
    }

    echo json_encode(['ok' => true, 'saved' => [$key]], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'save') {
    fail(400, '알 수 없는 요청입니다.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST 로만 저장할 수 있습니다.');
}

$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
    // post_max_size 를 넘기면 PHP가 본문을 통째로 버리고 빈 요청으로 넘긴다.
    fail(413, '보낸 데이터가 서버 허용 크기를 넘었습니다. php.ini 의 post_max_size 를 늘려 주세요 (현재 ' . ini_get('post_max_size') . ').');
}

$data = json_decode($raw, true);
$items = is_array($data) && isset($data['items']) && is_array($data['items']) ? $data['items'] : null;
if ($items === null) {
    fail(400, '잘못된 형식의 요청입니다.');
}

$sql = 'INSERT INTO `' . EAP_STATE_TABLE . '` (state_key, payload) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE payload = VALUES(payload)';
$stmt = $pdo->prepare($sql);

$saved = [];
try {
    $pdo->beginTransaction();
    foreach ($items as $item) {
        $key = is_array($item) ? (string) ($item['key'] ?? '') : '';
        if (!eap_is_allowed_key($key)) {
            $pdo->rollBack();
            fail(400, '허용되지 않은 저장 키입니다: ' . $key);
        }
        $encoded = (string) ($item['value'] ?? '');
        $value = $encoded === '' ? '' : base64_decode($encoded, true);
        if ($value === false) {
            $pdo->rollBack();
            fail(400, '값 디코딩에 실패했습니다: ' . $key);
        }
        $stmt->execute([':k' => $key, ':v' => $value]);
        $saved[] = $key;
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // max_allowed_packet 을 넘긴 경우가 대부분이라 안내를 같이 준다.
    fail(500, '저장에 실패했습니다. 데이터가 너무 크면 MariaDB 의 max_allowed_packet 을 늘려야 할 수 있습니다.');
}

echo json_encode(['ok' => true, 'saved' => $saved], JSON_UNESCAPED_UNICODE);
