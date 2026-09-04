<?php

declare(strict_types=1);

namespace Pericles\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pericles\Device\Base64Url;
use Pericles\Http\ApiException;

final class PasswordResetService
{
    public function __construct(private PDO $database, private int $ttlSeconds = 1800, private int $limit = 3) {}

    public function request(string $email, string $ipAddress): ?array
    {
        $email = AuthService::normalizeEmail($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new ApiException('validation_error', 400, 'A valid email address is required.');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $emailHash = hash('sha256', $email);
        $rate = $this->database->prepare('SELECT COUNT(*) FROM password_reset_attempts WHERE attempted_at >= :threshold AND (email_hash = :email_hash OR ip_address = :ip)');
        $rate->execute([':threshold'=>$now->modify('-15 minutes')->format('Y-m-d H:i:s'), ':email_hash'=>$emailHash, ':ip'=>substr($ipAddress,0,45)]);
        if ((int)$rate->fetchColumn() >= $this->limit) throw new ApiException('rate_limited', 429, 'Too many recovery requests. Try again later.');
        $this->database->prepare('INSERT INTO password_reset_attempts (email_hash, ip_address, attempted_at) VALUES (:email_hash, :ip, :now)')->execute([':email_hash'=>$emailHash, ':ip'=>substr($ipAddress,0,45), ':now'=>$now->format('Y-m-d H:i:s')]);
        $query = $this->database->prepare("SELECT id, email, display_name FROM users WHERE email = :email AND status = 'active' LIMIT 1");
        $query->execute([':email'=>$email]); $user = $query->fetch();
        if (!is_array($user)) return null;
        $this->database->prepare('UPDATE password_reset_tokens SET used_at = :now WHERE user_id = :user_id AND used_at IS NULL')->execute([':now'=>$now->format('Y-m-d H:i:s'), ':user_id'=>(int)$user['id']]);
        $token = Base64Url::encode(random_bytes(32)); $expires = $now->modify('+' . max(300,$this->ttlSeconds) . ' seconds');
        $this->database->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, created_at, expires_at, request_ip) VALUES (:user_id, :hash, :created_at, :expires_at, :ip)')->execute([':user_id'=>(int)$user['id'], ':hash'=>hash('sha256',$token), ':created_at'=>$now->format('Y-m-d H:i:s'), ':expires_at'=>$expires->format('Y-m-d H:i:s'), ':ip'=>substr($ipAddress,0,45)]);
        return ['token'=>$token, 'email'=>(string)$user['email'], 'display_name'=>(string)($user['display_name'] ?? ''), 'expires_at'=>$expires->format(DATE_ATOM)];
    }

    public function reset(string $token, string $password): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/',$token)) throw new ApiException('reset_invalid',400,'This recovery link is invalid or expired.');
        if (strlen($password)<10 || strlen($password)>1024) throw new ApiException('validation_error',400,'The password must contain at least 10 characters.');
        $driver=(string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME); if($driver==='sqlite')$this->database->exec('BEGIN IMMEDIATE TRANSACTION');else$this->database->beginTransaction();
        try {
            $query=$this->database->prepare('SELECT id,user_id,expires_at,used_at FROM password_reset_tokens WHERE token_hash=:hash LIMIT 1'.($driver==='mysql'?' FOR UPDATE':'')); $query->execute([':hash'=>hash('sha256',$token)]); $row=$query->fetch();
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC')); if(!is_array($row)||$row['used_at']!==null||new DateTimeImmutable((string)$row['expires_at'],new DateTimeZone('UTC'))<=$now) throw new ApiException('reset_invalid',400,'This recovery link is invalid or expired.');
            $nowSql=$now->format('Y-m-d H:i:s');
            $this->database->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')->execute([':hash'=>password_hash($password,PASSWORD_DEFAULT),':id'=>(int)$row['user_id']]);
            $this->database->prepare('UPDATE password_reset_tokens SET used_at=:now WHERE id=:id AND used_at IS NULL')->execute([':now'=>$nowSql,':id'=>(int)$row['id']]);
            $this->database->prepare('UPDATE api_sessions SET revoked_at=:now WHERE user_id=:user_id AND revoked_at IS NULL')->execute([':now'=>$nowSql,':user_id'=>(int)$row['user_id']]);
            if($driver==='sqlite')$this->database->exec('COMMIT');else$this->database->commit();
        } catch (\Throwable $e) { if($driver==='sqlite')$this->database->exec('ROLLBACK');elseif($this->database->inTransaction())$this->database->rollBack(); throw $e; }
    }
}
