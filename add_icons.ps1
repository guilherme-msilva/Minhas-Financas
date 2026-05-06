$files = Get-ChildItem -Filter *.php
$iconTags = "`n    <link rel=`"icon`" type=`"image/x-icon`" href=`"icon.ico`">`n    <link rel=`"icon`" type=`"image/png`" href=`"icon.png`">`n</head>"

foreach ($file in $files) {
    $name = $file.Name
    if ($name -eq "conexao.php" -or $name -eq "menu.php" -or $name -eq "logout.php" -or $name -eq "api_importacao.php") {
        continue
    }

    $content = Get-Content $file.FullName -Raw
    if ($content -match "</head>" -and $content -notmatch "icon\.png") {
        $content = $content -replace "</head>", $iconTags
        Set-Content -Path $file.FullName -Value $content -Encoding UTF8
        Write-Host "Updated $name"
    }
}
