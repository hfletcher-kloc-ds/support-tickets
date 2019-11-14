class appClass
{	
	start(){		
		$("body")
			.append(
				$("<img></img>")
					.addClass("logo")
					.attr("src", "images/klocLogo.png")
			)
			.append(
				$("<div></div>")
					.addClass("supportTasksTable")
					.attr("id", "supportTasksTable")
			)
			.append(
				$("<div></div>")
					.addClass("supportTasksTable")
					.attr("id", "addTaskFormContainer")
					.hide()
			)
			
		websocket.addEventListener("message", function(e){
			var notification_body = JSON.parse( JSON.parse( e.data ).notification_body );
			
			if( notification_body.method == "createSupportTicket" ){
				//Get the details of the support ticket
				_api.call(
					"getSupportTicketByID",
					{
						support_ticket_id: notification_body.support_ticket_id
					},
					function(data){
						//Get the ticket
						var ticket = data[0];
						
						//Display the ticket
						app.displayTicket( ticket );
							
						//Sort tickets
						app.sortTickets();
					}
				);
			} else if( notification_body.method == "deleteSupportTicket" ){
				$("[data-ticket-id='"+ notification_body.support_ticket_id +"']").remove();
				
				//Count the number of tasks and display "no tasks" messaage if necessary
				app.checkIfDisplayNoTasksMessage();
			} else if( notification_body.method == "updateSupportTicket" ){
				app.updateExistingSupportTicket( notification_body.support_ticket_id );
			}
		})
		
		//Load all tickets
		app.getAllSupportTickets();
	}
	
	updateExistingSupportTicket( ticketID ){
		//Remove the existing ticket
		$("[data-ticket-id='"+ ticketID +"']").remove();
		
		//Get the details of the ticket from the server
		_api.call(
			"getSupportTicketByID",
			{support_ticket_id: ticketID},
			function(ticket){
				//Display the ticket
				app.displayTicket( ticket[0] );
				
				//Sort all tickets on display
				app.sortTickets();
			}
		);
	}
	
	getAllSupportTickets(){
		_api.call(
			"listSupportTickets",
			{},
			function(data){
				$("#supportTasksTable").empty().append(
						$("<div></div>")
							.addClass("supportTasksTableRow")
							.addClass("centerAlign")
							.append(
								$("<i></i>")
									.addClass("fa")
									.addClass("fa-2x")
									.addClass("fa-plus-square")
									.addClass("add-more-tasks-button")
									.addClass("addMoreTasksButton")
									.attr("title", "add new")
									.click(function(e){
										app.addEditSupportTask();
									})
							)
							.append(
								$("<h2></h2>")
									.text("Support Tasks")
							)
					);
				
				$.each( data, function(i, ticket){
					app.displayTicket( ticket );
				})
				
				app.sortTickets();
				
				//Count the number of tasks and display "no tasks" messaage if necessary
				app.checkIfDisplayNoTasksMessage();
			}
		);
	}
	
	checkIfDisplayNoTasksMessage(){
		if( ( $('.supportTasksTableRow').length - 1 ) == 0 ){		
			$("#supportTasksTable")
				.append(
					$("<div></div>")
						.addClass("fullWidth")
						.addClass("centerAlign")
						.append(
							$("<h3></h3>")
								.addClass("transparent")
								.text("No tickets to display")
						)
				)
		}
	}
	
	addEditSupportTask( customerID = 0, taskDescription = "New Support Task", priorityID = 1, dueDate = 0, estimatedTimeHours = 1, supportTaskID = 0){
		if( dueDate == 0 )dueDate = app.ukDateFormat( new Date() );
		
		//Clear the form.
		$("#addTaskFormContainer")
			.empty()
			.append(
				$("<div></div>")
					.addClass("supportTasksTableRow")
					.append(
						$("<h2></h2>")
							.text("Add / Edit Support Task")
							.append(
								" | "
							)
							.append(
								$("<span></span>")
									.text("Cancel")
									.attr("title", "Cancel")
									.click(function(e){
										$("#addTaskFormContainer").hide();
										$("#supportTasksTable").show();
									})
							)
					)
					.append("Customer:")
					.append(
						$("<select></select>")
							.addClass("editTaskField")
							.attr("id", "addEditSupportTaskCustomer")
							.append(
								$("<option></option>")
									.val(0)
									.text("--Please Select--")
							)
					)
					.append("Task Description:")
					.append(
						$("<textarea></textarea>")
							.addClass("taskDescriptionTextField")
							.attr("id", "addEditSupportTaskDescription")
							.val( taskDescription )
					)
					.append("Task Priority:")
					.append(
						$("<select></select>")
							.addClass("editTaskField")
							.attr("id", "addEditSupportTaskPriority")
							.append(
								$("<option></option>")
									.val(0)
									.text("--Please Select--")
							)
					)
					.append("Due Date:")
					.append(
						$("<input></input>")
							.addClass("editTaskField")
							.attr("type", "date")
							.attr("id", "addEditSupportTaskDueDate")
							.val( dueDate )
					)
					.append("Estimated Time (Hours):")
					.append(
						$("<input></input>")
							.addClass("editTaskField")
							.attr("type", "number")
							.attr("id", "addEditSupportTaskEstimatedHours")
							.val( estimatedTimeHours )
					)
					.append(
						$("<button></button>")
							.addClass("addEditSupportTaskSaveButton")
							.addClass("editTaskField")
							.text("Save")
							.attr("data-support-task-id", supportTaskID)
							.click(function(e){
								var method = "createSupportTicket";
								
								if( parseInt( supportTaskID ) != 0 )method = "updateSupportTicket";
								
								_api.call(
									method,
									{
										customer_id: $("#addEditSupportTaskCustomer option:selected").val(),
										task_description: $("#addEditSupportTaskDescription").val(),
										priority_id: $("#addEditSupportTaskPriority").val(),
										due_date: $("#addEditSupportTaskDueDate").val(),
										time_estimate_hours: $("#addEditSupportTaskEstimatedHours").val(),
										support_ticket_id: $(this).attr("data-support-task-id")
									},
									function(data){
										$("#addTaskFormContainer").hide();
										$("#supportTasksTable").show();
										app.getAllSupportTickets();
									}
								);
							})
					)
			)
			.show();
			
		//Get the options for the customer list
		_api.call(
			"listCustomers",
			{},
			function(customers){
				$.each(customers, function( i, customer ){
					$("#addEditSupportTaskCustomer")
						.append(
							$("<option></option>")
								.val( customer.id )
								.text( customer.customer_name )
						)
						
					if( parseInt( customer.id ) == parseInt( customerID ) ){
						$("#addEditSupportTaskCustomer option:last").attr("selected", true);
					}
				})
			}
		);
		
		//Get the options for the priority list
		_api.call(
			"listPriorities",
			{},
			function(priorities){
				$.each(priorities, function( i, priority ){
					$("#addEditSupportTaskPriority")
						.append(
							$("<option></option>")
								.val( priority.id )
								.text( priority.priority_name )
						)
						
					if( parseInt( priority.id ) == parseInt( priorityID ) ){
						$("#addEditSupportTaskPriority option:last").attr("selected", true);
					}
				})
			}
		);
		
		//Hide list of support tasks
		$("#supportTasksTable").hide();
	}
	
	displayTicket( ticket ){
		$("#supportTasksTable")
			.append(
				$("<div></div>")
					.attr("data-ticket-id", ticket.id)
					.attr("data-ticket-priority", ticket.priority_id)
					.attr("data-ticket-due-date", ticket.due_date)
					.addClass("supportTasksTableRow")
					.addClass("supportTasksRowBorder")
					.append(
						$("<h3></h3>")
							.addClass("noMargin")
							.attr("customer-name-ticket-id", ticket.id)
							.attr("customer-name-customer-id", ticket.customer_id)
							.text( ticket.customer_name )
					)
					.append(
						$("<p></p>")
							.addClass("noMargin")
							.addClass("ticketTitleText")
							.attr("task-description-ticket-id", ticket.id)
							.text( "#" + ticket.id + " - " + ticket.task_description)
					)
					.append(
						$("<p></p>")
							.attr("task-details-ticket-id", ticket.id)
							.append(
								$("<span></span>")
									.attr("task-details-priority-id", ticket.id)
									.attr("data-currently-selected-priority", ticket.priority_id)
									.text("Priority: " + ticket.priority)
							)
							.append("<br />")
							.append(
								$("<span></span>")
									.attr("task-details-due-date-id", ticket.id)
									.text("Due Date: " + app.ukDateFormat( ticket.due_date ))
							)
							.append("<br />")
							.append(
								$("<span></span>")
									.attr("task-details-estimate-time-id", ticket.id)
									.text("Estimated Time: " + ticket.time_estimate_hours + " hour" + ( parseFloat(ticket.time_estimate_hours) > 1 ? "s" : "" ))
							)
					)
			)
	}
	
	sortTickets(){
		//Sort all displayed tickets
		var sortedTickets = $("[data-ticket-id]").sort(function(a,b){
			a = $(a);
			b = $(b);
			
			var priority_a = parseInt( a.attr("data-ticket-priority") );
			var priority_b = parseInt( b.attr("data-ticket-priority") );
			
			var due_a = ( new Date( a.attr("data-ticket-due-date") ) ).getTime();
			var due_b = ( new Date( b.attr("data-ticket-due-date") ) ).getTime();
			
			if( due_a == due_b ){
				return (priority_a < priority_b) ? -1 : (priority_a > priority_b ) ? 1 : 0
			} else {
				return (due_a < due_b) ? -1 : (due_a > due_b ) ? 1 : 0
			}
		});
		
		$.each(sortedTickets, function(i, ticket){
			ticket = $(ticket);
			ticket.remove();
			
			var ticketID = ticket.attr("data-ticket-id")
			
			ticket.find(".ticketSettings").remove();
			
			$("#supportTasksTable").append(
				ticket.prepend(
						$("<span></span>")
							.addClass("ticketSettings")
							.append(
								$("<i></i>")
									.addClass("fa")
									.addClass("fa-gears")
									.attr("data-ticket-options", ticketID)
									.attr("title", "Options")
									.click(function(e){
										//Hide self
										$(this).hide();
										
										//Show cancel button
										$("[data-ticket-options-cancel='"+ ticketID +"']").show();
										
										//Show edit button
										$("[data-ticket-edit='"+ticketID+"']").show();
										
										//Show delete button
										$("[data-ticket-delete='"+ticketID+"']").show();
									})
							)
							.append(
								$("<i></i>")
									.addClass("fa")
									.addClass("fa-window-close")
									.attr("data-ticket-options-cancel", ticketID)
									.attr("title", "Cancel")
									.hide()
									.click(function(e){
										//Hide self
										$(this).hide();
										
										//Show options button
										$("[data-ticket-options='"+ ticketID +"']").show()
										
										//Hide edit button
										$("[data-ticket-edit='"+ticketID+"']").hide();
										
										//Hide edit button
										$("[data-ticket-edit-save='"+ticketID+"']").hide();
										
										//Hide delete button
										$("[data-ticket-delete='"+ticketID+"']").hide();
										
										app.closeAllEditFields( ticketID );
									})
							)
							.append(
								$("<i></i>")
									.addClass("fa")
									.addClass("fa-edit")
									.attr("title", "Edit")
									.addClass("smallLeftMargin")
									.attr("data-ticket-edit", ticketID)
									.hide()
									.click(function(e){
										_api.call(
											"getSupportTicketByID",
											{
												support_ticket_id: $(this).attr("data-ticket-edit")
											},
											function(ticket){
												//Get the ticket
												ticket = ticket[0];
												
												//Show the add/edit support task form
												app.addEditSupportTask( ticket.customer_id, ticket.task_description, ticket.priority_id, ticket.due_date, ticket.estimated_time_hours, ticket.id);
												
												//Remove all edit buttons
												app.closeAllEditFields();
											}
										);
									})
							)
							.append(
								$("<i></i>")
									.addClass("fa")
									.addClass("fa-trash")
									.attr("title", "Delete")
									.attr("data-ticket-delete", ticketID)
									.addClass("smallLeftMargin")
									.hide()
									.click(function(e){
										var originalButton = $(this);
										
										$(this).hide().after(
											$("<span></span>")
												.text("Are you sure?")
												.append(
													$("<span></span>")
														.addClass("smallLeftMargin")
														.text("No")
														.click(function(e){
															$(this).parent().remove();
															originalButton.show();
														})
												)
												.append(
													$("<span></span>")
														.attr("data-ticket-delete-confirm", $(this).attr("data-ticket-delete"))
														.addClass("smallLeftMargin")
														.text("Yes")
														.click(function(e){
															var ticketID = $(this).attr("data-ticket-delete-confirm")
															
															_api.call(
																"deleteSupportTicket",
																{
																	support_ticket_id: ticketID
																}
															);
														})
												)
										);
									})
							)
					)
			);
			
			if( app.isDueToday( ticket.attr("data-ticket-due-date") ) )ticket.addClass("dueToday");
			if( app.isOverdue( ticket.attr("data-ticket-due-date") ) )ticket.addClass("overdue");
		})
	}
	
	closeAllEditFields( ticketID ){
		$("[data-is-property='"+ ticketID +"']").show();
		
		$(this).hide();
		
		$("[data-ticket-options='"+ ticketID +"']").show();
		$("[data-ticket-options-cancel='"+ ticketID +"']").hide();
		$("[data-ticket-delete='"+ ticketID +"']").hide();
		$("[data-ticket-edit-save='"+ ticketID +"']").hide();
		
		//Remove all edit fields
		$("[data-is-edit='"+ ticketID +"']").remove();
	}

	isOverdue( taskDueDate ){
		taskDueDate = new Date( app.ukDateFormat( taskDueDate ) );
		var todayDate = new Date( app.ukDateFormat( new Date() ) );
		
		if( taskDueDate.getTime() < todayDate.getTime() ){
			return true;
		}
		
		return false;
	}
	
	isDueToday(taskDueDate){
		taskDueDate = new Date( app.ukDateFormat( taskDueDate ) );
		var todayDate = new Date( app.ukDateFormat( new Date() ) );
		
		if( taskDueDate.getTime() == todayDate.getTime() ){
			return true;
		}
		
		return false;
	}
	
	ukDateFormat(usDate){
		var date = new Date(usDate);
		return ( (date.getDate() < 10 ? "0" : "") + date.getDate() + "-" + ( ( date.getMonth() < 10 ? "0" : "" ) + parseInt( date.getMonth() + 1 ) ) + "-" + date.getFullYear() );
	}
	
	usDateFormat(ukDate){
		ukDate = ukDate.split("-");
		var usDate = ukDate[2] + "-" + ukDate[1] + "-" + ukDate[0];
		return usDate;
	}
}

app = new appClass();