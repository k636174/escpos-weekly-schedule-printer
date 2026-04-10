<?php

declare(strict_types=1);

session_start();

const DEFAULT_IP = '172.16.20.31';
const DEFAULT_PORT = 9100;
const DEFAULT_TIMEOUT = 5;
const IP_SETTINGS_FILE = 'ip-addresses.json';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function nowString(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function addLog(string $message): void
{
    if (!isset($_SESSION['logs']) || !is_array($_SESSION['logs'])) {
        $_SESSION['logs'] = [];
    }
    $_SESSION['logs'][] = '[' . nowString() . '] ' . $message;
    if (count($_SESSION['logs']) > 500) {
        $_SESSION['logs'] = array_slice($_SESSION['logs'], -500);
    }
}

function clearLogs(): void
{
    $_SESSION['logs'] = [];
}

function getLogsText(): string
{
    $logs = $_SESSION['logs'] ?? [];
    if (!is_array($logs)) {
        return '';
    }
    return implode("\n", $logs);
}

function normalizeNewlines(string $text): string
{
    return str_replace(["\r\n", "\r"], "\n", $text);
}

function normalizePrintableText(string $text): string
{
    $normalized = normalizeNewlines($text);
    if ($normalized === '' || !str_ends_with($normalized, "\n")) {
        $normalized .= "\n";
    }
    return $normalized;
}

function printableLineCount(string $text): int
{
    return count(explode("\n", normalizePrintableText($text)));
}

function fitTextToLineCount(string $text, int $targetLineCount): string
{
    $target = max(1, $targetLineCount);
    $lines = explode("\n", normalizePrintableText($text));

    if (count($lines) > $target) {
        $lines = array_slice($lines, 0, $target);
    } else {
        while (count($lines) < $target) {
            $lines[] = '';
        }
    }

    return implode("\n", $lines);
}

function countPrintableLines(string $text): int
{
    return count(explode("\n", normalizePrintableText($text)));
}

function buildOverflowState(string $text, int $printableLimit): array
{
    $limit = max(1, $printableLimit);
    $totalLines = countPrintableLines($text);
    $overflowCount = max(0, $totalLines - $limit);

    if ($overflowCount > 0) {
        return [
            'overflow_count' => $overflowCount,
            'overflow_start' => $limit + 1,
            'overflow_end' => $totalLines,
            'overflow_message' => sprintf(
                '印刷範囲外の行があります: %d〜%d行 (%d行)',
                $limit + 1,
                $totalLines,
                $overflowCount
            ),
        ];
    }

    return [
        'overflow_count' => 0,
        'overflow_start' => 0,
        'overflow_end' => 0,
        'overflow_message' => '印刷範囲内です。',
    ];
}

function mondayOfWeek(DateTimeImmutable $date): DateTimeImmutable
{
    $dayOfWeek = (int)$date->format('w'); // 0=Sun..6=Sat
    $offset = ($dayOfWeek + 6) % 7;
    return $date->setTime(0, 0)->modify(sprintf('-%d day', $offset));
}

function generateWeekText(DateTimeImmutable $startDate): string
{
    $start = mondayOfWeek($startDate);
    $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    $lines = [''];
    for ($i = 0; $i < 7; $i++) {
        $day = $start->modify(sprintf('+%d day', $i));
        $label = $day->format('Y-m-d') . '(' . $names[$i] . ')';

        if ($names[$i] === 'Sat' || $names[$i] === 'Sun') {
            $lines[] = sprintf('     !! %s !!', $label);
        } else {
            $lines[] = sprintf('    %s', $label);
        }

        $lines[] = '';
        $lines[] = '';
        $lines[] = '';

        if ($i !== 6) {
            $lines[] = '----------------------------------------------';
        }
    }

    return implode("\n", $lines) . "\n";
}

function getSavedFilePath(DateTimeImmutable $weekStart): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'm5-weekly-refill-' . $weekStart->format('Y-m-d') . '.bak.txt';
}

function getIpSettingsPath(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . IP_SETTINGS_FILE;
}

