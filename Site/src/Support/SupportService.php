<?php

declare(strict_types=1);

namespace Pericles\Support;

use PDO;
use Pericles\Http\ApiException;

final class SupportService
{
    private const CATEGORIES = ['account','payment','access','device','installation','product','other'];

    public function __construct(private PDO $database) {}

    public function listForUser(int $userId): array
    {
        $statement=$this->database->prepare('SELECT t.id,t.ticket_number,t.subject,t.category,t.status,t.created_at,t.updated_at,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) AS message_count FROM support_tickets t WHERE t.user_id=:user_id ORDER BY t.updated_at DESC');
        $statement->execute([':user_id'=>$userId]); return $statement->fetchAll();
    }

    public function create(int $userId, string $subject, string $category, string $message): array
    {
        return $this->createRequest($userId, null, $subject, $category, $message);
    }

    public function createRequest(?int $userId, ?string $guestEmail, string $subject, string $category, string $message): array
    {
        $subject=trim($subject); $message=trim($message); $category=strtolower(trim($category)); $guestEmail=strtolower(trim((string)$guestEmail));
        if($subject===''||strlen($subject)>180||strlen($message)<10||strlen($message)>10000||!in_array($category,self::CATEGORIES,true)) throw new ApiException('validation_error',400,'Complete the subject, category, and message.');
        if($userId===null&&($guestEmail===''||strlen($guestEmail)>254||filter_var($guestEmail,FILTER_VALIDATE_EMAIL)===false)) throw new ApiException('validation_error',400,'Enter a valid email address.');
        $driver=(string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME); if($driver==='sqlite')$this->database->exec('BEGIN IMMEDIATE TRANSACTION');else$this->database->beginTransaction();
        try {
            $now=gmdate('Y-m-d H:i:s'); $number='SUP-'.gmdate('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
            $insert=$this->database->prepare("INSERT INTO support_tickets (ticket_number,user_id,guest_email,subject,category,status,created_at,updated_at) VALUES (:number,:user_id,:email,:subject,:category,'open',:now,:now)");
            $insert->execute([':number'=>$number,':user_id'=>$userId,':email'=>$userId===null?$guestEmail:null,':subject'=>$subject,':category'=>$category,':now'=>$now]); $ticketId=(int)$this->database->lastInsertId();
            $this->database->prepare('INSERT INTO support_messages (ticket_id,author_user_id,author_email,message,is_staff_reply,created_at) VALUES (:ticket_id,:user_id,:email,:message,0,:now)')->execute([':ticket_id'=>$ticketId,':user_id'=>$userId,':email'=>$userId===null?$guestEmail:null,':message'=>$message,':now'=>$now]);
            if($driver==='sqlite')$this->database->exec('COMMIT');else$this->database->commit();
            return ['id'=>$ticketId,'ticket_number'=>$number];
        } catch(\Throwable $e){if($driver==='sqlite')$this->database->exec('ROLLBACK');elseif($this->database->inTransaction())$this->database->rollBack();throw $e;}
    }

    public function adminList(): array
    {
        return $this->database->query('SELECT t.id,t.ticket_number,t.subject,t.category,t.status,t.created_at,t.updated_at,COALESCE(u.email,t.guest_email) AS email,assignee.email AS assigned_email,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) AS message_count FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN users assignee ON assignee.id=t.assigned_to_user_id ORDER BY t.updated_at DESC LIMIT 200')->fetchAll();
    }
}
