<?php

/**
 * Classe para criação de socket em PHP para esperar por dados de um Rastreador TK306
 * Pode-se adaptar este para a captura de infos de outros rastreadores, basta para isso entender o padrao de entrada.
 */
class coletor_gps{

    #ip do nosso socket
    protected $ip_address = "0.0.0.0";
    #porta a criar socket
    protected $port = "9000";
    #cria conexao com banco[]
    protected $conn=null;


    function __construct(){
        $this->conecta_pdo();
        $this->cria_socket();
    }

    function conecta_pdo(){
        try {
            $this->conn = new PDO('mysql:host=localhost;dbname=captura_gps', 'root', 'XXXXXXXXXXXX');
        } catch(PDOException $e) {
            echo 'ERROR: ' . $e->getMessage();
            return false;
        }
        return true;
    }

    function insert($query){
        echo "\n------------\n".$query."\n------------\n";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
    }

    function cria_socket(){
        $server = stream_socket_server("tcp://".$this->ip_address.":".$this->port, $errno, $errorMessage);
        if ($server === false) {
            die("stream_socket_server error: $errorMessage");
        }
        $client_sockets = array();
        while (true) {
            $read_sockets = $client_sockets;
            $read_sockets[] = $server;
            if(!stream_select($read_sockets, $write, $except, 300000)) {
                die('stream_select error.');
            }
            if(in_array($server, $read_sockets)) {
                $new_client = stream_socket_accept($server);
                if ($new_client) {
                    echo 'new connection: ' . stream_socket_get_name($new_client, true) . "\n";
                    $client_sockets[] = $new_client;
                    echo "total clients: ". count($client_sockets) . "\n";
                }
                unset($read_sockets[ array_search($server, $read_sockets) ]);
            }
            
            foreach ($read_sockets as $socket) {
                $data = fread($socket, 128);
                echo ">>> data: " . $data . "\n";
                $tk103_data = explode( ',', $data);
                $response = "";
                switch (count($tk103_data)) {
                    case 0:
                    $dados=explode("&",$data);
                    preg_match_all("@id=215794&timestamp=(\d+)&lat=([\-\d\.]*)&lon=([\-\d\.]*)&speed=([\-\d\.]*)&bearing=0.0&altitude=([\-\d\.]*)&batt=([\-\d\.]*)@",$data,$matches);
                    print_r($matches);
                    break;
                    // 359710049095095 -> heartbeat requires "ON" response
                    case 1:
                    $response = "ON";
                    echo "sent ON to client\n";
                    break;
                    // ##,imei:359710049095095,A -> this requires a "LOAD" response
                    case 3:
                    if ($tk103_data[0] == "##") {
                        $response = "LOAD";
                        echo "sent LOAD to client\n";
                    }
                    break;
                    // ,imei:359710048015151,OBD,160215230906,72852,0.00,1.24,30,11,48.63%,39,16.86%,1118,14.06,,,,;
                    case 17:
                    echo "#####################################\n";
                    echo "ODOMETER: ".$tk103_data[3]."\n";
                    echo "remaining fuel: ".$tk103_data[4]."\n";
                    echo "Average fuel: ".$tk103_data[5]."\n";
                    echo "driving time: ".$tk103_data[6]."\n";
                    echo "speed: ".$tk103_data[7]."\n";
                    echo "Power load: ".$tk103_data[8]."\n";
                    echo "water temp: ".$tk103_data[9]."\n";
                    echo "Throttle percentage: ".$tk103_data[10]."\n";
                    echo "engine speed: ".$tk103_data[11]."\n";
                    echo "Battry voltage: ".$tk103_data[12]."\n";
                    echo "???1: ".$tk103_data[13]."\n";
                    echo "???2: ".$tk103_data[14]."\n";
                    echo "???3: ".$tk103_data[15]."\n";
                    echo "???4: ".$tk103_data[16]."\n";
                    echo "#####################################\n";
                    break;
                    // imei:359710049095095,tracker,151006012336,,F,172337.000,A,5105.9792,N,11404.9599,W,0.01,322.56,,0,0,,,  -> this is our gps data
                    case 19:

                    $imei = substr($tk103_data[0], 5);
                    $alarm = $tk103_data[1];
                    $gps_time = $this->nmea_to_mysql_time($tk103_data[2]);
                    $latitude = $this->degree_to_decimal($tk103_data[7], $tk103_data[8]);
                    $longitude = $this->degree_to_decimal($tk103_data[9], $tk103_data[10]);
                    $speed_in_knots = $tk103_data[11];
                    $speed_in_mph = 1.15078 * $speed_in_knots;
                    $speed_in_kmh= 1.609 * $speed_in_mph;
                    $bearing = $tk103_data[12];
                    $odometer = $tk103_data[17];
                    $a_identificar = $tk103_data[16];

                    echo "#####################################\n";
                    echo "imei: ".$imei."\n";
                    echo "alarm: ".$alarm."\n";
                    echo "gps_time: ".$gps_time."\n";
                    echo "latitude: ".$latitude."\n";
                    echo "longitude: ".$longitude."\n";
                    echo "speed_in_knots: ".$speed_in_knots."\n";
                    echo "speed_in_mph: ".$speed_in_mph."\n";
                    echo "bearing: ".$bearing."\n";
                    echo "#####################################\n";

                    if ($alarm == "help me") {
                        $response = "**,imei:" + $imei + ",E;";
                    }
                    break;
                }
                if (!$data) {
                    unset($client_sockets[ array_search($socket, $client_sockets) ]);
                    @fclose($socket);
                    echo "client disconnected. total clients: ". count($client_sockets) . "\n";
                    continue;
                }
                if (sizeof($response) > 0) {
                    fwrite($socket, $response);
                }
            }
        }
    }

    /**
     * Classe para traducao da hora padrao nmea para o padrao timestamp do mysql
     */
    function nmea_to_mysql_time($date_time){

        $year = substr($date_time,0,2);
        $month = substr($date_time,2,2);
        $day = substr($date_time,4,2);
        $hour = substr($date_time,6,2);
        $minute = substr($date_time,8,2);
        $second = substr($date_time,10,2);

        return date("Y-m-d H:i:s", mktime($hour,$minute,$second,$month,$day,$year));
    }

    /**
     * Funcao para conversado de coordenadas do padrao graus para o padrao decimal.
     */
    function degree_to_decimal($coordinates_in_degrees, $direction){
        $degrees = (int)($coordinates_in_degrees / 100);
        $minutes = $coordinates_in_degrees - ($degrees * 100);
        $seconds = $minutes / 60;
        $coordinates_in_decimal = $degrees + $seconds;

        if (($direction == "S") || ($direction == "W")) {
            $coordinates_in_decimal = $coordinates_in_decimal * (-1);
        }
        return number_format($coordinates_in_decimal, 6,'.','');
    }
}
$obj=new coletor_gps();