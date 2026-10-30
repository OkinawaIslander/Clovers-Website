<?php
/**
 * 轻量 SMTP 发信类（无需 Composer）
 *
 * 支持：
 *   - 465 端口（SSL 直连，QQ 邮箱最常用）
 *   - 587 / 25 端口（STARTTLS 升级）
 *   - AUTH LOGIN / AUTH PLAIN 两种登录方式
 *   - 465 不通时自动改用备用端口（SMTP_FALLBACK_PORT）
 *
 * 出错时会给出中文原因（授权码错误、端口被拦、超时等），
 * 直接显示在网站提示里，方便对照排查。
 */
class SmtpMailer
{
    private $host;
    private $ports = array();
    private $user;
    private $pass;
    private $fromName;
    private $fromEmail;
    private $timeout;
    private $socket = null;
    private $lastError = '';
    private $trace = array();
    private $endpoint = '';
    private $ehloResp = '';
    private $lastResponse = '';
    private $secureMode = '';

    public function __construct($host, $port, $user, $pass, $fromName = '', $fallbackPort = 0, $timeout = 15)
    {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->fromName = $fromName ?: $user;
        $this->fromEmail = $user;
        $this->timeout = $timeout > 0 ? $timeout : 15;

        $this->ports[] = (int) $port;
        if ($fallbackPort && (int) $fallbackPort !== (int) $port) {
            $this->ports[] = (int) $fallbackPort;
        }
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    /** 实际成功的「主机:端口(加密方式)」 */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    /** 每个尝试步骤的日志，排查用 */
    public function getTrace()
    {
        return $this->trace;
    }

    /**
     * 发送邮件，成功返回 true
     */
    public function send($to, $subject, $body, $isHtml = true)
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = '收件邮箱格式不正确';
            return false;
        }

        $errors = array();
        foreach ($this->ports as $port) {
            $this->trace = array();
            try {
                $this->connect($port);
                $this->handshake($port);
                $this->authenticate();
                $this->sendMessage($to, $subject, $body, $isHtml);
                $this->quit();
                $this->close();
                $this->endpoint = $this->host . ':' . $port . ' (' . $this->secureMode . ')';
                return true;
            } catch (Exception $e) {
                $this->trace[] = '端口 ' . $port . '：' . $e->getMessage();
                $errors[] = '端口 ' . $port . '：' . $e->getMessage();
                $this->close();
            }
        }

