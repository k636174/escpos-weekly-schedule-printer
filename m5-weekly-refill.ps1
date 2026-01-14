#requires -version 5.1
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# ------------------------------------------------------------
# DPI: Per-Monitor v2 を試し、ダメなら従来方式（再実行でも落ちないよう型定義ガード）
# ------------------------------------------------------------
if (-not ("DpiUtil" -as [type])) {
    Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class DpiUtil {
  [DllImport("user32.dll")] public static extern bool SetProcessDPIAware();
  [DllImport("user32.dll")] public static extern IntPtr SetProcessDpiAwarenessContext(IntPtr dpiFlag);
}
"@
}

try {
    # DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2 = -4
    [void][DpiUtil]::SetProcessDpiAwarenessContext([IntPtr](-4))
} catch {
    try { [DpiUtil]::SetProcessDPIAware() | Out-Null } catch {}
}

# ------------------------------------------------------------
# Utility: Encoding / ESC-POS
# ------------------------------------------------------------
function Encode-SjisWin([string]$text) {
    # 改行を LF に寄せる
    $normalized = $text -replace "`r`n", "`n" -replace "`r", "`n"
    if (-not $normalized.EndsWith("`n")) { $normalized += "`n" }

    $enc = [System.Text.Encoding]::GetEncoding("shift_jis")
    return [byte[]]$enc.GetBytes($normalized)
}

function Build-EscPosPayload {
    param(
        [string]$Text,
        [bool]$DoubleSize,
        [bool]$Cut
    )

    # Normalize newlines and ensure trailing LF
    $text = $Text -replace "`r`n", "`n" -replace "`r", "`n"
    if (-not $text.EndsWith("`n")) { $text += "`n" }

    # 必ず byte[] に固定（AddRange 由来の型事故を避ける）
    [byte[]]$init       = 0x1B,0x40           # ESC @ 初期化
    [byte[]]$jpEnable   = 0x1C,0x43,0x01      # FS C 1 日本語フォント有効（機種差あり）
    [byte[]]$dbl        = 0x1D,0x21,0x11      # GS ! 0x11 文字サイズ拡大（任意）
    [byte[]]$cutCmd     = 0x1D,0x56,0x41,0x03 # GS V A 3 カット（任意）
    [byte[]]$fontSmall  = 0x1B,0x4D,0x01      # ESC M 1 フォントB (小)
    [byte[]]$lineSpacing= 0x1B,0x33,0x28      # ESC 3 n 行間（0x28=40程度）
    [byte[]]$revOn      = 0x1D,0x42,0x01      # GS B 1 反転 ON
    [byte[]]$revOff     = 0x1D,0x42,0x00      # GS B 0 反転 OFF

    $payload = @()
    $payload += $init
    $payload += $jpEnable
    $payload += $fontSmall
    $payload += $lineSpacing
    if ($DoubleSize) { $payload += $dbl }

    $enc = [System.Text.Encoding]::GetEncoding("shift_jis")

    $pos = 0
    while ($pos -lt $text.Length) {
        $sub = $text.Substring($pos)
        $m = [regex]::Match($sub, '(?s)!!(.+?)!!')
        if (-not $m.Success) {
            if ($sub.Length -gt 0) { $payload += $enc.GetBytes($sub) }
            break
        }
        if ($m.Index -gt 0) {
            $before = $sub.Substring(0, $m.Index)
            $payload += $enc.GetBytes($before)
        }
        $inner = $m.Groups[1].Value
        $payload += $revOn
        $payload += $enc.GetBytes($inner)
        $payload += $revOff
        $pos += $m.Index + $m.Length
    }

    if ($Cut) { $payload += $cutCmd }

    return [byte[]]$payload
}

