-- ============================================================
-- 006_username_max_25.sql
-- Reduces users.username from VARCHAR(50) to VARCHAR(25).
-- Run ONCE on existing installs after 005 (if applied).
-- ============================================================

-- Safety check: abort if any username exceeds 25 chars.
DROP PROCEDURE IF EXISTS _check_username_max25;
DELIMITER //
CREATE PROCEDURE _check_username_max25()
BEGIN
  DECLARE cnt INT;
  SELECT COUNT(*) INTO cnt FROM `users` WHERE CHAR_LENGTH(`username`) > 25;
  IF cnt > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ABORT: usernames longer than 25 chars exist. Run: SELECT id, username FROM users WHERE CHAR_LENGTH(username) > 25;';
  END IF;
END //
DELIMITER ;
CALL _check_username_max25();
DROP PROCEDURE IF EXISTS _check_username_max25;

ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(25) NOT NULL;
