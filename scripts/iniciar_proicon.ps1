# Servidor local con validación MIME nativa y protección de archivos adjuntos.
# Ejecutar desde PowerShell: .\scripts\iniciar_proicon.ps1
$ErrorActionPreference = 'Stop'
$extensionesProicon = & php -m
$opcionesProicon = @()
if ($extensionesProicon -notcontains 'fileinfo') { $opcionesProicon += @('-d', 'extension=fileinfo') }
$raizProicon = Split-Path -Parent $PSScriptRoot
& php @opcionesProicon -S localhost:8000 -t $raizProicon (Join-Path $PSScriptRoot 'servidor_local.php')
