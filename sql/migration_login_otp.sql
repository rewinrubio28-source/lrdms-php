-- ------------------------------------------------------------
-- Migration: add email-based OTP option to the 2FA login step
-- Run this once against your existing LRDMS database.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_otp_codes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  code            VARCHAR(10) NOT NULL,
  expires_at      DATETIME NOT NULL,
  used_at         DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
