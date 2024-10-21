<?php
declare(strict_types=1); // Activar el tipado estricto

require 'libraries/efactura.php';

header('Access-Control-Allow-Origin: *');

// Validamos la entrada de la variable `name_file`
if (!isset($_REQUEST['name_file']) || empty($_REQUEST['name_file'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Falta el parámetro name_file'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$name_file = $_REQUEST['name_file'];

// Validamos que `$empresa` esté definida
if (!isset($empresa) || !is_array($empresa) || !isset($empresa['ruc'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Falta la información de la empresa o el RUC no está definido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Llamada a la función principal
ws_sunat($empresa, $name_file);

/**
 * Función para enviar el archivo a SUNAT.
 */
function ws_sunat(array $empresa, string $nombre_archivo): void {
    // URL del servicio web (ajustada para el dominio proporcionado)
    $ruta_dominio = "https://apisunat.llamadevs.com/";
    
    // Construimos la URL con los parámetros adecuados
    $url = $ruta_dominio . "ws_sunat/index.php?numero_documento=" . urlencode($nombre_archivo)
        . "&cod_1=1&cod_2=0&cod_3=" . urlencode($empresa['ruc'])
        . "&cod_4=MODDATOS&cod_5=moddatos&cod_6=1";

    // Obtener los datos de la API, con manejo de errores en caso de fallo
    $data = @file_get_contents($url);

    if ($data === false) {
        // Si `file_get_contents` falla, devolvemos un mensaje de error
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo conectar al servicio SUNAT.'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Intentamos decodificar el JSON recibido
    $info = json_decode($data, true);

    if ($info === null || !is_array($info)) {
        echo json_encode([
            'success' => false,
            'error' => 'Error al procesar la respuesta del servicio SUNAT.'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Mensajes de respuesta
    $respuesta_codigo = '';
    $respuesta_mensaje = '';

    // Construimos la respuesta final
    $jsondata = [
        'success'       =>  true,
        'codigo'        =>  $respuesta_codigo,
        'error_existe'  =>  $info['error_existe'] ?? false,
        'message'       =>  $respuesta_mensaje . ($info['error_mensaje'] ?? '')
    ];

    // Enviamos la respuesta en formato JSON
    echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);
}
