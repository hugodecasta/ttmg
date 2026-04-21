<?php
// Simple tool submission endpoint: accepts JSON, sends formatted email.
// Expects environment configuration in .env file at project root.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- PHP 8 string helper polyfills (for PHP 7.x environments) -------------
if (!function_exists('str_starts_with')) {
	function str_starts_with($haystack, $needle) {
		return $needle === '' || strpos($haystack, $needle) === 0;
	}
}
if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle) {
		return $needle === '' || strpos($haystack, $needle) !== false;
	}
}

// ---- Helpers --------------------------------------------------------------
function respond($code, $data) {
	http_response_code($code);
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function load_env($path) {
	if (!file_exists($path)) return [];
	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$env = [];
	foreach ($lines as $line) {
		if (str_starts_with(trim($line), '#')) continue;
		if (!str_contains($line, '=')) continue;
		[$k, $v] = explode('=', $line, 2);
		$env[trim($k)] = trim($v);
	}
	return $env;
}

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Minimal SMTP client supporting STARTTLS + AUTH LOGIN (for port 587)
function smtp_send($config, $to, $subject, $html, $text, $extra_headers = []) {
	$server = $config['SENDER_SMTP_SERVER'] ?? null;
	$port   = (int)($config['SENDER_PORT'] ?? 587);
	$user   = $config['SENDER'] ?? null;
	$pass   = $config['SENDER_KEY'] ?? null;
	if (!$server || !$user || !$pass) throw new Exception('SMTP config incomplete');

	$timeout = 30;
	$fp = @stream_socket_client($server . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
	if (!$fp) throw new Exception('SMTP connect failed: ' . $errstr);

	$read = function() use ($fp) {
		$data = '';
		while ($line = fgets($fp, 515)) { // 512 + CRLF
			$data .= $line;
			if (preg_match('/^[0-9]{3} /', $line)) break; // last line of reply
			if (strlen($line) < 4) break;
		}
		return $data;
	};
	$cmd = function($line) use ($fp, $read) {
		fwrite($fp, $line . "\r\n");
		return $read();
	};

	$banner = $read();
	if (!str_starts_with($banner, '220')) throw new Exception('Bad banner: ' . trim($banner));
	$ehlo = $cmd('EHLO tool-registry');
	if (!str_contains($ehlo, 'STARTTLS')) {
		// If server supports implicit TLS (port 465) we might already be encrypted.
	} else {
		$starttls = $cmd('STARTTLS');
		if (!str_starts_with($starttls, '220')) throw new Exception('STARTTLS failed: ' . trim($starttls));
		if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
			throw new Exception('TLS negotiation failed');
		}
		$ehlo = $cmd('EHLO tool-registry'); // re-issue after STARTTLS
	}

	$auth = $cmd('AUTH LOGIN');
	if (!str_starts_with($auth, '334')) throw new Exception('AUTH not accepted: ' . trim($auth));
	$u = $cmd(base64_encode($user));
	if (!str_starts_with($u, '334')) throw new Exception('Username rejected: ' . trim($u));
	$p = $cmd(base64_encode($pass));
	if (!str_starts_with($p, '235')) throw new Exception('Password rejected: ' . trim($p));

	$from = $user;
	$mail = $cmd('MAIL FROM: <' . $from . '>' );
	if (!str_starts_with($mail, '250')) throw new Exception('MAIL FROM failed: ' . trim($mail));
	$rcpt = $cmd('RCPT TO: <' . $to . '>' );
	if (!str_starts_with($rcpt, '250')) throw new Exception('RCPT TO failed: ' . trim($rcpt));
	$data_reply = $cmd('DATA');
	if (!str_starts_with($data_reply, '354')) throw new Exception('DATA not accepted: ' . trim($data_reply));

	$boundary = 'b_' . bin2hex(random_bytes(8));
	$headers = [];
	$headers[] = 'From: Tool Registry <' . $from . '>';
	$headers[] = 'To: <' . $to . '>';
	$headers[] = 'Subject: ' . $subject;
	$headers[] = 'MIME-Version: 1.0';
	$headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
	foreach ($extra_headers as $hline) {
		if ($hline) $headers[] = $hline;
	}
	$headers_str = implode("\r\n", $headers);

	$body  = "--$boundary\r\n";
	$body .= "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $text . "\r\n";
	$body .= "--$boundary\r\n";
	$body .= "Content-Type: text/html; charset=utf-8\r\n\r\n" . $html . "\r\n";
	$body .= "--$boundary--\r\n";

	// Dot-stuff lines starting with a dot
	$full = $headers_str . "\r\n\r\n" . preg_replace('/(^|\r\n)\./', '$1..', $body) . "\r\n."; // final period terminator
	fwrite($fp, $full . "\r\n");
	$final = $read();
	$cmd('QUIT');
	fclose($fp);
	if (!str_starts_with($final, '250')) throw new Exception('Send failed: ' . trim($final));
}

// ---- Request validation ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	respond(405, ['error' => 'Only POST allowed']);
}

