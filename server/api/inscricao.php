<?php
/**
 * FaleConoscoController.php
 * Processa a inscrição na lista de espera da Equovali.
 */

// PHPMailer — carregamento direto (sem Composer), compatível com FTP
$_phpmailerBase = __DIR__ . '/../libs/PHPMailer/src/';
require_once $_phpmailerBase . 'Exception.php';
require_once $_phpmailerBase . 'PHPMailer.php';
require_once $_phpmailerBase . 'SMTP.php';
unset($_phpmailerBase);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// tmp/ fica FORA do public_html (um nível acima de api/), fora do alcance da web
$_sp = __DIR__ . '/../../tmp/sessions';
if (!is_dir($_sp)) @mkdir($_sp, 0750, true);
session_save_path($_sp);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
unset($_sp);

// ── CSRF ────────────────────────────────────────────────────────────────────
$csrfToken = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo "Requisição inválida. Recarregue a página e tente novamente.";
    exit;
}
// Rotaciona o token após uso
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ── Honeypot ─────────────────────────────────────────────────────────────────
if (!empty($_POST['b_phone'])) {
    // Bot detectado — responde 200 para não dar pistas
    echo "Inscrição recebida com sucesso!";
    exit;
}

// ── Rate limiting por IP (arquivo JSON) ─────────────────────────────────────
$ip            = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipHash        = hash('sha256', $ip);
$rateDir       = __DIR__ . '/../../tmp/rate_limit/';
$rateFile      = $rateDir . $ipHash . '.json';
$maxTentativas = 5;
$janelaSeg     = 60;

if (!is_dir($rateDir)) {
    mkdir($rateDir, 0750, true);
    file_put_contents($rateDir . '.htaccess', "Deny from all\n");
}

$registro = ['tentativas' => 0, 'expira' => 0];
if (file_exists($rateFile)) {
    $registro = json_decode(file_get_contents($rateFile), true) ?? $registro;
}
if (time() > $registro['expira']) {
    $registro = ['tentativas' => 0, 'expira' => time() + $janelaSeg];
}
$registro['tentativas']++;
file_put_contents($rateFile, json_encode($registro));

if ($registro['tentativas'] > $maxTentativas) {
    http_response_code(429);
    echo "Muitas tentativas. Aguarde um minuto e tente novamente.";
    exit;
}

// ── Coleta e saneamento dos campos ──────────────────────────────────────────
$nomeCrianca = trim($_POST['nome_crianca'] ?? '');
$nascimento  = trim($_POST['nascimento']   ?? '');
$telefone    = trim($_POST['telefone']     ?? '');
$responsavel = trim($_POST['responsavel']  ?? '');
$diagnostico = trim($_POST['diagnostico']  ?? '');
$cid         = trim($_POST['cid']          ?? '');

// ── Validação dos campos obrigatórios ────────────────────────────────────────
$erros = [];

if (empty($nomeCrianca))                       $erros[] = "Nome da criança é obrigatório.";
if (strlen($nomeCrianca) > 100)                $erros[] = "Nome da criança muito longo.";

if (empty($nascimento))                        $erros[] = "Data de nascimento é obrigatória.";
if (!empty($nascimento)) {
    $dt = DateTime::createFromFormat('Y-m-d', $nascimento);
    if (!$dt || $dt->format('Y-m-d') !== $nascimento || $dt > new DateTime()) {
        $erros[] = "Data de nascimento inválida.";
    }
}

if (empty($telefone))                          $erros[] = "Telefone é obrigatório.";
if (strlen($telefone) > 20)                    $erros[] = "Telefone inválido.";

if (empty($responsavel))                       $erros[] = "Nome do responsável é obrigatório.";
if (strlen($responsavel) > 100)                $erros[] = "Nome do responsável muito longo.";

if (empty($diagnostico))                       $erros[] = "Diagnóstico é obrigatório.";
if (strlen($diagnostico) > 2000)               $erros[] = "Diagnóstico excede o limite de caracteres.";

if (strlen($cid) > 20)                         $erros[] = "CID inválido.";

