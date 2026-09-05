<?php

declare(strict_types=1);

namespace Pericles\Support;

use PDO;
use Pericles\Http\ApiException;

final class SupportService
{
    private const CATEGORIES = ['account','payment','access','device','installation','product','other'];
    private const PRIORITIES = ['low','normal','high','urgent'];
    private const STATUSES = ['open','in_progress','awaiting_user','resolved','closed'];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'application/octet-stream' => 'log',
    ];

    private ?bool $enhancedSchema = null;

    public function __construct(private PDO $database, private ?string $storagePath = null)
    {
        $this->storagePath ??= dirname(__DIR__, 2) . '/storage/support';
    }

    public function listForUser(int $userId): array
    {
        if (!$this->hasEnhancedSchema()) {
            $statement = $this->database->prepare('SELECT t.id,t.ticket_number,t.subject,t.category,t.status,t.created_at,t.updated_at,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) AS message_count FROM support_tickets t WHERE t.user_id=:user_id ORDER BY t.updated_at DESC');
            $statement->execute([':user_id'=>$userId]);
            $rows = $statement->fetchAll();
            foreach ($rows as &$row) $row['priority'] = 'normal';
            unset($row);
            return $rows;
        }
        $statement=$this->database->prepare(
            'SELECT t.id,t.ticket_number,t.subject,t.category,t.priority,t.status,t.created_at,t.updated_at, '
            . 'g.name AS game_name,o.order_number,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id AND m.is_internal_note=0) AS message_count '
            . 'FROM support_tickets t LEFT JOIN products p ON p.id=t.product_id LEFT JOIN product_games pg ON pg.product_id=p.id '
            . 'LEFT JOIN games g ON g.id=pg.game_id LEFT JOIN orders o ON o.id=t.order_id '
            . 'WHERE t.user_id=:user_id ORDER BY t.updated_at DESC'
        );
        $statement->execute([':user_id'=>$userId]);
        return $statement->fetchAll();
    }

    public function create(int $userId, string $subject, string $category, string $message): array
    {
        return $this->createRequest($userId, null, $subject, $category, $message);
    }

    public function createRequest(
        ?int $userId,
        ?string $guestEmail,
        string $subject,
        string $category,
        string $message,
        string $priority = 'normal',
        ?int $productId = null,
        ?int $orderId = null,
        array $attachments = []
    ): array {
        $subject=trim($subject); $message=trim($message); $category=strtolower(trim($category));
        $priority=strtolower(trim($priority)); $guestEmail=strtolower(trim((string)$guestEmail));
        if($subject===''||strlen($subject)>180||strlen($message)<10||strlen($message)>10000||!in_array($category,self::CATEGORIES,true)) {
            throw new ApiException('validation_error',400,'Complete the subject, category, and message.');
        }
        if (!in_array($priority, self::PRIORITIES, true)) throw new ApiException('validation_error', 400, 'Select a valid priority.');
        if($userId===null&&($guestEmail===''||strlen($guestEmail)>254||filter_var($guestEmail,FILTER_VALIDATE_EMAIL)===false)) {
            throw new ApiException('validation_error',400,'Enter a valid email address.');
        }
        if ($userId !== null && $orderId !== null) $this->requireOwnedOrder($userId, $orderId);
        if ($productId !== null) $this->requireProduct($productId);
        $storedCategory = $this->persistedCategory($category);

        $driver=$this->driver();
        $this->begin($driver);
        $storedFiles = [];
        try {
            $now=gmdate('Y-m-d H:i:s');
            $number='SUP-'.gmdate('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
            if ($this->hasEnhancedSchema()) {
                $insert=$this->database->prepare(
                    "INSERT INTO support_tickets (ticket_number,user_id,guest_email,product_id,order_id,subject,category,priority,status,created_at,updated_at) "
                    . "VALUES (:number,:user_id,:email,:product_id,:order_id,:subject,:category,:priority,'open',:created_at,:updated_at)"
                );
                $insert->execute([':number'=>$number,':user_id'=>$userId,':email'=>$userId===null?$guestEmail:null,':product_id'=>$productId,':order_id'=>$orderId,':subject'=>$subject,':category'=>$storedCategory,':priority'=>$priority,':created_at'=>$now,':updated_at'=>$now]);
            } else {
                $insert=$this->database->prepare("INSERT INTO support_tickets (ticket_number,user_id,guest_email,subject,category,status,created_at,updated_at) VALUES (:number,:user_id,:email,:subject,:category,'open',:created_at,:updated_at)");
                $insert->execute([':number'=>$number,':user_id'=>$userId,':email'=>$userId===null?$guestEmail:null,':subject'=>$subject,':category'=>$storedCategory,':created_at'=>$now,':updated_at'=>$now]);
            }
            $ticketId=(int)$this->database->lastInsertId();
            $messageId=$this->insertMessage($ticketId,$userId,$userId===null?$guestEmail:null,$message,false,false,$now);
            if ($attachments !== [] && $this->hasEnhancedSchema()) $storedFiles = $this->storeAttachments($ticketId, $messageId, $userId, $attachments, $now);
            $this->commit($driver);
            return ['id'=>$ticketId,'ticket_number'=>$number,'priority'=>$priority];
        } catch(\Throwable $exception) {
            $this->rollBack($driver);
            foreach ($storedFiles as $path) if (is_file($path)) @unlink($path);
            throw $exception;
        }
    }

    public function ticketForUser(string $reference, int $userId): array
    {
        $ticket = $this->findTicket($reference, false);
        if ((int) ($ticket['user_id'] ?? 0) !== $userId) throw new ApiException('ticket_not_found', 404, 'Support ticket not found.');
        return $this->hydrateTicket($ticket, false);
    }

    public function ticketForAdmin(string $reference): array
    {
        return $this->hydrateTicket($this->findTicket($reference, false), true);
    }

    public function replyForUser(string $reference, int $userId, string $message, array $attachments = []): void
    {
        $message = trim($message);
        if (strlen($message) < 2 || strlen($message) > 10000) throw new ApiException('validation_error', 400, 'Enter a reply between 2 and 10,000 characters.');
        $driver = $this->driver();
        $this->begin($driver);
        $storedFiles = [];
        try {
            $ticket = $this->findTicket($reference, true);
            if ((int) ($ticket['user_id'] ?? 0) !== $userId) throw new ApiException('ticket_not_found', 404, 'Support ticket not found.');
            if ((string) $ticket['status'] === 'closed') throw new ApiException('ticket_closed', 409, 'This ticket is closed.');
            $now = gmdate('Y-m-d H:i:s');
            $messageId = $this->insertMessage((int)$ticket['id'],$userId,null,$message,false,false,$now);
            $this->database->prepare("UPDATE support_tickets SET status='open',resolved_at=NULL,updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>(int)$ticket['id']]);
            if ($attachments !== [] && $this->hasEnhancedSchema()) $storedFiles = $this->storeAttachments((int)$ticket['id'],$messageId,$userId,$attachments,$now);
            $this->commit($driver);
        } catch (\Throwable $exception) {
            $this->rollBack($driver);
            foreach ($storedFiles as $path) if (is_file($path)) @unlink($path);
            throw $exception;
        }
    }

    public function markSolved(string $reference, int $userId): void
    {
        $ticket = $this->findTicket($reference, false);
        if ((int)($ticket['user_id'] ?? 0) !== $userId) throw new ApiException('ticket_not_found',404,'Support ticket not found.');
        if ((string)$ticket['status'] === 'closed') throw new ApiException('ticket_closed',409,'This ticket is closed.');
        $now=gmdate('Y-m-d H:i:s');
        $this->database->prepare("UPDATE support_tickets SET status='resolved',resolved_at=:resolved_at,updated_at=:updated_at WHERE id=:id")->execute([':resolved_at'=>$now,':updated_at'=>$now,':id'=>(int)$ticket['id']]);
        $this->audit($userId,'support.ticket_resolved','support_ticket',(string)$ticket['id'],['reference'=>$reference],null);
    }

    public function updateByStaff(
        string $reference,
        int $actorUserId,
        string $priority,
        string $status,
        ?int $assigneeUserId,
        ?string $reply,
        bool $internalNote,
        string $ipAddress,
        array $attachments = []
    ): void {
        $priority=strtolower(trim($priority)); $status=strtolower(trim($status)); $reply=trim((string)$reply);
        if(!in_array($priority,self::PRIORITIES,true)||!in_array($status,self::STATUSES,true)) throw new ApiException('validation_error',400,'Select a valid priority and status.');
        if(strlen($reply)>10000) throw new ApiException('validation_error',400,'The reply is too long.');
        if($assigneeUserId!==null)$this->requireStaff($assigneeUserId);
        $driver=$this->driver(); $this->begin($driver); $storedFiles=[];
        try {
            $ticket=$this->findTicket($reference,true); $now=gmdate('Y-m-d H:i:s');
            if($internalNote&&$reply!==''&&$status==='awaiting_user')$status=(string)$ticket['status'];
            if($assigneeUserId!==null&&$ticket['assigned_to_user_id']===null&&$status==='open')$status='in_progress';
            $resolvedAt=in_array($status,['resolved','closed'],true)?$now:null;
            $this->database->prepare('UPDATE support_tickets SET priority=:priority,status=:status,assigned_to_user_id=:assignee,resolved_at=:resolved,closed_at=:closed,updated_at=:now WHERE id=:id')->execute([
                ':priority'=>$priority,':status'=>$status,':assignee'=>$assigneeUserId,':resolved'=>$resolvedAt,':closed'=>$status==='closed'?$now:null,':now'=>$now,':id'=>(int)$ticket['id'],
            ]);
            if($reply!==''){
                $messageId=$this->insertMessage((int)$ticket['id'],$actorUserId,null,$reply,true,$internalNote,$now);
                if($attachments!==[])$storedFiles=$this->storeAttachments((int)$ticket['id'],$messageId,$actorUserId,$attachments,$now);
            }
            $changes=['reference'=>$reference];
            foreach(['priority','status','assigned_to_user_id'] as $field){$new=$field==='priority'?$priority:($field==='status'?$status:$assigneeUserId);if((string)($ticket[$field]??'')!==(string)$new)$changes[$field]=['from'=>$ticket[$field]??null,'to'=>$new];}
            $this->audit($actorUserId,'support.ticket_updated','support_ticket',(string)$ticket['id'],$changes,$ipAddress);
            if($reply!==''&&$internalNote)$this->audit($actorUserId,'support.internal_note_added','support_ticket',(string)$ticket['id'],['reference'=>$reference],$ipAddress);
            $this->commit($driver);
        } catch(\Throwable $exception){$this->rollBack($driver);foreach($storedFiles as $path)if(is_file($path))@unlink($path);throw $exception;}
    }

    public function adminList(): array
    {
        if (!$this->hasEnhancedSchema()) return $this->database->query('SELECT t.id,t.ticket_number,t.subject,t.category,t.status,t.created_at,t.updated_at,COALESCE(u.email,t.guest_email) AS email,assignee.email AS assigned_email,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) AS message_count FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN users assignee ON assignee.id=t.assigned_to_user_id ORDER BY t.updated_at DESC LIMIT 200')->fetchAll();
        return $this->database->query(
            "SELECT t.id,t.ticket_number,t.subject,t.category,t.priority,t.status,t.created_at,t.updated_at,COALESCE(u.email,t.guest_email) AS email,"
            . "assignee.email AS assigned_email,g.name AS game_name,o.order_number,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id AND m.is_internal_note=0) AS message_count "
            . "FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN users assignee ON assignee.id=t.assigned_to_user_id "
            . "LEFT JOIN products p ON p.id=t.product_id LEFT JOIN product_games pg ON pg.product_id=p.id LEFT JOIN games g ON g.id=pg.game_id LEFT JOIN orders o ON o.id=t.order_id "
            . "ORDER BY CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, t.updated_at ASC LIMIT 200"
        )->fetchAll();
    }

    public function attachmentForUser(int $attachmentId, int $userId, bool $staff = false): array
    {
        $sql='SELECT a.*,t.user_id,m.is_internal_note FROM support_attachments a INNER JOIN support_tickets t ON t.id=a.ticket_id INNER JOIN support_messages m ON m.id=a.message_id WHERE a.id=:id LIMIT 1';
        $statement=$this->database->prepare($sql);$statement->execute([':id'=>$attachmentId]);$row=$statement->fetch();
        if(!is_array($row)||(!$staff&&((int)($row['user_id']??0)!==$userId||(int)$row['is_internal_note']===1)))throw new ApiException('attachment_not_found',404,'Attachment not found.');
        $path=rtrim((string)$this->storagePath,'/\\').DIRECTORY_SEPARATOR.(string)$row['storage_name'];
        if(!is_file($path))throw new ApiException('attachment_not_found',404,'Attachment not found.');
        $row['path']=$path;return $row;
    }

    private function hydrateTicket(array $ticket,bool $includeInternal): array
    {
        $sql='SELECT m.id,m.author_user_id,m.author_email,m.message,m.is_staff_reply,'.($this->hasEnhancedSchema()?'m.is_internal_note':'0 AS is_internal_note').',m.created_at,u.display_name,u.email FROM support_messages m LEFT JOIN users u ON u.id=m.author_user_id WHERE m.ticket_id=:ticket_id';
        if(!$includeInternal&&$this->hasEnhancedSchema())$sql.=' AND m.is_internal_note=0';
        $sql.=' ORDER BY m.created_at,m.id';$messages=$this->database->prepare($sql);$messages->execute([':ticket_id'=>(int)$ticket['id']]);$ticket['messages']=$messages->fetchAll();
        if($this->hasEnhancedSchema()){
            $attachments=$this->database->prepare('SELECT id,message_id,original_name,mime_type,file_size,created_at FROM support_attachments WHERE ticket_id=:ticket_id ORDER BY id');$attachments->execute([':ticket_id'=>(int)$ticket['id']]);$byMessage=[];foreach($attachments->fetchAll() as $attachment)$byMessage[(int)$attachment['message_id']][]=$attachment;
            foreach($ticket['messages'] as &$message)$message['attachments']=$byMessage[(int)$message['id']]??[];unset($message);
        }
        return $ticket;
    }

    private function findTicket(string $reference,bool $lock): array
    {
        $reference=strtoupper(trim($reference));if(!preg_match('/^SUP-[A-Z0-9-]{8,24}$/',$reference))throw new ApiException('ticket_not_found',404,'Support ticket not found.');
        $fields=$this->hasEnhancedSchema()?'t.priority,t.product_id,t.order_id,t.resolved_at,':'\'normal\' AS priority,NULL AS product_id,NULL AS order_id,NULL AS resolved_at,';
        $sql='SELECT t.id,t.ticket_number,t.user_id,t.guest_email,t.assigned_to_user_id,t.subject,t.category,'.$fields.'t.status,t.created_at,t.updated_at,t.closed_at,COALESCE(u.email,t.guest_email) AS customer_email,assignee.email AS assigned_email';
        if($this->hasEnhancedSchema())$sql.=',g.name AS game_name,o.order_number';
        $sql.=' FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN users assignee ON assignee.id=t.assigned_to_user_id';
        if($this->hasEnhancedSchema())$sql.=' LEFT JOIN products p ON p.id=t.product_id LEFT JOIN product_games pg ON pg.product_id=p.id LEFT JOIN games g ON g.id=pg.game_id LEFT JOIN orders o ON o.id=t.order_id';
        $sql.=' WHERE t.ticket_number=:reference LIMIT 1'.($lock&&$this->driver()==='mysql'?' FOR UPDATE':'');
        $statement=$this->database->prepare($sql);$statement->execute([':reference'=>$reference]);$ticket=$statement->fetch();if(!is_array($ticket))throw new ApiException('ticket_not_found',404,'Support ticket not found.');return $ticket;
    }

    private function insertMessage(int $ticketId,?int $authorUserId,?string $authorEmail,string $message,bool $staff,bool $internal,string $now): int
    {
        $sql='INSERT INTO support_messages (ticket_id,author_user_id,author_email,message,is_staff_reply'.($this->hasEnhancedSchema()?',is_internal_note':'').',created_at) VALUES (:ticket_id,:user_id,:email,:message,:staff'.($this->hasEnhancedSchema()?',:internal':'').',:now)';
        $params=[':ticket_id'=>$ticketId,':user_id'=>$authorUserId,':email'=>$authorEmail,':message'=>$message,':staff'=>$staff?1:0,':now'=>$now];if($this->hasEnhancedSchema())$params[':internal']=$internal?1:0;
        $statement=$this->database->prepare($sql);$statement->execute($params);return(int)$this->database->lastInsertId();
    }

    private function storeAttachments(int $ticketId,int $messageId,?int $userId,array $uploads,string $now): array
    {
        $files=$this->normalizeUploads($uploads);if(count($files)>3)throw new ApiException('validation_error',400,'Attach no more than three files.');
        $stored=[];$directory=rtrim((string)$this->storagePath,'/\\');if($files!==[]&&!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new ApiException('attachment_storage_failed',500,'The attachment could not be stored.');
        $finfo=new \finfo(FILEINFO_MIME_TYPE);
        foreach($files as $file){
            if((int)$file['error']===UPLOAD_ERR_NO_FILE)continue;if((int)$file['error']!==UPLOAD_ERR_OK||(int)$file['size']<1||(int)$file['size']>5*1024*1024)throw new ApiException('validation_error',400,'Each attachment must be smaller than 5 MB.');
            $mime=(string)$finfo->file((string)$file['tmp_name']);if(!isset(self::ALLOWED_MIME_TYPES[$mime]))throw new ApiException('validation_error',400,'That attachment type is not supported.');
            $original=substr(basename((string)$file['name']),0,255);$storage=bin2hex(random_bytes(24)).'.'.self::ALLOWED_MIME_TYPES[$mime];$destination=$directory.DIRECTORY_SEPARATOR.$storage;
            if(!move_uploaded_file((string)$file['tmp_name'],$destination))throw new ApiException('attachment_storage_failed',500,'The attachment could not be stored.');
            $stored[]=$destination;$this->database->prepare('INSERT INTO support_attachments (ticket_id,message_id,uploaded_by_user_id,original_name,storage_name,mime_type,file_size,created_at) VALUES (:ticket,:message,:user,:original,:storage,:mime,:size,:now)')->execute([':ticket'=>$ticketId,':message'=>$messageId,':user'=>$userId,':original'=>$original,':storage'=>$storage,':mime'=>$mime,':size'=>(int)$file['size'],':now'=>$now]);
        }
        return $stored;
    }

    private function normalizeUploads(array $uploads): array
    {
        if(!isset($uploads['name']))return[];if(!is_array($uploads['name']))return[$uploads];$files=[];foreach($uploads['name'] as $index=>$name)$files[]=['name'=>$name,'type'=>$uploads['type'][$index]??'','tmp_name'=>$uploads['tmp_name'][$index]??'','error'=>$uploads['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$uploads['size'][$index]??0];return$files;
    }

    private function requireOwnedOrder(int $userId,int $orderId): void{$statement=$this->database->prepare('SELECT COUNT(*) FROM orders WHERE id=:id AND user_id=:user');$statement->execute([':id'=>$orderId,':user'=>$userId]);if((int)$statement->fetchColumn()!==1)throw new ApiException('validation_error',400,'Select one of your orders.');}
    private function requireProduct(int $productId): void{$statement=$this->database->prepare('SELECT COUNT(*) FROM products WHERE id=:id');$statement->execute([':id'=>$productId]);if((int)$statement->fetchColumn()!==1)throw new ApiException('validation_error',400,'Select a valid Enhancement.');}
    private function requireStaff(int $userId): void{$statement=$this->database->prepare("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id AND r.slug IN ('admin','moderator')");$statement->execute([':id'=>$userId]);if((int)$statement->fetchColumn()!==1)throw new ApiException('validation_error',400,'Select a valid staff member.');}
    private function persistedCategory(string $category): string
    {
        if ($this->driver() !== 'mysql') return $category;
        try {
            $statement=$this->database->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='support_tickets' AND column_name='category' LIMIT 1");
            $type=strtolower((string)$statement->fetchColumn());
            if ($type===''||str_contains($type,"'".$category."'")) return $category;
        } catch (\PDOException) {
            return $category;
        }
        return $category==='installation'?'product':'other';
    }
    private function hasEnhancedSchema(): bool{if($this->enhancedSchema!==null)return$this->enhancedSchema;try{$this->database->query('SELECT priority,resolved_at FROM support_tickets WHERE 1=0');$this->database->query('SELECT is_internal_note FROM support_messages WHERE 1=0');$this->database->query('SELECT id FROM support_attachments WHERE 1=0');return$this->enhancedSchema=true;}catch(\PDOException){return$this->enhancedSchema=false;}}
    private function driver(): string{return(string)$this->database->getAttribute(PDO::ATTR_DRIVER_NAME);}
    private function begin(string $driver): void{if($driver==='sqlite')$this->database->exec('BEGIN IMMEDIATE TRANSACTION');else$this->database->beginTransaction();}
    private function commit(string $driver): void{if($driver==='sqlite')$this->database->exec('COMMIT');else$this->database->commit();}
    private function rollBack(string $driver): void{if($driver==='sqlite'){if($this->database->inTransaction())$this->database->exec('ROLLBACK');}elseif($this->database->inTransaction())$this->database->rollBack();}
    private function audit(?int $actor,string $action,string $type,string $id,array $metadata,?string $ip): void{try{$statement=$this->database->prepare('INSERT INTO admin_audit_logs (actor_user_id,action,target_type,target_id,metadata_json,ip_address,created_at) VALUES (:actor,:action,:type,:id,:metadata,:ip,:now)');$statement->execute([':actor'=>$actor,':action'=>$action,':type'=>$type,':id'=>$id,':metadata'=>json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),':ip'=>$ip===null?null:substr($ip,0,45),':now'=>gmdate('Y-m-d H:i:s')]);}catch(\PDOException){}}
}
