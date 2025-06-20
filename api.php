<?php
// api.php - Backend central que maneja configuración, solicitud, verificación, historial y descarga de CFDI vía SAT

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\Fiel;
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\FielRequestBuilder;
use PhpCfdi\SatWsDescargaMasiva\Service;
use PhpCfdi\SatWsDescargaMasiva\WebClient\GuzzleWebClient;
use PhpCfdi\SatWsDescargaMasiva\Services\Query\QueryParameters;
use PhpCfdi\SatWsDescargaMasiva\Shared\DateTimePeriod;
use PhpCfdi\SatWsDescargaMasiva\Shared\RequestType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DownloadType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentStatus;

// Configuración inicial
date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');

$solicitudesBaseDir = __DIR__ . '/solicitudes';
$descargasBaseDir   = __DIR__ . '/descargas';
if (!is_dir($solicitudesBaseDir)) mkdir($solicitudesBaseDir, 0755, true);
if (!is_dir($descargasBaseDir))   mkdir($descargasBaseDir, 0755, true);

session_start();

// Helpers
function respuesta_error(string $msg): void {
    echo json_encode(['success'=>false,'message'=>$msg]);
    exit;
}
function respuesta_ok(array $data=[]): void {
    echo json_encode(array_merge(['success'=>true],$data));
    exit;
}
function getFielFromRequest(array $cerFile, array $keyFile, string $password) {
    if (!isset($cerFile['tmp_name'],$keyFile['tmp_name'])||$password==='') {
        respuesta_error('Archivos .cer, .key y contraseña requeridos.');
    }
    if ($cerFile['error']!==UPLOAD_ERR_OK) respuesta_error('Error al subir CER');
    if ($keyFile['error']!==UPLOAD_ERR_OK) respuesta_error('Error al subir KEY');
    try {
        $fiel = Fiel::create(
            file_get_contents($cerFile['tmp_name']),
            file_get_contents($keyFile['tmp_name']),
            $password
        );
        if (!$fiel->isValid()) respuesta_error('FIEL inválida o contraseña incorrecta.');
        return $fiel;
    } catch (\Throwable $e) {
        respuesta_error('Error al validar FIEL: '.$e->getMessage());
    }
}
function loadRequestData(string $rfc, string $requestId): array {
    global $solicitudesBaseDir;
    $dir = "{$solicitudesBaseDir}/{$rfc}";
    if (!is_dir($dir)) return [];
    foreach (scandir($dir) as $file) {
        if (str_ends_with($file,'.json')) {
            $data = json_decode(file_get_contents("{$dir}/{$file}"), true);
            if (($data['request_id'] ?? '') === $requestId) {
                return $data;
            }
        }
    }
    return [];
}

// Nueva función para obtener todas las solicitudes de un RFC
function getAllRequestsForRfc(string $rfc): array {
    global $solicitudesBaseDir;
    $dir = "{$solicitudesBaseDir}/{$rfc}";
    $requests = [];
    
    if (!is_dir($dir)) return $requests;
    
    $files = scandir($dir);
    foreach ($files as $file) {
        if (str_ends_with($file, '.json')) {
            $filePath = "{$dir}/{$file}";
            $data = json_decode(file_get_contents($filePath), true);
            if (is_array($data) && isset($data['request_id'])) {
                $requests[] = $data;
            }
        }
    }
    
    // Ordenar por fecha de solicitud (más recientes primero)
    usort($requests, function($a, $b) {
        $dateA = $a['fecha_solicitud'] ?? '';
        $dateB = $b['fecha_solicitud'] ?? '';
        return strcmp($dateB, $dateA);
    });
    
    return $requests;
}

function saveRequestData(string $rfc, string $requestId, array $data): void {
    global $solicitudesBaseDir;
    $dir = "{$solicitudesBaseDir}/{$rfc}";
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $path = "{$dir}/{$requestId}.json";
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
        or respuesta_error('No se pudo guardar la solicitud.');
}

// Función para determinar el estado basado en el StatusRequest
function determineEstado($statusRequest, $packages): string {
    if ($statusRequest->isExpired()) {
        return 'expirado';
    }
    if ($statusRequest->isFailure()) {
        return 'fallido';
    }
    if ($statusRequest->isRejected()) {
        return 'rechazado';
    }
    if ($statusRequest->isInProgress() || $statusRequest->isAccepted()) {
        return 'procesando';
    }
    if ($statusRequest->isFinished()) {
        return !empty($packages) ? 'listo' : 'terminado_sin_datos';
    }
    return 'desconocido';
}