        $this->lastError = implode(' ｜ ', $errors);
        return false;
    }

    /**
     * 只测试「连接 + 登录」，不真正发信（用于健康自检页）
     * 返回 array('ok' => bool, 'endpoint' => string, 'message' => string, 'steps' => array)
     */
    public function testAuth()
    {
        $errors = array();
        foreach ($this->ports as $port) {
            $this->trace = array();
            try {
                $this->connect($port);
                $this->handshake($port);
                $this->authenticate();
                $this->quit();
                $this->close();
                $endpoint = $this->host . ':' . $port . ' (' . $this->secureMode . ')';

                return array(
                    'ok' => true,
                    'endpoint' => $endpoint,
                    'message' => 'SMTP 连接与登录均成功（' . $endpoint . '）',
                    'steps' => $this->trace,
                );
            } catch (Exception $e) {
                $this->trace[] = '端口 ' . $port . '：' . $e->getMessage();
                $errors[] = '端口 ' . $port . '：' . $e->getMessage();
                $this->close();
            }
        }

        return array(
            'ok' => false,
            'endpoint' => '',
            'message' => implode(' ｜ ', $errors),
            'steps' => $this->trace,
        );
    }

    // ================= 内部实现 =================

    private function connect($port)
    {
        if (!function_exists('stream_socket_client')) {
            throw new Exception('PHP 禁用了 stream_socket_client 函数，请在宝塔 PHP 设置的「禁用函数」里删除它');
        }

        $secure = in_array((int) $port, array(465, 994), true);
        if ($secure && !in_array('ssl', stream_get_transports(), true)) {
            throw new Exception('PHP 没有开启 openssl 扩展（缺少 ssl 传输），请在宝塔 PHP 设置里安装/启用 openssl');
        }
        $this->secureMode = $secure ? 'SSL' : '明文';

        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
            ),
        ));

        $remote = ($secure ? 'ssl://' : 'tcp://') . $this->host . ':' . $port;
        $this->trace[] = '连接 ' . $remote;

        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new Exception($this->explainConnectError($errstr, $errno));
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->read(array(220), '服务器问候');
    }

    private function explainConnectError($errstr, $errno)
    {
        $msg = trim((string) $errstr);
        $lower = strtolower($msg);

        if (strpos($lower, 'connection refused') !== false) {
            return '连接被拒绝（' . $this->host . ' 的端口未开放，或被服务器防火墙拦截）';
        }
        if (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false) {
            return '连接超时（可能是阿里云安全组 / 宝塔系统防火墙拦截了出站端口，或服务器无法访问外网）';
        }
        if (strpos($lower, 'getaddrinfo') !== false || strpos($lower, 'name or service not known') !== false) {
            return '域名解析失败，无法找到 ' . $this->host . '（请检查服务器 DNS 设置）';
        }
        if (strpos($lower, 'ssl') !== false || strpos($lower, 'crypto') !== false) {
            return 'SSL 握手失败：' . $msg . '（请确认 PHP openssl 扩展已开启）';
        }
        if ($msg === '') {
            return '无法连接 SMTP 服务器（错误号 ' . $errno . '），请检查端口与网络';
        }
        return '无法连接 SMTP 服务器：' . $msg . '（错误号 ' . $errno . '）';
    }

    private function handshake($port)
    {
        $ehlo = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $ehlo);
        if ($ehlo === '') {
            $ehlo = 'localhost';
        }

        $this->cmd('EHLO ' . $ehlo, array(250), 'EHLO 握手');
        $this->ehloResp = $this->lastResponse;

        // 非 465 端口：尝试 STARTTLS 加密（QQ 的 587 端口必须加密后才能登录）
        if ((int) $port !== 465) {
            if (stripos($this->ehloResp, 'STARTTLS') !== false) {
                $this->cmd('STARTTLS', array(220), 'STARTTLS');
                $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($ok !== true) {
                    throw new Exception('STARTTLS 加密失败（请确认 PHP openssl 扩展已开启）');
                }
                $this->cmd('EHLO ' . $ehlo, array(250), '加密后 EHLO');
                $this->ehloResp = $this->lastResponse;
                $this->secureMode = 'STARTTLS';
            } else {
                // 25 端口明文也能尝试登录；587 明文登录会被拒绝，交给 AUTH 报错
                $this->trace[] = '服务器未提供 STARTTLS，将以明文尝试登录';
            }
        }
    }

    private function authenticate()
    {
        $authLine = '';
        if (preg_match('/^250[ -]AUTH[ =](.+)$/mi', $this->ehloResp, $m)) {
            $authLine = strtoupper(trim($m[1]));
        }

        // 优先 LOGIN；只有服务器明确不支持时才用 PLAIN
        if ($authLine !== '' && strpos($authLine, 'LOGIN') === false && strpos($authLine, 'PLAIN') !== false) {
            $this->authPlain();
            return;
        }

        $this->cmd('AUTH LOGIN', array(334), 'AUTH LOGIN');
        $this->cmd(base64_encode($this->user), array(334), '提交邮箱账号');
        $this->cmd(base64_encode($this->pass), array(235), '提交授权码');
    }

    private function authPlain()
    {
        $token = base64_encode("\0" . $this->user . "\0" . $this->pass);
        $this->cmd('AUTH PLAIN ' . $token, array(235), 'AUTH PLAIN');
    }

    private function sendMessage($to, $subject, $body, $isHtml)
    {
        $this->cmd('MAIL FROM:<' . $this->fromEmail . '>', array(250), 'MAIL FROM');
        $this->cmd('RCPT TO:<' . $to . '>', array(250, 251), 'RCPT TO');
        $this->cmd('DATA', array(354), 'DATA');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
        $domain = substr(strrchr($this->fromEmail, '@'), 1);
        $messageId = '<' . bin2hex(random_bytes(8)) . '.' . time() . '@' . $domain . '>';
        $contentType = $isHtml ? 'text/html' : 'text/plain';

        $headers = array(
            'From: ' . $encodedName . ' <' . $this->fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date('r'),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType . '; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: Clovers-SmtpMailer',
        );

        $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        $this->trace[] = '发送邮件数据（' . strlen($data) . ' 字节）';
        $this->write($data . "\r\n.\r\n");
        $this->read(array(250), '邮件正文');
    }

    private function quit()
    {
        try {
            $this->write("QUIT\r\n");
            if ($this->socket) {
                @fgets($this->socket, 512);
            }
        } catch (Exception $e) {
            // 退出失败不影响发信结果
        }
    }

    private function cmd($command, $expect, $step)
    {
        $this->trace[] = '>> ' . $this->maskCommand($command);
        $this->write($command . "\r\n");
        $response = $this->read($expect, $step);
        $this->trace[] = '<< ' . trim(preg_replace('/\s+/', ' ', $response));
        return $response;
    }

    private function write($data)
    {
        if (!$this->socket) {
            throw new Exception('连接已关闭');
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false || $written < strlen($data)) {
            throw new Exception('向服务器写入数据失败，连接可能已断开');
        }
    }

    private function read($expect, $step)
    {
        $response = '';
        while (true) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new Exception($step . ' 超时：服务器 ' . $this->timeout . ' 秒内没有响应（端口可能被拦截）');
                }
                throw new Exception($step . ' 失败：连接被服务器提前关闭（' . $this->clean($response) . '）');
            }

            $response .= $line;

            // 多行响应：第 4 个字符是 '-' 表示还有下一行
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        $this->lastResponse = $response;
        $code = (int) substr($response, 0, 3);
        $expect = (array) $expect;

        if (!in_array($code, $expect, true)) {
            throw new Exception($step . ' 失败：' . $this->clean($response) . $this->hintForCode($code));
        }

        return $response;
    }

    private function hintForCode($code)
    {
        if (in_array($code, array(530, 534, 535), true)) {
            return '【请检查 QQ 邮箱授权码：不是 QQ 登录密码，需要到 mail.qq.com → 设置 → 账户 里重新生成，并且和上面的账号是同一个 QQ 号】';
        }
        if ($code === 550 || $code === 553) {
            return '【收件地址被拒绝，请确认邮箱地址真实存在】';
        }
        if ($code === 554) {
            return '【邮件被服务器判定为垃圾邮件，可稍后重试或更换邮件内容】';
        }
        if ($code === 421) {
            return '【服务器当前繁忙或连接数过多，请稍后重试】';
        }
        if (in_array($code, array(500, 502, 504), true)) {
            return '【服务器不支持该命令，请确认端口是 465 或 587】';
        }
        return '';
    }

    private function clean($text)
    {
        $text = preg_replace('/\s+/', ' ', (string) $text);
        return trim($text);
    }

    private function maskCommand($command)
    {
        if (stripos($command, 'AUTH PLAIN') === 0) {
            return 'AUTH PLAIN ******';
        }
        if (preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $command)) {
            return '******';
        }
        return $command;
    }

    private function close()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}
