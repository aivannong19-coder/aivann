<?php

final class ChatStore
{
    public function __construct(private PDO $database)
    {
    }

    public static function connect(array $config): self
    {
        $host = configValue($config, 'DB_HOST', '127.0.0.1');
        $port = configValue($config, 'DB_PORT', '3306');
        $name = configValue($config, 'DB_NAME', 'kayasure_chat');
        $user = configValue($config, 'DB_USER');
        if ($user === '' || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || preg_match('/[;\x00-\x20]/', $host)) {
            throw new RuntimeException('Database configuration is incomplete.');
        }

        $database = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
            $user,
            configValue($config, 'DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]
        );
        $database->exec("SET time_zone = '+00:00'");
        return new self($database);
    }

    // Store the question before the API request, without holding a SQL transaction open.
    public function recordUserMessage(string $sessionHash, string $content): int
    {
        $this->database->beginTransaction();
        try {
            $conversation = $this->database->prepare(
                'INSERT INTO chat_conversations (session_hash) VALUES (?) '
                . 'ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP(6)'
            );
            $conversation->execute([$sessionHash]);
            $lookup = $this->database->prepare('SELECT id FROM chat_conversations WHERE session_hash = ?');
            $lookup->execute([$sessionHash]);
            $conversationId = $lookup->fetchColumn();
            if ($conversationId === false) {
                throw new RuntimeException('Conversation could not be created.');
            }
            $insert = $this->database->prepare(
                "INSERT INTO chat_messages (conversation_id, role, content, status) VALUES (?, 'user', ?, 'pending')"
            );
            $insert->execute([$conversationId, $content]);
            $messageId = (int) $this->database->lastInsertId();
            $this->database->commit();
            return $messageId;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    // Only completed messages are model context; failed and interrupted requests are excluded.
    public function context(string $sessionHash): array
    {
        $query = $this->database->prepare(
            "SELECT m.role, m.content FROM chat_messages m "
            . 'INNER JOIN chat_conversations c ON c.id = m.conversation_id '
            . "WHERE c.session_hash = ? AND m.status = 'completed' ORDER BY m.id DESC LIMIT 12"
        );
        $query->execute([$sessionHash]);
        return array_reverse($query->fetchAll());
    }

    public function history(string $sessionHash): array
    {
        $query = $this->database->prepare(
            'SELECT m.id, m.role, m.content, m.status, m.error_message, m.created_at '
            . 'FROM chat_messages m INNER JOIN chat_conversations c ON c.id = m.conversation_id '
            . 'WHERE c.session_hash = ? ORDER BY m.id DESC LIMIT 101'
        );
        $query->execute([$sessionHash]);
        $rows = $query->fetchAll();
        $limited = count($rows) > 100;
        if ($limited) {
            array_pop($rows);
        }
        return ['messages' => array_reverse($rows), 'history_limited' => $limited];
    }

    // Commit the answer and completion together; a duplicate completion cannot add a second reply.
    public function saveReply(string $sessionHash, int $userMessageId, string $answer): int
    {
        $this->database->beginTransaction();
        try {
            $pending = $this->database->prepare(
                'SELECT m.conversation_id FROM chat_messages m '
                . 'INNER JOIN chat_conversations c ON c.id = m.conversation_id '
                . "WHERE c.session_hash = ? AND m.id = ? AND m.role = 'user' AND m.status = 'pending' FOR UPDATE"
            );
            $pending->execute([$sessionHash, $userMessageId]);
            $conversationId = $pending->fetchColumn();
            if ($conversationId === false) {
                throw new RuntimeException('Pending message was not found.');
            }

            $insert = $this->database->prepare(
                "INSERT INTO chat_messages (conversation_id, role, content, status) VALUES (?, 'assistant', ?, 'completed')"
            );
            $insert->execute([$conversationId, $answer]);
            $replyId = (int) $this->database->lastInsertId();
            $complete = $this->database->prepare(
                "UPDATE chat_messages SET status = 'completed', error_message = NULL WHERE id = ?"
            );
            $complete->execute([$userMessageId]);
            $touch = $this->database->prepare('UPDATE chat_conversations SET updated_at = CURRENT_TIMESTAMP(6) WHERE id = ?');
            $touch->execute([$conversationId]);
            $this->database->commit();
            return $replyId;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function failMessage(string $sessionHash, int $userMessageId, string $safeError): void
    {
        $query = $this->database->prepare(
            'UPDATE chat_messages m INNER JOIN chat_conversations c ON c.id = m.conversation_id '
            . "SET m.status = 'failed', m.error_message = ? "
            . "WHERE c.session_hash = ? AND m.id = ? AND m.role = 'user' AND m.status = 'pending'"
        );
        $query->execute([mb_substr($safeError, 0, 255, 'UTF-8'), $sessionHash, $userMessageId]);
    }

    private function rollback(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }
}
