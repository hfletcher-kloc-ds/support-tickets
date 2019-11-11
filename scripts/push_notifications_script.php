<?php
	header('Content-Type: application/json');
	
	session_start();
	
	date_default_timezone_set("Europe/London");

	include '../config/database.php';
	
	//Instantiate push notifications
	$pushNotifications = new pushNotificationsClass( $dbConn, $argv );
	$pushNotifications->runServer();

	class pushNotificationsClass
	{
		protected $resources;
		protected $database;
		protected $options;
		
		//function __construct( $db_connection = NULL, $args = array(), $address = "192.168.1.208", $port = 8080 ){
		function __construct( $db_connection = NULL, $args = array(), $address = "localhost", $port = 8080 ){
			//Initialise resources as an object
			$this->resources = new stdClass();
			$this->socket = new stdClass();
			$this->options   = new stdClass();
			
			//Create a blank array for storing sockets
			$this->resources->clients = array();
			
			//Check for database connection
			if( $db_connection == NULL ){
				die("Error: You have not provided a valid database connection.");
			} else {
				$this->database = $db_connection;
			}
			
			//Set the options
			$this->options->address = $address;
			$this->options->port    = $port;
			$this->options->messageCheckIntervalSeconds = 2;
			$this->options->lastMessageCheckAt = 0;
		}
		
		function runServer(){
			//Get the user socket
			$this->resources->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			
			//Set options on that socket
			socket_set_option($this->resources->socket, SOL_SOCKET, SO_REUSEADDR, 1);
			
			//Set the array of sockets
			$this->resources->clients = array(array("user" => NULL, "socket" => $this->resources->socket));
			
			//Bind this socket to the address & port
			socket_bind($this->resources->socket, $this->options->address, $this->options->port);
			
			//Listen for connections on this socket
			socket_listen($this->resources->socket, 5);
			echo "Starting server...\n\n";
			
			//Loop forever
			do{
				usleep( 500 );
				//This is the changed socket.
				$allConnectedSockets = array_column( $this->resources->clients, 'socket' );
				
				//Get sockets that have changed state
				$val = @socket_select($allConnectedSockets,$write = NULL ,$except=NULL,0);
				
				foreach ($allConnectedSockets as $sock) {
					//Read the data from that socket
					$data = @socket_read( $sock, NULL, PHP_NORMAL_READ );
					
					//If the current socket is the one that has changed
					if($sock === $this->resources->socket){
						//Accept data from the newly connected socket
						$newlyConnectedSocket = socket_accept( $this->resources->socket );
						
						//Store this resource for future use with a blank user.
						array_push($this->resources->clients, array(
								"socket" => $newlyConnectedSocket,
								"handshake" => false
							));
							
						//Echo a new connection message
						echo "A new user connected...\n\n";
						
						//Perform the handshake
						$this->handshake( $newlyConnectedSocket );
						
						$this->resources->clients[array_search( $newlyConnectedSocket, array_column( $this->resources->clients, 'socket' ) )]["handshake"] = true;
					} else {
						$arrayPosition = array_search( $sock, array_column( $this->resources->clients, 'socket' ) );
						
						$readData = @socket_read( $sock, 256 );
						
						if( $readData === "" ){
							$this->disconnected( $arrayPosition );
						} else {
							//Get the input data.
							$readData =  $this->unmask( $readData );
						
						    //Try to make that into a usable php array
							$readData = json_decode( $readData, true );

							//Check the data is the right type.
							if( gettype( $readData ) === "array" ){
								if( !isset( $readData['action'] ) ){
									$this->send_message( $sock, 400, "You have not specified an action to perform" );
									continue;
								}
								
								//Get the information of this socket
								$socket_information = $this->resources->clients[ $arrayPosition ];
									
								//A list of valid actions
								if( $readData['action'] == 1 ){
									//confirm as real user
									$this->resources->clients[ $arrayPosition ]['is_real_user'] = true;
								} else if( $readData['action'] == 9999 ){
									//Do nothing
								} else {
									$this->send_message( $sock, 400, "That action is not valid." );
									continue;
								}
							} else {
								$this->send_message( $sock, 400, "Your message is not the right format. Please send JSON string" );
							}
						}
					}
				}
				
				/**
					This section of the code checks for any pending push notifications
				**/
				if( $this->options->lastMessageCheckAt <= ( time() - ( $this->options->messageCheckIntervalSeconds ) ) ){
					$this->sendPendingNotifications();
				}
			}while(1);
			socket_close($this->resources->socket);
		}
		
		function sendPendingNotifications(){
			//Get all notifications from the database
			$stmt = $this->database->prepare("SELECT * FROM push_notifications WHERE processed=0");
			$stmt->execute();
			$allNotifications = $stmt->fetchAll( PDO::FETCH_ASSOC );
			
			//Send all notifications
			foreach( $allNotifications as $key=>$notification ){
				foreach( array_column( $this->resources->clients, "socket" ) as $clientSocket ){
					$this->send_message( $clientSocket, 200, $notification, false, 0 );
				}
					
				//Mark the original notification as processed
				$this->database->prepare("UPDATE push_notifications SET processed=1 WHERE id=:notification_id")->execute(array(":notification_id" => $notification['id']));
			}
			
			//Reset last check time
			$this->options->lastMessageCheckAt = time();
		}
		
		function requiredFields( $data = array(), $fields = array() ){
			foreach( $fields as $field ){
				if( !isset( $data[ $field ] ) ){
					return false;
				}
			}
			
			return true;
		}
		
		function disconnected( $arrayPosition ){
			//Get the socket details
			$socketDetails =  $this->resources->clients[$arrayPosition];
			
			//Close that socket
			@socket_close( $socketDetails["socket"] );
			unset( $this->resources->clients[ $arrayPosition ] );
			
			//Re-order the array keys
			$this->resources->clients = array_values( $this->resources->clients );
			
			echo "Disconnected...\n\n";
		}
		
		function unmask($payload) {
			$length = ord($payload[1]) & 127;
			if($length == 126) {
				$masks = substr($payload, 4, 4);
				$data = substr($payload, 8);
			}
			elseif($length == 127) {
				$masks = substr($payload, 10, 4);
				$data = substr($payload, 14);
			}
			else {
				$masks = substr($payload, 2, 4);
				$data = substr($payload, 6);
			}
			$text = '';
			for ($i = 0; $i < strlen($data); ++$i) {
				$text .= $data[$i] ^ $masks[$i%4];
			}
			return $text;
		}

		function send_message( $socket = NULL, $status = 200, $data = array(), $system = true, $subtype = 0 ){
			//Get the properties of the socket we are sending to
			$originalSocketProperties = $this->resources->clients[ array_search( $socket, array_column( $this->resources->clients, 'socket' ) ) ];
			
			if( !isset( $originalSocketProperties['is_real_user'] ) )return false;
			
			$data = $this->hybi10Encode(json_encode($data));
			
			$returnValue = true;
			
			if( $socket == NULL ){
					$returnValue = false;
				}
				
				$stringLength = strlen($data);				
				if( @socket_write( $socket, $data, $stringLength ) === false ){
					//the connectionn has gone away
					$this->disconnected( array_search( $socket, array_column( $this->resources->clients, 'socket' ) ) );
					$returnValue = false;
				}
			
			return $returnValue;
		}

		function hybi10Encode($payload, $type = 'text', $masked = false){
			$frameHead = array();
			$frame = '';
			$payloadLength = strlen($payload);
			
			switch($type)
			{		
				case 'text':
					// first byte indicates FIN, Text-Frame (10000001):
					$frameHead[0] = 129;				
				break;			
			
				case 'close':
					// first byte indicates FIN, Close Frame(10001000):
					$frameHead[0] = 136;
				break;
			
				case 'ping':
					// first byte indicates FIN, Ping frame (10001001):
					$frameHead[0] = 137;
				break;
			
				case 'pong':
					// first byte indicates FIN, Pong frame (10001010):
					$frameHead[0] = 138;
				break;
			}
			
			// set mask and payload length (using 1, 3 or 9 bytes) 
			if($payloadLength > 65535)
			{
				$payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
				$frameHead[1] = ($masked === true) ? 255 : 127;
				for($i = 0; $i < 8; $i++)
				{
					$frameHead[$i+2] = bindec($payloadLengthBin[$i]);
				}
				// most significant bit MUST be 0 (close connection if frame too big)
				if($frameHead[2] > 127)
				{
					$this->close(1004);
					return false;
				}
			}
			elseif($payloadLength > 125)
			{
				$payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
				$frameHead[1] = ($masked === true) ? 254 : 126;
				$frameHead[2] = bindec($payloadLengthBin[0]);
				$frameHead[3] = bindec($payloadLengthBin[1]);
			}
			else
			{
				$frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
			}

			// convert frame-head to string:
			foreach(array_keys($frameHead) as $i)
			{
				$frameHead[$i] = chr($frameHead[$i]);
			}
			if($masked === true)
			{
				// generate a random mask:
				$mask = array();
				for($i = 0; $i < 4; $i++)
				{
					$mask[$i] = chr(rand(0, 255));
				}
				
				$frameHead = array_merge($frameHead, $mask);			
			}						
			$frame = implode('', $frameHead);

			// append payload to frame:
			$framePayload = array();	
			for($i = 0; $i < $payloadLength; $i++)
			{		
				$frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
			}

			return $frame;
		}

		
		function handshake( $socket ){
			//Show a message to say that we started the handshake
			echo "Handshake started...\n\n";
			
			//Receive the header information from the newly connected socket
			socket_recv($socket, $hds, 2048, NULL);
			
			//Check Sec-WebSocket-Key is present
			if(preg_match("/Sec-WebSocket-Key: (.*)\r\n/",$hds,$matchs)){
				//Get the websocket key and generate a hash of it to create the Sec-WebSocket-Accept header.
				$key = $matchs[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
				$key =  base64_encode(sha1($key, true)); 
				
				//Create the new header information in a string
				$headers = "HTTP/1.1 101 Switching Protocols\r\n".
				"Upgrade: websocket\r\n".
				"Connection: Upgrade\r\n".
				"Sec-WebSocket-Accept: $key".
				"\r\n\r\n";
				
				//Send the new header information to the socket
				socket_write($socket, $headers);
				
				//A message that the handshake has completed successfully.
				echo "Handshake completed successfully.\n\n";
			} else {
				//If this is reached, no websocket key was specified
				echo "Handshake failed. Sec-WebSocket-Key not present.";
			}
		}
	}
?>