<?php
declare(strict_types=1);

/**
 * index.php / api.php 가 함께 쓰는 설정 로딩 · DB 연결 · 로그인 확인.
 */

const EAP_STATE_TABLE = 'settlement_state';
const EAP_SESSION_KEY = 'eap_settlement_auth';

/**
 * db.config.php 를 같은 폴더 → 상위 → 상위의 상위 순으로 찾는다.
 * 기존 EAP 사이트가 상위 폴더에 두고 여러 페이지가 공유하는 구조를 그대로 따른다.
 */
function eap_load_config(): array
{
    foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2)] as $dir) {
        $path = $dir . '/db.config.php';
        if (is_file($path)) {
            $config = require $path;
            if (is_array($config)) {
                return $config;
            }
        }
    }
    return [];
}

function eap_login_password(array $config): string
{
    // settlement_password 를 우선 쓰고, 없으면 기존 dashboard_password 를 그대로 쓴다.
    foreach (['settlement_password', 'dashboard_password'] as $key) {
        $value = (string) ($config[$key] ?? '');
        if ($value !== '' && $value !== '__FILL_IN_ON_SERVER__') {
            return $value;
        }
    }
    return '';
}

function eap_is_authed(): bool
{
    return !empty($_SESSION[EAP_SESSION_KEY]);
}

function eap_connect(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'] ?? 'localhost',
        $config['port'] ?? 3306,
        $config['dbname'] ?? '',
        $config['charset'] ?? 'utf8mb4'
    );
    return new PDO($dsn, $config['user'] ?? '', $config['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * 최초 요청 시 테이블이 없으면 만든다 — 서버에서 수동 SQL 작업이 필요 없도록.
 * 화면의 각 저장 항목(업로드한 엑셀, 비용표, 등급표, 정산방식)을 키 하나에 JSON 한 덩어리로 넣는다.
 */
function eap_ensure_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS `' . EAP_STATE_TABLE . '` (
        state_key VARCHAR(191) NOT NULL,
        payload LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (state_key)
    ) DEFAULT CHARSET=utf8mb4');
}

function eap_load_state(PDO $pdo): array
{
    $state = [];
    foreach ($pdo->query('SELECT state_key, payload FROM `' . EAP_STATE_TABLE . '`') as $row) {
        $state[$row['state_key']] = $row['payload'];
    }
    return $state;
}

/**
 * 화면이 쓰는 키만 받는다. 임의의 키로 DB를 채우지 못하게 막는 역할.
 */
function eap_is_allowed_key(string $key): bool
{
    return $key !== ''
        && strlen($key) <= 191
        && strpos($key, 'eap-') === 0
        && preg_match('/^[a-zA-Z0-9._-]+$/', $key) === 1;
}
