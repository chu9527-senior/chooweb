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
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