function Send-ToPrinter {
    param(
        [byte[]]$Bytes,
        [string]$Ip,
        [int]$Port,
        [int]$TimeoutSec
    )

    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect($Ip, $Port, $null, $null)
        if (-not $iar.AsyncWaitHandle.WaitOne([TimeSpan]::FromSeconds($TimeoutSec))) {
            throw "接続タイムアウト (${TimeoutSec}s)"
        }
        $client.EndConnect($iar)

        $stream = $client.GetStream()
        $stream.WriteTimeout = $TimeoutSec * 1000
        $stream.ReadTimeout  = $TimeoutSec * 1000

        $stream.Write($Bytes, 0, $Bytes.Length)
        $stream.Flush()
    } finally {
        if ($client.Connected) { $client.Close() } else { $client.Dispose() }
    }
}

# ------------------------------------------------------------
# Week template generator / default week (先頭1行空ける)
# ------------------------------------------------------------
function Generate-WeekText([datetime]$StartDate) {
    # Adjust to Monday (week starting Monday)
    $offset = ([int]$StartDate.DayOfWeek + 6) % 7
    $start = $StartDate.AddDays(-$offset).Date
    $end = $start.AddDays(6).Date

    $lines = @()
    $lines += ""  # leading blank line
    # $lines += ("             {0}～{1}" -f @($start.ToString('yyyy-MM-dd'), $end.ToString('yyyy-MM-dd')))
    # $lines += '----------------------------------------------'

    $names = @('Mon','Tue','Wed','Thu','Fri','Sat','Sun')
    for ($i = 0; $i -lt 7; $i++) {
        $d = $start.AddDays($i)
        $dow = $names[$i]
        $isWeekend = ($dow -eq 'Sat' -or $dow -eq 'Sun')
        if ($isWeekend) {
            $lines += ("     !! {0}({1}) !!" -f @($d.ToString('yyyy-MM-dd'), $dow))
        } else {
            $lines += ("    {0}({1})" -f @($d.ToString('yyyy-MM-dd'), $dow))
        }
        $lines += ""
        $lines += ""
        $lines += ""
        if($i -ne 6){
            $lines += '----------------------------------------------'
        }
    }
    # $lines += "      ◆メモ"
    # $lines += ""
    # $lines += ""
    # $lines += '----------------------------------------------'

    # ensure trailing newline at end
    return ($lines -join "`n") + "`n"
}

# default: current week's Monday
$today = Get-Date
$defaultStart = $today.AddDays(-(([int]$today.DayOfWeek + 6) % 7)).Date

# Save file path for storing printed content
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

function Get-SavedFilePath([datetime]$startDate) {
    $dateStr = $startDate.ToString('yyyy-MM-dd')
    return Join-Path $scriptDir "m5-weekly-refill-${dateStr}.bak.txt"
}

# Initialize with default text
$fixedText = Generate-WeekText -StartDate $defaultStart

# Load saved content if it exists for the current week
$savedTextFile = Get-SavedFilePath -startDate $defaultStart
if (Test-Path $savedTextFile) {
    try {
        $savedText = Get-Content -Path $savedTextFile -Raw -Encoding UTF8
        if ($savedText.Trim().Length -gt 0) {
            $fixedText = $savedText
        }
    } catch {
        # Silently ignore if we can't read the file
    }
}

# ------------------------------------------------------------
# GUI
# ------------------------------------------------------------
[System.Windows.Forms.Application]::EnableVisualStyles()

$form = New-Object System.Windows.Forms.Form
$form.Text = "Week Schedule ESC/POS Printer"
$form.StartPosition = "CenterScreen"
$form.Size = New-Object System.Drawing.Size(1000, 740)
$form.MinimumSize = New-Object System.Drawing.Size(900, 650)
$form.AutoScaleMode = [System.Windows.Forms.AutoScaleMode]::Dpi
$form.Font = New-Object System.Drawing.Font("Segoe UI", 12)

