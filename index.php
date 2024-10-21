<?php
declare(strict_types=1); // Activar el tipado estricto

error_reporting(E_ERROR | E_WARNING | E_PARSE);

// Reemplazamos PclZip con ZipArchive
require('lib/pclzip.lib.php');

// Validar que los parámetros requeridos existan
if (!isset($_GET['cod_6'], $_GET['cod_7'], $_GET['numero_documento'])) {
    die(json_encode(['success' => false, 'error' => 'Faltan parámetros requeridos.']));
}

// Asignamos los parámetros
$serie = $_GET['cod_7'];
$NomArch = $_GET['numero_documento'];

// Determinamos la acción según el valor de cod_6
switch ($_GET['cod_6']) {
    case 1:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'sendBill'; // Hacer facturas o boletas
        break;
    case 2:
        $ruta = '../files/facturacion_electronica/BAJA/FIRMA/';
        $metodo = 'sendSummary'; // Enviar anulación
        break;
    case 3:
        $ruta = '../files/facturacion_electronica/BAJA/RPTA/';
        $metodo = 'getStatus'; // Recibir ticket de anulación
        break;
    case 4:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'getStatusCdr'; // Preguntar estado CDR
        break;
    default:
        die(json_encode(['success' => false, 'error' => 'Acción no válida para cod_6']));
}

// Si estamos enviando documento o anulando, creamos el ZIP
if (in_array($_GET['cod_6'], [1, 2])) {
    // Creamos el archivo ZIP con ZipArchive
    $zip = new ZipArchive();
    $zipFile = $ruta . $NomArch . '.zip';

    if (!file_exists($zipFile)) {
        if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
            $zip->addFile($ruta . $NomArch . '.xml', basename($NomArch . '.xml'));
            $zip->close();
            chmod($zipFile, 0777);
        } else {
            die(json_encode(['success' => false, 'error' => 'Error al crear el archivo ZIP.']));
        }
    }
}

// Construimos el contenido del XML a enviar a SUNAT
switch ($_GET['cod_6']) {
    case 1:
    case 2:
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($ruta . $NomArch . '.zip')) . '</contentFile>';
        break;
    case 3:
        $content = '<ticket>' . $_GET['cod_8'] . '</ticket>';
        break;
    case 4:
        $documento = explode("-", $_GET['numero_documento']);
        $content = '<rucComprobante>' . $documento[0] . '</rucComprobante>
                    <tipoComprobante>' . $documento[1] . '</tipoComprobante>
                    <serieComprobante>' . $documento[2] . '</serieComprobante>
                    <numeroComprobante>' . $documento[3] . '</numeroComprobante>';
        break;
}

// Clase personalizada para la llamada SOAP
class feedSoap extends SoapClient {
    public string $XMLStr = "";

    public function setXMLStr(string $value): void {
        $this->XMLStr = $value;
    }

    public function getXMLStr(): string {
        return $this->XMLStr;
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $one_way = false): string {
        $request = $this->XMLStr;
        $dom = new DOMDocument('1.0');
        try {
            $dom->loadXML($request);
        } catch (DOMException $e) {
            die($e->code);
        }
        $request = $dom->saveXML();
        return parent::__doRequest($request, $location, $action, $version, $one_way);
    }

    public function SoapClientCall(string $SOAPXML): void {
        $this->setXMLStr($SOAPXML);
    }
}

// Llamada SOAP a SUNAT
function soapCall(string $wsdlURL, string $callFunction, string $XMLString): string {
    $client = new feedSoap($wsdlURL, array('trace' => true));
    $client->SoapClientCall($XMLString);
    $client->__call($callFunction, array(), array());
    return $client->__getLastResponse();
}

// Selección de entorno (beta o producción)
switch ($_GET['cod_2']) {
    case 0:
        $wsdlURL = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
        break;
    case 1:
        $wsdlURL = 'billService.wsdl'; // Actualiza esto según sea necesario
        break;
    default:
        die(json_encode(['success' => false, 'error' => 'Entorno no válido para cod_2']));
}

// Estructura del XML para la conexión
$XMLString = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
 <soapenv:Header>
     <wsse:Security>
         <wsse:UsernameToken>
             <wsse:Username>' . $_GET['cod_3'] . $_GET['cod_4'] . '</wsse:Username>
             <wsse:Password>' . $_GET['cod_5'] . '</wsse:Password>
         </wsse:UsernameToken>
     </wsse:Security>
 </soapenv:Header>
 <soapenv:Body>
     <ser:' . $metodo . '>' . $content . '</ser:' . $metodo . '>
 </soapenv:Body>
