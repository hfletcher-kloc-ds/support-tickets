<?php

	//API returns JSON
	header('Content-Type: application/json');
	
	//UK timezone
	date_default_timezone_set( 'Europe/London' );

	//These are the database credentials
	include '../config/database.php';
	
	//Start new server code
	$server = new server();
	
	//Get the api methods
	$api = new api();
	$available_methods = get_class_methods( $api );
	
	//Check the user specified the method to execute
	if( !isset( $_POST['method'] ) )$server->out( 400, "Please specify method as post parameter" );
	
	//Does the requested method exist?
	if( !in_array( $_POST['method'], $available_methods ) )$server->out( 400, "Method doesn't exist" );
	
	//Execute the method and store the output
	$method = $_POST['method'];
	$output = $server->$method();
	
	//Check output in correct format
	if( !isset( $output[0] ) || !isset( $output[1] ) )$output = array( 500, "Internal server error. Method returned wrong or no output" );
	
	//Return the output
	$server->out( $output[0], $output[1] );
	
	class api
	{
		function listPriorities(){
			//returns list of priorities
			$stmt = $this->database->prepare("SELECT * FROM priority");
			if( !$stmt->execute() )return array( 400, "Error running query" );
			
			return array( 200, $stmt->fetchAll(PDO::FETCH_ASSOC) );
		}
		
		function listCustomers(){
			//returns list of customers
			$stmt = $this->database->prepare("SELECT * FROM customers");
			if( !$stmt->execute() )return array( 400, "Error running query" );
			
			return array( 200, $stmt->fetchAll(PDO::FETCH_ASSOC) );
		}
		
		function listAssignees(){
			//returns list of customers
			$stmt = $this->database->prepare("SELECT * FROM assignees");
			if( !$stmt->execute() )return array( 400, "Error running query" );
			
			return array( 200, $stmt->fetchAll(PDO::FETCH_ASSOC) );
		}
		
		function createCustomer(){
			//inserts new customer to the database
			//requires the following fields
			$this->requiredFieldsCheck(array("customer_name"));
			
			//prevent blank customer name
			$this->preventBlankFields(array("customer_name"));
			
			//Create the new customer insert query
			$stmt = $this->database->prepare("INSERT INTO customers (`customer_name`, `customer_contact_email`, `customer_contact_number`) VALUES (:customer_name, :customer_contact_email, :customer_contact_number)");
			
			//Run the query, binding parameters to it
			if(
				!$stmt->execute(array(
						":customer_name" => $_POST['customer_name'],			
						":customer_contact_email" => "None.",
						":customer_contact_number" => "None."
					))
			)return array( 400, "Error inserting customer to database");
			
			//Get the new customer ID
			$customerID = $this->database->lastInsertID();
			
			//Queue a push notification
			$this->pushNotification(array(
					"method" => "createCustomer",
					"customer_id" => $customerID,
					"customer_name" => $_POST['customer_name'],
					"customer_contact_email" => "Not available.",
					"customer_contact_number" => "Not available."
				));
			
			//Give the user the ID of the new customer
			return array( 200, $customerID );
		}
		
		function updateCustomer(){
			//updates customer name of an existing customer
			//requires the following fields
			$this->requiredFieldsCheck(array("customer_id", "customer_name", "customer_contact_email", "customer_contact_number"));
			
			//does the customer exist?
			if(!in_array( $_POST['customer_id'], array_column( $this->listCustomers()[1], 'id' ) ))return array(400, "Customer ID doesn't exist");
			
			//prevent blank customer name
			$this->preventBlankFields(array("customer_name", "customer_contact_email", "customer_contact_number"));
			
			//Build query to update customer
			$stmt = $this->database->prepare("UPDATE customers SET customer_name=:customer_name, customer_contact_email=:customer_contact_email, customer_contact_number=:customer_contact_number WHERE id=:customer_id");
			
			//Run the query, binding params
			if(
				!$stmt->execute(array(
						":customer_id" => $_POST['customer_id'],
						":customer_name" => $_POST['customer_name'],
						":customer_contact_email" => $_POST['customer_contact_email'],
						":customer_contact_number" => $_POST['customer_contact_number']
					))
			)return array( 400, "Customer failed to update.");
			
			//Queue a push notification
			$this->pushNotification($_POST);
			
			return array( 200, "Customer updated" );
		}
		
		function deleteCustomer(){
			//removes a customer from the database
			//requires customer id
			$this->requiredFieldsCheck(array("customer_id"));
			
			//Check the customer exists
			if(!in_array( $_POST['customer_id'], array_column( $this->listCustomers()[1], 'id' ) ))return array(400, "Customer ID doesn't exist");
			
			//Check if the customer has any open tickets
			if( sizeof( $this->getAllActiveSupportTicketsForCustomer()[1] ) > 0 )return array( 400, "Cannot delete customer whilst they have active tickets" );
			
			//build query to deleteCustomer
			$stmt = $this->database->prepare("DELETE FROM customers WHERE id=:customer_id");
			
			//Run the query, binding the parameters
			if(
				!$stmt->execute(array(
						":customer_id" => $_POST['customer_id']
					))
			)return array( 400, "Error running delete customer query" );
			
			//Queue a push notification
			$this->pushNotification($_POST);
			
			return array( 200, "Customer deleted." );
		}
		
		function getAllActiveSupportTicketsForCustomer(){
			//which customer
			$this->requiredFieldsCheck(array("customer_id"));
			
			//Build query
			$stmt = $this->database->prepare("SELECT * FROM support_tickets WHERE customer_id=:customer_id AND archived=0 AND completed=0");
			
			//Run query binding parameters
			if(
				!$stmt->execute(array(
						":customer_id" => $_POST['customer_id']
					))
			)return array( 400, "Error running query");
			
			//Return the results to the user
			return array( 200, $stmt->fetchAll( PDO::FETCH_ASSOC ) );
		}
		
		function validateSupportTicketDetails(){
			//requires the following fields
			$this->requiredFieldsCheck(array(
					"customer_id",
					"task_description",
					"priority_id",
					"due_date",
					"time_estimate_hours",
					"assignee_id"
				));
				
			//Check the customer exists
			if(!in_array( $_POST['customer_id'], array_column( $this->listCustomers()[1], 'id' ) ))return array(400, "Customer ID doesn't exist");
			
			//Check the priority exists
			if(!in_array( $_POST['priority_id'], array_column( $this->listPriorities()[1], 'id' ) ))return array(400, "Priority ID doesn't exist");
			
			//Check the assignee exists
			if(!in_array( $_POST['assignee_id'], array_column( $this->listAssignees()[1],  'id' ) ))return array( 400, "Assignee ID doesn't exist");
			
			//Prevent blank task descriptions
			$this->preventBlankFields(array("task_description"));
			
			//Is a valid date selected?
			$date = explode("-", $_POST['due_date']);
			if( 
				!isset( $date[2] ) ||
				!checkdate( (int)$date[1], (int)$date[2], (int)$date[0] )
			)return array(400, "Invalid due date");
			
			//Is the date in the future or today?
			$date = new DateTime( $_POST['due_date'] );
			$current_date = new DateTime( date('Y-m-d') );
			if( $date < $current_date )return array( 400, "Due date can't be in the past" );
			
			//Check estimated time is greater than 0
			if( (float)$_POST['time_estimate_hours'] <= 0 )return array( 400, "Estimated time must be greater than 0");
			
			return array( 200, "Valid details." );
		}
		
		function createSupportTicket(){
			//adds a new support ticket to the database
			//validate support ticket details
			$validated = $this->validateSupportTicketDetails();
			if( $validated[0] != 200 )return $validated;
			
			//Build a query to insert the support ticket
			$stmt = $this->database->prepare("INSERT INTO support_tickets (`customer_id`,`task_description`,`priority_id`,`due_date`,`time_estimate_hours`, `assignee_id`) VALUES ( :customer_id, :task_description, :priority_id, :due_date, :time_estimate_hours, :assignee_id)");
			
			//Run the query, binding parameters
			if(
				!$stmt->execute(array(
						":customer_id" => $_POST['customer_id'],
						":task_description" => $_POST['task_description'],
						":priority_id" => $_POST['priority_id'],
						":due_date" => $_POST['due_date'],
						":time_estimate_hours" => $_POST['time_estimate_hours'],
						":assignee_id" => $_POST['assignee_id']
					))
			)return array( 400, "Error inserting the support ticket to the database" );
			
			//Get the ID of the inserted support ticket
			$supportTicketID = $this->database->lastInsertID();
			
			//Queue a push notification
			$this->pushNotification(array(
					"method" => "createSupportTicket",
					"support_ticket_id" => $supportTicketID
				));
			
			//Return the id of the created ticket
			return array( 200, $supportTicketID );
		}
		
		function listSupportTickets( $specific_id = 0 ){
			//this method returns all support tickets
			//build a query
			$stmt = $this->database->prepare("SELECT st.*, c.customer_name, c.customer_contact_number, c.customer_contact_email, p.priority_name AS 'priority', a.assignee_name FROM support_tickets st JOIN customers c ON c.id=st.customer_id JOIN priority p ON p.id=st.priority_id JOIN assignees a ON st.assignee_id=a.id WHERE ( st.id=:specific_id OR :specific_id=0 ) AND archived=0");
			
			//run query
			if( !$stmt->execute(array(
					":specific_id" => $specific_id
				))
			)return array(400, "Error running query to get support tickets" );
			
			//return data
			return array( 200, $stmt->fetchAll( PDO::FETCH_ASSOC ) );
		}
		
		function getSupportTicketByID(){
			//returns a single support ticket details by ID
			//what ID?
			$this->requiredFieldsCheck(array( "support_ticket_id" ));
			
			//get all tickets
			$ticket = $this->listSupportTickets( $_POST['support_ticket_id'] )[1];
			
			//check there is at least one, if not, call terminate method (don't just return, as this method is used elsewhere)
			if( sizeof( $ticket ) == 0 )$this->out( 400, "Support ticket doesn't exist" );
			
			return array( 200, $ticket );
		}
		
		function updateSupportTicket(){
			//updates details of existing support ticket
			//Need at least the support ticket id id
			$this->requiredFieldsCheck(array("support_ticket_id"));
			
			//Get the existing details for this support ticket
			$existingDetails = $this->getSupportTicketByID()[1][0];
			
			//loop through the existing details and see if the user specified any fields to update
			foreach( $existingDetails as $field=>$value ){
				//has the user specified the field?
				if( !isset( $_POST[ $field ] ) ){
					//It exists, overwrite the existing value
					$_POST[ $field ] = $value;
				}
			}
			
			//validate support ticket details
			$validated = $this->validateSupportTicketDetails();
			if( $validated[0] != 200 )return $validated;
			
			//build Query to update the support ticket
			$stmt = $this->database->prepare("UPDATE support_tickets SET customer_id=:customer_id, task_description=:task_description, priority_id=:priority_id, due_date=:due_date, time_estimate_hours=:time_estimate_hours, assignee_id=:assignee_id WHERE id=:support_ticket_id");
			
			//Run the query, binding parameters
			if(
				!$stmt->execute(array(
						":customer_id" => $_POST['customer_id'],
						":task_description" => $_POST['task_description'],
						":priority_id" => $_POST['priority_id'],
						":due_date" => $_POST['due_date'],
						":time_estimate_hours" => $_POST['time_estimate_hours'],
						":support_ticket_id" => $_POST['support_ticket_id'],
						":assignee_id" => $_POST['assignee_id']
					))
			)return array( 400, "Error running update query" );
			
			//Queue a push notification
			$this->pushNotification($_POST);
			
			return array( 200, "Ticket updated.");
		}
		
		function deleteSupportTicket(){
			//marks a ticket as archived so it doesn't show up in listSupportTickets method
			//what ID?
			$this->requiredFieldsCheck(array( "support_ticket_id" ));
			
			//check the ticket exists (this method kills call if it doesn't)
			$this->getSupportTicketByID();
			
			//Build a query to archive the ticket
			$stmt = $this->database->prepare("UPDATE support_tickets SET archived=1 WHERE id=:support_ticket_id");
			
			//Run the query, binding parameter
			if(
				!$stmt->execute(array(
						":support_ticket_id" => $_POST['support_ticket_id']
					))
			)return array( 400, "Failed to delete support ticket" );
			
			//Queue a push notification
			$this->pushNotification($_POST);
			
			return array( 200, "Support ticket deleted." );
		}
		
		function completeSupportTicket(){
			//marks a ticket as completed
			//what ID?
			$this->requiredFieldsCheck(array( "support_ticket_id" ));
			
			//check the ticket exists (this method kills call if it doesn't)
			$this->getSupportTicketByID();
			
			//Build a query to archive the ticket
			$stmt = $this->database->prepare("UPDATE support_tickets SET completed=1 WHERE id=:support_ticket_id");
			
			//Run the query, binding parameter
			if(
				!$stmt->execute(array(
						":support_ticket_id" => $_POST['support_ticket_id']
					))
			)return array( 400, "Failed to complete support ticket" );
			
			//Queue a push notification
			$this->pushNotification($_POST);
			
			return array( 200, "Support ticket completed." );
		}
	}
	
	class server extends api
	{
		protected $database;
		
		public function __construct(){
			global $dbConn;
			$this->database =& $dbConn;
		}
		
		function pushNotification( $body = array() ){
			$body = JSON_ENCODE($body);
			
			$stmt = $this->database->prepare("INSERT INTO push_notifications (`notification_body`) VALUES (:notification_body)");
			
			$stmt->execute(array(
					":notification_body" => $body
				));
				
			return true;
		}
		
		function requiredFieldsCheck( $fields = array(), $entity = NULL ){
			if( $entity == NULL )$entity = $_POST;
			
			foreach( $fields as $key=>$fieldName ){
				if( !isset( $entity[$fieldName] ) ){
					$this->out( 400, "Missing field `{$fieldName}` from parameters." );
				}
			}
		}
		
		function preventBlankFields( $fields = array(), $entity = NULL ){
			if( $entity == NULL )$entity = $_POST;
			
			foreach( $fields as $key=>$fieldName ){
				if( $entity[$fieldName] == ""){
					$this->out( 400, "Blank field `{$fieldName}` cannot be blank." );
				}
			}
		}
		
		function out($status=400, $data="Bad Request"){
			echo JSON_ENCODE(array(
					"status" => $status,
					"data"   => $data
				));
				
			die();
		}
	}