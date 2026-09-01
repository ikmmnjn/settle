# 정산자동화 대시보드 — 서버 배포 안내

FTP로 올려서 여러 사람이 같은 데이터를 보게 하는 방법입니다.
올린 엑셀/CSV, 비용표, 등급표, 정산방식 선택이 **MariaDB에 저장**됩니다.

## 1. 올릴 파일

`deploy/` 폴더의 파일을 FTP로 웹 폴더에 올립니다.

| 파일 | 설명 |
|---|---|
| `index.php` | 대시보드 본체. 로그인 화면이 여기 붙어 있습니다. |
| `api.php` | 화면에서 바뀐 내용을 DB에 저장하는 엔드포인트. |
| `lib.php` | 위 두 파일이 함께 쓰는 설정·DB 연결 코드. |
| `db.config.example.php` | 접속 설정 예시. **이 파일은 올리지 않아도 됩니다.** |

## 2. db.config.php 만들기

`db.config.example.php`를 복사해 `db.config.php`로 이름을 바꾸고, 서버에서 값을 채웁니다.

```php
return [
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => '<DB 이름>',
    'user' => '<DB 계정>',
    'password' => '<DB 비밀번호>',
    'charset' => 'utf8mb4',
    'settlement_password' => '<대시보드 로그인 비밀번호>',
];
```

- `index.php`와 같은 폴더에 두면 됩니다.
- 이미 상위 폴더에 `db.config.php`를 쓰고 있다면 그대로 두세요. **같은 폴더 → 상위 → 상위의 상위** 순서로 찾습니다.
- 기존 파일을 재사용하는 경우 `settlement_password`가 없으면 `dashboard_password`를 대신 씁니다.
- **비밀번호는 서버의 이 파일에만 적으세요.** 대화창이나 git에 남기지 마세요.

## 3. 테이블

따로 SQL을 실행할 필요 없습니다. 처음 접속할 때 `settlement_state` 테이블이 자동으로 만들어집니다.

```sql
CREATE TABLE settlement_state (
  state_key  VARCHAR(191) NOT NULL PRIMARY KEY,
  payload    LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;
```

화면의 저장 항목이 키 하나에 JSON 한 덩어리로 들어갑니다.

| state_key | 내용 |
|---|---|
| `eap-upload-centers-v1` | 올린 상담센터목록 |
| `eap-upload-counselors-v1` | 올린 상담사목록 |
| `eap-upload-experts-v1` | 올린 전문가관리목록 |
| `eap-upload-facedata-v1` | 올린 대면 상담기록 |
| `eap-upload-remotedata-v1` | 올린 비대면 상담기록 |
| `eap-cost-table-v1` | 센터별 상담사 비용 표 |
| `eap-grade-table-v1` | 센터 상담사 등급 표 |
| `eap-settlement-tax-v1` | 센터별 3.3% 선택 |
| `eap-settlement-doc-v1` | 센터별 전자계산서/현금영수증 선택 |

백업은 `api.php?action=load`로 전체 JSON을 받거나, 위 테이블을 덤프하면 됩니다.

## 4. 확인

1. 브라우저에서 `https://<주소>/index.php` 접속 → 로그인 화면
2. 비밀번호 입력 후 대시보드 진입
3. 아무 엑셀이나 올리고 → 우측 상단에 **"서버에 저장됨"** 표시 확인
4. **다른 브라우저(또는 시크릿 창)로 다시 접속** → 올린 내용이 그대로 보이면 성공

우측 상단 표시가 빨간 **"저장 실패"**로 뜨면 그 문구에 원인이 같이 나옵니다.

## 5. 자주 걸리는 것

- **저장 실패 / 413**: 상담 기록이 크면 PHP의 `post_max_size`(흔히 8M)에 걸립니다. `php.ini`에서 `post_max_size`와 `memory_limit`을 넉넉히(예: 64M) 올리세요.
- **저장 실패 / 500**: MariaDB `max_allowed_packet`이 작을 때 납니다. 64M 이상 권장.
- **로그인이 자꾸 풀림**: 세션 만료입니다. 새로고침 후 다시 로그인하면 됩니다. 저장 중이던 내용은 다시 시도합니다.
- **HTML을 그냥 열었을 때**: `정산자동화_대시보드.html`은 서버와 무관하게 그 브라우저에만 저장하는 로컬 전용입니다. 서버 데이터를 보려면 `index.php`로 접속해야 합니다.

## 6. 보안

- 상담사 이름·계좌번호·정산금액이 있는 페이지입니다. **HTTPS 주소로만** 접속하세요.
- `index.php`는 로그인을 통과하지 못하면 HTML을 아예 내보내지 않습니다.
- 예전에 올려둔 `index.html`(로그인 없는 정적 파일)이 서버에 남아 있으면 **반드시 지우세요.** 그 파일에는 상담센터·상담사·전문가 목록이 그대로 들어 있고 누구나 볼 수 있습니다.
