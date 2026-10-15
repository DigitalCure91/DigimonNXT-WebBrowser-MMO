#requires -Version 5.1
# Portable component checks. This does NOT execute the Windows interactive UI
# or bypass its physical-session guard; only function definitions are loaded.
param([string]$TestPhpPath = '')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$source = Join-Path (Split-Path $PSScriptRoot -Parent) 'world_server.ps1'
$tokens = $null; $errors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($source, [ref]$tokens, [ref]$errors)
if ($errors.Count -gt 0) { throw ($errors | Out-String) }
foreach ($function in $ast.FindAll({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] }, $false)) {
    . ([scriptblock]::Create($function.Extent.Text))
}
Add-Type -Path (Join-Path $PSScriptRoot 'ConsoleHost.cs')
$script:Checks = 0
function Check([bool]$Pass, [string]$Name) { if (-not $Pass) { throw ('FAILED: ' + $Name) }; $script:Checks++; Write-Output ('PASS ' + $Name) }
$script:Output = [Collections.Generic.List[string]]::new()
function Say([string]$Text, [ConsoleColor]$Color = [ConsoleColor]::Gray) { $script:Output.Add($Text) }
function Reset-Feed {
    $script:Output.Clear(); $script:NeedDraw = $false
    $script:TrafficMode = 'full'; $script:Filter = 'all'; $script:Paused = $false
    $script:Groups = @{}; $script:Recent = @{}; $script:BotSamples = @{}; $script:TrafficSecond = -1; $script:TrafficLines = 0; $script:EventLines = 0
    $script:Epoch = [DateTime]::new(2020,1,1,0,0,0,[DateTimeKind]::Utc)
    $script:LastSummary = [DateTime]::UtcNow; $script:HiddenCount = 0; $script:JournalLosses = 0; $script:ReportedJournalLosses=0; $script:BadRecords=0; $script:ReportedBadRecords=0
}
$temp = Join-Path ([IO.Path]::GetTempPath()) ('nxt-console-test-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($temp) | Out-Null
$tails = [Collections.Generic.List[IDisposable]]::new()
try {
    Check ($errors.Count -eq 0) 'PowerShell syntax parses'
    $safe = [DigimonNxt.WorldConsole.Terminal]::Safe(('hello' + [char]27 + '[31m' + [char]13 + [char]0x202E),100)
    Check ($safe.IndexOf([char]27) -lt 0 -and $safe.IndexOf([char]13) -lt 0 -and $safe.IndexOf([char]0x202E) -lt 0) 'Terminal controls and bidi formatting stripped'
    Check ([DigimonNxt.WorldConsole.Terminal]::Safe(('x'*100),30).Length -eq 30) 'Terminal output length bounded'
    foreach ($command in @('account password Admin', '.account password Admin', "'account' 'password' Admin", "acc'ount' pass'word' Admin", 'ACCOUNT CREATE Admin admin@example.test', 'acc\ount pass\word Admin')) {
        Check (Is-SecretCommand $command) ('Password operation redacted: ' + $command)
    }
    Check (-not (Is-SecretCommand 'account info Admin')) 'Read-only account command can remain in history'
    if ($TestPhpPath) {
        $PhpPath=$TestPhpPath; $GamePath=$temp; $script:Pending=$null
        $script:Backend=Join-Path $temp 'json-echo.php'
        [IO.File]::WriteAllText($script:Backend, '<?php $r=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); echo json_encode(["ok"=>true,"data"=>["hash"=>hash("sha256",$r["secret"]),"line"=>$r["line"]]],JSON_UNESCAPED_UNICODE);', [Text.UTF8Encoding]::new($false))
        $secret='fixture-'+[char]0x03A9+[char]0x65E5
        $line='fixture-'+[char]0x00E9
        Start-Backend '--execute' @{line=$line;secret=$secret}
        if(-not $script:Pending.Process.WaitForExit(10000)){throw 'UTF-8 child fixture did not finish'}
        $reply=$script:Pending.Output.GetAwaiter().GetResult() | ConvertFrom-Json
        $hasher=[Security.Cryptography.SHA256]::Create()
        try{$hash=([BitConverter]::ToString($hasher.ComputeHash([Text.Encoding]::UTF8.GetBytes($secret)))).Replace('-','').ToLowerInvariant()}finally{$hasher.Dispose()}
        Check ($reply.ok -and $reply.data.hash -eq $hash -and $reply.data.line -eq $line) 'JSON stdin preserves Unicode command and secret bytes'
        $script:Pending.Process.Dispose(); $script:Pending=$null; $secret=$null
    }
    $path = Join-Path $temp 'events.php'
    $tail = [DigimonNxt.WorldConsole.FileTail]::new($path); $tails.Add($tail)
    Check ($tail.Read($false,4096).Lines.Count -eq 0) 'Missing initial journal is harmless'
    [IO.File]::WriteAllText($path, "<?php exit; ?>`nfirst`n", [Text.UTF8Encoding]::new($false))
    $batch = $tail.Read($false,4096)
    Check ($batch.Lines.Count -eq 1 -and $batch.Lines[0] -eq 'first') 'Journal created after attachment includes first event and skips PHP guard'
    [IO.File]::AppendAllText($path, 'partial', [Text.UTF8Encoding]::new($false))
    Check ($tail.Read($false,4096).Lines.Count -eq 0) 'Incomplete final record is retained'
    [IO.File]::AppendAllText($path, "-finished`n", [Text.UTF8Encoding]::new($false))
    Check ($tail.Read($false,4096).Lines[0] -eq 'partial-finished') 'Partial record completed once'
    $unicode = [Text.Encoding]::UTF8.GetBytes(([string][char]0x03A9 + [char]0x65E5 + "`n"))
    $stream = [IO.File]::Open($path,[IO.FileMode]::Append,[IO.FileAccess]::Write,[IO.FileShare]::ReadWrite)
    try { $stream.Write($unicode,0,1); $stream.Flush(); $null=$tail.Read($false,4096); $stream.Write($unicode,1,$unicode.Length-1); $stream.Flush() } finally { $stream.Dispose() }
    Check ($tail.Read($false,4096).Lines[0] -eq ([string][char]0x03A9 + [char]0x65E5)) 'UTF-8 character split across writes survives'
    $rows = @(); for($i=0;$i -lt 30;$i++){ $rows += "old-$i" }
    [IO.File]::AppendAllText($path, (($rows -join "`n") + "`n"), [Text.UTF8Encoding]::new($false))
    $seen = [Collections.Generic.List[string]]::new()
    foreach($row in $tail.Read($false,17).Lines){$seen.Add($row)}
    [IO.File]::Move($path,(Join-Path $temp 'events.1.php'))
    [IO.File]::WriteAllText($path,"new-file`n",[Text.UTF8Encoding]::new($false))
    for($i=0;$i -lt 40;$i++){foreach($row in $tail.Read($false,17).Lines){$seen.Add($row)}}
    Check ($seen.Count -eq 31 -and $seen[29] -eq 'old-29' -and $seen[30] -eq 'new-file') 'Rotation drains old file before new records with no loss'
    Reset-Feed
    for($i=0;$i -lt 100;$i++){Emit-Event ([pscustomobject]@{category='auth';event='login_failed';message='Sign-in failed';ip='127.0.0.1';account='guest'})}
    Check ($script:Output.Count -eq 1 -and $script:Groups.Count -eq 1 -and @($script:Groups.Values)[0].Count -eq 99) 'Repeated failed logins are summarized'
    Reset-Feed
    for($i=0;$i -lt 600;$i++){Emit-Event ([pscustomobject]@{category='bots';event=('action-'+$i);message='AI action';ip='local';account=('bot-'+$i)})}
    Check ($script:Groups.Count -le 251 -and $script:Output.Count -lt 600) 'Bot flood bounded in display and memory'
    Reset-Feed
    for($i=0;$i -lt 40;$i++){Emit-Event ([pscustomobject]@{category='bots';event='wild_battle';message=('Wild battle: bot-'+$i);ip='local';account=('bot-'+$i)})}
    $group=@($script:Groups.Values)[0]
    Check ($script:Output.Count -eq 1 -and $script:Output[0].Contains('bot-0') -and $group.Count -eq 39 -and $group.Account -eq 'AI tamers (grouped)' -and -not $group.Message.Contains('bot-0')) 'First bot event keeps identity; remaining grouped count excludes sample'
    Reset-Feed
    for($i=0;$i -lt 100;$i++){Emit-Event ([pscustomobject]@{category='request';event='request';path=('/test'+$i+'.php');method='GET';status=200;ip='127.0.0.1'})}
    Check ($script:Output.Count -le 16 -and $script:Groups.Count -gt 0) 'Full traffic mode bounded even with unique requests'
    Reset-Feed
    foreach($poll in @('world_notice','world_map_presence','tamer_pulse','_health','_client_manifest')){Emit-Event ([pscustomobject]@{category='request';path=('/DigimonNXT/'+$poll+'.php');method='GET';status=200;ip='127.0.0.1'})}
    Check ($script:Output.Count -eq 0 -and $script:Groups.Count -eq 5) 'Background polling never spams individual lines'
    $script:Paused=$true; $script:Filter='request'
    Emit-Event ([pscustomobject]@{category='admin';message='Account ban applied';ip='local';account='owner'})
    Check ($script:Output.Count -eq 1 -and $script:Output[0].Contains('Account ban applied')) 'Admin results bypass display pause and category filter'
    Reset-Feed
    Emit-Event ([pscustomobject]@{category='request';path='/game.php?password=NEVER_SHOW#x';method='GET';status=200;ip='127.0.0.1'})
    Check (-not ($script:Output -join '').Contains('NEVER_SHOW')) 'Request query values are never displayed'
    Reset-Feed
    Emit-Event ([pscustomobject]@{category='system';message='Recovery';journal_losses=3})
    Emit-Event ([pscustomobject]@{category='system';message='Recovery';journal_losses=3})
    Check (@($script:Output | Where-Object { $_ -like '*write failures*' }).Count -eq 1) 'Journal failure count warns once per increase'
    for($i=4;$i -lt 104;$i++){Emit-Event ([pscustomobject]@{category='system';message='Recovery';journal_losses=$i})}
    Check (@($script:Output | Where-Object { $_ -like '*write failures*' }).Count -eq 1) 'Growing journal failure counts cannot flood warnings'
    $script:BadRecords=8; $script:LastSummary=[DateTime]::UtcNow.AddSeconds(-16); Flush-Summaries
    Check (@($script:Output | Where-Object { $_ -like '*write failures*' }).Count -eq 2 -and @($script:Output | Where-Object { $_ -like '*unreadable journal records*' }).Count -eq 1) 'Journal losses and unreadable records receive bounded interval diagnostics'
    Reset-Feed
    $ApacheAccessLog=Join-Path $temp 'access.log'; $ApacheUrlPath='/DigimonNXT/'
    $script:ApacheEnabled=$true; $script:LastApacheError=''
    [IO.File]::WriteAllText($ApacheAccessLog,'')
    $script:Apache=[DigimonNxt.WorldConsole.FileTail]::new($ApacheAccessLog); $tails.Add($script:Apache)
    $null=$script:Apache.Read($false,4096)
    $apacheRows=@(
        '127.0.0.1 - - [22/Sep/2026:12:30:00 +0000] "GET /OtherMMO/sprite.png HTTP/1.1" 200 10',
        '127.0.0.1 - - [22/Sep/2026:12:30:00 +0000] "GET /DigimonNXT/login.php HTTP/1.1" 200 10',
        '127.0.0.1 - - [22/Sep/2026:12:30:00 +0000] "GET /DigimonNXT/sprite.png?secret=HIDDEN HTTP/1.1" 200 10',
        '127.0.0.1 - - [22/Sep/2026:12:30:00 +0000] "GET /DigimonNXT/missing.php HTTP/1.1" 404 10'
    )
    [IO.File]::AppendAllText($ApacheAccessLog,($apacheRows -join "`n")+"`n",[Text.UTF8Encoding]::new($false))
    Read-Apache
    $display=$script:Output -join "`n"
    Check ($script:Output.Count -eq 2 -and -not $display.Contains('OtherMMO') -and -not $display.Contains('login.php')) 'Apache isolation and successful dynamic duplicate suppression'
    Check (-not $display.Contains('HIDDEN') -and $display.Contains('unattributed') -and $display.Contains('missing.php')) 'Static IP never inferred as account; query removed; 404 retained'
    $script:LastSummary=[DateTime]::UtcNow.AddSeconds(-16)
    for($i=0;$i -lt 20;$i++){Emit-Event ([pscustomobject]@{category='bots';event=('action-'+($i%10));message='AI action';ip='local'})}
    $before=$script:Output.Count; Flush-Summaries
    Check (($script:Output.Count-$before) -le 6 -and $script:Groups.Count -eq 0) 'Summary lists at most four busiest groups and clears window counts'
    Write-Output ('PASS: '+$script:Checks+' console component checks. Native Windows UI and OS session guard remain unexecuted on non-Windows hosts.')
} finally {
    foreach($tail in $tails){$tail.Dispose()}
    Remove-Item -LiteralPath $temp -Recurse -Force
}