</soapenv:Envelope>';

// Ejecutamos la llamada SOAP
$ruta_cdr = '../files/facturacion_electronica/CDR/';
$result = soapCall($wsdlURL, $metodo, $XMLString);

// Guardamos la respuesta y procesamos los archivos recibidos
descargarRespone($NomArch, $result, $ruta_cdr);
$response = leerXmlResponse($NomArch, $ruta_cdr);
descargarCDR_ZIP($NomArch, $response, $ruta_cdr);
obtenerCDR_XML($NomArch, $ruta_cdr);
$respuesta = leerCDR_XML($NomArch, $ruta, $ruta_cdr);
elminarArchivos($NomArch, $ruta, $ruta_cdr);

echo json_encode($respuesta);
exit;

// Funciones auxiliares
function descargarRespone(string $NomArch, string $result, string $ruta_cdr): void {
    file_put_contents($ruta_cdr . 'C' . $NomArch . '.xml', $result);
}

function leerXmlResponse(string $NomArch, string $ruta_cdr): string {
    $xml = simplexml_load_file($ruta_cdr . 'C' . $NomArch . '.xml');
    foreach ($xml->xpath('//applicationResponse') as $response) {
        return (string) $response;
    }
    return '';
}

function descargarCDR_ZIP(string $NomArch, string $response, string $ruta_cdr): void {
    $cdr = base64_decode($response);
    file_put_contents($ruta_cdr . 'R-' . $NomArch . '.zip', $cdr);
    chmod($ruta_cdr . 'R-' . $NomArch . '.zip', 0777);
}

function obtenerCDR_XML(string $NomArch, string $ruta_cdr): void {
    $zip = new ZipArchive();
    if ($zip->open($ruta_cdr . 'R-' . $NomArch . '.zip') === TRUE) {
        $zip->extractTo($ruta_cdr);
        $zip->close();
    } else {
        die("Error al extraer el archivo ZIP.");
    }
}

function leerCDR_XML(string $NomArch, string $ruta, string $ruta_cdr): array {
    $ruta_general = carpeta_actual() . "/files/facturacion_electronica/";
    if (file_exists($ruta_cdr . 'R-' . $NomArch . '.xml')) {
        $library = new SimpleXMLElement($ruta_cdr . 'R-' . $NomArch . '.xml', 0, true);
        $ns = $library->getDocNamespaces();
        $ext1 = $library->children($ns['cac']);
        $ext2 = $ext1->DocumentResponse;
        $ext3 = $ext2->children($ns['cac']);
        $ext4 = $ext3->children($ns['cbc']);

        $codigo_hash = getFirma($NomArch, $ruta);
        $xml_base_64 = base64_encode(file_get_contents($ruta . $NomArch . '.zip'));
        $cdr_base_64 = base64_encode(file_get_contents($ruta_cdr . 'R-' . $NomArch . '.zip'));

        return [
            'respuesta_sunat_codigo' => trim((string) $ext4->ResponseCode),
            'respuesta_sunat_descripcion' => trim((string) $ext4->Description),
            'ruta_xml' => $ruta_general . 'FIRMA/' . $NomArch . '.xml',
            'ruta_cdr' => $ruta_general . 'CDR/R-' . $NomArch . '.xml',
            'ruta_pdf' => $ruta_general . 'PDF/' . $NomArch . '.pdf',
            'codigo_hash' => $codigo_hash,
            'xml_base_64' => $xml_base_64,
            'cdr_base_64' => $cdr_base_64
        ];
    }
    return [];
}

function getFirma(string $NomArch, string $ruta): string {
    $xml = simplexml_load_file($ruta . $NomArch . '.xml');
    foreach ($xml->xpath('//ds:DigestValue') as $response) {
        return (string) $response;
    }
    return '';
}

function elminarArchivos(string $NomArch, string $ruta, string $ruta_cdr): void {
    unlink($ruta_cdr . 'C' . $NomArch . '.xml');
    unlink($ruta_cdr . 'R-' . $NomArch . '.zip');
    unlink($ruta . $NomArch . '.zip');
}

function carpeta_actual(): string {
    $archivo_actual = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
    $dir = explode('/', $archivo_actual);
    $cadena = '';
    for ($i = 0; $i < (count($dir) - 2); $i++) {
        $cadena .= $dir[$i] . "/";
    }
    return substr($cadena, 0, -1);
}
