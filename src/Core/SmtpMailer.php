<?php
namespace exPricer\Core;

class SmtpMailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $from;
    private $replyTo;
    private $socket;
    private $debug = false;
    private $timeout = 10; // 10 seconds timeout

    public function __construct($host, $port, $username, $password, $from, $replyTo = null) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->from = $from;
        $this->replyTo = $replyTo ?? $from;
    }

    public function setDebug($debug) {
        $this->debug = $debug;
    }

    public function setTimeout($seconds) {
        $this->timeout = $seconds;
    }

    private function debug($message) {
        if ($this->debug) {
            error_log("SMTP Debug: " . $message);
        }
    }

    private function connect() {
        // Handle different SMTP ports:
        // 465: SMTPS (SSL from start)
        // 587: Submission (STARTTLS)
        // 25: Traditional SMTP (no encryption by default)
        if ($this->port == 465) {
            $this->socket = @fsockopen('ssl://' . $this->host, $this->port, $errno, $errstr, $this->timeout);
        } else {
            $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        }
        
        if (!$this->socket) {
            throw new \RuntimeException("Failed to connect to SMTP server: $errstr ($errno)");
        }

        $response = $this->getResponse();
        $this->debug("Server: " . $response);

        // Send EHLO
        $this->sendCommand("EHLO " . $_SERVER['HTTP_HOST'], 250);
        
        // For ports 587 and 25, try STARTTLS if available
        if ($this->port == 587 || $this->port == 25) {
            try {
                $this->sendCommand("STARTTLS", 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException("Failed to enable TLS");
                }
                // Send EHLO again after TLS
                $this->sendCommand("EHLO " . $_SERVER['HTTP_HOST'], 250);
            } catch (\Exception $e) {
                $this->debug("TLS not available, continuing without encryption: " . $e->getMessage());
            }
        }
        
        // Login if credentials are provided
        if (!empty($this->username) && !empty($this->password)) {
            $this->sendCommand("AUTH LOGIN", 334);
            $this->sendCommand(base64_encode($this->username), 334);
            $this->sendCommand(base64_encode($this->password), 235);
        }
    }

    private function getResponse() {
        $response = '';
        $info = stream_get_meta_data($this->socket);
        
        while (!feof($this->socket) && !$info['timed_out']) {
            $line = fgets($this->socket, 515);
            if ($line === false) break;
            
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
            
            $info = stream_get_meta_data($this->socket);
        }
        
        if ($info['timed_out']) {
            throw new \RuntimeException("SMTP connection timed out");
        }
        
        return $response;
    }

    private function sendCommand($command, $expectedCode) {
        fputs($this->socket, $command . "\r\n");
        $this->debug("Client: " . $command);
        
        $response = $this->getResponse();
        $this->debug("Server: " . $response);
        
        $code = intval(substr($response, 0, 3));
        if ($code !== $expectedCode) {
            throw new \RuntimeException("SMTP Error: Expected $expectedCode, got $code - $response");
        }
        
        return $response;
    }

    public function send($to, $subject, $message) {
        try {
            $this->connect();
            
            // Set sender
            $this->sendCommand("MAIL FROM: <{$this->from}>", 250);
            
            // Set recipient
            $this->sendCommand("RCPT TO: <{$to}>", 250);
            
            // Send email data
            $this->sendCommand("DATA", 354);
            
            // Prepare headers
            $headers = [
                "From: {$this->from}",
                "Reply-To: {$this->replyTo}",
                "To: {$to}",
                "Subject: {$subject}",
                "MIME-Version: 1.0",
                "Content-Type: text/html; charset=UTF-8",
                "Content-Length: " . strlen($message),
                "X-Mailer: PHP/" . phpversion()
            ];
            
            // Debug message content
            $this->debug("Message length: " . strlen($message));
            $this->debug("Longest line length: " . max(array_map('strlen', explode("\n", $message))));
            
            // Check for long URLs
            if (preg_match_all('/https?:\/\/[^\s<>"]+|www\.[^\s<>"]+/', $message, $matches)) {
                foreach ($matches[0] as $url) {
                    $this->debug("Found URL with length " . strlen($url) . ": " . substr($url, 0, 50) . "...");
                }
            }
            
            // Normalize line endings in the message
            $message = str_replace(["\r\n", "\r", "\n"], "\r\n", $message);
            
            // Send headers and message with proper line endings
            $emailContent = implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.\r\n";
            fputs($this->socket, $emailContent);
            
            // Get the response after sending the message
            $response = $this->getResponse();
            $this->debug("Server response after sending message: " . $response);
            
            // Check if the message was accepted
            $code = intval(substr($response, 0, 3));
            if ($code !== 250) {
                throw new \RuntimeException("SMTP Error: Message not accepted by server - $response");
            }
            
            $this->sendCommand("QUIT", 221);
            fclose($this->socket);
            return true;
            
        } catch (\Exception $e) {
            if ($this->socket) {
                fclose($this->socket);
            }
            error_log("SMTP Error: " . $e->getMessage());
            throw $e;
        }
    }
} 