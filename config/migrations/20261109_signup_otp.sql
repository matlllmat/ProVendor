-- Persistent send limits survive browser refreshes and new sessions.
CREATE TABLE IF NOT EXISTS signup_otp_limits (
    bucket CHAR(64) NOT NULL,
    window_started INT UNSIGNED NOT NULL,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
