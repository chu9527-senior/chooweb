<?php
/**
 * DB 접속 설정 템플릿
 *
 * 사용법:
 * 1) 이 파일을 같은 폴더에 db_config.php 라는 이름으로 복사합니다.
 * 2) cPanel > MySQL 데이터베이스 마법사에서 만든 실제 값으로 아래 4개를 채웁니다.
 * 3) db_config.php는 .gitignore에 등록되어 있어 git/GitHub에는 올라가지 않습니다.
 *    (비밀번호가 포함되므로 절대 커밋하지 마세요)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'cpanel사용자명_데이터베이스명');
define('DB_USER', 'cpanel사용자명_DB사용자명');
define('DB_PASS', '실제_DB_비밀번호');

// admin.php(문의 내역 관리 페이지) 접속 비밀번호. 직접 원하는 값으로 바꿔주세요.
define('ADMIN_PASSWORD', '관리자_페이지_비밀번호');