function isValidIpAddress(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function normalizeIpList(array $ips): array
{
    $normalized = [];
    foreach ($ips as $ip) {
        if (!is_string($ip)) {
            continue;
        }

        $trimmed = trim($ip);
        if ($trimmed === '' || !isValidIpAddress($trimmed)) {
            continue;
        }
        $normalized[] = $trimmed;
    }

    return array_values(array_unique($normalized));
}

function loadIpSettings(): array
{
    $path = getIpSettingsPath();
    if (!is_file($path)) {
        return ['ips' => [], 'selected' => ''];
    }

    $json = @file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return ['ips' => [], 'selected' => ''];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return ['ips' => [], 'selected' => ''];
    }

    $ips = normalizeIpList((array)($decoded['ips'] ?? []));
    $selected = trim((string)($decoded['selected'] ?? ''));
    if ($selected !== '' && !in_array($selected, $ips, true)) {
        $selected = '';
    }

    return ['ips' => $ips, 'selected' => $selected];
}

function saveIpSettings(array $ips, string $selected): void
{
    $normalizedIps = normalizeIpList($ips);
    $normalizedSelected = trim($selected);
    if ($normalizedSelected !== '' && !in_array($normalizedSelected, $normalizedIps, true)) {
        $normalizedSelected = '';
    }

    $payload = json_encode(
        ['ips' => $normalizedIps, 'selected' => $normalizedSelected],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    if ($payload === false) {
        throw new RuntimeException('Failed to encode IP settings.');
    }

    if (@file_put_contents(getIpSettingsPath(), $payload) === false) {
        throw new RuntimeException('Failed to save IP settings.');
    }
}

function loadSavedContent(DateTimeImmutable $weekStart): ?string
{
    $path = getSavedFilePath($weekStart);
    if (!is_file($path)) {
        return null;
    }

    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return null;
    }

    return normalizePrintableText($content);
}

function saveWeekContent(DateTimeImmutable $weekStart, string $text): string
{
    $savePath = getSavedFilePath($weekStart);
    $normalized = normalizePrintableText($text);

    if (@file_put_contents($savePath, $normalized) === false) {
        throw new RuntimeException('Failed to save content.');
    }

    return $savePath;
}

function encodeSjis(string $text): string
{
    $encoded = mb_convert_encoding($text, 'SJIS-win', 'UTF-8');
    if ($encoded === false) {
        throw new RuntimeException('Failed to convert text to Shift_JIS.');
    }
    return $encoded;
}

function buildEscPosPayload(string $text, bool $doubleSize, bool $cut): string
{
    $normalized = normalizePrintableText($text);

    $init = "\x1B\x40";
    $jpEnable = "\x1C\x43\x01";
    $fontSmall = "\x1B\x4D\x01";
    $lineSpacing = "\x1B\x33\x28";
    $dbl = "\x1D\x21\x11";
    $revOn = "\x1D\x42\x01";
    $revOff = "\x1D\x42\x00";
    $cutCmd = "\x1D\x56\x41\x03";

    $payload = $init . $jpEnable . $fontSmall . $lineSpacing;
    if ($doubleSize) {
        $payload .= $dbl;
    }

    $parts = preg_split('/!!(.+?)!!/s', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        throw new RuntimeException('Failed to parse marker sections.');
    }

    foreach ($parts as $idx => $part) {
        if (($idx % 2) === 1) {
            $payload .= $revOn . encodeSjis($part) . $revOff;
        } else {
            $payload .= encodeSjis($part);
        }
    }

    if ($cut) {
        $payload .= $cutCmd;
    }

    return $payload;
}

function sendToPrinter(string $bytes, string $ip, int $port, int $timeoutSec): void
{
    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        sprintf('tcp://%s:%d', $ip, $port),
        $errno,
        $errstr,
        $timeoutSec,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(sprintf('接続失敗: %s (%d)', $errstr ?: 'unknown error', $errno));
    }

    try {
        stream_set_timeout($socket, $timeoutSec);

        $remaining = strlen($bytes);
        $offset = 0;
        while ($remaining > 0) {
            $written = fwrite($socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException(sprintf('送信タイムアウト (%ds)', $timeoutSec));
                }
                throw new RuntimeException('送信失敗: ソケット書き込みエラー');
            }
            $offset += $written;
            $remaining -= $written;
        }
        fflush($socket);
    } finally {
        fclose($socket);
    }
}

