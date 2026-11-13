-- Select the application's database before importing this file.
-- The CLI setup script creates the database and imports these tables safely.
-- Re-running this file preserves existing conversations and messages.

CREATE TABLE IF NOT EXISTS users (
    -- Legacy identity columns are retained to preserve existing account records.
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    storage_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    phone VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_storage_key (storage_key),
    UNIQUE KEY uq_users_phone (phone),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_challenges (
    id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    method ENUM('phone', 'google') NOT NULL,
    destination VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    otp_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at BIGINT UNSIGNED NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    verified_at BIGINT UNSIGNED NULL,
    consumed_at BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_auth_challenges_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started BIGINT UNSIGNED NOT NULL,
    hits BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (key_hash),
    KEY ix_auth_rate_limits_window (window_started)
) ENGINE=InnoDB DEFAULT CHARACTER SET ascii COLLATE ascii_bin;

CREATE TABLE IF NOT EXISTS chat_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_conversations_session_hash (session_hash)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'completed',
    error_message VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_chat_messages_conversation_id (conversation_id, id),
    CONSTRAINT fk_chat_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