// Proteção contra injeção de cabeçalho nos campos de texto curto
foreach ([$nomeCrianca, $telefone, $responsavel, $cid] as $campo) {
    if (preg_match("/[\r\n]/", $campo)) {
        $erros[] = "Dados inválidos detectados.";
        break;
    }
}

if (!empty($erros)) {
    http_response_code(400);
    echo implode(' ', $erros);
    exit;
}

// ── Validação e processamento dos arquivos ───────────────────────────────────
$allowedMimes = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];
$maxFileSize = 5 * 1024 * 1024; // 5 MB

$uploadDir = __DIR__ . '/../../tmp/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0750, true);
    file_put_contents($uploadDir . '.htaccess', "Deny from all\n");
}

$arquivosInput = $_FILES['arquivos'] ?? null;
if (empty($arquivosInput['name'][0])) {
    http_response_code(400);
    echo "Envie pelo menos um laudo ou documento.";
    exit;
}

$totalArquivos = count(array_filter($arquivosInput['name'], fn($n) => $n !== ''));
if ($totalArquivos > 3) {
    http_response_code(400);
    echo "Máximo de 3 arquivos permitido.";
    exit;
}

$savedFiles = [];
for ($i = 0; $i < $totalArquivos; $i++) {
    if ($arquivosInput['error'][$i] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo "Erro ao receber o arquivo " . ($i + 1) . ". Tente novamente.";
        exit;
    }

    if ($arquivosInput['size'][$i] > $maxFileSize) {
        http_response_code(400);
        echo "O arquivo \"" . htmlspecialchars($arquivosInput['name'][$i]) . "\" excede 5MB.";
        exit;
    }

    // Validação de MIME pelo conteúdo real (não pela extensão declarada)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($arquivosInput['tmp_name'][$i]);

    if (!array_key_exists($mime, $allowedMimes)) {
        http_response_code(400);
        echo "Tipo de arquivo não permitido. Use apenas PDF, JPG ou PNG.";
        exit;
    }

    // Nome seguro: hash aleatório + extensão real detectada
    $ext      = $allowedMimes[$mime];
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($arquivosInput['tmp_name'][$i], $destPath)) {
        http_response_code(500);
        echo "Falha ao salvar arquivo. Tente novamente.";
        exit;
    }

    $savedFiles[] = [
        'path'         => $destPath,
        'originalName' => basename($arquivosInput['name'][$i]),
    ];
}

// ── Credenciais via .env (fora do public_html, um nível acima) ───────────────
$envFile = __DIR__ . '/../../.env';
if (!file_exists($envFile)) {
    error_log("Arquivo .env não encontrado.");
    http_response_code(500);
    echo "Erro de configuração do servidor. Tente pelo WhatsApp.";
    exit;
}
$env = parse_ini_file($envFile);

define('MAIL_HOST',       $env['MAIL_HOST']       ?? 'smtp.gmail.com');
define('MAIL_PORT',       (int)($env['MAIL_PORT'] ?? 587));
define('MAIL_USERNAME',   $env['MAIL_USERNAME']   ?? '');
define('MAIL_PASSWORD',   $env['MAIL_PASSWORD']   ?? '');
define('MAIL_FROM_EMAIL', $env['MAIL_FROM_EMAIL'] ?? '');
define('MAIL_FROM_NAME',  $env['MAIL_FROM_NAME']  ?? 'Equovali');
define('MAIL_TO_EMAIL',   $env['MAIL_TO_EMAIL']   ?? '');
define('MAIL_BCC_EMAIL',  $env['MAIL_BCC_EMAIL']  ?? '');

$nasciFormatado  = DateTime::createFromFormat('Y-m-d', $nascimento)->format('d/m/Y');
$dataEnvio       = date('d/m/Y \à\s H:i:s');
$cidTexto        = !empty($cid) ? htmlspecialchars($cid) : '<em>Não informado</em>';
$htmlNome        = htmlspecialchars($nomeCrianca);
$htmlTelefone    = htmlspecialchars($telefone);
$htmlResponsavel = htmlspecialchars($responsavel);
$htmlDiagnostico = nl2br(htmlspecialchars($diagnostico));