function applyTemplate(string $fixedText, bool $showMarkers): string
{
    $normalized = normalizePrintableText($fixedText);
    if ($showMarkers) {
        return $normalized;
    }
    return preg_replace('/!!(.+?)!!/s', '$1', $normalized) ?? $normalized;
}

function parseDateOrNull(string $value): ?DateTimeImmutable
{
    $trim = trim($value);
    if ($trim === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $trim);
    if ($dt === false || $dt->format('Y-m-d') !== $trim) {
        return null;
    }
    return $dt;
}

function loadOrGenerateWeekText(DateTimeImmutable $baseDate): array
{
    $weekStart = mondayOfWeek($baseDate);
    $saved = loadSavedContent($weekStart);
    if ($saved !== null) {
        return [$weekStart, $saved, 'Loaded saved backup.'];
    }
    return [$weekStart, generateWeekText($weekStart), 'Generated week template.'];
}

if (!extension_loaded('mbstring')) {
    http_response_code(500);
    echo 'mbstring extension is required.';
    exit;
}

$todayMonday = mondayOfWeek(new DateTimeImmutable('today'));

$state = [
    'ip' => DEFAULT_IP,
    'port' => (string)DEFAULT_PORT,
    'timeout' => (string)DEFAULT_TIMEOUT,
    'double' => false,
    'cut' => true,
    'editable' => true,
    'show_markers' => true,
    'start_date' => $todayMonday->format('Y-m-d'),
    'fixed_text' => '',
    'preview_text' => '',
    'template_line_count' => 0,
    'saved_ips' => [],
    'selected_ip' => '',
    'new_ip' => '',
    'show_ip_settings' => false,
    'overflow_count' => 0,
    'overflow_start' => 0,
    'overflow_end' => 0,
    'overflow_message' => '',
];