$root = New-Object System.Windows.Forms.TableLayoutPanel
$root.Dock = 'Fill'
$root.Padding = New-Object System.Windows.Forms.Padding(12)
$root.ColumnCount = 1
$root.RowCount = 5
$root.RowStyles.Add((New-Object System.Windows.Forms.RowStyle([System.Windows.Forms.SizeType]::AutoSize)))
$root.RowStyles.Add((New-Object System.Windows.Forms.RowStyle([System.Windows.Forms.SizeType]::AutoSize)))
$root.RowStyles.Add((New-Object System.Windows.Forms.RowStyle([System.Windows.Forms.SizeType]::Percent, 55)))
$root.RowStyles.Add((New-Object System.Windows.Forms.RowStyle([System.Windows.Forms.SizeType]::AutoSize)))
$root.RowStyles.Add((New-Object System.Windows.Forms.RowStyle([System.Windows.Forms.SizeType]::Percent, 45)))

$settings = New-Object System.Windows.Forms.FlowLayoutPanel
$settings.Dock = 'Top'
$settings.AutoSize = $true
$settings.WrapContents = $true
$settings.FlowDirection = 'LeftToRight'
$settings.Padding = New-Object System.Windows.Forms.Padding(0,0,0,8)

function New-Label($text) {
    $l = New-Object System.Windows.Forms.Label
    $l.Text = $text
    $l.AutoSize = $true
    $l.Margin = New-Object System.Windows.Forms.Padding(0,8,8,0)
    return $l
}

function Wrap-Control([System.Windows.Forms.Control]$ctrl, [int]$height, [int]$rightMargin = 16) {
    $p = New-Object System.Windows.Forms.Panel
    $p.Height = $height
    $p.Width  = $ctrl.Width
    $p.Margin = New-Object System.Windows.Forms.Padding(0,0,$rightMargin,0)

    $y = [Math]::Max(0, [int](($height - $ctrl.PreferredSize.Height) / 2))
    $ctrl.Location = New-Object System.Drawing.Point(0, $y)
    $ctrl.Margin = 0
    $p.Controls.Add($ctrl)
    return $p
}

function New-TextBox($w) {
    $t = New-Object System.Windows.Forms.TextBox
    $t.Width = $w
    $t.Margin = 0
    return $t
}

function New-Nud($w, $min, $max, $val) {
    $n = New-Object System.Windows.Forms.NumericUpDown
    $n.Width = $w
    $n.Minimum = $min
    $n.Maximum = $max
    $n.Value = $val
    $n.Margin = 0
    return $n
}

function New-Check($text, $checked=$false) {
    $c = New-Object System.Windows.Forms.CheckBox
    $c.Text = $text
    $c.Checked = $checked
    $c.AutoSize = $true
    $c.Margin = New-Object System.Windows.Forms.Padding(0,8,16,0)
    return $c
}

function New-Button($text, $w) {
    $b = New-Object System.Windows.Forms.Button
    $b.Text = $text
    $b.Width = $w
    $b.Height = 44
    $b.Margin = New-Object System.Windows.Forms.Padding(0,0,10,0)
    return $b
}

$inputHeight = 36

# IP
$settings.Controls.Add((New-Label "IP"))
$txtIp = New-TextBox 190
$txtIp.Text = "172.16.20.31"
$settings.Controls.Add((Wrap-Control $txtIp $inputHeight))

# Port
$settings.Controls.Add((New-Label "Port"))
$nudPort = New-Nud 110 1 65535 9100
$settings.Controls.Add((Wrap-Control $nudPort $inputHeight))

# Timeout
$settings.Controls.Add((New-Label "Timeout(s)"))
$nudTimeout = New-Nud 100 1 60 5
$settings.Controls.Add((Wrap-Control $nudTimeout $inputHeight))

# Options
$chkDouble   = New-Check "2倍(拡大)" $false
$chkCut      = New-Check "カット" $true
$chkEditable = New-Check "プレビュー編集" $false
$chkShowMarkers = New-Check "マーカー表示" $false
$settings.Controls.AddRange(@($chkDouble, $chkCut, $chkEditable, $chkShowMarkers))

