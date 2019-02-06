angular.module('AmpersandApp')
.service('NavigationBarService', function(Restangular, $localStorage, $sessionStorage, $timeout, NotificationService, $q){
    let navbar = {
        home: null, // home/start page, can be set in project.yaml (default: '#/prototype/welcome')
        top: [],
        new: [],
        refresh: [],
        role: [],
        ext: []
    };
    let defaultSettings = {
        notify_showSignals: true,
        notify_showInfos: true,
        notify_showSuccesses: true,
        notify_autoHideSuccesses: true,
        notify_showErrors: true,
        notify_showWarnings: true,
        notify_showInvariants: true,
        autoSave: true
    };
    let observerCallables = [];

    let notifyObservers = function(){
        angular.forEach(observerCallables, function(callable){
            callable();
        });
    };

    let pendingNavbarPromise = null;
    function getNavbarPromise() {
        if (pendingNavbarPromise === null) {
            pendingNavbarPromise = Restangular
            .one('app/navbar')
            .get()
            .finally(function() {
                pendingNavbarPromise = null;
            });
        }

        return pendingNavbarPromise;
    }

    let service = {
        navbar : navbar,
        defaultSettings : defaultSettings,

        addObserverCallable : function(callable){
            observerCallables.push(callable);
        },

        getRouteForHomePage : function() {
            if (navbar.home === null) {
                return getNavbarPromise()
                .then(function (data){
                    return data.home;
                }, function (error) {
                    console.error('Error in getting nav bar data: ', error);
                })
            } else {
                return $q.resolve(navbar.home);
            }
        },

        refreshNavBar : function(){
            return getNavbarPromise()
            .then(function(data){
                // Content of navbar
                navbar.home = data.home;
                navbar.top = data.top;
                navbar.new = data.new;
                navbar.refresh = data.refresh;
                navbar.role = data.role;
                navbar.ext = data.ext;

                // Content for session storage
                $sessionStorage.session = data.session;
                $sessionStorage.sessionRoles = data.sessionRoles;
                $sessionStorage.sessionVars = data.sessionVars;
                
                // Save default settings
                service.defaultSettings = data.defaultSettings;
                service.initializeSettings();
                
                // Update notifications
                NotificationService.updateNotifications(data.notifications);

                notifyObservers();
            }, function(error){
                service.initializeSettings();
            });
        },

        initializeSettings : function(){
            let resetRequired = false;

            // Check for undefined settings
            angular.forEach(service.defaultSettings, function(value, index, obj){
                if($localStorage[index] === undefined) {
                    resetRequired = true;
                }
            });

            if(resetRequired) service.resetSettingsToDefault();
        },

        resetSettingsToDefault : function(){
            // all off
            angular.forEach(service.defaultSettings, function(value, index, obj){
                $localStorage[index] = false;
            });
            
            $timeout(function() {
                // Reset to default
                $localStorage.$reset(service.defaultSettings);
            }, 500);
        }
    };
    
    return service;
});
