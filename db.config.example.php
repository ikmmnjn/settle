<?php
/**
 * 정산자동화 대시보드 DB 접속 설정.
 *
 * 사용법:
 *   1. 이 파일을 db.config.php 로 복사합니다.
 *   2. password 와 settlement_password 를 서버에서 직접 채워 넣습니다.
 *   3. FTP로 index.php / api.php 와 같은 폴더에 올립니다.
 *      (이미 상위 폴더에 db.config.php 를 쓰고 있다면 그대로 두면 됩니다 —
 *       index.php 와 api.php 가 같은 폴더 → 상위 → 상위의 상위 순으로 찾습니다.)
 *
 * 주의:
 *   - 비밀번호는 대화창이나 소스 저장소(git)에 남기지 말고 이 파일에만 직접 입력하세요.
 *   - settlement_password 는 DB 비밀번호와 다른, 대시보드 로그인 전용 비밀번호입니다.
 *   - 상담사 이름·계좌번호·정산금액이 보이는 페이지이니 반드시 HTTPS 주소로만 접속하세요.
 */

return [
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => 'successkkaeap',
    'user' => 'successkkaeap',
    'password' => '__FILL_IN_ON_SERVER__',
    'charset' => 'utf8mb4',

    // 대시보드 로그인 비밀번호 (기존 db.config.php 를 재사용하는 경우
    // 이 값이 없으면 dashboard_password 를 대신 사용합니다)
    'settlement_password' => '__FILL_IN_ON_SERVER__',
];