$raw = file_get_contents('php://input');
// limit ~100 KB
if (strlen($raw) > 100000) respond(413, ['error' => 'Payload too large']);
$json = json_decode($raw, true);
if (!$json) respond(400, ['error' => 'Invalid JSON']);

$required = ['name','description','url','contact_email'];
$missing = array_filter($required, fn($k)=>!isset($json[$k]) || trim($json[$k])==='');
if ($missing) respond(422, ['error' => 'Missing fields', 'fields' => array_values($missing)]);

// Normalize fields (accept optional keywords array)
$fields = ['name','description','version','icon','author','author_url','url','contact_email'];
$tool = [];
foreach ($fields as $f) { $tool[$f] = isset($json[$f]) ? trim((string)$json[$f]) : ''; }
// keywords: optional array from UI; if provided as string fallback by splitting commas
if (isset($json['keywords'])) {
	if (is_array($json['keywords'])) {
		$kw = array_values(array_filter(array_map('strval', $json['keywords']), fn($s)=>trim($s) !== ''));
	} else {
		$kw = array_values(array_filter(array_map('trim', explode(',', (string)$json['keywords']))));
	}
	// de-duplicate, lower-case for consistency in search
	$kw = array_values(array_unique(array_map(function($s){ return preg_replace('/\s+/', ' ', strtolower(trim($s))); }, $kw)));
	$tool['keywords'] = $kw;
} else {
	$tool['keywords'] = [];
}
if (!filter_var($tool['contact_email'], FILTER_VALIDATE_EMAIL)) {
	respond(422, ['error' => 'Invalid contact_email']);
}

