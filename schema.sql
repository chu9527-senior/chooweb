-- 추영준(인생설계가이드) 홍보 웹사이트 - 문의 저장용 테이블
-- cPanel의 phpMyAdmin에서 이 파일을 그대로 Import 하시면 됩니다.

CREATE TABLE IF NOT EXISTS `inquiries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `organization` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `inquiry_type` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 이미 inquiries 테이블을 만든 뒤라면(기존 운영 중인 DB), 아래 한 줄만 phpMyAdmin의
-- SQL 탭에서 실행하면 됩니다 (위 CREATE TABLE은 새로 설치할 때만 필요합니다).
-- ALTER TABLE `inquiries` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0 AFTER `message`;
