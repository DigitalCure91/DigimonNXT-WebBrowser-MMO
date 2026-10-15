#requires -Version 5.1
<#
.SYNOPSIS
Install or manage Digimon NXT's always-on Windows tamer worker.
.DESCRIPTION
The scheduled task runs PHP directly from the actual served game folder. It
starts with Windows, runs without any logged-in player or Windows user, and
retries if XAMPP's database is not ready. No database or Windows password is
stored here. Database settings come from the served game's config/app.php.
.EXAMPLE
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\install_tamer_service.ps1 -GamePath "C:\xampp\htdocs\DigimonNXT"
.EXAMPLE
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\install_tamer_service.ps1 -Action Status -GamePath "D:\Games\DigimonNXT"
#>
[CmdletBinding()]
param(
    [ValidateSet('Install', 'Status', 'Start', 'Stop', 'Uninstall')]
    [string]$Action = 'Install',
    [string]$GamePath = 'C:\xampp\htdocs\DigimonNXT',
    [string]$PhpPath = 'C:\xampp\php\php.exe'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Start-Process joins ArgumentList into one native command line. Quote each
# value using Windows argv rules, never interpolate it into PowerShell code.
function ConvertTo-NativeArgument([string]$Value) {
    $quoted = [regex]::Replace($Value, '(\\*)"', '$1$1\"')
    $quoted = [regex]::Replace($quoted, '(\\+)$', '$1$1')
    return '"' + $quoted + '"'
}

function Get-AbsoluteLocalPath([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value) -or $Value.IndexOfAny([char[]]"`r`n`0") -ge 0) {
        throw 'Enter a valid absolute local Windows path.'
    }
    if ($Value -notmatch '^[A-Za-z]:[\\/]') {
        throw 'Use an absolute local drive path, such as C:\xampp\htdocs\DigimonNXT.'
    }
    $full = [System.IO.Path]::GetFullPath($Value)
    if ($full -notmatch '^[A-Za-z]:\\') {
        throw 'Use a local drive path. Network shares and mapped network drives are unsuitable for the SYSTEM worker.'
    }
    $drive = [System.IO.DriveInfo]::new([System.IO.Path]::GetPathRoot($full))
    if ($drive.DriveType -eq [System.IO.DriveType]::Network) {
        throw 'Use a local disk. The SYSTEM worker cannot use your user-mapped network drives.'
    }
    return $full.TrimEnd('\')
}

function Show-TamerTask([string]$Name, [string]$Root) {
    $task = Get-ScheduledTask -TaskName $Name -TaskPath '\' -ErrorAction SilentlyContinue
    Write-Host "Game folder: $Root"
    Write-Host "Task name:   $Name"
    if ($null -eq $task) {
        Write-Host 'Always-on worker: NOT INSTALLED. Run this script with -Action Install.'
        return
    }
    $info = Get-ScheduledTaskInfo -TaskName $Name -TaskPath '\'
    Write-Host "Task state:  $($task.State)"
    Write-Host "Last start:  $($info.LastRunTime)"
    Write-Host ('Last result: {0} (0 = stopped successfully; 267009 = currently running)' -f $info.LastTaskResult)
    foreach ($entry in $task.Actions) {
        Write-Host "PHP:         $($entry.Execute)"
        Write-Host "Arguments:   $($entry.Arguments)"
    }
    Write-Host "Worker log:  $(Join-Path $Root 'storage\logs\digimon-nxt.log')"
    Write-Host 'Check AI Activity in the game for completed actions and the worker heartbeat.'
    Write-Host 'A running task can be waiting for MySQL; inspect the worker log if activity has stopped.'
}

try {
    if ($env:OS -ne 'Windows_NT') { throw 'This installer requires Windows and Windows PowerShell 5.1 or later.' }
    $GamePath = Get-AbsoluteLocalPath $GamePath
    $PhpPath = Get-AbsoluteLocalPath $PhpPath

    # Elevation is needed to create/control a SYSTEM task, but not for a normal
    # status query. The batch launchers pass paths as -File arguments only.
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if ($Action -ne 'Status' -and -not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Write-Host 'Windows will request administrator approval to manage the background tamer task.'
        $elevationArguments = @('-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath,
            '-Action', $Action, '-GamePath', $GamePath, '-PhpPath', $PhpPath)
        $argumentLine = ($elevationArguments | ForEach-Object { ConvertTo-NativeArgument $_ }) -join ' '
        $windowsPowerShell = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
        $child = Start-Process -FilePath $windowsPowerShell -ArgumentList $argumentLine -Verb RunAs -Wait -PassThru
        if ($child.ExitCode -eq 0) {
            Write-Host "$Action completed for $GamePath."
            Write-Host 'Use tamer_service_status_windows.bat or AI Activity in the game to check progress.'
        } else {
            Write-Host 'The task operation failed. Run this script from an administrator PowerShell window to retain the full error output.' -ForegroundColor Red
        }
        exit $child.ExitCode
    }

    Import-Module ScheduledTasks -ErrorAction Stop
    # Normalized, case-insensitive path identity lets separate game installs
    # coexist without overwriting each other's task or database configuration.
    $hashProvider = [Security.Cryptography.SHA256]::Create()
    try {
        $pathBytes = [Text.Encoding]::UTF8.GetBytes($GamePath.ToLowerInvariant())
        $pathHash = ([BitConverter]::ToString($hashProvider.ComputeHash($pathBytes))).Replace('-', '').Substring(0, 16).ToLowerInvariant()
    } finally { $hashProvider.Dispose() }
    $taskName = 'DigimonNXT-Tamers-' + $pathHash

    if ($Action -eq 'Status') { Show-TamerTask $taskName $GamePath; exit 0 }
    $existing = Get-ScheduledTask -TaskName $taskName -TaskPath '\' -ErrorAction SilentlyContinue

    if ($Action -eq 'Stop') {
        if ($null -eq $existing) { throw 'No tamer task is installed for this game folder.' }
        Disable-ScheduledTask -TaskName $taskName -TaskPath '\' | Out-Null
        Stop-ScheduledTask -TaskName $taskName -TaskPath '\'
        Write-Host 'The tamer worker is stopped and disabled. Use -Action Start to resume it.'
        exit 0
    }
    if ($Action -eq 'Uninstall') {
        if ($null -ne $existing) {
            Disable-ScheduledTask -TaskName $taskName -TaskPath '\' | Out-Null
            Stop-ScheduledTask -TaskName $taskName -TaskPath '\'
            Unregister-ScheduledTask -TaskName $taskName -TaskPath '\' -Confirm:$false
        }
        Write-Host "Removed task $taskName. Game files, configuration, players, and bot progress are preserved."
        exit 0
    }

    $workerPath = Join-Path $GamePath 'includes\tamer_worker.php'
    if (-not (Test-Path -LiteralPath $GamePath -PathType Container)) { throw "Game folder not found: $GamePath" }
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP was not found: $PhpPath. Supply -PhpPath with your XAMPP php.exe path." }
    if (-not (Test-Path -LiteralPath $workerPath -PathType Leaf)) {
        throw "The served game has no always-on worker: $workerPath. Copy the updated public_html contents into the game folder first."
    }
    foreach ($required in @('config\app.php', 'includes\bootstrap.php', 'includes\bots.php')) {
        if (-not (Test-Path -LiteralPath (Join-Path $GamePath $required) -PathType Leaf)) {
            throw "The served game is incomplete: $required is missing. GamePath must be the folder containing index.php and config, not the extracted source root."
        }
    }
    $checkOutput = @(& $PhpPath $workerPath --check 2>&1)
    if ($LASTEXITCODE -ne 0) { throw "Worker validation failed. $($checkOutput -join [Environment]::NewLine)" }
    if (($checkOutput -join [Environment]::NewLine).Trim() -ne 'Digimon NXT persistent tamer worker v1 ready') {
        throw 'The served worker did not return the supported validation response. Copy the complete update into GamePath before installing the task.'
    }

    if ($Action -eq 'Start') {
        if ($null -eq $existing) { throw 'No tamer task is installed for this game folder. Run -Action Install first.' }
        Enable-ScheduledTask -TaskName $taskName -TaskPath '\' | Out-Null
        Start-ScheduledTask -TaskName $taskName -TaskPath '\'
        Show-TamerTask $taskName $GamePath
        exit 0
    }

    $workerArguments = @($workerPath, '--batch=40', '--interval=5')
    $workerArgumentLine = ($workerArguments | ForEach-Object { ConvertTo-NativeArgument $_ }) -join ' '
    $taskAction = New-ScheduledTaskAction -Execute $PhpPath -Argument $workerArgumentLine -WorkingDirectory $GamePath
    $startup = New-ScheduledTaskTrigger -AtStartup
    # IgnoreNew makes this a recovery check, not a second running worker.
    # Omit RepetitionDuration so the check repeats indefinitely.
    $recovery = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
    $taskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) `
        -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Priority 7
    $taskPrincipal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $description = "Digimon NXT autonomous tamers for $GamePath. Uses this game's config/app.php. Runs without browser or player sessions."
    if ($null -ne $existing -and $existing.State -eq 'Running') {
        Disable-ScheduledTask -TaskName $taskName -TaskPath '\' | Out-Null
        Stop-ScheduledTask -TaskName $taskName -TaskPath '\'
        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            $stopping = Get-ScheduledTask -TaskName $taskName -TaskPath '\'
            if ($stopping.State -ne 'Running') { break }
            Start-Sleep -Milliseconds 250
        }
        if ($stopping.State -eq 'Running') {
            throw 'The existing worker has not stopped yet. Wait a moment and run Install again.'
        }
    }
    Register-ScheduledTask -TaskName $taskName -TaskPath '\' -Action $taskAction -Trigger @($startup, $recovery) `
        -Settings $taskSettings -Principal $taskPrincipal -Description $description -Force | Out-Null
    Enable-ScheduledTask -TaskName $taskName -TaskPath '\' | Out-Null
    Start-ScheduledTask -TaskName $taskName -TaskPath '\'
    Write-Host 'The always-on tamer task is installed and has been started.' -ForegroundColor Green
    Write-Host 'You can close this window and all browser tabs. No player login is required.'
    Write-Host 'Keep the computer awake and MySQL running. The worker retries while MySQL is unavailable.'
    Write-Host 'For operation after a reboot, also configure XAMPP MySQL to run as a Windows service.'
    Show-TamerTask $taskName $GamePath
    Write-Host 'Manage this same installation using -Action Status, Start, Stop, or Uninstall with the same -GamePath.'
    exit 0
} catch {
    Write-Host ('Tamer task setup failed: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