$ipSettings = loadIpSettings();
$state['saved_ips'] = $ipSettings['ips'];
$state['selected_ip'] = $ipSettings['selected'];
if ($state['selected_ip'] !== '') {
    $state['ip'] = $state['selected_ip'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    [$weekStart, $fixedText, $source] = loadOrGenerateWeekText($todayMonday);
    $state['start_date'] = $weekStart->format('Y-m-d');
    $state['fixed_text'] = normalizePrintableText($fixedText);
    $state['template_line_count'] = printableLineCount($state['fixed_text']);
    $state['preview_text'] = applyTemplate($state['fixed_text'], $state['show_markers']);
    $state = array_merge($state, buildOverflowState($state['preview_text'], $state['template_line_count']));
    addLog('Initialized.');
    addLog('Text source: ' . $source);
    addLog('Preview updated.');
} else {
    $state['ip'] = trim((string)($_POST['ip'] ?? DEFAULT_IP));
    $state['port'] = trim((string)($_POST['port'] ?? (string)DEFAULT_PORT));
    $state['timeout'] = trim((string)($_POST['timeout'] ?? (string)DEFAULT_TIMEOUT));
    $state['double'] = isset($_POST['double']);
    $state['cut'] = isset($_POST['cut']);
    $state['editable'] = isset($_POST['editable']);
    $state['show_markers'] = isset($_POST['show_markers']);
    $state['start_date'] = trim((string)($_POST['start_date'] ?? $todayMonday->format('Y-m-d')));
    $state['fixed_text'] = normalizePrintableText((string)($_POST['fixed_text'] ?? ''));
    $state['preview_text'] = normalizePrintableText((string)($_POST['preview_text'] ?? ''));
    $state['selected_ip'] = trim((string)($_POST['selected_ip'] ?? $state['selected_ip']));
    $state['new_ip'] = trim((string)($_POST['new_ip'] ?? ''));

    $action = (string)($_POST['action'] ?? '');
    $state['show_ip_settings'] = isset($_POST['show_ip_settings']) ||
        in_array($action, ['open_ip_settings', 'add_ip', 'remove_ip', 'apply_selected_ip'], true);

    try {
        $selected = parseDateOrNull($state['start_date']);
        if ($selected === null) {
            throw new InvalidArgumentException('Start date must be in YYYY-MM-DD format.');
        }

        if ($state['fixed_text'] === '') {
            [$ws, $fixed, $source] = loadOrGenerateWeekText($selected);
            $state['start_date'] = $ws->format('Y-m-d');
            $state['fixed_text'] = normalizePrintableText($fixed);
            addLog('Text source: ' . $source);
        }
        $state['template_line_count'] = printableLineCount($state['fixed_text']);
        $state = array_merge($state, buildOverflowState($state['preview_text'], $state['template_line_count']));

        if ($action === 'open_ip_settings') {
            $state['show_ip_settings'] = true;
            addLog('IP settings opened.');
        } elseif ($action === 'close_ip_settings') {
            $state['show_ip_settings'] = false;
            addLog('IP settings closed.');
        } elseif ($action === 'add_ip') {
            if ($state['new_ip'] === '') {
                throw new InvalidArgumentException('IP to add is empty.');
            }
            if (!isValidIpAddress($state['new_ip'])) {
                throw new InvalidArgumentException('Invalid IP format.');
            }

            $ips = $state['saved_ips'];
            if (!in_array($state['new_ip'], $ips, true)) {
                $ips[] = $state['new_ip'];
            }
            $state['saved_ips'] = normalizeIpList($ips);
            $state['selected_ip'] = $state['new_ip'];
            $state['ip'] = $state['new_ip'];
            saveIpSettings($state['saved_ips'], $state['selected_ip']);
            addLog('IP added: ' . $state['new_ip']);
            $state['new_ip'] = '';
            $state['show_ip_settings'] = true;
        } elseif ($action === 'remove_ip') {
            $target = $state['selected_ip'];
            if ($target === '') {
                throw new InvalidArgumentException('No selected IP to remove.');
            }

            $state['saved_ips'] = array_values(array_filter(
                $state['saved_ips'],
                static fn(string $ip): bool => $ip !== $target
            ));
            $state['selected_ip'] = $state['saved_ips'][0] ?? '';
            saveIpSettings($state['saved_ips'], $state['selected_ip']);

            if ($state['ip'] === $target) {
                $state['ip'] = $state['selected_ip'] !== '' ? $state['selected_ip'] : DEFAULT_IP;
            }
            addLog('IP removed: ' . $target);
            $state['show_ip_settings'] = true;
        } elseif ($action === 'apply_selected_ip') {
            if ($state['selected_ip'] === '') {
                throw new InvalidArgumentException('No selected IP to apply.');
            }
            if (!in_array($state['selected_ip'], $state['saved_ips'], true)) {
                throw new InvalidArgumentException('Selected IP is not in saved list.');
            }

            $state['ip'] = $state['selected_ip'];
            saveIpSettings($state['saved_ips'], $state['selected_ip']);
            addLog('IP applied: ' . $state['selected_ip']);
            $state['show_ip_settings'] = true;
        } elseif ($action === 'prev_week' || $action === 'next_week' || $action === 'apply_week') {
            $shifted = $selected;
            if ($action === 'prev_week') {
                $shifted = $selected->modify('-7 day');
            } elseif ($action === 'next_week') {
                $shifted = $selected->modify('+7 day');
            }

            [$weekStart, $fixedText, $source] = loadOrGenerateWeekText($shifted);
            $state['start_date'] = $weekStart->format('Y-m-d');
            $state['fixed_text'] = normalizePrintableText($fixedText);
            $state['template_line_count'] = printableLineCount($state['fixed_text']);
            $state['preview_text'] = applyTemplate($state['fixed_text'], $state['show_markers']);
            $state = array_merge($state, buildOverflowState($state['preview_text'], $state['template_line_count']));
            addLog('Text source: ' . $source);

            if ($action === 'prev_week') {
                addLog('Moved to previous week: ' . $state['start_date']);
            } elseif ($action === 'next_week') {
                addLog('Moved to next week: ' . $state['start_date']);
            } else {
                addLog('Applied week: ' . $state['start_date']);
            }
            addLog('Preview updated.');
        } elseif ($action === 'reload') {
            $state['preview_text'] = applyTemplate($state['fixed_text'], $state['show_markers']);
            $state = array_merge($state, buildOverflowState($state['preview_text'], $state['template_line_count']));
            addLog('Preview updated.');
        } elseif ($action === 'toggle_markers') {
            $state['preview_text'] = applyTemplate($state['fixed_text'], $state['show_markers']);
            $state = array_merge($state, buildOverflowState($state['preview_text'], $state['template_line_count']));
            addLog('Marker display: ' . ($state['show_markers'] ? 'ON' : 'OFF'));
            addLog('Preview updated.');
        } elseif ($action === 'toggle_editable') {
            addLog('Preview editable: ' . ($state['editable'] ? 'ON' : 'OFF'));
        } elseif ($action === 'clear_log') {
            clearLogs();
        } elseif ($action === 'save') {
            $saveText = normalizePrintableText($state['preview_text']);
            if (trim($saveText) === '') {
                throw new InvalidArgumentException('Nothing to save.');
            }
            $weekStart = mondayOfWeek($selected);
            $savePath = saveWeekContent($weekStart, $saveText);
            addLog('Saved content: ' . basename($savePath));
        } elseif ($action === 'print') {
            if ($state['ip'] === '') {
                throw new InvalidArgumentException('IP is required.');
            }

            $port = (int)$state['port'];
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Port must be between 1 and 65535.');
            }

            $timeout = (int)$state['timeout'];
            if ($timeout < 1 || $timeout > 60) {
                throw new InvalidArgumentException('Timeout must be between 1 and 60 seconds.');
            }

            $printText = normalizePrintableText($state['preview_text']);
            if (trim($printText) === '') {
                throw new InvalidArgumentException('Nothing to print.');
            }

            addLog(sprintf(
                '送信開始: %s:%d (Timeout=%ds, DoubleSize=%s, Cut=%s)',
                $state['ip'],
                $port,
                $timeout,
                $state['double'] ? 'true' : 'false',
                $state['cut'] ? 'true' : 'false'
            ));

            $payload = buildEscPosPayload($printText, $state['double'], $state['cut']);
            sendToPrinter($payload, $state['ip'], $port, $timeout);

            $weekStart = mondayOfWeek($selected);
            $savePath = saveWeekContent($weekStart, $printText);
            addLog('Saved content: ' . basename($savePath));

            addLog(sprintf('印刷完了: %s:%d', $state['ip'], $port));
        }
    } catch (Throwable $e) {
        addLog('Print failed: ' . $e->getMessage());
    }

    $state = array_merge($state, buildOverflowState($state['preview_text'], $state['template_line_count']));
}

