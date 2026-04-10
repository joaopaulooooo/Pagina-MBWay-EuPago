<?php
/**
 * SMTP class — minimal SMTP client.
 * Supports STARTTLS (port 587) and SMTPS (port 465).
 * Based on PHPMailer 6.x structure (LGPL 2.1).
 */
namespace PHPMailer\PHPMailer;

class SMTP
{
    const DEFAULT_PORT    = 25;
    const MAX_LINE_LENGTH = 998;
    const LE              = "\r\n";
    const MAX_REPLY_SIZE  = 131072; // Fix A4: limite 128KB por resposta

    public int    $Timeout   = 30;
    public int    $Timelimit = 30;  // Fix M5: era 300s
    public bool   $do_debug  = false;
    public string $ErrorInfo = '';

    protected mixed  $smtp_conn   = false;
    protected string $last_reply  = '';
    protected array  $server_caps = [];

    public function connect(string $host, int $port = self::DEFAULT_PORT, int $timeout = 30, array $options = []): bool
    {
        // Fix C3: garantir verify_peer para SMTPS
        if (!empty($options)) {
            $options += [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ];
            if (!isset($options['cafile'])) {
                $cafile = ini_get('openssl.cafile');
                if ($cafile && is_file($cafile)) $options['cafile'] = $cafile;
            }
        }

        $this->smtp_conn = stream_socket_client(
            $host . ':' . $port,
            $errno, $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $options])
        );

        if (!is_resource($this->smtp_conn)) {
            $this->ErrorInfo = "connect failed ({$errno})"; // Fix M1: sem $errstr
            return false;
        }
        stream_set_timeout($this->smtp_conn, $timeout);
        $announce = $this->get_lines();
        if (substr($announce, 0, 3) !== '220') {
            $this->ErrorInfo = 'Connect: bad greeting';
            return false;
        }
        return true;
    }

    public function hello(string $host = ''): bool
    {
        $host = $this->sanitizeHost($host); // Fix A1
        return $this->sendHello('EHLO', $host) || $this->sendHello('HELO', $host);
    }

    protected function sendHello(string $hello, string $host): bool
    {
        $noerror = $this->sendCommand($hello, $hello . ' ' . $host, 250);
        if ($noerror) {
            $this->parseHelloFields($this->last_reply);
        } else {
            $this->server_caps = [];
        }
        return $noerror;
    }

    protected function parseHelloFields(string $response): void
    {
        $this->server_caps = [];
        foreach (explode("\n", $response) as $n => $s) {
            $s = trim(substr($s, 4));
            if (!$s) continue;
            [$cap] = explode(' ', strtoupper($s));
            $this->server_caps[$cap] = $n === 0 ? $s : (strpos($s, ' ') !== false ? explode(' ', $s, 2)[1] : true);
        }
    }

    public function getServerExt(string $name): mixed
    {
        return $this->server_caps[strtoupper($name)] ?? false;
    }

    public function startTLS(): bool
    {
        // Fix M2: verificar que servidor anunciou STARTTLS no EHLO
        if (!isset($this->server_caps['STARTTLS'])) {
            $this->ErrorInfo = 'startTLS: server did not advertise STARTTLS capability';
            return false;
        }
        if (!$this->sendCommand('STARTTLS', 'STARTTLS', 220)) {
            return false;
        }
        // Fix C3: TLS 1.2+ com verificação de certificado para STARTTLS
        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        $params = stream_context_get_params($this->smtp_conn);
        $params['options']['ssl'] = array_merge($params['options']['ssl'] ?? [], [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]);
        stream_context_set_params($this->smtp_conn, $params);
        if (!stream_socket_enable_crypto($this->smtp_conn, true, $crypto)) {
            $this->ErrorInfo = 'startTLS: crypto enable failed';
            return false;
        }
        return true;
    }

    public function authenticate(string $username, string $password): bool
    {
        // Fix A3: preferir AUTH PLAIN se suportado, fallback para AUTH LOGIN
        $authCaps = strtoupper((string)($this->server_caps['AUTH'] ?? ''));
        if (strpos($authCaps, 'PLAIN') !== false) {
            return $this->authPlain($username, $password);
        }
        return $this->authLogin($username, $password);
    }

    protected function authPlain(string $username, string $password): bool
    {
        $creds = base64_encode("\0" . $username . "\0" . $password);
        if (!$this->sendCommand('AUTH PLAIN', 'AUTH PLAIN ' . $creds, 235)) {
            $this->ErrorInfo = 'AUTH PLAIN failed'; // Fix M1
            return false;
        }
        return true;
    }

    protected function authLogin(string $username, string $password): bool
    {
        if (!$this->sendCommand('AUTH LOGIN', 'AUTH LOGIN', 334)) {
            $this->ErrorInfo = 'AUTH LOGIN not accepted';
            return false;
        }
        if (!$this->sendCommand('User', base64_encode($username), 334)) {
            $this->ErrorInfo = 'AUTH LOGIN username failed';
            return false;
        }
        if (!$this->sendCommand('Password', base64_encode($password), 235)) {
            $this->ErrorInfo = 'AUTH LOGIN password failed';
            return false;
        }
        return true;
    }

    public function mail(string $from): bool
    {
        return $this->sendCommand('MAIL FROM', 'MAIL FROM:<' . $from . '>', 250);
    }

    public function recipient(string $to): bool
    {
        return $this->sendCommand('RCPT TO', 'RCPT TO:<' . $to . '>', [250, 251]);
    }

    public function data(string $msg_data): bool
    {
        if (!$this->sendCommand('DATA', 'DATA', 354)) return false;

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $msg_data));
        $data  = '';

        foreach ($lines as $line) {
            // Fix C1: dot-stuffing com precedência de operadores correta
            // BUG ORIGINAL: ($line[0] ?? '' === '.') — '' === '.' avaliado antes do ??
            // CORRETO: verificar isset + comparação explícita
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }

            // Fix M4: wrap respeitando UTF-8
            if (strlen($line) > self::MAX_LINE_LENGTH) {
                $line = $this->wrapLine($line, self::MAX_LINE_LENGTH);
            }

            $data .= $line . self::LE;
        }
        $data .= '.' . self::LE;

        // Fix C2: verificar retorno do fwrite
        $written = fwrite($this->smtp_conn, $data);
        if ($written === false || $written < strlen($data)) {
            $this->ErrorInfo = 'DATA fwrite failed: connection may have dropped';
            return false;
        }

        $reply = $this->get_lines();
        $code  = (int) substr($reply, 0, 3);
        if ($code !== 250) {
            $this->ErrorInfo = 'DATA send failed: got ' . $code;
            return false;
        }
        $this->last_reply = $reply;
        return true;
    }

    public function quit(bool $close_on_error = true): bool
    {
        $ok = $this->sendCommand('QUIT', 'QUIT', 221);
        if ($ok || $close_on_error) $this->close();
        return $ok;
    }

    public function close(): void
    {
        if (is_resource($this->smtp_conn)) {
            fclose($this->smtp_conn);
            $this->smtp_conn = false;
        }
    }

    public function connected(): bool
    {
        return is_resource($this->smtp_conn);
    }

    protected function sendCommand(string $command, string $commandstring, int|array $expect): bool
    {
        if (!$this->connected()) {
            $this->ErrorInfo = "sendCommand({$command}): not connected";
            return false;
        }

        // Fix C2: verificar retorno do fwrite
        $written = fwrite($this->smtp_conn, $commandstring . self::LE);
        if ($written === false) {
            $this->ErrorInfo = "{$command}: fwrite failed";
            return false;
        }

        $reply = $this->get_lines();
        $code  = (int) substr($reply, 0, 3);
        $this->last_reply = $reply;
        $expected = is_array($expect) ? $expect : [$expect];
        if (!in_array($code, $expected)) {
            $this->ErrorInfo = "{$command}: unexpected response " . implode('/', $expected);
            return false;
        }
        return true;
    }

    protected function get_lines(): string
    {
        if (!is_resource($this->smtp_conn)) return '';
        $data    = '';
        $endtime = time() + $this->Timelimit;
        stream_set_timeout($this->smtp_conn, $this->Timeout);

        while (is_resource($this->smtp_conn) && !feof($this->smtp_conn)) {
            $str = fgets($this->smtp_conn, 515);
            if ($str === false) break;

            // Fix A4: limite de tamanho total — previne DoS por memória
            if (strlen($data) + strlen($str) > self::MAX_REPLY_SIZE) {
                $this->ErrorInfo = 'get_lines: response exceeds maximum allowed size';
                $this->close();
                return '';
            }

            $data .= $str;

            // Fix A5: validar formato RFC 5321 — linha final tem DDD + espaço
            if (strlen($str) >= 4 && ctype_digit(substr($str, 0, 3)) && $str[3] === ' ') {
                break;
            }
            if (time() > $endtime) break;
        }
        return $data;
    }

    /**
     * Fix A1: sanitizar hostname para prevenir SMTP header injection no EHLO.
     */
    protected function sanitizeHost(string $host): string
    {
        $host = preg_replace('/[\r\n\t\0]/', '', $host);
        $host = preg_replace('/[^a-zA-Z0-9.\-\[\]:]/', '', $host);
        return $host ?: 'localhost';
    }

    /**
     * Fix M4: wrap de linha respeitando limites de caracteres multi-byte UTF-8.
     * wordwrap() opera em bytes e corta sequências UTF-8 invalidando caracteres.
     */
    protected function wrapLine(string $line, int $maxLen): string
    {
        if (strlen($line) <= $maxLen) return $line;
        $result = '';
        $chars  = mb_str_split($line, 1, 'UTF-8');
        $chunk  = '';
        foreach ($chars as $char) {
            if (strlen($chunk . $char) > $maxLen) {
                $result .= $chunk . self::LE;
                $chunk   = '';
            }
            $chunk .= $char;
        }
        return $result . $chunk;
    }
}
