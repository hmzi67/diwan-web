-- Contact form submissions. Apply BEFORE deploying contact.php. Idempotent.
-- Every message is stored before it is emailed, so a mail failure never loses
-- an enquiry; the rows also back the per-IP / per-email rate limit.
CREATE TABLE IF NOT EXISTS contact_messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120)    NOT NULL,
  email       VARCHAR(190)    NOT NULL,
  topic       ENUM('sales','support','licence','device','refund','privacy','other') NOT NULL,
  order_ref   VARCHAR(40)     NULL,
  message     TEXT            NOT NULL,
  ip          VARCHAR(45)     NOT NULL,
  email_sent  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_contact_messages_ip_time (ip, created_at),
  KEY idx_contact_messages_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