# Start date picker + week controls
$settings.Controls.Add((New-Label "開始日"))
$dtpStart = New-Object System.Windows.Forms.DateTimePicker
$dtpStart.Format = 'Custom'
$dtpStart.CustomFormat = 'yyyy-MM-dd'
$dtpStart.Width = 140
$dtpStart.Value = $defaultStart
$settings.Controls.Add((Wrap-Control $dtpStart $inputHeight))

$btnPrevWeek = New-Button "<" 48
$btnNextWeek = New-Button ">" 48
$btnApplyWeek = New-Button "週を反映" 120
$settings.Controls.Add((Wrap-Control $btnPrevWeek $inputHeight 8))
$settings.Controls.Add((Wrap-Control $btnNextWeek $inputHeight 8))
$settings.Controls.Add((Wrap-Control $btnApplyWeek $inputHeight))

# Events
function Load-SavedContent([datetime]$startDate) {
    $savedTextFile = Get-SavedFilePath -startDate $startDate
    if (Test-Path $savedTextFile) {
        try {
            $savedText = Get-Content -Path $savedTextFile -Raw -Encoding UTF8
            if ($savedText.Trim().Length -gt 0) {
                return $savedText
            }
        } catch {
            # Silently ignore if we can't read the file
        }
    }
    return $null
}

$btnPrevWeek.Add_Click({
    $dtpStart.Value = $dtpStart.Value.AddDays(-7)
    $fixedText = Generate-WeekText -StartDate $dtpStart.Value
    $savedContent = Load-SavedContent -startDate $dtpStart.Value
    if ($savedContent) { $fixedText = $savedContent }
    Apply-Template
    Add-Log ("週を更新: " + $dtpStart.Value.ToString("yyyy-MM-dd"))
})
$btnNextWeek.Add_Click({
    $dtpStart.Value = $dtpStart.Value.AddDays(7)
    $fixedText = Generate-WeekText -StartDate $dtpStart.Value
    $savedContent = Load-SavedContent -startDate $dtpStart.Value
    if ($savedContent) { $fixedText = $savedContent }
    Apply-Template
    Add-Log ("週を更新: " + $dtpStart.Value.ToString("yyyy-MM-dd"))
})
$btnApplyWeek.Add_Click({
    $fixedText = Generate-WeekText -StartDate $dtpStart.Value
    $savedContent = Load-SavedContent -startDate $dtpStart.Value
    if ($savedContent) { $fixedText = $savedContent }
    Apply-Template
    Add-Log ("週を反映: " + $dtpStart.Value.ToString("yyyy-MM-dd"))
})

# Preview Label
$lblPreview = New-Object System.Windows.Forms.Label
$lblPreview.Text = "印字文字列（プレビュー）"
$lblPreview.AutoSize = $true
$lblPreview.Margin = New-Object System.Windows.Forms.Padding(0,0,0,6)

# Preview Text
$txtPreview = New-Object System.Windows.Forms.TextBox
$txtPreview.Dock = 'Fill'
$txtPreview.Multiline = $true
$txtPreview.ScrollBars = 'Vertical'
$txtPreview.Font = New-Object System.Drawing.Font("Consolas", 12)
$txtPreview.ReadOnly = $true

# Buttons + Log label
$bar = New-Object System.Windows.Forms.FlowLayoutPanel
$bar.Dock = 'Top'
$bar.AutoSize = $true
$bar.WrapContents = $true
$bar.FlowDirection = 'LeftToRight'
$bar.Padding = New-Object System.Windows.Forms.Padding(0,10,0,6)

$btnReload   = New-Button "テンプレ再読込" 180
$btnPrint    = New-Button "印刷" 120
$btnClearLog = New-Button "ログクリア" 120
$btnPrint.Enabled = $true

$bar.Controls.AddRange(@($btnReload, $btnPrint, $btnClearLog))

$lblLog = New-Object System.Windows.Forms.Label
$lblLog.Text = "ログ"
$lblLog.AutoSize = $true
$lblLog.Margin = New-Object System.Windows.Forms.Padding(6,12,0,0)
$bar.Controls.Add($lblLog)

