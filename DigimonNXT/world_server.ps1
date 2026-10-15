#requires -Version 5.1
<#
.SYNOPSIS
Digimon NXT local World Server console: live activity and CLI administration.
.DESCRIPTION
Run on the physical Windows server as an administrator. No listening port,
remote shell, web administration endpoint or database password is added.
GamePath is the served folder containing index.php, not the source ZIP root.
#>
[CmdletBinding()]
param(
    [string]$GamePath = '',
    [string]$PhpPath = '',
    [string]$ApacheAccessLog = '',
    [string]$ApacheUrlPath = '',
    [switch]$NoApache,
    [switch]$NoElevation
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Quote-NativeArgument([string]$Value) {
    $quoted = [regex]::Replace($Value, '(\\*)"', '$1$1\"')
    $quoted = [regex]::Replace($quoted, '(\\+)$', '$1$1')
    return '"' + $quoted + '"'
}

function Resolve-LocalPath([string]$Value, [string]$Label) {
    if ([string]::IsNullOrWhiteSpace($Value) -or $Value.IndexOfAny([char[]]"`r`n`0") -ge 0 -or $Value -notmatch '^[A-Za-z]:[\\/]') {
        throw "$Label must be an absolute local drive path, such as C:\xampp\htdocs\DigimonNXT."
    }
    $full = [IO.Path]::GetFullPath($Value)
    $drive = [IO.DriveInfo]::new([IO.Path]::GetPathRoot($full))
    if ($drive.DriveType -eq [IO.DriveType]::Network) { throw "$Label must be on the server's local disk, not a network drive." }
    return $full.TrimEnd('\')
}

function Property-Or($Object, [string]$Name, $Default) {
    if ($null -ne $Object -and $null -ne $Object.PSObject.Properties[$Name]) { return $Object.$Name }
    return $Default
}

function Say([string]$Text, [ConsoleColor]$Color = [ConsoleColor]::Gray) {
    [DigimonNxt.WorldConsole.Terminal]::Line($Text, $Color)
    $script:NeedDraw = $true
}

function System-Line([string]$Text, [ConsoleColor]$Color = [ConsoleColor]::DarkGray) {
    Say (('{0}  SYSTEM   {1}' -f [DateTime]::UtcNow.ToString('yyyy-MM-dd HH:mm:ss'), $Text)) $Color
}

function Start-Backend([string]$Mode, $Request) {
    if ($null -ne $script:Pending) { throw 'An administration command is already running.' }
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $PhpPath
    $start.Arguments = ((@($script:Backend, '--game-dir', $GamePath, $Mode) | ForEach-Object { Quote-NativeArgument $_ }) -join ' ')
    $start.WorkingDirectory = $GamePath
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.StandardOutputEncoding = [Text.Encoding]::UTF8
    $start.StandardErrorEncoding = [Text.Encoding]::UTF8
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    if (-not $process.Start()) { throw 'PHP could not be started.' }
    $outputTask = $process.StandardOutput.ReadToEndAsync()
    $errorTask = $process.StandardError.ReadToEndAsync()
    try {
        if ($null -ne $Request) {
            # Windows PowerShell's default OEM console encoding must not alter
            # Unicode account names, announcements or masked passwords.
            $payload = [Text.UTF8Encoding]::new($false).GetBytes(($Request | ConvertTo-Json -Compress -Depth 5))
            $process.StandardInput.BaseStream.Write($payload, 0, $payload.Length)
            $process.StandardInput.BaseStream.Flush()
            [Array]::Clear($payload, 0, $payload.Length)
        }
    } finally { $process.StandardInput.Close() }
    $script:Pending = @{ Process = $process; Output = $outputTask; Error = $errorTask; Mode = $Mode; Started = [DateTime]::UtcNow }
    $script:NeedDraw = $true
}

function Show-LocalHelp {
    Say 'LOCAL DISPLAY COMMANDS  |  These change this console only.' Cyan
    foreach ($line in @(
        '  help [command]                    All administration commands or focused help',
        '  console status                    Paths, feed state and display settings',
        '  console traffic summary|full|off  Group repeats/assets; full shows more requests',
        '  console filter all|request|auth|game|bots|admin|system',
        '  console apache on|off             Include this game''s Apache static/404 traffic',
        '  console pause | console resume    Pause/resume the traffic display',
        '  console clear                     Clear this window (Ctrl+L)',
        '  exit | quit                       Close console; game and tamer worker stay running',
        '  Up/Down: history  Left/Right/Home/End: edit  Tab: complete command  Esc: cancel',
        '  Passwords use a masked prompt. Never put a password in a command.',
        '  UTC timestamps; guest = no signed-in account; static assets never infer identity.'
    )) { Say $line }
}

function Show-Commands {
    Say 'WORLD SERVER ADMINISTRATION' Cyan
    foreach ($command in $script:Commands) {
        $usage = [string](Property-Or $command 'usage' (Property-Or $command 'name' ''))
        $description = [string](Property-Or $command 'description' '')
        Say ('  ' + $usage) White
        if ($description) { Say ('      ' + $description) DarkGray }
    }
    Show-LocalHelp
}

function Show-ConsoleStatus {
    Say ('Game:    ' + $GamePath) White
    Say ('PHP:     ' + $PhpPath)
    Say ('Journal: ' + $script:JournalPath)
    Say ('Apache:  ' + $ApacheAccessLog + '  [URL prefix ' + $ApacheUrlPath + ']')
    Say ('Display: traffic=' + $script:TrafficMode + ', filter=' + $script:Filter + ', paused=' + $script:Paused + ', apache=' + $script:ApacheEnabled)
    Say 'Repeated requests and assets are summarized every 15 seconds. Full mode is capped at 8 traffic lines/second.'
    Say 'Administration results always appear. Complete unsummarized dynamic records remain in the protected journal.'
}

function Is-SecretCommand([string]$Line) {
    # Match the backend's optional leading dot and quoted/escaped token forms.
    # Conservative over-redaction is preferable to retaining a password command.
    $normalized = $Line -replace '["''\\]', ''
    $normalized = $normalized -replace '^\s*\.\s*', ''
    return $normalized -match '^\s*account\s+(create|password)(\s|$)'
}

function Submit-Line([string]$Line) {
    $Line = $Line.Trim()
    if (-not $Line) { return }
    $safeHistory = -not (Is-SecretCommand $Line)
    if ($safeHistory -and ($script:History.Count -eq 0 -or $script:History[$script:History.Count-1] -ne $Line)) {
        $script:History.Add($Line)
        if ($script:History.Count -gt 100) { $script:History.RemoveAt(0) }
    }
    $script:HistoryIndex = $script:History.Count
    Say ('world> ' + $(if ($safeHistory) { $Line } else { '[account password operation: input not retained]' })) Cyan
    switch -Regex ($Line) {
        '^(exit|quit)$' { $script:Running = $false; return }
        '^console\s+clear$' { [Console]::Clear(); $script:NeedDraw = $true; return }
        '^console\s+status$' { Show-ConsoleStatus; return }
        '^console\s+help$' { Show-LocalHelp; return }
        '^console\s+pause$' { $script:Paused = $true; System-Line 'Traffic display paused. Administration results remain visible.' Yellow; return }
        '^console\s+resume$' { $script:Paused = $false; System-Line 'Traffic display resumed. Suppressed traffic remains in the journal.' Green; return }
        '^console\s+traffic\s+(summary|full|off)$' { $script:TrafficMode = $Matches[1].ToLowerInvariant(); System-Line ('Traffic mode: ' + $script:TrafficMode) Cyan; return }
        '^console\s+filter\s+(all|request|auth|game|bots|admin|system)$' { $script:Filter = $Matches[1].ToLowerInvariant(); System-Line ('Category filter: ' + $script:Filter) Cyan; return }
        '^console\s+apache\s+(on|off)$' { $script:ApacheEnabled = $Matches[1] -eq 'on'; System-Line ('Apache traffic: ' + $script:ApacheEnabled) Cyan; return }
        '^console(?:\s|$)' { Show-LocalHelp; return }
        '^help$' { Show-Commands; return }
    }
    $script:Request = @{ line = $Line }
    Start-Backend '--execute' $script:Request
}

function Finish-Backend {
    if ($null -eq $script:Pending -or -not $script:Pending.Process.HasExited) { return }
    $job = $script:Pending
    $script:Pending = $null
    try {
        $raw = $job.Output.GetAwaiter().GetResult()
        $stderr = $job.Error.GetAwaiter().GetResult()
        if ($raw.Length -gt 4194304) { throw 'The command response was too large to display.' }
        try { $result = $raw | ConvertFrom-Json -ErrorAction Stop } catch { throw 'PHP did not return a valid console response. Check your PHP version, extensions and selected game folder.' }
        if ($null -eq $result -or $null -eq $result.PSObject.Properties['ok']) { throw 'PHP returned an unsupported response.' }
        if ($stderr) { System-Line 'PHP emitted a diagnostic. Check storage/logs; raw diagnostic text is hidden to avoid exposing secrets.' Yellow }
        foreach ($line in @(Property-Or $result 'lines' @())) { Say ([string]$line) $(if ($result.ok) { [ConsoleColor]::Gray } else { [ConsoleColor]::Yellow }) }
        if ($job.Mode -eq '--describe') {
            $script:Commands = @(Property-Or $result 'commands' @())
            Start-Backend '--status' $null
            return
        }
        if ($job.Mode -eq '--status') {
            if (-not $result.ok) { System-Line 'Server status needs attention. The console stays open for diagnosis; use status after correcting configuration.' Yellow }
            System-Line 'Ready. Type help for commands. Timestamps are UTC; Ctrl+L clears the display.' Cyan
            return
        }
        if (Property-Or $result 'requires_secret' $false) {
            $script:InputMode = 'secret'
            $script:Input = ''; $script:Cursor = 0
            System-Line 'Enter the password below. It is masked and never saved in console history. Esc cancels.' Yellow
            return
        }
        if (Property-Or $result 'requires_confirmation' $false) {
            $script:ConfirmPhrase = [string](Property-Or $result 'confirmation' 'CONFIRM')
            $script:InputMode = 'confirm'
            $script:Input = ''; $script:Cursor = 0
            System-Line ('Review the command above. Type ' + $script:ConfirmPhrase + ' to apply it; Esc cancels.') Yellow
            return
        }
        $script:Request = $null
        $script:InputMode = 'command'
    } catch {
        System-Line $_.Exception.Message Red
        $script:Request = $null
        $script:InputMode = 'command'
    } finally { $job.Process.Dispose(); $script:NeedDraw = $true }
}

function Emit-Event($Event, [bool]$Apache = $false) {
    $category = [string](Property-Or $Event 'category' 'system')
    $losses = [int64](Property-Or $Event 'journal_losses' 0)
    if ($losses -gt $script:JournalLosses) {
        $script:JournalLosses = $losses
        if ($script:ReportedJournalLosses -eq 0) {
            System-Line ('The journal reported ' + $losses + ' write failures. Check storage permissions and free disk space.') Yellow
            $script:ReportedJournalLosses = $losses
        }
    }
    $isAdmin = $category -eq 'admin'
    if (-not $isAdmin -and ($script:Paused -or ($script:Filter -ne 'all' -and $category -ne $script:Filter))) { $script:HiddenCount++; return }
    $traffic = $category -eq 'request'
    if ($traffic -and $script:TrafficMode -eq 'off') { return }
    $now = [DateTime]::UtcNow
    $account = [string](Property-Or $Event 'account' '')
    if (-not $account) { $account = if ($Apache) { 'unattributed' } else { 'guest' } }
    $ip = [string](Property-Or $Event 'ip' '-')
    if (-not $ip) { $ip = 'local' }
    $path = [string](Property-Or $Event 'path' '')
    $method = [string](Property-Or $Event 'method' '')
    $status = [string](Property-Or $Event 'status' '')
    $message = [string](Property-Or $Event 'message' '')
    $name = [string](Property-Or $Event 'event' $category)
    $stamp = [string](Property-Or $Event 'ts' $now.ToString('o'))
    try { $stamp = [DateTimeOffset]::Parse($stamp).UtcDateTime.ToString('yyyy-MM-dd HH:mm:ss') } catch { $stamp = $now.ToString('yyyy-MM-dd HH:mm:ss') }
    if ($traffic) {
        # Defensive second barrier: access paths and messages never carry query strings.
        $path = ($path -split '[?#]', 2)[0]
        $message = ($method + ' ' + $path + ' -> ' + $status)
        $duration = Property-Or $Event 'duration_ms' $null
        if ($null -ne $duration) { $message += ('  ' + $duration + 'ms') }
    }
    $poll = $traffic -and $path -match '/(?:_health|_client_manifest|bot_tick|tamer_pulse|world_notice|world_map_presence|ai_activity|notifications|api)(?:[./_]|$)'
    $key = "$category|$ip|$account|$method|$path|$status|$name"
    if ($category -eq 'bots') { $key = 'bots|' + $name }
    $repeated = $script:Recent.ContainsKey($key) -and ($now - $script:Recent[$key]).TotalSeconds -lt 5
    $burst = [int64][Math]::Floor(($now - $script:Epoch).TotalSeconds)
    if ($burst -ne $script:TrafficSecond) { $script:TrafficSecond = $burst; $script:TrafficLines = 0; $script:EventLines = 0 }
    $group = -not $isAdmin -and (($category -eq 'bots' -and $script:BotSamples.ContainsKey($name)) -or $repeated -or $script:EventLines -ge 20 -or ($traffic -and ($poll -or ($Apache -and $script:TrafficMode -eq 'summary') -or $script:TrafficLines -ge 8)))
    if ($group) {
        if ($category -eq 'bots') { $message = ('AI activity: ' + $name); $ip = 'local'; $account = 'AI tamers (grouped)' }
        elseif (-not $traffic) { $message = ('Grouped activity: ' + $name) }
        # Cardinality is capped: arbitrary request paths cannot exhaust console memory.
        if (-not $script:Groups.ContainsKey($key) -and $script:Groups.Count -ge 250) { $key = 'overflow'; $message = 'Additional grouped traffic'; $ip = 'various'; $account = 'various' }
        if (-not $script:Groups.ContainsKey($key)) { $script:Groups[$key] = @{ Count = 0; Message = $message; IP = $ip; Account = $account; Category = $category; Stamp = $stamp } }
        $script:Groups[$key].Count++
        return
    }
    if ($traffic) { $script:TrafficLines++ }
    if ($category -eq 'bots') { $script:BotSamples[$name] = $true }
    if (-not $isAdmin) { $script:EventLines++; $script:Recent[$key] = $now }
    $color = switch ($category) { 'auth' { [ConsoleColor]::Yellow } 'game' { [ConsoleColor]::Green } 'admin' { [ConsoleColor]::Magenta } 'request' { [ConsoleColor]::DarkGray } 'bots' { [ConsoleColor]::DarkCyan } default { [ConsoleColor]::Gray } }
    if ((Property-Or $Event 'level' 'info') -match '^(error|critical)$') { $color = [ConsoleColor]::Red }
    Say ('{0}  {1,-8} {2}  [{3} | {4}]' -f $stamp, $category.ToUpperInvariant(), $message, $ip, $account) $color
}

function Flush-Summaries {
    $now = [DateTime]::UtcNow
    if (($now - $script:LastSummary).TotalSeconds -lt 15) { return }
    if (-not $script:Paused -and $script:Groups.Count -gt 0) {
        $all = @($script:Groups.Values | Sort-Object Count -Descending)
        $total = 0
        foreach ($group in $all) { $total += $group.Count }
        Say ('{0}  SUMMARY  {1} grouped events in 15s; {2} groups. Busiest:' -f $now.ToString('yyyy-MM-dd HH:mm:ss'), $total, $all.Count) DarkCyan
        foreach ($group in @($all | Select-Object -First 4)) {
            Say ('  {0,5}x {1}: {2}  [{3} | {4}]' -f $group.Count, $group.Category, $group.Message, $group.IP, $group.Account) DarkGray
        }
        if ($all.Count -gt 4) { Say ('  + ' + ($all.Count - 4) + ' other groups; all dynamic records remain in the journal.') DarkGray }
    }
    $script:Groups.Clear()
    $script:BotSamples.Clear()
    if ($script:JournalLosses -gt $script:ReportedJournalLosses) {
        System-Line (($script:JournalLosses - $script:ReportedJournalLosses).ToString() + ' additional journal write failures; cumulative count ' + $script:JournalLosses + '. Check storage permissions and free disk space.') Yellow
        $script:ReportedJournalLosses = $script:JournalLosses
    }
    if ($script:BadRecords -gt $script:ReportedBadRecords) {
        System-Line (($script:BadRecords - $script:ReportedBadRecords).ToString() + ' unreadable journal records were skipped in this interval; inspect the journal file.') Yellow
        $script:ReportedBadRecords = $script:BadRecords
    }
    foreach ($key in @($script:Recent.Keys)) { if (($now - $script:Recent[$key]).TotalSeconds -ge 5) { $script:Recent.Remove($key) } }
    if ($script:Recent.Count -gt 5000) { $script:Recent.Clear() }
    $script:LastSummary = $now
}

function Read-Journal {
    $batch = $script:Journal.Read($false, 524288)
    if ($batch.Error -and $batch.Error -ne $script:LastJournalError) { System-Line ('Journal unavailable: ' + $batch.Error) Yellow; $script:LastJournalError = $batch.Error }
    if ($batch.Gap) { System-Line 'A malformed or oversized journal record was skipped; inspect the journal file for detail.' Yellow }
    foreach ($line in $batch.Lines) {
        try { $entry = $line | ConvertFrom-Json -ErrorAction Stop; Emit-Event $entry } catch { $script:BadRecords++ }
    }
}

function Read-Apache {
    if (-not $script:ApacheEnabled -or $null -eq $script:Apache) { return }
    $batch = $script:Apache.Read($false, 524288)
    if ($batch.Error -and $batch.Error -ne $script:LastApacheError) { System-Line ('Apache log unavailable: ' + $batch.Error) Yellow; $script:LastApacheError = $batch.Error }
    foreach ($line in $batch.Lines) {
        # Supports Apache Common/Combined log format. Headers, referrers, bodies
        # and query strings are deliberately excluded from console output.
        if ($line -notmatch '^(?<ip>\S+)\s+\S+\s+\S+\s+\[(?<time>[^\]]+)\]\s+"(?<method>[A-Z]+)\s+(?<path>[^\s"]+)\s+HTTP/[^\s"]+"\s+(?<status>\d{3})\s+') { continue }
        $ip = $Matches.ip; $rawPath = $Matches.path; $method = $Matches.method; $status = [int]$Matches.status; $rawTime = $Matches.time
        $parsedIp = $null
        if (-not [Net.IPAddress]::TryParse($ip, [ref]$parsedIp)) { continue }
        $path = ($rawPath -split '[?#]', 2)[0]
        if ($path -match '^https?://') { try { $path = ([Uri]$path).AbsolutePath } catch { continue } }
        if (-not $path.StartsWith($ApacheUrlPath, [StringComparison]::OrdinalIgnoreCase)) { continue }
        if ($path.EndsWith('.php', [StringComparison]::OrdinalIgnoreCase) -and $status -lt 400) { continue }
        if ($path.EndsWith('/') -and $status -lt 400) { continue }
        $stamp = [DateTime]::UtcNow.ToString('o')
        try {
            $rawTime = [regex]::Replace($rawTime, ' ([+-]\d{2})(\d{2})$', ' $1:$2')
            $stamp = [DateTimeOffset]::ParseExact($rawTime, 'dd/MMM/yyyy:HH:mm:ss zzz', [Globalization.CultureInfo]::InvariantCulture).ToString('o')
        } catch { }
        Emit-Event ([pscustomobject]@{ ts = $stamp; category = 'request'; event = 'apache'; ip = $ip; account = ''; method = $method; path = $path; status = $status }) $true
    }
}

function Cancel-Input {
    $script:Input = ''; $script:Cursor = 0
    if ($script:InputMode -ne 'command') {
        $script:Request = $null
        $script:InputMode = 'command'
        System-Line 'Command cancelled before submission.' Yellow
    }
    $script:HistoryIndex = $script:History.Count
    $script:NeedDraw = $true
}

function Complete-Input {
    if ($script:InputMode -ne 'command' -or -not $script:Input) { return }
    $names = @('console status', 'console help', 'console traffic summary', 'console traffic full', 'console traffic off', 'console filter all', 'console pause', 'console resume', 'console clear', 'console apache on', 'console apache off', 'exit', 'help')
    foreach ($command in $script:Commands) { $names += [string](Property-Or $command 'name' '') }
    $candidates = @($names | Where-Object { $_.StartsWith($script:Input, [StringComparison]::OrdinalIgnoreCase) } | Sort-Object -Unique)
    if ($candidates.Count -eq 1) { $script:Input = $candidates[0] + ' '; $script:Cursor = $script:Input.Length }
    elseif ($candidates.Count -gt 1) { Say ('Complete: ' + ($candidates -join ' | ')) DarkCyan }
    $script:NeedDraw = $true
}

function Read-Keys {
    $keyCount = 0
    while ([Console]::KeyAvailable -and $keyCount -lt 64) {
        $key = [Console]::ReadKey($true); $keyCount++
        $control = ($key.Modifiers -band [ConsoleModifiers]::Control) -ne 0
        if ($control -and $key.Key -eq [ConsoleKey]::L) { [DigimonNxt.WorldConsole.Terminal]::Erase(); [Console]::Clear(); $script:NeedDraw = $true; continue }
        if ($control -and $key.Key -eq [ConsoleKey]::C) { Cancel-Input; continue }
        if ($null -ne $script:Pending) { continue }
        switch ($key.Key) {
            Escape { Cancel-Input }
            Enter {
                $entered = $script:Input
                $script:Input = ''; $script:Cursor = 0
                [DigimonNxt.WorldConsole.Terminal]::Erase()
                if ($script:InputMode -eq 'secret') {
                    if (-not $entered) { System-Line 'Password cannot be empty. Esc cancels.' Yellow }
                    else { $script:Request.secret = $entered; $entered = $null; $script:InputMode = 'command'; Start-Backend '--execute' $script:Request }
                } elseif ($script:InputMode -eq 'confirm') {
                    if ($entered -ceq $script:ConfirmPhrase) { $script:Request.confirm = $true; $script:InputMode = 'command'; Start-Backend '--execute' $script:Request }
                    else { $script:Request = $null; $script:InputMode = 'command'; System-Line 'Confirmation did not match. No change was applied.' Yellow }
                } else { Submit-Line $entered }
                $entered = $null
            }
            Backspace { if ($script:Cursor -gt 0) { $script:Input = $script:Input.Remove($script:Cursor-1,1); $script:Cursor-- } }
            Delete { if ($script:Cursor -lt $script:Input.Length) { $script:Input = $script:Input.Remove($script:Cursor,1) } }
            LeftArrow { if ($script:Cursor -gt 0) { $script:Cursor-- } }
            RightArrow { if ($script:Cursor -lt $script:Input.Length) { $script:Cursor++ } }
            Home { $script:Cursor = 0 }
            End { $script:Cursor = $script:Input.Length }
            UpArrow {
                if ($script:InputMode -eq 'command' -and $script:HistoryIndex -gt 0) { $script:HistoryIndex--; $script:Input = $script:History[$script:HistoryIndex]; $script:Cursor = $script:Input.Length }
            }
            DownArrow {
                if ($script:InputMode -eq 'command' -and $script:HistoryIndex -lt $script:History.Count) { $script:HistoryIndex++; $script:Input = if ($script:HistoryIndex -eq $script:History.Count) { '' } else { $script:History[$script:HistoryIndex] }; $script:Cursor = $script:Input.Length }
            }
            Tab { Complete-Input }
            default {
                if (-not $control -and -not [char]::IsControl($key.KeyChar) -and $script:Input.Length -lt 2048) {
                    $script:Input = $script:Input.Insert($script:Cursor, [string]$key.KeyChar); $script:Cursor++
                }
            }
        }
        $script:NeedDraw = $true
    }
}

try {
    if ($env:OS -ne 'Windows_NT') { throw 'This launcher requires Windows PowerShell 5.1 on the Windows server PC.' }
    $senderInfo = Get-Variable PSSenderInfo -ErrorAction SilentlyContinue
    if ($env:SSH_CONNECTION -or $env:SSH_CLIENT -or $env:SSH_TTY -or ($null -ne $senderInfo -and $null -ne $senderInfo.Value)) { throw 'Remote sessions are not allowed. Open this console directly on the server PC.' }
    if (-not [Environment]::UserInteractive -or [Console]::IsInputRedirected -or [Console]::IsOutputRedirected) { throw 'An interactive local console is required. Piped input and redirected output are not allowed.' }
    Add-Type -Path (Join-Path $PSScriptRoot 'world-console\ConsoleHost.cs')
    if (-not [DigimonNxt.WorldConsole.LocalSession]::IsPhysicalConsole()) { throw 'This console is available only in the physical server PC session. RDP and remote sessions are blocked.' }
    if (-not $GamePath) {
        $parent = Split-Path $PSScriptRoot -Parent
        if (Test-Path -LiteralPath (Join-Path $parent 'index.php')) { $GamePath = $parent }
        elseif (Test-Path -LiteralPath (Join-Path $parent 'public_html\index.php')) { $GamePath = Join-Path $parent 'public_html' }
        else { $GamePath = 'C:\xampp\htdocs\DigimonNXT' }
    }
    if (-not $PhpPath) { $PhpPath = 'C:\xampp\php\php.exe' }
    if (-not $ApacheAccessLog) {
        $xampp = Split-Path (Split-Path $PhpPath -Parent) -Parent
        $ApacheAccessLog = Join-Path $xampp 'apache\logs\access.log'
    }
    $GamePath = Resolve-LocalPath $GamePath 'GamePath'
    $PhpPath = Resolve-LocalPath $PhpPath 'PhpPath'
    $ApacheAccessLog = Resolve-LocalPath $ApacheAccessLog 'ApacheAccessLog'
    if (-not $ApacheUrlPath) { $ApacheUrlPath = '/' + (Split-Path $GamePath -Leaf) + '/'; if ($ApacheUrlPath -eq '/public_html/') { $ApacheUrlPath = '/DigimonNXT/' } }
    if (-not $ApacheUrlPath.StartsWith('/') -or $ApacheUrlPath -match '[?#\s\x00-\x1f]') { throw 'ApacheUrlPath must be the game URL path, such as /DigimonNXT/ or / for a dedicated virtual host.' }
    if (-not $ApacheUrlPath.EndsWith('/')) { $ApacheUrlPath += '/' }
    if (-not [DigimonNxt.WorldConsole.LocalSession]::IsAdministrator()) {
        if ($NoElevation) { throw 'Open this console with Run as administrator on the server PC.' }
        Write-Host 'Windows administrator approval is required for the local World Server console.'
        $arguments = @('-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath, '-GamePath', $GamePath, '-PhpPath', $PhpPath, '-ApacheAccessLog', $ApacheAccessLog, '-ApacheUrlPath', $ApacheUrlPath, '-NoElevation')
        if ($NoApache) { $arguments += '-NoApache' }
        $executable = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
        $child = Start-Process -FilePath $executable -ArgumentList (($arguments | ForEach-Object { Quote-NativeArgument $_ }) -join ' ') -Verb RunAs -Wait -PassThru
        exit $child.ExitCode
    }
    foreach ($file in @('index.php', 'config\app.php', 'includes\bootstrap.php')) {
        if (-not (Test-Path -LiteralPath (Join-Path $GamePath $file) -PathType Leaf)) { throw "The served game folder is incomplete: $file missing. Set NXT_GAME_DIR to the folder containing index.php." }
    }
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP not found: $PhpPath. Set NXT_PHP in the launcher to your XAMPP php.exe." }
    $script:Backend = Join-Path $PSScriptRoot 'world_console_backend.php'
    if (-not (Test-Path -LiteralPath $script:Backend -PathType Leaf)) { throw 'world_console_backend.php is missing. Keep the complete tools folder together.' }
    $script:JournalPath = Join-Path $GamePath 'storage\world-console\events.php'
    $script:Journal = [DigimonNxt.WorldConsole.FileTail]::new($script:JournalPath)
    $script:Apache = [DigimonNxt.WorldConsole.FileTail]::new($ApacheAccessLog)
    $script:ApacheEnabled = -not $NoApache
    $script:TrafficMode = 'summary'; $script:Filter = 'all'; $script:Paused = $false
    $script:Groups = @{}; $script:Recent = @{}; $script:BotSamples = @{}; $script:TrafficSecond = 0; $script:TrafficLines = 0; $script:EventLines = 0
    $script:LastSummary = [DateTime]::UtcNow; $script:Epoch = [DateTime]::new(2020,1,1,0,0,0,[DateTimeKind]::Utc)
    $script:HiddenCount = 0; $script:BadRecords = 0; $script:ReportedBadRecords = 0; $script:LastJournalError = ''; $script:LastApacheError = ''; $script:JournalLosses = 0; $script:ReportedJournalLosses = 0
    $script:History = [Collections.Generic.List[string]]::new(); $script:HistoryIndex = 0
    $script:Input = ''; $script:Cursor = 0; $script:InputMode = 'command'; $script:ConfirmPhrase = ''
    $script:Request = $null; $script:Pending = $null; $script:Commands = @(); $script:Running = $true; $script:NeedDraw = $true
    $oldTitle = [Console]::Title; $oldControl = [Console]::TreatControlCAsInput
    [Console]::TreatControlCAsInput = $true
    [Console]::Title = 'Digimon NXT | World Server | LOCAL ADMIN'
    [Console]::Clear()
    Say 'DIGIMON NXT  /  WORLD SERVER' Cyan
    Say 'Local administrator console  |  Live traffic + game administration  |  UTC' White
    Say 'Commands and activity share this window. New events preserve the command you are typing.' DarkGray
    Show-ConsoleStatus
    if ($script:ApacheEnabled -and -not (Test-Path -LiteralPath $ApacheAccessLog)) { System-Line 'Apache access log not found yet. Dynamic game activity will still appear; set NXT_APACHE_LOG for another installation.' Yellow }
    # Attach at end: an opening console never replays an old traffic flood.
    $null = $script:Journal.Read($false,524288)
    $null = $script:Apache.Read($false,524288)
    Start-Backend '--describe' $null
    $lastRead = [DateTime]::MinValue; $lastGuard = [DateTime]::MinValue
    while ($script:Running) {
        $now = [DateTime]::UtcNow
        if (($now-$lastGuard).TotalSeconds -ge 2) {
            if (-not [DigimonNxt.WorldConsole.LocalSession]::IsPhysicalConsole()) { throw 'The local physical console session changed. World Server administration is closing.' }
            $lastGuard = $now
        }
        Finish-Backend
        Read-Keys
        if (($now-$lastRead).TotalMilliseconds -ge 500) { Read-Journal; Read-Apache; Flush-Summaries; $lastRead = $now }
        if ($script:NeedDraw) {
            $prompt = if ($null -ne $script:Pending) { 'working> ' } elseif ($script:InputMode -eq 'secret') { 'password> ' } elseif ($script:InputMode -eq 'confirm') { 'confirm> ' } else { 'world> ' }
            [DigimonNxt.WorldConsole.Terminal]::Draw($prompt, $script:Input, $script:Cursor, ($script:InputMode -eq 'secret'))
            $script:NeedDraw = $false
        }
        Start-Sleep -Milliseconds 35
    }
    [DigimonNxt.WorldConsole.Terminal]::Erase()
    Say 'Console closed. Apache, MySQL and autonomous tamers continue running.' Cyan
} catch {
    Write-Host ("World Server console: " + $_.Exception.Message) -ForegroundColor Red
    exit 1
} finally {
    if (Get-Variable Pending -Scope Script -ErrorAction SilentlyContinue) {
        if ($null -ne $script:Pending) {
            # Do not kill an in-flight database transaction. Closing this console
            # closes its pipes; the child can finish or roll back safely.
            $script:Pending.Process.Dispose()
        }
    }
    $script:Input = ''; $script:Request = $null
    if (Get-Variable Journal -Scope Script -ErrorAction SilentlyContinue) { $script:Journal.Dispose() }
    if (Get-Variable Apache -Scope Script -ErrorAction SilentlyContinue) { $script:Apache.Dispose() }
    if (Get-Variable oldControl -ErrorAction SilentlyContinue) { [Console]::TreatControlCAsInput = $oldControl; [Console]::Title = $oldTitle }
}
