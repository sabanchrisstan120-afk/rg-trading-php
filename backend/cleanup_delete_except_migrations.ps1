$t = Get-Date -Format "yyyyMMdd_HHmmss"
$backup = "C:\Users\granz\Downloads\rg-trading-backend-mysql-backup_$t.zip"
Compress-Archive -Path "C:\Users\granz\Downloads\rg-trading-backend-mysql\*" -DestinationPath $backup -Force
Write-Output "BACKUP_CREATED: $backup"
Set-Location "C:\Users\granz\Downloads\rg-trading-backend-mysql"
Get-ChildItem -Force | Where-Object { $_.Name -ne 'migrations' } | ForEach-Object {
    Write-Output "REMOVING: $($_.FullName)"
    Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction Stop
}
Write-Output "DELETION_COMPLETE"