// Procesar acción
$action = $_POST['action'] ?? '';
try {
    switch ($action) {

        case 'guardarConfig':
            $fiel = getFielFromRequest($_FILES['cerfile'],$_FILES['keyfile'],$_POST['password']??'');
            $_SESSION['cer']      = file_get_contents($_FILES['cerfile']['tmp_name']);
            $_SESSION['key']      = file_get_contents($_FILES['keyfile']['tmp_name']);
            $_SESSION['password'] = $_POST['password'];
            respuesta_ok(['rfc'=>$fiel->getRfc()]);

        case 'listarSolicitudes':
            $fiel    = getFielFromRequest($_FILES['cerfile'],$_FILES['keyfile'],$_POST['password']??'');
            $rfc     = $fiel->getRfc();
            
            // Obtener todas las solicitudes del RFC desde el filesystem SIN verificar automáticamente
            $allRequests = getAllRequestsForRfc($rfc);
            $out = [];
            
            foreach ($allRequests as $meta) {
                $reqId = $meta['request_id'] ?? '';
                if ($reqId === '') continue;
                
                // Solo usar datos almacenados, no verificar automáticamente para mejorar rendimiento
                $out[] = [
                    'requestId'       => $reqId,
                    'estado'          => $meta['estado'] ?? 'pendiente',
                    'fecha_solicitud' => $meta['fecha_solicitud'] ?? '',
                    'fecha_inicio'    => $meta['fecha_inicio']    ?? '',
                    'fecha_fin'       => $meta['fecha_fin']       ?? '',
                    'formato'         => $meta['formato']         ?? '',
                    'status'          => $meta['status']          ?? '',
                    'paquetes'        => $meta['paquetes'] ?? [],
                    'verifications'   => $meta['verifications']   ?? [],
                ];
            }
            
            respuesta_ok(['solicitudes'=>$out]);

        case 'solicitar':
            $fiel        = getFielFromRequest($_FILES['cerfile'],$_FILES['keyfile'],$_POST['password']??'');
            $rfc         = $fiel->getRfc();
            $tipo        = $_POST['tipo']    ?? '';
            $format      = $_POST['format']  ?? 'xml';
            $statusFilter= $_POST['status']  ?? 'active';
            $fechaI      = $_POST['fechaInicio'] ?? '';
            $fechaF      = $_POST['fechaFin']    ?? '';
            if ($tipo===''||$fechaI===''||$fechaF==='') {
                respuesta_error('Faltan datos para la solicitud.');
            }
            $builder      = new FielRequestBuilder($fiel);
            $client       = new GuzzleWebClient();
            $service      = new Service($builder,$client);
            $downloadType = strtolower($tipo)==='emitidos'  ? DownloadType::issued()   : DownloadType::received();
            $requestType  = strtolower($format)==='xml'      ? RequestType::xml()      : RequestType::metadata();
            // DocumentStatus
            $docStatus = DocumentStatus::undefined();
            if ($format==='xml') {
                $docStatus = DocumentStatus::active();
            } else {
                if ($statusFilter==='active')    $docStatus = DocumentStatus::active();
                elseif ($statusFilter==='cancelled') $docStatus = DocumentStatus::cancelled();
            }
            $periodo = DateTimePeriod::createFromValues("{$fechaI} 00:00:00","{$fechaF} 23:59:59");
            $params  = QueryParameters::create($periodo)
                        ->withDownloadType($downloadType)
                        ->withRequestType($requestType)
                        ->withDocumentStatus($docStatus);
            $qr = $service->query($params);
            if (!$qr->getStatus()->isAccepted()) {
                respuesta_error('Error de consulta: '.$qr->getStatus()->getMessage());
            }
            $reqId = $qr->getRequestId();
            
            // Guardar en session para compatibilidad (opcional)
            if (!isset($_SESSION['request_ids'])) {
                $_SESSION['request_ids'] = [];
            }
            $_SESSION['request_ids'][] = $reqId;
            
            saveRequestData($rfc,$reqId,[
                'request_id'      => $reqId,
                'fecha_solicitud' => date('Y-m-d H:i:s'),
                'estado'          => 'pendiente',
                'paquetes'        => [],
                'tipo'            => strtolower($tipo),
                'formato'         => strtolower($format),
                'status'          => $statusFilter,
                'fecha_inicio'    => $fechaI,
                'fecha_fin'       => $fechaF,
                'verifications'   => []
            ]);
            respuesta_ok(['requestId'=>$reqId]);

        case 'verificar':
            $fiel      = getFielFromRequest($_FILES['cerfile'],$_FILES['keyfile'],$_POST['password']??'');
            $rfc       = $fiel->getRfc();
            $reqId     = $_POST['requestId'] ?? '';
            if ($reqId==='') respuesta_error('Falta requestId.');
            
            $service   = new Service(new FielRequestBuilder($fiel), new GuzzleWebClient());
            $vr        = $service->verify($reqId);
            
            // Verificar que el proceso de verificación fue correcto
            if (!$vr->getStatus()->isAccepted()) {
                // Actualizar estado a error
                $meta = loadRequestData($rfc, $reqId);
                $meta['estado'] = 'error';
                $entry = [
                    'timestamp'    => date('Y-m-d H:i:s'),
                    'codigoEstado' => $vr->getStatus()->getCode(),
                    'mensaje'      => 'Fallo al verificar: ' . $vr->getStatus()->getMessage(),
                    'paquetes'     => [],
                    'isFinished'   => false
                ];
                if (!isset($meta['verifications'])) $meta['verifications'] = [];
                $meta['verifications'][] = $entry;
                saveRequestData($rfc, $reqId, $meta);
                
                respuesta_ok([
                    'requestId'    => $reqId,
                    'estado'       => 'error',
                    'paquetes'     => [],
                    'codigoEstado' => $vr->getStatus()->getCode(),
                    'mensaje'      => 'Fallo al verificar: ' . $vr->getStatus()->getMessage(),
                    'isFinished'   => false
                ]);
            }
            
            // Verificar que la consulta no haya sido rechazada
            if (!$vr->getCodeRequest()->isAccepted()) {
                $meta = loadRequestData($rfc, $reqId);
                $meta['estado'] = 'rechazado';
                $entry = [
                    'timestamp'    => date('Y-m-d H:i:s'),
                    'codigoEstado' => $vr->getCodeRequest()->getCode(),
                    'mensaje'      => 'Solicitud rechazada: ' . $vr->getCodeRequest()->getMessage(),
                    'paquetes'     => [],
                    'isFinished'   => true
                ];
                if (!isset($meta['verifications'])) $meta['verifications'] = [];
                $meta['verifications'][] = $entry;
                saveRequestData($rfc, $reqId, $meta);
                
                respuesta_ok([
                    'requestId'    => $reqId,
                    'estado'       => 'rechazado',
                    'paquetes'     => [],
                    'codigoEstado' => $vr->getCodeRequest()->getCode(),
                    'mensaje'      => 'Solicitud rechazada: ' . $vr->getCodeRequest()->getMessage(),
                    'isFinished'   => true
                ]);
            }
            
            $stReq     = $vr->getStatusRequest();
            $packages  = is_array($vr->getPackagesIds()) ? $vr->getPackagesIds() : [];
            $isFinished= $stReq->isFinished();
            
            // Usar la nueva función para determinar el estado
            $estado = determineEstado($stReq, $packages);
            
            // Crear mensaje más descriptivo
            $mensaje = '';
            if ($stReq->isExpired()) {
                $mensaje = 'La solicitud ha expirado';
            } elseif ($stReq->isFailure()) {
                $mensaje = 'La solicitud falló durante el procesamiento';
            } elseif ($stReq->isRejected()) {
                $mensaje = 'La solicitud fue rechazada';
            } elseif ($stReq->isInProgress()) {
                $mensaje = 'La solicitud se está procesando';
            } elseif ($stReq->isAccepted()) {
                $mensaje = 'La solicitud fue aceptada y está en proceso';
            } elseif ($stReq->isFinished()) {
                if (!empty($packages)) {
                    $mensaje = 'La solicitud está lista. ' . count($packages) . ' paquete(s) disponible(s)';
                } else {
                    $mensaje = 'La solicitud terminó pero no generó paquetes';
                }
            } else {
                $mensaje = 'Estado desconocido';
            }
            
            // Actualizar historial
            $meta = loadRequestData($rfc, $reqId);
            $entry = [
                'timestamp'    => date('Y-m-d H:i:s'),
                'codigoEstado' => $vr->getStatus()->getCode(),
                'mensaje'      => $mensaje,
                'paquetes'     => $packages,
                'isFinished'   => $isFinished
            ];
            if (!isset($meta['verifications']) || !is_array($meta['verifications'])) {
                $meta['verifications'] = [];
            }
            $meta['verifications'][] = $entry;
            $meta['estado']   = $estado;
            $meta['paquetes'] = $packages;
            saveRequestData($rfc, $reqId, $meta);
            
            respuesta_ok([
                'requestId'   => $reqId,
                'estado'      => $estado,
                'paquetes'    => $packages,
                'codigoEstado'=> $vr->getStatus()->getCode(),
                'mensaje'     => $mensaje,
                'isFinished'  => $isFinished
            ]);

        case 'descargar':
            $fiel      = getFielFromRequest($_FILES['cerfile'],$_FILES['keyfile'],$_POST['password']??'');
            $rfc       = $fiel->getRfc();
            $reqId     = $_POST['requestId']   ?? '';
            $pkgId     = $_POST['packageId']   ?? '';
            if ($reqId===''||$pkgId==='') respuesta_error('Faltan requestId o packageId.');
            
            // Usar Service::download() en lugar de DownloadService
            $service   = new Service(new FielRequestBuilder($fiel), new GuzzleWebClient());
            $dr        = $service->download($pkgId);
            
            if (!$dr->getStatus()->isAccepted()) {
                respuesta_error('Error en descarga: '.$dr->getStatus()->getMessage());
            }
            
            $zipData   = $dr->getPackageContent();
            if (empty($zipData)) {
                respuesta_error('El paquete descargado está vacío');
            }
            
            // Crear directorio de descargas si no existe
            $rfcDir = "{$descargasBaseDir}/{$rfc}";
            if (!is_dir($rfcDir)) mkdir($rfcDir, 0755, true);
            
            $zipPath   = "{$rfcDir}/{$reqId}_{$pkgId}.zip";
            
            if (file_put_contents($zipPath, $zipData) === false) {
                respuesta_error('No se pudo escribir el archivo ZIP.');
            }
            
            // Registrar la descarga en el historial
            $meta = loadRequestData($rfc, $reqId);
            if (!isset($meta['downloads'])) {
                $meta['downloads'] = [];
            }
            $meta['downloads'][] = [
                'packageId' => $pkgId,
                'timestamp' => date('Y-m-d H:i:s'),
                'filename'  => basename($zipPath),
                'size'      => filesize($zipPath)
            ];
            saveRequestData($rfc, $reqId, $meta);
            
            // Enviar archivo
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.basename($zipPath).'"');
            header('Content-Length: '.filesize($zipPath));
            readfile($zipPath);
            exit;

        case 'leerPaquete':
            $fiel      = getFielFromRequest($_FILES['cerfile'],$_FILES['keyfile'],$_POST['password']??'');
            $rfc       = $fiel->getRfc();
            $reqId     = $_POST['requestId']   ?? '';
            $pkgId     = $_POST['packageId']   ?? '';
            if ($reqId===''||$pkgId==='') respuesta_error('Faltan requestId o packageId.');
            
            $zipPath = "{$descargasBaseDir}/{$rfc}/{$reqId}_{$pkgId}.zip";
            if (!file_exists($zipPath)) {
                respuesta_error('El archivo ZIP no existe. Descárgalo primero.');
            }
            
            // Determinar el tipo de paquete basado en los metadatos de la solicitud
            $meta = loadRequestData($rfc, $reqId);
            $formato = $meta['formato'] ?? 'xml';
            
            try {
                if ($formato === 'metadata') {
                    // Leer paquete de metadata
                    $reader = \PhpCfdi\SatWsDescargaMasiva\PackageReader\MetadataPackageReader::createFromFile($zipPath);
                    $contenido = [];
                    foreach ($reader->metadata() as $uuid => $metadata) {
                        $contenido[] = [
                            'uuid' => $uuid,
                            'fechaEmision' => $metadata->fechaEmision,
                            'rfcEmisor' => $metadata->rfcEmisor,
                            'nombreEmisor' => $metadata->nombreEmisor ?? '',
                            'rfcReceptor' => $metadata->rfcReceptor,
                            'nombreReceptor' => $metadata->nombreReceptor ?? '',
                            'pacId' => $metadata->pacId ?? '',
                            'total' => $metadata->total ?? 0,
                            'efectoComprobante' => $metadata->efectoComprobante ?? '',
                            'estatusComprobante' => $metadata->estatusComprobante ?? ''
                        ];
                    }
                    respuesta_ok(['tipo' => 'metadata', 'registros' => count($contenido), 'contenido' => $contenido]);
                } else {
                    // Leer paquete de CFDI
                    $reader = \PhpCfdi\SatWsDescargaMasiva\PackageReader\CfdiPackageReader::createFromFile($zipPath);
                    $contenido = [];
                    $contador = 0;
                    foreach ($reader->cfdis() as $uuid => $xmlContent) {
                        $contenido[] = [
                            'uuid' => $uuid,
                            'tamano' => strlen($xmlContent),
                            'preview' => substr(strip_tags($xmlContent), 0, 200) . '...'
                        ];
                        $contador++;
                        // Limitar para evitar respuestas muy grandes
                        if ($contador >= 100) {
                            break;
                        }
                    }
                    respuesta_ok(['tipo' => 'cfdi', 'registros' => $contador, 'contenido' => $contenido]);
                }
            } catch (\Exception $e) {
                respuesta_error('Error al leer el paquete: ' . $e->getMessage());
            }

        default:
            respuesta_error("Acción '{$action}' no válida.");
    }
} catch (\Throwable $e) {
    respuesta_error('Error interno: '.$e->getMessage());
}