# Log Text
$txtLog = New-Object System.Windows.Forms.TextBox
$txtLog.Dock = 'Fill'
$txtLog.Multiline = $true
$txtLog.ScrollBars = 'Vertical'
$txtLog.Font = New-Object System.Drawing.Font("Consolas", 12)
$txtLog.ReadOnly = $true

function Add-Log([string]$msg) {
    $ts = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $txtLog.AppendText("[$ts] $msg`r`n")
}

function Apply-Template {
    # Show or hide !! markers in preview based on $chkShowMarkers
    if ($chkShowMarkers -and $chkShowMarkers.Checked) {
        $previewText = $fixedText -replace "(\r?\n)", "`r`n"
    } else {
        # Remove !! markers for preview so it matches printed output. Use singleline regex.
        $previewText = ($fixedText -replace '(?s)!!(.+?)!!', '$1') -replace "(\r?\n)", "`r`n"
    }
    $txtPreview.Text = $previewText
    $btnPrint.Enabled = ($txtPreview.Text.Trim().Length -gt 0)
    Add-Log "テンプレを反映しました。"
}

# Events
$chkEditable.Add_CheckedChanged({
    $txtPreview.ReadOnly = -not $chkEditable.Checked
    Add-Log ("プレビュー編集: " + ($(if ($chkEditable.Checked) { "ON" } else { "OFF" })))
})
$chkShowMarkers.Add_CheckedChanged({
    Add-Log ("マーカー表示: " + ($(if ($chkShowMarkers.Checked) { "ON" } else { "OFF" })))
    Apply-Template
})

$btnReload.Add_Click({ Apply-Template })
$txtPreview.Add_TextChanged({ $btnPrint.Enabled = ($txtPreview.Text.Trim().Length -gt 0) })
$btnClearLog.Add_Click({ $txtLog.Clear() })

$btnPrint.Add_Click({
    try {
        $ip = $txtIp.Text.Trim()
        if ($ip.Length -eq 0) { throw "IPが未入力です。" }

        $port = [int]$nudPort.Value
        $timeout = [int]$nudTimeout.Value
        $doubleSize = [bool]$chkDouble.Checked
        $cut = [bool]$chkCut.Checked

        $text = $txtPreview.Text
        if ($text.Trim().Length -eq 0) { throw "印刷する文字が空です。" }

        Add-Log "送信開始: ${ip}:$port (Timeout=${timeout}s, DoubleSize=$doubleSize, Cut=$cut)"

        $payload = Build-EscPosPayload -Text $text -DoubleSize $doubleSize -Cut $cut
        Send-ToPrinter -Bytes $payload -Ip $ip -Port $port -TimeoutSec $timeout

        # Save the printed content to file (with week start date in filename)
        try {
            $weekStartDate = $dtpStart.Value.Date
            $savedTextFile = Get-SavedFilePath -startDate $weekStartDate
            Set-Content -Path $savedTextFile -Value $text -Encoding UTF8
            Add-Log "内容を保存しました: $(Split-Path -Leaf $savedTextFile)"
        } catch {
            Add-Log "警告: 内容の保存に失敗しました: $($_.Exception.Message)"
        }

        Add-Log "印刷成功: ${ip}:$port"
    } catch {
        Add-Log "印刷失敗: $($_.Exception.Message)"
        [System.Windows.Forms.MessageBox]::Show(
            "印刷失敗: $($_.Exception.Message)",
            "Error",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    }
})

# Assemble
$root.Controls.Add($settings,   0, 0)
$root.Controls.Add($lblPreview, 0, 1)
$root.Controls.Add($txtPreview, 0, 2)
$root.Controls.Add($bar,        0, 3)
$root.Controls.Add($txtLog,     0, 4)

$form.Controls.Add($root)

# Startup
Add-Log "起動しました。"
Apply-Template

# Run
[System.Windows.Forms.Application]::Run($form)

