<?php
header('Content-Type: text/html; charset=UTF-8');

$wsdlURL    = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
$ruta       = '../files/facturacion_electronica/FIRMA/';
$NomArch    = $_GET['name_file'] ?? '';

if (empty($NomArch)) {
    die(json_encode(['success' => false, 'message' => 'Nombre de archivo no proporcionado.']));
}

## =============================================================================
## Creación del archivo .ZIP utilizando ZipArchive
$zip = new ZipArchive();
$zipFilePath = $ruta . $NomArch . ".zip";
$xmlFilePath = $ruta . $NomArch . ".xml";

if (file_exists($zipFilePath)) {
    $r = 1; // El archivo ZIP ya existe
} else {
    if ($zip->open($zipFilePath, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($xmlFilePath, basename($xmlFilePath)); // Añadir el archivo XML al ZIP
        $zip->close();
    } else {
        die(json_encode(['success' => false, 'message' => 'Error al crear el archivo ZIP.']));
    }
}
chmod($zipFilePath, 0777); // Cambiar permisos del archivo ZIP

# ==============================================================================

# Procedimiento para enviar comprobante a la SUNAT

class feedSoap extends SoapClient {

    public $XMLStr = "";

    public function setXMLStr($value) {
        $this->XMLStr = $value;
    }

    public function getXMLStr() {
        return $this->XMLStr;
    }

    public function __doRequest($request, $location, $action, $version, $one_way = 0) {
        $request = $this->XMLStr;
        $dom = new DOMDocument('1.0', 'UTF-8'); // Codificación UTF-8

        if (!$dom->loadXML($request)) {
            throw new Exception('Error al cargar el XML.');
        }

        $request = $dom->saveXML();
        return parent::__doRequest($request, $location, $action, $version, $one_way);
    }

    public function SoapClientCall($SOAPXML) {
        $this->setXMLStr($SOAPXML);
    }
}

function soapCall($wsdlURL, $callFunction = "", $XMLString) {
    $client = new feedSoap($wsdlURL, array('trace' => true));
    $client->SoapClientCall($XMLString);
    $client->__call("$callFunction", array());
    return $client->__getLastResponse();
}

// Estructura del XML para la conexión
$XMLString = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
 <soapenv:Header>
     <wsse:Security>
         <wsse:UsernameToken Id="ABC-123">
             <wsse:Username>20604051984MODDATOS</wsse:Username>
             <wsse:Password>moddatos</wsse:Password>
         </wsse:UsernameToken>
     </wsse:Security>
 </soapenv:Header>
 <soapenv:Body>
     <ser:sendBill>
        <fileName>' . $NomArch . '.zip</fileName>
        <contentFile>' . base64_encode(file_get_contents($zipFilePath)) . '</contentFile>
     </ser:sendBill>
 </soapenv:Body>
</soapenv:Envelope>';

// Realizamos la llamada a nuestra función SOAP
$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);

// Descargamos y procesamos la respuesta
descargarRespone($NomArch, $result, $ruta);
$response = leerXmlResponse($NomArch, $ruta);
descargarCDR_ZIP($NomArch, $response, $ruta);
obtenerCDR_XML($NomArch, $ruta);
$respuesta = leerCDR_XML($NomArch, $ruta);

// Retornamos la respuesta en formato JSON
echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);

// Eliminamos archivos temporales
eliminarArchivos($NomArch, $ruta);

// Funciones auxiliares

function descargarRespone($NomArch, $result, $ruta) {
    $archivo = fopen($ruta . 'C' . $NomArch . '.xml', 'w+');
    fwrite($archivo, $result);
    fclose($archivo);
}

function leerXmlResponse($NomArch, $ruta) {
    $xml = simplexml_load_file($ruta . 'C' . $NomArch . '.xml');
    foreach ($xml->xpath('//applicationResponse') as $response) {
        // Procesar respuesta
    }
    return $response;
}

function descargarCDR_ZIP($NomArch, $response, $ruta) {
    $cdr = base64_decode($response);
    $archivo = fopen($ruta . 'R-' . $NomArch . '.zip', 'w+');
    fwrite($archivo, $cdr);
    fclose($archivo);
    chmod($ruta . 'R-' . $NomArch . '.zip', 0777);
}

function obtenerCDR_XML($NomArch, $ruta) {
    $archive = new ZipArchive();
    if ($archive->open($ruta . 'R-' . $NomArch . '.zip') === TRUE) {
        $archive->extractTo($ruta);
        $archive->close();
        chmod($ruta . 'R-' . $NomArch . '.xml', 0777);
    } else {
        die("Error: No se pudo extraer el archivo ZIP.");
    }
}

function leerCDR_XML($NomArch, $ruta) {
    $resultado = array();
    if (file_exists($ruta . 'R-' . $NomArch . '.xml')) {
        $library = new SimpleXMLElement($ruta . 'R-' . $NomArch . '.xml', 0, true);

        $ns = $library->getDocNamespaces();
        $ext1 = $library->children($ns['cac']);
        $ext2 = $ext1->DocumentResponse;
        $ext3 = $ext2->children($ns['cac']);
        $ext4 = $ext3->children($ns['cbc']);

        $resultado = array(
            'respuesta_sunat_codigo' => trim($ext4->ResponseCode),
            'respuesta_sunat_descripcion' => trim($ext4->Description)
        );
    }
    return $resultado;
}

function eliminarArchivos($NomArch, $ruta) {
    // Eliminamos los archivos generados
    unlink($ruta . 'C' . $NomArch . '.xml');
    unlink($ruta . $NomArch . '.zip');
    unlink($ruta . 'R-' . $NomArch . '.zip');
}
