<?php
declare(strict_types=1); // Activar el tipado estricto

require 'libraries/efactura.php';

header('Access-Control-Allow-Origin: *');

// Validamos que el parámetro name_file esté presente en la solicitud
if (!isset($_REQUEST['name_file']) || empty($_REQUEST['name_file'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Falta el parámetro name_file'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$name_file = $_REQUEST['name_file'];

// Llamamos a la función para firmar el XML
firmar_xml($name_file);

/**
 * Función que firma el archivo XML usando la clase Factura.
 */
function firmar_xml(string $name_file): void {
    $baja = '';
    $entorno = 0;

    // Definimos las rutas
    $carpeta_baja = ($baja != '') ? 'BAJA/' : '';
    $carpeta = "files/facturacion_electronica/$carpeta_baja";
    $dir = $carpeta . "XML/" . $name_file;

    // Validamos que el archivo XML exista antes de proceder
    if (!file_exists($dir)) {
        echo json_encode([
            'success' => false,
            'error' => 'El archivo XML no existe: ' . $name_file
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Leemos el contenido del archivo XML
    $xmlstr = file_get_contents($dir);
    
    if ($xmlstr === false) {
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo leer el archivo XML: ' . $name_file
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        // Cargamos el contenido del XML en DOMDocument
        $domDocument = new \DOMDocument();
        $domDocument->loadXML($xmlstr);

        // Firmamos el XML usando la clase Factura
        $factura = new Factura();
        $xml = $factura->firmar($domDocument, '', $entorno);

        // Guardamos el archivo firmado en la carpeta FIRMA
        $content = $xml->saveXML();
        file_put_contents($carpeta . "FIRMA/" . $name_file, $content);

        // Enviamos la respuesta de éxito
        echo json_encode([
            'success' => true,
            'message' => 'El archivo XML ha sido firmado correctamente: ' . $name_file
        ], JSON_UNESCAPED_UNICODE);

    } catch (\Exception $e) {
        // Capturamos cualquier excepción que pueda ocurrir durante la firma
        echo json_encode([
            'success' => false,
            'error' => 'Error al procesar el archivo XML: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
