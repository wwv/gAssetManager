jQuery.loginza = {};

jQuery.loginza.onceMore = function()
{	
	document.location = $('.appos_loginza_auth_error,.appos_loginza_auth_success').attr('once_more');
};

$(document).ready(function() {
	$('#appos_loginza_auth_once_more')
       .click( function(event) {
    	     jQuery.loginza.onceMore();
             event.preventDefault();
          });
	
	$(document).on('click', '.appos_aka_row_delete', function(event) {
  	  var aka_id = $(this).parent().attr('aka_id'); 
	  var confirmed = confirm("а�б� аБаОаЛб�б�аЕ аНаЕ б�аОб�аИб�аЕ аВб�аОаДаИб�б� аНаА appossum.com "+$('.appos_aka_row[aka_id='+aka_id+'] .appos_aka_row_as').text()+' '+$('.appos_aka_row[aka_id='+aka_id+'] .appos_aka_row_via').text()+"?");
	  if(confirmed) {
		 $('.appos_aka').load('/loginza/aka_delete/aka_id/'+aka_id);  
	  } 	      
      event.preventDefault();
    });
	
	if ($('.appos_loginza_auth_success[redirect_to]').size()>0) {
		$('body').oneTime(3000, "redirectafterakalogin", function() {  		  
			window.parent.document.location = $('.appos_loginza_auth_success').attr('redirect_to');
		});			
	}   
	
	if ($('.appos_loginza_auth_success[once_more]').size()>0) {
		$(window.parent.document).find('.appos_aka').load('/loginza/akas_refresh', function() {
			$('body').oneTime(3000, "refreshakas", function() {  		  
			    jQuery.loginza.onceMore();
			});
		});		
	}    
});