<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\HttpException;
use PDO;

final class AuthService
{
    private const SESSION_TTL = '+7 days';

    private const USER_SELECT = 'SELECT u.*, s.name AS station_name
                                   FROM users u
                                   LEFT JOIN stations s ON s.id = u.station_id';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{token: string, user: array} */
    public function login(string $username, string $password): array
    {
        $stmt = $this->db->prepare(self::USER_SELECT . ' WHERE u.username = :u AND u.active = 1');
        $stmt->execute(['u' => trim($username)]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password_hash'])) {
            throw new HttpException(401, 'INVALID_CREDENTIALS', 'Kullanıcı adı veya şifre hatalı.');
        }

        $this->db->exec("DELETE FROM sessions WHERE expires_at <= datetime('now')");

        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            "INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (:h, :u, datetime('now', :ttl))"
        )->execute(['h' => hash('sha256', $token), 'u' => $row['id'], 'ttl' => self::SESSION_TTL]);

        return ['token' => $token, 'user' => self::publicUser($row)];
    }

    /** Geçerli oturumun kullanıcısı; token geçersiz veya süresi dolmuşsa null. */
    public function userFromToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            self::USER_SELECT . " JOIN sessions se ON se.user_id = u.id
             WHERE se.token_hash = :h AND se.expires_at > datetime('now') AND u.active = 1"
        );
        $stmt->execute(['h' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row === false ? null : self::publicUser($row);
    }

    public function logout(string $token): void
    {
        $this->db->prepare('DELETE FROM sessions WHERE token_hash = :h')->execute(['h' => hash('sha256', $token)]);
    }

    private static function publicUser(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'username'     => $row['username'],
            'role'         => $row['role'],
            'station_id'   => $row['station_id'] === null ? null : (int) $row['station_id'],
            'station_name' => $row['station_name'],
        ];
    }
}