$logsText = getLogsText();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Week Schedule ESC/POS Printer</title>
<style>
:root {
  --bg: #f7f7f3;
  --panel: #fffef9;
  --ink: #222222;
  --sub: #5f5f5f;
  --line: #d8d5ca;
  --accent: #0e6f52;
  --danger: #b42318;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  background: radial-gradient(circle at 15% 0%, #fff, var(--bg));
  color: var(--ink);
  font-family: "BIZ UDPGothic", "Yu Gothic UI", sans-serif;
}
.container {
  max-width: 1320px;
  margin: 20px auto;
  padding: 0 14px;
}
.panel {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 10px;
  box-shadow: 0 6px 20px rgba(0,0,0,.05);
  padding: 14px;
}
.layout {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(320px, 1fr);
  gap: 14px;
  align-items: start;
}
.left-col, .right-col {
  min-width: 0;
}
.controls {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.field { display: flex; flex-direction: column; gap: 4px; }
label { font-size: 13px; color: var(--sub); }
input[type="text"], input[type="number"], input[type="date"], textarea {
  width: 100%;
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 8px;
  font: inherit;
  background: #fff;
}
textarea {
  min-height: 330px;
  white-space: pre;
  font-family: Consolas, "BIZ UD Gothic", monospace;
  line-height: 1.28;
}
.preview-area {
  min-height: 640px;
  overflow: hidden;
  resize: none;
  font-size: 13px;
  line-height: 1.2;
}
.preview-area.out-of-range {
  border-color: var(--danger);
  box-shadow: 0 0 0 1px rgba(180, 35, 24, 0.2);
}
.log-area {
  min-height: 230px;
}
.checks {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 10px;
}
button {
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #ffffff;
  color: var(--ink);
  padding: 10px 12px;
  font: inherit;
  cursor: pointer;
}
button.primary {
  background: var(--accent);
  color: #fff;
  border-color: var(--accent);
}
button.danger {
  background: #fff5f4;
  color: var(--danger);
  border-color: #f0c4c0;
}
.buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 8px 0 12px;
}
.section-title {
  margin: 6px 0;
  font-weight: 700;
}
.notice {
  font-size: 13px;
  color: var(--sub);
  margin-bottom: 8px;
}
.notice.overflow {
  color: var(--danger);
  font-weight: 700;
}
.preview-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
}
.preview-head .notice {
  margin-bottom: 0;
}
@media (max-width: 720px) {
  .layout {
    grid-template-columns: 1fr;
  }
  .preview-area {
    min-height: 360px;
  }
  textarea { min-height: 260px; }
}
</style>
</head>
<body>
<div class="container">
  <form method="post" class="panel">
    <div class="layout">
      <section class="left-col">
        <div class="preview-head">
          <div class="section-title">印刷プレビュー</div>
          <div class="notice">印刷時はこのプレビュー内容が送信されます。</div>
        </div>
        <textarea id="preview_text" class="preview-area<?= $state['overflow_count'] > 0 ? ' out-of-range' : '' ?>" name="preview_text" data-line-count="<?= h((string)$state['template_line_count']) ?>" <?= $state['editable'] ? '' : 'readonly' ?>><?= h($state['preview_text']) ?></textarea>
        <div id="overflow_notice" class="notice<?= $state['overflow_count'] > 0 ? ' overflow' : '' ?>"><?= h($state['overflow_message']) ?></div>
      </section>

      <section class="right-col">
        <div class="controls">
          <div class="field">
            <label for="ip">IP</label>
            <input id="ip" name="ip" type="text" value="<?= h($state['ip']) ?>" required>
          </div>
          <div class="field">
            <label for="port">Port</label>
            <input id="port" name="port" type="number" min="1" max="65535" value="<?= h($state['port']) ?>" required>
          </div>
          <div class="field">
            <label for="timeout">Timeout(s)</label>
            <input id="timeout" name="timeout" type="number" min="1" max="60" value="<?= h($state['timeout']) ?>" required>
          </div>
          <div class="field">
            <label for="start_date">開始日</label>
            <input id="start_date" name="start_date" type="date" value="<?= h($state['start_date']) ?>" required>
          </div>
        </div>

        <div class="checks">
          <label><input type="checkbox" name="double" <?= $state['double'] ? 'checked' : '' ?>> 2倍サイズ</label>
          <label><input type="checkbox" name="cut" <?= $state['cut'] ? 'checked' : '' ?>> カット</label>
          <label><input id="editable" type="checkbox" name="editable" <?= $state['editable'] ? 'checked' : '' ?> onchange="submitAction('toggle_editable')"> プレビュー編集モード</label>
          <label><input id="show_markers" type="checkbox" name="show_markers" <?= $state['show_markers'] ? 'checked' : '' ?> onchange="submitAction('toggle_markers')"> マーカー表示</label>
        </div>

        <div class="buttons">
          <button type="button" onclick="submitAction('open_ip_settings')">IP設定</button>
          <button type="button" onclick="submitAction('prev_week')">&lt;</button>
          <button type="button" onclick="submitAction('next_week')">&gt;</button>
          <button type="button" onclick="submitAction('apply_week')">週を適用</button>
          <button type="button" onclick="submitAction('reload')">テンプレ再読込</button>
          <button type="button" onclick="submitAction('save')">保存</button>
          <button type="button" class="primary" onclick="submitAction('print')">印刷</button>
          <button type="button" class="danger" onclick="submitAction('clear_log')">ログクリア</button>
        </div>

        <div class="section-title" style="margin-top:12px;">ログ</div>
        <?php if ($state['show_ip_settings']): ?>
        <div class="panel" style="margin: 0 0 12px; padding: 10px;">
          <div class="section-title">IP設定</div>
          <div class="field" style="margin-bottom:8px;">
            <label for="selected_ip">登録済みIP</label>
            <select id="selected_ip" name="selected_ip" style="width:100%; border:1px solid var(--line); border-radius:8px; padding:8px; background:#fff;">
              <option value="">-- choose --</option>
              <?php foreach ($state['saved_ips'] as $savedIp): ?>
              <option value="<?= h($savedIp) ?>" <?= $state['selected_ip'] === $savedIp ? 'selected' : '' ?>><?= h($savedIp) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="margin-bottom:8px;">
            <label for="new_ip">新しいIP</label>
            <input id="new_ip" name="new_ip" type="text" value="<?= h($state['new_ip']) ?>" placeholder="192.168.0.10">
          </div>
          <div class="buttons" style="margin-bottom:0;">
            <button type="button" onclick="submitAction('apply_selected_ip')">選択IPを反映</button>
            <button type="button" onclick="submitAction('add_ip')">IPを追加</button>
            <button type="button" class="danger" onclick="submitAction('remove_ip')">選択IPを削除</button>
            <button type="button" onclick="submitAction('close_ip_settings')">閉じる</button>
          </div>
        </div>
        <?php endif; ?>

        <textarea class="log-area" readonly><?= h($logsText) ?></textarea>
      </section>
    </div>

    <input type="hidden" id="fixed_text" name="fixed_text" value="<?= h($state['fixed_text']) ?>">
    <input type="hidden" name="show_ip_settings" value="<?= $state['show_ip_settings'] ? '1' : '' ?>">
    <input type="hidden" id="action" name="action" value="">
  </form>