$htmlBody = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#333;">
  <div style="background:#0e801d;padding:28px 32px;border-radius:10px 10px 0 0;">
    <h2 style="color:#fff;margin:0;font-size:1.25rem;letter-spacing:-0.3px;">
      Nova inscrição — Lista de Espera Equovali
    </h2>
  </div>
  <div style="background:#f9f9f9;padding:28px 32px;border:1px solid #e0e0e0;border-top:0;border-radius:0 0 10px 10px;">
    <table style="width:100%;border-collapse:collapse;font-size:0.93rem;">
      <tr style="border-bottom:1px solid #eee;">
        <td style="padding:10px 0;font-weight:700;width:175px;color:#555;">Nome da criança</td>
        <td style="padding:10px 0;">{$htmlNome}</td>
      </tr>
      <tr style="border-bottom:1px solid #eee;">
        <td style="padding:10px 0;font-weight:700;color:#555;">Data de nascimento</td>
        <td style="padding:10px 0;">{$nasciFormatado}</td>
      </tr>
      <tr style="border-bottom:1px solid #eee;">
        <td style="padding:10px 0;font-weight:700;color:#555;">Telefone (WhatsApp)</td>
        <td style="padding:10px 0;">{$htmlTelefone}</td>
      </tr>
      <tr style="border-bottom:1px solid #eee;">
        <td style="padding:10px 0;font-weight:700;color:#555;">Responsável</td>
        <td style="padding:10px 0;">{$htmlResponsavel}</td>
      </tr>
      <tr style="border-bottom:1px solid #eee;">
        <td style="padding:10px 0;font-weight:700;color:#555;">CID</td>
        <td style="padding:10px 0;">{$cidTexto}</td>
      </tr>
      <tr>
        <td style="padding:10px 0;font-weight:700;color:#555;">Data de envio</td>
        <td style="padding:10px 0;">{$dataEnvio}</td>
      </tr>
    </table>
    <div style="margin-top:20px;padding-top:20px;border-top:1px solid #ddd;">
      <p style="font-weight:700;margin:0 0 10px;color:#555;">Diagnóstico</p>
      <p style="line-height:1.7;margin:0;">{$htmlDiagnostico}</p>
    </div>
    <p style="margin-top:28px;font-size:0.78rem;color:#aaa;">Enviado automaticamente via site Equovali</p>
  </div>
</div>
HTML;

$textBody = "Nova inscrição — Lista de Espera Equovali\n"
    . str_repeat("-", 48) . "\n"
    . "Nome da criança : $nomeCrianca\n"
    . "Nascimento      : $nasciFormatado\n"
    . "Telefone        : $telefone\n"
    . "Responsável     : $responsavel\n"
    . "CID             : " . ($cid ?: 'Não informado') . "\n"
    . "Data de envio   : $dataEnvio\n"
    . str_repeat("-", 48) . "\n"
    . "Diagnóstico:\n$diagnostico";

$mail = new PHPMailer(true);

try {
    // Conexão SMTP com STARTTLS (porta 587)
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addAddress(MAIL_TO_EMAIL, 'Equovali');
    if (!empty(MAIL_BCC_EMAIL)) {
        $mail->addBCC(MAIL_BCC_EMAIL, 'Cópia');
    }

    $mail->isHTML(true);
    $mail->Subject = "Nova inscrição: $nomeCrianca";
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;

    foreach ($savedFiles as $arquivo) {
        $mail->addAttachment($arquivo['path'], $arquivo['originalName']);
    }

    $mail->send();

    foreach ($savedFiles as $arquivo) {
        @unlink($arquivo['path']);
    }

    echo "Inscrição realizada com sucesso! Entraremos em contato pelo WhatsApp em breve.";

} catch (Exception $e) {
    foreach ($savedFiles as $arquivo) {
        @unlink($arquivo['path']);
    }
    error_log("PHPMailer erro: {$mail->ErrorInfo}");
    http_response_code(500);
    echo "Não foi possível enviar a inscrição agora. Tente pelo WhatsApp ou e-mail diretamente.";
}
