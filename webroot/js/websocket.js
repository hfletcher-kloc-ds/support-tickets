var websocket = null;

class websocketClass
{
	
	constructor(){
//		websocket = new WebSocket('ws://support.local:8080');
                websocket = new WebSocket('wss://qa.kloc.co.uk/supportSocket');
		
		websocket.onopen = function(e){
			console.log("Connected to websocket");
			
			_websocket.socketSend({action: 1})
		}
		
		websocket.onmessage = function(e){
			var payload = JSON.parse(e.data);
			console.log( payload );
		}
	}
	
	socketSend( data ){
		//Send the data
		websocket.send(JSON.stringify(data));
	}
	
	socketKeepAlive(){
		setTimeout(function(){
			_websocket.socketSend({
				action: 9999
			});
			_websocket.socketKeepAlive();
		}, 20000)
	}
}

_websocket = new websocketClass();
