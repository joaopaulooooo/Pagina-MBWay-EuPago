<?php
/**
 * PHPMailer — minimal SMTP mailer.
 * Supports STARTTLS (port 587) and SMTPS (port 465).
 * API-compatible with PHPMailer 6.x (LGPL 2.1).
 */
namespace PHPMailer\PHPMailer;

class PHPMailer
{
    // ── Transport constants ───────────────────────────────────────────────
    const ENCRYPTION_STARTTLS = 'tls';
    const ENCRYPTION_SMTPS    = 'ssl';

    // ── Public properties (PHPMailer 6.x API) ────────────────────────────
    public string $Host       = 'localhost';
    public int    $Port       = 587;
    public bool   $SMTPAuth   = true;
    public string $Username   = '';
    public string $Password   = '';
    public string $SMTPSecure = self::ENCRYPTION_STARTTLS;
    public string $CharSet    = 'UTF-8';
    public string $ContentType = 'text/plain';
    public string $Encoding   = '8bit';
    public string $Subject    = '';
    public string $Body       = '';
    public string $AltBody    = '';
    public string $ErrorInfo  = '';
    public bool   $SMTPDebug  = false;

    protected string $Mailer    = 'smtp';
    protected array  $to        = [];
    protected array  $cc        = [];
    protected array  $bcc       = [];
    protected array  $replyTo   = [];
    protected string $fromEmail = '';
    protected string $fromName  = '';
    protected ?SMTP  $smtp      = null;
    // Boundary gerado UMA única vez por envio e partilhado entre buildHeaders() e buildBody()
    protected string $boundary  = '';

    // ── Constructor ───────────────────────────────────────────────────────
    public function __construct(bool $exceptions = false) {}

    // ── API methods ───────────────────────────────────────────────────────
    public function isSMTP(): void { $this->Mailer = 'smtp'; }

    public function setFrom(string $address, string $name = ''): bool
    {
        // Fix A2: validar formato do endereço
        if (!$this->validateAddress($address)) {
            throw new Exception('Invalid From address: ' . $address);
        }
        $this->fromEmail = $this->punyEncode($address);
        $this->fromName  = $this->sanitizeHeader($name); // Fix A1
        return true;
    }

    public function addAddress(string $address, string $name = ''): bool
    {
        if (!$this->validateAddress($address)) {
            throw new Exception('Invalid recipient address: ' . $address);
        }
        $this->to[] = [$this->punyEncode($address), $this->sanitizeHeader($name)];
        return true;
    }

    public function addReplyTo(string $address, string $name = ''): bool
    {
        if (!$this->validateAddress($address)) {
            throw new Exception('Invalid Reply-To address: ' . $address);
        }
        $this->replyTo[] = [$this->punyEncode($address), $this->sanitizeHeader($name)];
        return true;
    }

    public function addCC(string $address, string $name = ''): bool
    {
        if (!$this->validateAddress($address)) {
            throw new Exception('Invalid CC address: ' . $address);
        }
        $this->cc[] = [$this->punyEncode($address), $this->sanitizeHeader($name)];
        return true;
    }

    public function addBCC(string $address, string $name = ''): bool
    {
        if (!$this->validateAddress($address)) {
            throw new Exception('Invalid BCC address: ' . $address);
        }
        $this->bcc[] = [$this->punyEncode($address), $this->sanitizeHeader($name)];
        return true;
    }

    public function isHTML(bool $isHTML = true): void
    {
        $this->ContentType = $isHTML ? 'text/html' : 'text/plain';
    }

    // ── Header sanitization helpers ───────────────────────────────────────