// ---- Email content --------------------------------------------------------
$pretty_json = json_encode($tool, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$properties_rows = '';
foreach ($tool as $k=>$v) {
	$vv = is_array($v) ? implode(', ', array_map('h', $v)) : nl2br(h($v));
	$properties_rows .= '<tr><td style="padding:4px 8px;font-weight:600;color:#1f2933;font-size:13px;text-transform:uppercase;letter-spacing:.5px">' . h($k) . '</td><td style="padding:4px 8px;font-size:14px;color:#37424d">' . $vv . '</td></tr>';
}

$html = '<!doctype html><html><body style="font-family:Inter,system-ui,sans-serif;background:#f5f7fa;padding:24px;">'
	. '<div style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e8eb;border-radius:16px;padding:32px;box-shadow:0 4px 12px rgba(0,0,0,0.04);">'
	. '<h1 style="margin:0 0 4px 0;font-size:28px;letter-spacing:-1px;color:#0f1419;">' . h($tool['name']) . '</h1>'
	. '<p style="margin:0 0 24px 0;font-size:16px;line-height:1.5;color:#4b5b68;">' . nl2br(h($tool['description'])) . '</p>'
	. '<h2 style="font-size:18px;margin:0 0 8px 0;color:#1f2933;">Properties</h2>'
	. '<table style="border-collapse:collapse;width:100%;background:#fafbfc;border:1px solid #e5e8eb;border-radius:12px;overflow:hidden;font-family:inherit;">'
	. $properties_rows
	. '</table>'
	. '<h2 style="font-size:18px;margin:28px 0 8px;color:#1f2933;">JSON Snippet</h2>'
	. '<pre style="background:#1e2730;color:#e3ecf4;padding:18px 20px;font-size:13px;line-height:1.45;border-radius:12px;overflow:auto;">' . h($pretty_json) . '</pre>'
	. '<p style="margin:28px 0 0;font-size:12px;color:#6c7a86;">Sent ' . date('c') . '</p>'
	. '</div></body></html>';

$text_lines = [
	'New tool submitted: ' . $tool['name'],
	'',
	$tool['description'],
	'',
	'Properties:'
];
foreach ($tool as $k=>$v) $text_lines[] = strtoupper($k) . ': ' . $v;
$text_lines[] = '';
$text_lines[] = 'JSON:';
$text_lines[] = $pretty_json;
$text = implode("\n", $text_lines);

// ---- Send -----------------------------------------------------------------
$env = load_env(__DIR__ . '/.env');
$to = $env['ADMIN_MAIL'] ?? null;
if (!$to) respond(500, ['error' => 'ADMIN_MAIL not configured']);

try {
	// First: send to admin with Reply-To set to contact_email so replies go to submitter
	smtp_send($env, $to, 'New Tool Submission: ' . $tool['name'], $html, $text, [ 'Reply-To: ' . $tool['contact_email'] ]);

	// Second: confirmation email to submitter (plain & simple)
	$confirm_text = "Your tool '" . $tool['name'] . "' has been submitted to the Tool Registry.\n\n" .
		"Summary:\n" .
		"Name: " . $tool['name'] . "\n" .
		"Description: " . $tool['description'] . "\n" .
		"URL: " . $tool['url'] . "\n\n" .
		"We'll review it shortly. If any follow-up is required we'll reach out to this address.\n\n" .
		"Sent " . date('c');
	$confirm_html = '<!doctype html><html><body style="font-family:Inter,system-ui,sans-serif;padding:32px;background:#f5f7fa;">'
		. '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e8eb;border-radius:16px;padding:30px;">'
		. '<h1 style="margin:0 0 12px;font-size:24px;letter-spacing:-.5px;color:#0f1419;">Submission Received</h1>'
		. '<p style="margin:0 0 18px;font-size:15px;line-height:1.5;color:#37424d;">Your tool <strong>' . h($tool['name']) . '</strong> has been submitted to the Tool Registry.</p>'
		. '<ul style="margin:0 0 18px 16px;padding:0;font-size:14px;color:#37424d;line-height:1.5;">'
		. '<li><strong>Name:</strong> ' . h($tool['name']) . '</li>'
		. '<li><strong>Description:</strong> ' . h($tool['description']) . '</li>'
		. '<li><strong>URL:</strong> ' . h($tool['url']) . '</li>'
		. '</ul>'
		. '<p style="margin:0 0 12px;font-size:13px;color:#566370;">We\'ll review it shortly. If any follow-up is required we\'ll reach out to this address.</p>'
		. '<p style="margin:24px 0 0;font-size:11px;color:#7a8894;">Sent ' . date('c') . '</p>'
		. '</div></body></html>';

	// Fire and forget; errors here shouldn't block overall success
	try { smtp_send($env, $tool['contact_email'], 'Tool Submission Received: ' . $tool['name'], $confirm_html, $confirm_text); } catch (Throwable $ignored) {}

	respond(200, ['ok' => true]);
} catch (Throwable $e) {
	// Avoid leaking credentials; return generic message
	respond(500, ['error' => 'Email send failed', 'details' => $e->getMessage()]);
}
?>
