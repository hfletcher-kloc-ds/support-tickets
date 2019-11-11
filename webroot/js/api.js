class apiClass
{
	call( performOperation = "", options = {}, successCallback = _api.defaultSuccess, errorCallback = _api.defaultErrorHandler, failureCallback = _api.defaultFailHandler ){
		//Set which operation to perform
		options.method = performOperation;
		
		$.post(
			"api.php",
			options
		).done(function(data){
			if( data.status == 200 ){
				successCallback(data.data);
			} else {
				errorCallback(data.status, data.data);
			}
		}).fail(function( a, b, c ){
			failureCallback( a, b, c );
		})
	}
	
	defaultSuccess(data){
		$.notify(data, "success");
		console.log(data);
	}
	
	defaultErrorHandler(status, data){
		$.notify( data, "warn" );
		console.log( status, data );
	}
	
	defaultFailHandler(a,b,c){
		$.notify( "There was an error making the request to the API", "error" );
		console.log("-- Error Details --");
		console.log( a, b, c);
		console.log("--  End Details  --");
	}
}

_api = new apiClass();