# Entrada oculta; no guarda claves en archivos, argumentos del proceso ni historial.
$ErrorActionPreference = 'Stop'
$clavesProicon = @{}
$salidaAnteriorProicon = $OutputEncoding
try {
    $OutputEncoding = [System.Text.UTF8Encoding]::new($false)
    foreach ($usuarioProicon in @('pxicara', 'cserrano', 'amorales')) {
        $secretoProicon = Read-Host "Clave actual de $usuarioProicon" -AsSecureString
        $punteroProicon = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secretoProicon)
        try { $clavesProicon[$usuarioProicon] = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($punteroProicon) }
        finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($punteroProicon); $secretoProicon.Dispose() }
    }
    $clavesProicon | ConvertTo-Json -Compress | & php (Join-Path $PSScriptRoot '../tests/pruebas_contrasenas.php') --credenciales-stdin --solo-verificar
    $resultadoProicon = $LASTEXITCODE
} finally {
    $clavesProicon.Clear()
    $OutputEncoding = $salidaAnteriorProicon
}
exit $resultadoProicon