</div>
<script>
function submitAction(action) {
  document.getElementById('action').value = action;
  document.forms[0].submit();
}

function normalizeNewlines(text) {
  return text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

function countLinesForPreview(text) {
  let normalized = normalizeNewlines(text);
  if (!normalized.endsWith('\n')) normalized += '\n';
  return normalized.split('\n').length;
}

function updateOverflowIndicator() {
  const preview = document.getElementById('preview_text');
  if (!preview) return;
  const notice = document.getElementById('overflow_notice');
  if (!notice) return;

  const target = parseInt(preview.dataset.lineCount || '0', 10);
  if (!Number.isFinite(target) || target < 1) return;

  const total = countLinesForPreview(preview.value);
  const overflow = Math.max(0, total - target);

  if (overflow > 0) {
    const start = target + 1;
    notice.textContent = `印刷範囲外の行があります: ${start}〜${total}行 (${overflow}行)`;
    notice.classList.add('overflow');
    preview.classList.add('out-of-range');
  } else {
    notice.textContent = '印刷範囲内です。';
    notice.classList.remove('overflow');
    preview.classList.remove('out-of-range');
  }
}

function autoResizePreview() {
  const preview = document.getElementById('preview_text');
  if (!preview) return;
  preview.style.height = 'auto';
  preview.style.height = preview.scrollHeight + 'px';
}

window.addEventListener('load', () => {
  updateOverflowIndicator();
  autoResizePreview();
});
window.addEventListener('resize', autoResizePreview);
document.getElementById('preview_text')?.addEventListener('input', () => {
  updateOverflowIndicator();
  autoResizePreview();
});
</script>
</body>
</html>
