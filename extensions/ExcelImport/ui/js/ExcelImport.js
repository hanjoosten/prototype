// Add angularFileUpload module to dependency list
var app = angular.module('AmpersandApp');
app.requires[app.requires.length] = 'angularFileUpload';

angular.module('AmpersandApp').config(function($routeProvider) {
	$routeProvider
		// default start page
		.when('/ext/ExcelImport',
			{	controller: 'ExcelImportController'
			,	templateUrl: 'extensions/ExcelImport/ui/views/ExcelImport.html'
			,	interfaceLabel: 'Excel import'
			});
}).controller('ExcelImportController', function ($scope, $rootScope, FileUploader, NotificationService) {
	
	// $rootScope, so that all information and uploaded files are kept while browsing in the application
	if (typeof $rootScope.uploader == 'undefined') {

		$rootScope.uploader = new FileUploader({
			 url: 'api/v1/excelimport/import'
		});
	}
	
	$rootScope.uploader.onSuccessItem = function(fileItem, response, status, headers) {
		NotificationService.updateNotifications(response.notifications);
        // console.info('onSuccessItem', fileItem, response, status, headers);
    };
    
    $rootScope.uploader.onErrorItem = function(item, response, status, headers){
        if(typeof response === 'object'){
            var message = response.msg || 'Error while importing';
            NotificationService.addError(message, status, true);
            
            if(response.notifications !== undefined) NotificationService.updateNotifications(response.notifications); 
        }else{
            var message = status + ' Error while importing';
            var details = response; // html content is excepted
            NotificationService.addError(message, status, true, details);
        }
    };
    
});