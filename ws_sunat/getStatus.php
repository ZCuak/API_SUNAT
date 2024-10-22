<?php
// Configurar error_reporting para solo mostrar errores críticos (excluyendo advertencias y notificaciones)
error_reporting(E_ERROR | E_PARSE);

// Definir ruta basada en el parámetro GET
$ruta = ($_GET['cod_6'] == '0') ? '../files/facturacion_electronica/FIRMA/' : '../files/facturacion_electronica/BAJA/FIRMA/';
$NomArch = $_GET['numero_documento'] ?? ''; // Manejar valor nulo en caso de que 'numero_documento' no esté presente en la URL

if (empty($NomArch)) {
    die(json_encode(['success' => false, 'message' => 'Número de documento no proporcionado.']));
}

## =============================================================================
## Creación del archivo .ZIP con ZipArchive
$zip = new ZipArchive();
$zipFile = $ruta . $NomArch . ".zip";

if (!file_exists($zipFile)) {
    if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($ruta . $NomArch . '.xml', basename($NomArch . '.xml'));
        $zip->close();
        chmod($zipFile, 0777);
    } else {
        die(json_encode(['success' => false, 'message' => 'Error al crear el archivo ZIP.']));
    }
}

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
        $dom = new DOMDocument('1.0', 'UTF-8'); // Especificar codificación UTF-8

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

// Asignar URL del WSDL basado en el tipo de documento y el ambiente
$wsdlURL = '';
if ($_GET['cod_1'] == 1) {
    // FACTURAS
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'billService.wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
} elseif ($_GET['cod_1'] == 9) {
    // GUIAS
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService?wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService?wsdl';
} else {
    die(json_encode(['success' => false, 'message' => 'Código de documento inválido.']));
}

// Estructura del XML para la conexión
$XMLString = '
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
    <soapenv:Header>
        <wsse:Security>
            <wsse:UsernameToken>
                <wsse:Username>' . htmlspecialchars($_GET['cod_3'] . $_GET['cod_4']) . '</wsse:Username>
                <wsse:Password>' . htmlspecialchars($_GET['cod_5']) . '</wsse:Password>
            </wsse:UsernameToken>
        </wsse:Security>
    </soapenv:Header>
    <soapenv:Body>
        <ser:getStatus>
            <ticket>' . htmlspecialchars($_GET['cod_7']) . '</ticket>
        </ser:getStatus>
    </soapenv:Body>
</soapenv:Envelope>';

$error_mensaje = '';
$error_existe = 0;
$result = '';

try {
    $result = soapCall($wsdlURL, "sendBill", $XMLString);
    
    // Guardar la respuesta en un archivo XML
    $archivo = fopen('C' . $NomArch . '.xml', 'w+');
    fwrite($archivo, $result);
    fclose($archivo);

    // Procesar la respuesta (CDR - Constancia de Recepción)
    // Decodificar el archivo CDR
    // Asumimos que el contenido está en la variable $response (después del procesamiento)
    $cdr = base64_decode($response);
    $archivo = fopen($ruta . 'R-' . $NomArch . '.zip', 'w+');
    fwrite($archivo, $cdr);
    fclose($archivo);
    chmod($ruta . 'R-' . $NomArch . '.zip', 0777);

    // Extraer el archivo ZIP usando ZipArchive
    $zip = new ZipArchive();
    if ($zip->open($ruta . 'R-' . $NomArch . '.zip') === TRUE) {
        $zip->extractTo($ruta);  // Extraer a la ruta especificada
        $zip->close();
        chmod('R-' . $NomArch . '.xml', 0777);  // Cambiar permisos al archivo extraído
    } else {
        throw new Exception("Error al extraer el archivo ZIP.");
    }
    unlink('C' . $NomArch . '.xml');

} catch (Exception $e) {
    $error_existe = 1;
    $error_mensaje = $e->getMessage();
}

//////////////////////////////////////////////////////////////////////////////
$jsondata = array(
    'success'       =>  ($error_existe === 0),
    'message'       =>  $result,
    'error_mensaje' =>  $error_mensaje,
    'error_existe'  =>  $error_existe
);

echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);
