ALTER TABLE users
    ADD COLUMN email varchar(254) DEFAULT NULL AFTER status,
    ADD UNIQUE KEY users_email_unique (email);

CREATE TABLE password_reset_otps (
    user_id int NOT NULL,
    otp_hash varchar(255) DEFAULT NULL,
    expires_at datetime DEFAULT NULL,
    attempts tinyint unsigned NOT NULL DEFAULT 0,
    request_count tinyint unsigned NOT NULL DEFAULT 0,
    rate_window_started_at datetime DEFAULT NULL,
    last_requested_at datetime DEFAULT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT password_reset_otps_user_fk
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