    /**
     * Fix A1: remover \r e \n de qualquer valor que vá para um header SMTP.
     * Previne header injection via Subject, From, Reply-To, etc.
     */
    protected function sanitizeHeader(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Fix A2: validar endereço de email antes de usar em headers ou RCPT TO.
     */
    protected function validateAddress(string $address): bool
    {
        return (bool) filter_var($address, FILTER_VALIDATE_EMAIL);
    }

    // ── Send ──────────────────────────────────────────────────────────────
    public function send(): bool
    {
        if (empty($this->to)) {
            throw new Exception('No recipients defined');
        }
        if (empty($this->fromEmail)) {
            throw new Exception('From address not set');
        }

        // Gerar boundary aqui, uma única vez, antes de buildHeaders e buildBody
        if ($this->AltBody && $this->ContentType === 'text/html') {
            $this->boundary = 'b' . md5(uniqid('mailer', true) . $this->Subject);
        } else {
            $this->boundary = '';
        }

        $header = $this->buildHeaders();
        $body   = $this->buildBody();

        return $this->smtpSend($header, $body);
    }

    // ── SMTP send ─────────────────────────────────────────────────────────
    protected function smtpSend(string $header, string $body): bool
    {
        $this->smtp = new SMTP();

        // Choose connection type
        if ($this->SMTPSecure === self::ENCRYPTION_SMTPS) {
            $host    = 'ssl://' . $this->Host;
            $options = ['verify_peer' => true, 'verify_peer_name' => true];
        } else {
            $host    = $this->Host;
            $options = [];
        }

        if (!$this->smtp->connect($host, $this->Port, 30, $options)) {
            throw new Exception('SMTP connect failed: ' . $this->smtp->ErrorInfo);
        }

        $hostname = $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';
        if (!$this->smtp->hello($hostname)) {
            throw new Exception('EHLO failed: ' . $this->smtp->ErrorInfo);
        }

        // STARTTLS upgrade
        if ($this->SMTPSecure === self::ENCRYPTION_STARTTLS) {
            if (!$this->smtp->startTLS()) {
                throw new Exception('STARTTLS failed: ' . $this->smtp->ErrorInfo);
            }
            // Re-EHLO after TLS
            if (!$this->smtp->hello($hostname)) {
                throw new Exception('EHLO after STARTTLS failed: ' . $this->smtp->ErrorInfo);
            }
        }

        // Auth
        if ($this->SMTPAuth) {
            if (!$this->smtp->authenticate($this->Username, $this->Password)) {
                throw new Exception('SMTP auth failed: ' . $this->smtp->ErrorInfo);
            }
        }

        // MAIL FROM
        if (!$this->smtp->mail($this->fromEmail)) {
            throw new Exception('MAIL FROM failed: ' . $this->smtp->ErrorInfo);
        }

        // RCPT TO — all recipients
        $allRcpt = array_merge($this->to, $this->cc, $this->bcc);
        foreach ($allRcpt as [$addr, ]) {
            if (!$this->smtp->recipient($addr)) {
                throw new Exception('RCPT TO <' . $addr . '> failed: ' . $this->smtp->ErrorInfo);
            }
        }

        // DATA — headers + blank line + body, conforme RFC 5321
        $fullMessage = $header . "\r\n" . $body;
        if (!$this->smtp->data($fullMessage)) {
            throw new Exception('DATA failed: ' . $this->smtp->ErrorInfo);
        }

        $this->smtp->quit();
        return true;
    }

    // ── Build headers ─────────────────────────────────────────────────────
    protected function buildHeaders(): string
    {
        $h  = 'Date: ' . date('r') . "\r\n";
        $h .= 'From: ' . $this->formatAddress($this->fromEmail, $this->fromName) . "\r\n";

        // To
        $toList = array_map(fn($r) => $this->formatAddress($r[0], $r[1]), $this->to);
        $h .= 'To: ' . implode(', ', $toList) . "\r\n";

        if ($this->cc) {
            $ccList = array_map(fn($r) => $this->formatAddress($r[0], $r[1]), $this->cc);
            $h .= 'Cc: ' . implode(', ', $ccList) . "\r\n";
        }

        if ($this->replyTo) {
            $rtList = array_map(fn($r) => $this->formatAddress($r[0], $r[1]), $this->replyTo);
            $h .= 'Reply-To: ' . implode(', ', $rtList) . "\r\n";
        }

        $h .= 'Subject: ' . $this->encodeHeader($this->sanitizeHeader($this->Subject)) . "\r\n";
        $h .= 'Message-ID: <Mailer' . bin2hex(random_bytes(8)) . '.' . mt_rand(10000000, 99999999) . '@' . ($_SERVER['SERVER_NAME'] ?? 'mailer.local') . ">\r\n";
        $h .= 'X-Mailer: PHPMailer/1.0' . "\r\n";
        $h .= 'MIME-Version: 1.0' . "\r\n";

        // Usar o boundary já gerado em send() — garante que header e body usam o mesmo valor
        if ($this->boundary !== '') {
            $h .= 'Content-Type: multipart/alternative; boundary="' . $this->boundary . '"';
        } else {
            $h .= 'Content-Type: ' . $this->ContentType . '; charset=' . $this->CharSet . "\r\n";
            $h .= 'Content-Transfer-Encoding: ' . $this->Encoding;
        }

        return $h;
    }

    // ── Build body ────────────────────────────────────────────────────────
    protected function buildBody(): string
    {
        // Usar o mesmo boundary do cabeçalho (gerado em send())
        if ($this->boundary !== '') {
            return
                '--' . $this->boundary . "\r\n" .
                'Content-Type: text/plain; charset=' . $this->CharSet . "\r\n" .
                'Content-Transfer-Encoding: ' . $this->Encoding . "\r\n\r\n" .
                $this->AltBody . "\r\n\r\n" .
                '--' . $this->boundary . "\r\n" .
                'Content-Type: text/html; charset=' . $this->CharSet . "\r\n" .
                'Content-Transfer-Encoding: ' . $this->Encoding . "\r\n\r\n" .
                $this->Body . "\r\n\r\n" .
                '--' . $this->boundary . '--';
        }
        return $this->Body;
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    protected function formatAddress(string $email, string $name = ''): string
    {
        if ($name === '') return $email;
        $name = $this->encodeHeader($name);
        return $name . ' <' . $email . '>';
    }

    protected function encodeHeader(string $str): string
    {
        if (preg_match('/[^\x20-\x7E]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }

    protected function punyEncode(string $address): string
    {
        // Basic pass-through; real IDN encoding requires intl extension
        return $address;
    }

    public function clearAddresses(): void   { $this->to = []; }
    public function clearCCs(): void         { $this->cc = []; }
    public function clearBCCs(): void        { $this->bcc = []; }
    public function clearReplyTos(): void    { $this->replyTo = []; }
    public function clearAllRecipients(): void {
        $this->to = $this->cc = $this->bcc = [];
    }
}
