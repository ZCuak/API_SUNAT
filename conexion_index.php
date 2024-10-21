<?php
header('Content-Type: text/html; charset=UTF-8');
require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP

//URL para enviar las solicitudes a SUNAT
$wsdlURL = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';

// NOMBRE DE ARCHIVO A PROCESAR.
//$NomArch = '20532710066-01-F002-00000026';
//$NomArch = '20604051984-01-F001-11';
$NomArch = '20604051984-01-F001-100';
//$NomArch = '20604051984-07-FC01-6';
## =============================================================================
## Creación del archivo .ZIP
$zip = new PclZip($NomArch . ".zip");
$zip->create($NomArch . ".xml");
chmod($NomArch . ".zip", 0777);
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
        $dom = new DOMDocument('1.0');
        try {
            $dom->loadXML($request);
        } catch (DOMException $e) {
            die($e->code);
        }
        $request = $dom->saveXML();
        //Solicitud
        return parent::__doRequest($request, $location, $action, $version, $one_way = 0);
    }

    public function SoapClientCall($SOAPXML) {
        return $this->setXMLStr($SOAPXML);
    }

}

function soapCall($wsdlURL, $callFunction = "", $XMLString) {
    $client = new feedSoap($wsdlURL, array('trace' => true));
    $reply = $client->SoapClientCall($XMLString);
    //echo "REQUEST:\n" . $client->__getFunctions() . "\n";
    $client->__call("$callFunction", array(), array());
    //$request = prettyXml($client->__getLastRequest());
    //echo highlight_string($request, true) . "<br/>\n";
    return $client->__getLastResponse();
}

//Estructura del XML para la conexión
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
        <contentFile>' . base64_encode(file_get_contents($NomArch . '.zip')) . '</contentFile>
     </ser:sendBill>
 </soapenv:Body>
</soapenv:Envelope>';

//Realizamos la llamada a nuestra función
$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);
descargarRespone($NomArch, $result);
$response = leerXmlResponse($NomArch);
descargarCDR_ZIP($NomArch, $response);
obtenerCDR_XML($NomArch);
$respuesta = leerCDR_XML($NomArch);
var_dump($respuesta);
elminarArchivos($NomArch);

//Descargamos el Archivo Response
function descargarRespone($NomArch, $result){
    $archivo = fopen('C' . $NomArch . '.xml', 'w+');
    fputs($archivo, $result);
    fclose($archivo);
}

/* LEEMOS EL ARCHIVO XML */
function leerXmlResponse($NomArch){
    $xml = simplexml_load_file('C' . $NomArch . '.xml');
    foreach ($xml->xpath('//applicationResponse') as $response) {

    }
    return $response;
}

/* AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN) */
function descargarCDR_ZIP($NomArch, $response){
    $cdr = base64_decode($response);
    $archivo = fopen('R-' . $NomArch . '.zip', 'w+');
    fputs($archivo, $cdr);
    fclose($archivo);
    chmod('R-' . $NomArch . '.zip', 0777);
}

function obtenerCDR_XML($NomArch){
    $archive = new PclZip('R-' . $NomArch . '.zip');
    if ($archive->extract() == 0) {
        die("Error : " . $archive->errorInfo(true));
    } else {
        chmod('R-' . $NomArch . '.xml', 0777);
    }
}

functIon leerCDR_XML($NomArch){        
    $resultado = array();
    //echo "abc";exit;        
    if(file_exists('R-' . $NomArch . '.xml')){            
        $library = new SimpleXMLElement('R-' . $NomArch . '.xml', null, true);

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

function elminarArchivos($NomArch){
    /* Eliminamos el Archivo Response */
    unlink('C' . $NomArch . '.xml');    
}

